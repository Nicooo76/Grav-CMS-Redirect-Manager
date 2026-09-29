<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RedirectLookup;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\SqliteLogStore;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceFactory::class)]
#[CoversClass(RedirectLookup::class)]
#[Group('grav')]
final class ServiceFactoryTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $site
     */
    private function factory(array $config = [], array $site = []): ServiceFactory
    {
        /** @var array{languages?: list<string>, default_language?: string, custom_base_url?: string, api_route?: string, admin_route?: string} $site */
        return new ServiceFactory($config, $site, $this->tmp . '/data', $this->tmp . '/cache', null, $this->clock);
    }

    public function testConfigLookupAndCoercion(): void
    {
        $f = $this->factory(['enabled' => 'true', 'redirects' => ['enabled' => '0', 'max_chain_depth' => '5', 'excluded_paths' => ['/a', ' ', '/b']]]);
        self::assertTrue($f->bool('enabled', false));
        self::assertFalse($f->bool('redirects.enabled', true));
        self::assertSame(5, $f->int('redirects.max_chain_depth', 10));
        self::assertSame(['/a', '/b'], $f->stringList('redirects.excluded_paths'));
        self::assertSame('fallback', $f->string('nope.deeper', 'fallback'));
        self::assertFalse($f->enabled());
    }

    public function testServicesAreCreatedOnceAndPointIntoTheDataDirectory(): void
    {
        $f = $this->factory();
        self::assertSame($f->repository(), $f->repository());
        self::assertSame($this->tmp . '/data/rules.yaml', $f->repository()->file());
        self::assertSame($f->matcher(), $f->matcher());
        self::assertSame($f->hitRecorder(), $f->hitRecorder());
        self::assertInstanceOf(PageIndex::class, $f->pageIndex());
    }

    public function testPageIndexComesFromTheProvider(): void
    {
        $index = new PageIndex();
        $f = new ServiceFactory([], [], $this->tmp . '/d', $this->tmp . '/c', null, $this->clock, static fn (): PageIndex => $index);
        self::assertSame($index, $f->pageIndex());
    }

    public function testMatcherUsesConfiguredGuardAndQueryIgnores(): void
    {
        $f = $this->factory(['security' => ['allowed_hosts' => ['ok.example.org']], 'redirects' => ['query_ignore' => ['ref']]]);
        self::assertTrue($f->targetGuard()->isHostAllowed('ok.example.org'));
        self::assertFalse($f->targetGuard()->isHostAllowed('evil.example.org'));
        self::assertSame(['ref'], $f->matcherOptions()->globalQueryIgnore);

        self::assertSame(['utm_*', 'fbclid', 'gclid', 'msclkid'], $this->factory()->matcherOptions()->globalQueryIgnore);
    }

    public function testDefaultLanguageReachesTheMatcher(): void
    {
        $f = $this->factory([], ['default_language' => 'en', 'languages' => ['en', 'de']]);
        self::assertSame('en', $f->matcherOptions()->defaultLanguage);
        self::assertNull($this->factory()->matcherOptions()->defaultLanguage);
    }

    public function testLogBackendFallsBackToJsonl(): void
    {
        self::assertInstanceOf(JsonlLogStore::class, $this->factory()->logStore());
        $sqlite = $this->factory(['log' => ['backend' => 'sqlite']])->logStore();
        self::assertInstanceOf(SqliteLogStore::isAvailable() ? SqliteLogStore::class : JsonlLogStore::class, $sqlite);
    }

    public function testNotFoundLoggerHonoursConfig(): void
    {
        $f = $this->factory(['log' => ['ip_mode' => 'anonymize', 'ignore_patterns' => ['/skip-*'], 'log_bots' => false]]);
        $logger = $f->notFoundLogger();

        $entry = $logger->log('/missing', '', null, 'Mozilla/5.0 Chrome/120', '203.0.113.77', null, 'example.org');
        self::assertNotNull($entry);
        self::assertSame('203.0.113.0', $entry->ip);
        self::assertNull($logger->log('/skip-me', '', null, 'Mozilla/5.0 Chrome/120', null, null, 'example.org'));
        self::assertNull($logger->log('/wp-login.php', '', null, 'Mozilla/5.0 Chrome/120', null, null, 'example.org'), 'default ignores stay on');
        self::assertNull($logger->log('/bot', '', null, 'Googlebot/2.1', null, null, 'example.org'));

        $off = $this->factory(['log' => ['ip_mode' => 'none', 'use_default_ignores' => false]])->notFoundLogger();
        self::assertNull($off->log('/x', '', null, 'Mozilla/5.0 Chrome/120', '203.0.113.77', null, 'example.org')?->ip);
        self::assertNotNull($off->log('/wp-login.php', '', null, 'Mozilla/5.0 Chrome/120', null, null, 'example.org'));
    }

    public function testRedirectLookup(): void
    {
        $f = $this->factory([], ['languages' => ['en', 'de'], 'custom_base_url' => '/sub']);
        $f->repository()->saveAll([
            new Rule('a', '/old', '/new'),
            Rule::fromArray(['id' => 'g', 'source' => '/gone', 'status' => 410]),
            Rule::fromArray(['id' => 'p', 'source' => '/alias', 'target' => '/real', 'status' => 200]),
            Rule::fromArray(['id' => 'nf', 'source' => '/late', 'target' => '/new', 'only_if_not_found' => true]),
        ]);
        $lookup = new RedirectLookup($f, ['PHP_SELF' => '/index.php'], 'example.org');

        self::assertSame(['status' => 301, 'location' => '/sub/new', 'rule_id' => 'a'], $lookup->lookup('/sub/old'));
        self::assertSame(['status' => 301, 'location' => '/sub/de/new', 'rule_id' => 'a'], $lookup->lookup('/sub/de/old?x=1'));
        self::assertSame(['status' => 301, 'location' => '/sub/new', 'rule_id' => 'a'], $lookup->lookup('https://example.org/sub/old'));
        self::assertSame(['status' => 410, 'location' => '', 'rule_id' => 'g'], $lookup->lookup('/sub/gone'));
        self::assertSame(['status' => 200, 'location' => '', 'rule_id' => 'p'], $lookup->lookup('/sub/alias'));
        self::assertNull($lookup->lookup('/sub/late'), 'only-if-not-found rules are not part of the lookup');
        self::assertNull($lookup->lookup('/sub/nothing'));
        self::assertNull($lookup->lookup('/sub/api/x'), 'excluded');
        self::assertNull($lookup->lookup('http:///bad'));
        self::assertNull($lookup->lookup("/sub/a\0b"));

        // the lookup records nothing
        self::assertFileDoesNotExist($this->tmp . '/data/hits');
        self::assertNotNull($f->matcher()->match(new RequestContext('/old'), MatchPhase::Early));
    }
}
