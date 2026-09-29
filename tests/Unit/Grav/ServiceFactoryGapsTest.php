<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The parts of ServiceFactory the main test leaves out: directory accessors, the default clock, the state stores,
 * webhook wiring, site URL/title and the auto-redirect settings.
 */
#[CoversClass(ServiceFactory::class)]
#[Group('grav')]
final class ServiceFactoryGapsTest extends TestCase
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
        putenv('REDIRECT_MANAGER_WEBHOOK_SECRET');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $site
     */
    private function factory(array $config = [], array $site = [], bool $fixedClock = true): ServiceFactory
    {
        /** @var array{languages?: list<string>, default_language?: string, custom_base_url?: string, api_route?: string, admin_route?: string, base_url?: string, site_title?: string} $site */
        return new ServiceFactory($config, $site, $this->tmp . '/data', $this->tmp . '/cache', null, $fixedClock ? $this->clock : null);
    }

    public function testDirectoryAccessorsAndDefaultClock(): void
    {
        $f = $this->factory(fixedClock: false);
        self::assertSame($this->tmp . '/data', $f->dataDir());
        self::assertSame($this->tmp . '/cache', $f->cacheDir());
        self::assertInstanceOf(SystemClock::class, $f->clock());
        self::assertSame($f->clock(), $f->clock());
        self::assertSame($this->clock, $this->factory()->clock());
    }

    public function testStateStoresLiveInTheDataDirectoryAndAreShared(): void
    {
        $f = $this->factory();

        self::assertSame($f->statsStore(), $f->statsStore());
        self::assertSame($f->resolvedPaths(), $f->resolvedPaths());
        self::assertSame($f->suggestionStore(), $f->suggestionStore());
        self::assertSame($f->checkResultStore(), $f->checkResultStore());
        self::assertSame($f->thresholdTracker(), $f->thresholdTracker());
        self::assertSame($f->autoState(), $f->autoState());

        self::assertSame($this->tmp . '/data/auto-state.json', $f->autoState()->file());

        $f->resolvedPaths()->markResolved('/gone', $this->clock->now());
        self::assertArrayHasKey('/gone', $f->resolvedPaths()->all());
        self::assertFileExists($this->tmp . '/data/404-state.json');

        self::assertSame([], $f->statsStore()->all());
        self::assertNull($f->checkResultStore()->lastRun());
        self::assertSame([], $f->suggestionStore()->all());
    }

    public function testLanguagesAreLowercased(): void
    {
        self::assertSame(['en', 'de-at'], $this->factory([], ['languages' => ['EN', 'de-AT']])->languages());
        self::assertSame([], $this->factory()->languages());
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function stringListInputs(): iterable
    {
        yield 'missing' => [null, []];
        yield 'scalar instead of list' => ['/a', []];
        yield 'mixed items' => [['/a', 5, ['nested'], '  ', ' /b '], ['/a', '5', '/b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stringListInputs')]
    public function testStringListIgnoresEverythingButNonEmptyScalars(mixed $value, array $expected): void
    {
        $config = $value === null ? [] : ['list' => $value];
        self::assertSame($expected, $this->factory($config)->stringList('list'));
    }

    public function testSiteUrlAndTitle(): void
    {
        $f = $this->factory([], ['base_url' => 'https://example.org/', 'site_title' => 'My site']);
        self::assertSame('https://example.org', $f->siteUrl());
        self::assertSame('My site', $f->siteTitle());

        $bare = $this->factory();
        self::assertSame('', $bare->siteUrl());
        self::assertSame('', $bare->siteTitle());
    }

    public function testWebhookNotifierIsNullWithoutUrl(): void
    {
        self::assertNull($this->factory()->webhookNotifier());
        self::assertNull($this->factory(['notifications' => ['webhook_url' => '   ']])->webhookNotifier());
    }

    public function testWebhookSecretComesFromConfigOrEnvironment(): void
    {
        $config = ['notifications' => ['webhook_url' => 'https://hooks.example.org/x', 'webhook_secret' => 'from-config']];
        $site = ['site_title' => 'Shop'];

        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = ['url' => $url, 'headers' => $options['headers'], 'body' => $options['body']];

            return new MockResponse('', ['http_code' => 204]);
        });

        $notifier = $this->factory($config, $site)->webhookNotifier($client);
        self::assertNotNull($notifier);
        self::assertTrue($notifier->send('test', ['a' => 1])->ok);

        putenv('REDIRECT_MANAGER_WEBHOOK_SECRET=from-env');
        $envNotifier = $this->factory($config, $site)->webhookNotifier($client);
        self::assertNotNull($envNotifier);
        self::assertTrue($envNotifier->send('test', ['a' => 1])->ok);

        self::assertCount(2, $captured);
        self::assertSame('https://hooks.example.org/x', $captured[0]['url']);
        self::assertStringContainsString('"site":"Shop"', $captured[0]['body']);

        $signature = static function (array $headers): string {
            foreach ($headers as $line) {
                if (str_starts_with((string) $line, 'X-Redirect-Manager-Signature: ')) {
                    return substr((string) $line, strlen('X-Redirect-Manager-Signature: '));
                }
            }

            return '';
        };
        $timestamp = static function (array $headers): string {
            foreach ($headers as $line) {
                if (str_starts_with((string) $line, 'X-Redirect-Manager-Timestamp: ')) {
                    return substr((string) $line, strlen('X-Redirect-Manager-Timestamp: '));
                }
            }

            return '';
        };
        foreach ([0 => 'from-config', 1 => 'from-env'] as $i => $secret) {
            $call = $captured[$i];
            self::assertNotSame('', $signature($call['headers']));
            self::assertSame(
                'sha256=' . hash_hmac('sha256', $timestamp($call['headers']) . '.' . $call['body'], $secret),
                $signature($call['headers']),
                'call ' . $i . ' is signed with the ' . $secret . ' secret',
            );
        }
    }

    public function testWebhookNotifierCreatesItsOwnHttpClientWhenNoneIsGiven(): void
    {
        self::assertNotNull($this->factory(['notifications' => ['webhook_url' => 'https://hooks.example.org/x']])->webhookNotifier());
    }

    public function testAutoRedirectConfigDefaults(): void
    {
        $config = $this->factory()->autoRedirectConfig();
        self::assertSame(StatusCode::MovedPermanently, $config->status);
        self::assertSame(ChildrenMode::Wildcard, $config->children);
        self::assertSame(DeletePolicy::Ask, $config->onDelete);
    }

    public function testAutoRedirectConfigReadsTheSection(): void
    {
        $config = $this->factory(['auto_redirect' => ['status' => 302]])->autoRedirectConfig();
        self::assertSame(StatusCode::Found, $config->status);

        // a section that is not a map falls back to the defaults
        $broken = $this->factory(['auto_redirect' => 'yes'])->autoRedirectConfig();
        self::assertSame(StatusCode::MovedPermanently, $broken->status);
    }
}
