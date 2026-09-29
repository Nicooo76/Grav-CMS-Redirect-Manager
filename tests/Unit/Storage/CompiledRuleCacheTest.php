<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\Storage\CompiledRuleCache;
use Grav\Plugin\RedirectManager\Storage\ParsedRulesCache;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(CompiledRuleCache::class)]
#[Group('storage')]
final class CompiledRuleCacheTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;
    private RuleRepository $repo;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->repo = new RuleRepository($this->tmp . '/data', $this->clock);
        $this->logged = [];
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function cache(): CompiledRuleCache
    {
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->sink[] = $level . ': ' . $message;
            }
        };

        return new CompiledRuleCache($this->tmp . '/cache', $this->repo->file(), new RuleCompiler(), $logger);
    }

    private function target(CompiledRuleCache $cache, string $path): ?string
    {
        $matcher = new Matcher($cache->load(), $this->clock, new MatcherOptions());

        return $matcher->match(new RequestContext($path), MatchPhase::Early)?->location;
    }

    public function testBuildsThenServesFromTheCompiledFile(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $cache = $this->cache();
        self::assertSame('/new', $this->target($cache, '/old'));
        self::assertFileExists($cache->cacheFile());
        self::assertStringStartsWith('<?php return', (string) file_get_contents($cache->cacheFile()));

        // a second instance (next request) reuses the file without rewriting it
        $before = stat($cache->cacheFile());
        clearstatcache();
        sleep(1);
        self::assertSame('/new', $this->target($this->cache(), '/old'));
        clearstatcache();
        self::assertSame($before['ino'] ?? 0, stat($cache->cacheFile())['ino'] ?? -1, 'warm load does not rewrite the file');
        self::assertSame($before['mtime'] ?? 0, stat($cache->cacheFile())['mtime'] ?? -1);
    }

    public function testARebuildUsesAndFillsTheParsedRulesCache(): void
    {
        $parsed = new ParsedRulesCache($this->tmp . '/cache', $this->repo->file());
        // The plugin's own writes fill the parsed rows: the rebuild after an edit does not parse YAML again.
        $repo = new RuleRepository($this->tmp . '/data', $this->clock, $parsed);
        $repo->upsert(new Rule('a', '/old', '/new'));
        $hash = RuleRepository::hashContent((string) file_get_contents($repo->file()));
        $parsed->store($hash, [['id' => 'from-cache', 'source' => '/cached', 'target' => '/served']]);

        $cache = new CompiledRuleCache($this->tmp . '/cache', $repo->file(), new RuleCompiler(), null, $parsed);

        self::assertSame('/served', $this->target($cache, '/cached'));
        self::assertNull($this->target($cache, '/old'), 'the rows of the cache, not the YAML, were compiled');

        // A hand edit changes the hash: the YAML is parsed and its rows are stored for the next rebuild.
        file_put_contents($repo->file(), "rules:\n  - id: edited\n    source: /edited\n    target: /now\n");
        $fresh = new CompiledRuleCache($this->tmp . '/cache', $repo->file(), new RuleCompiler(), null, $parsed);

        self::assertSame('/now', $this->target($fresh, '/edited'));
        self::assertNotNull($parsed->rows(RuleRepository::hashContent((string) file_get_contents($repo->file()))));
    }

    public function testRebuildsWhenRulesChange(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/one'));
        self::assertSame('/one', $this->target($this->cache(), '/old'));

        $this->repo->upsert(new Rule('a', '/old', '/two'));
        self::assertSame('/two', $this->target($this->cache(), '/old'));
    }

    public function testDetectsAnInPlaceEditOfTheSameSizeInTheSameSecond(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        $yaml = static fn (string $target): string => "version: 1\nrules:\n  - {id: a, source: /old, target: $target}\n";
        file_put_contents($this->repo->file(), $yaml('/one'));
        self::assertSame('/one', $this->target($this->cache(), '/old'));

        $mtime = filemtime($this->repo->file());
        file_put_contents($this->repo->file(), $yaml('/two'));
        touch($this->repo->file(), (int) $mtime);
        clearstatcache();
        self::assertSame('/two', $this->target($this->cache(), '/old'));
    }

    public function testMissingRulesFileIsAnEmptySetCachedOnce(): void
    {
        $cache = $this->cache();
        self::assertSame(0, $cache->load()->count());
        self::assertFileExists($cache->cacheFile());

        $stat = stat($cache->cacheFile());
        sleep(1);
        clearstatcache();
        self::assertSame(0, $this->cache()->load()->count());
        clearstatcache();
        self::assertSame($stat['ino'] ?? 0, stat($cache->cacheFile())['ino'] ?? -1);
        self::assertSame([], $this->logged);

        // creating the file afterwards is noticed
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        self::assertSame('/new', $this->target($this->cache(), '/old'));
    }

    public function testCorruptCacheFileIsRebuilt(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $cache = $this->cache();
        $cache->load();

        foreach (['<?php this is not php', '<?php return "a string";', '<?php return ["meta" => 1, "set" => 2];', ''] as $garbage) {
            file_put_contents($cache->cacheFile(), $garbage);
            self::assertSame('/new', $this->target($this->cache(), '/old'), 'garbage: ' . $garbage);
        }
        self::assertStringStartsWith('<?php return', (string) file_get_contents($cache->cacheFile()));
    }

    public function testInvalidateForcesRebuild(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $cache = $this->cache();
        $cache->load();
        $cache->invalidate();
        self::assertFileDoesNotExist($cache->cacheFile());
        self::assertSame('/new', $this->target($cache, '/old'));
        self::assertFileExists($cache->cacheFile());
    }

    public function testBrokenYamlKeepsPreviousRulesAndLogsOnce(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $this->cache()->load();

        file_put_contents($this->repo->file(), "rules: [broken\n  - : :");
        $cache = $this->cache();
        self::assertSame('/new', $this->target($cache, '/old'), 'last known good rules stay in force');
        self::assertNotNull($cache->lastError());
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('corrupt', $this->logged[0]);

        // the next request does not log again
        $this->target($this->cache(), '/old');
        self::assertCount(1, $this->logged);
    }

    public function testBrokenYamlWithoutHistoryIsAnEmptySet(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "\t\tnot: [valid");
        $cache = $this->cache();
        self::assertSame(0, $cache->load()->count());
        self::assertNotSame([], $this->logged);
    }

    public function testFixingTheFileRecovers(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), 'garbage: [');
        self::assertSame(0, $this->cache()->load()->count());

        $this->repo->saveAll([new Rule('a', '/old', '/new')]);
        $cache = $this->cache();
        self::assertSame('/new', $this->target($cache, '/old'));
        self::assertNull($cache->lastError());
    }
}
