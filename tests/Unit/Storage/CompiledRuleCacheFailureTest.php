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
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * CompiledRuleCache when the environment misbehaves: a lock or cache file that cannot be written, a rules file that
 * cannot be read, a cache another process finishes meanwhile, and the warm path for a file that is no longer "racy".
 */
#[CoversClass(CompiledRuleCache::class)]
#[Group('storage')]
final class CompiledRuleCacheFailureTest extends TestCase
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
        if (is_file($this->repo->file())) {
            @chmod($this->repo->file(), 0644);
        }
        $this->removeTempDir();
    }

    private function cache(?string $cacheDir = null): CompiledRuleCache
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

        return new CompiledRuleCache($cacheDir ?? $this->tmp . '/cache', $this->repo->file(), new RuleCompiler(), $logger);
    }

    private function target(CompiledRuleCache $cache, string $path): ?string
    {
        $matcher = new Matcher($cache->load(), $this->clock, new MatcherOptions());

        return $matcher->match(new RequestContext($path), MatchPhase::Early)?->location;
    }

    public function testTheLoadedSetIsKeptForTheRestOfTheRequest(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/one'));
        $cache = $this->cache();
        $first = $cache->load();

        $this->repo->upsert(new Rule('a', '/old', '/changed-later'));

        self::assertSame($first, $cache->load(), 'a second call in the same request does not stat or include again');
        self::assertSame('/one', $this->target($cache, '/old'));
        self::assertNull($cache->lastError());
    }

    public function testAFreshCacheWhoseRuleSetCannotBeHydratedIsRebuilt(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $cache = $this->cache();
        $cache->load();

        // Valid meta (so the cache looks fresh) around a rule set of a format this code does not know.
        /** @var array{meta: array<string, mixed>, set: array<string, mixed>} $data */
        $data = include $cache->cacheFile();
        $data['set'] = ['v' => 999];
        file_put_contents($cache->cacheFile(), '<?php return ' . var_export($data, true) . ";\n");

        $second = $this->cache();
        self::assertSame('/new', $this->target($second, '/old'));
        self::assertSame([], $this->logged, 'a stale format is not an error');

        /** @var array{meta: array<string, mixed>, set: array<string, mixed>} $rewritten */
        $rewritten = include $cache->cacheFile();
        self::assertNotSame(999, $rewritten['set']['v'] ?? null, 'the cache file was rewritten');
        self::assertSame('/new', $this->target($this->cache(), '/old'));
    }

    public function testAnUnlockableCacheDirectoryFallsBackToCompilingInMemory(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        file_put_contents($this->tmp . '/not-a-dir', 'x');
        $cache = $this->cache($this->tmp . '/not-a-dir');

        self::assertSame('/new', $this->target($cache, '/old'));
        self::assertNull($cache->lastError());
        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('warning: Redirect Manager: cannot lock the rule cache, compiling in memory.', $this->logged[0]);
        self::assertFileDoesNotExist($cache->cacheFile());
    }

    public function testInMemoryFallbackWithoutARulesFileIsAnEmptySet(): void
    {
        file_put_contents($this->tmp . '/not-a-dir', 'x');
        $cache = $this->cache($this->tmp . '/not-a-dir');

        self::assertSame(0, $cache->load()->count());
        self::assertNull($cache->lastError());
    }

    public function testInMemoryFallbackWithACorruptRulesFileReportsTheError(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "rules: [broken\n  - : :");
        file_put_contents($this->tmp . '/not-a-dir', 'x');
        $cache = $this->cache($this->tmp . '/not-a-dir');

        self::assertSame(0, $cache->load()->count());
        self::assertNotNull($cache->lastError());
        self::assertStringContainsString('corrupt', (string) $cache->lastError());
        self::assertCount(2, $this->logged);
        self::assertStringStartsWith('warning:', $this->logged[0]);
        self::assertStringStartsWith('error: Redirect Manager: Rules file', $this->logged[1]);
    }

    public function testACacheFileThatCannotBeReplacedStillServesTheRules(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $cache = $this->cache();
        mkdir($cache->cacheFile(), 0775, true); // a directory sits where the cache file belongs

        self::assertSame('/new', $this->target($cache, '/old'));
        self::assertNull($cache->lastError());
        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('warning: Redirect Manager: cannot write the compiled rule cache.', $this->logged[0]);
        self::assertDirectoryExists($cache->cacheFile());
    }

    public function testAnUnreadableRulesFileKeepsThePreviousRules(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/one'));
        $this->cache()->load();

        $this->repo->upsert(new Rule('a', '/old', '/second-version'));
        chmod($this->repo->file(), 0000);
        if (is_readable($this->repo->file())) {
            self::markTestSkipped('The file stays readable for this user (root), so the read error cannot be provoked.');
        }

        $cache = $this->cache();
        self::assertSame('/one', $this->target($cache, '/old'), 'the last compiled rules stay in force');
        self::assertStringContainsString('Cannot read', (string) $cache->lastError());
        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('error: Redirect Manager: cannot read the rules file.', $this->logged[0]);

        // The next request still runs on the previous rules and still reports the problem.
        $next = $this->cache();
        self::assertSame('/one', $this->target($next, '/old'));
        self::assertStringContainsString('Cannot read', (string) $next->lastError());

        chmod($this->repo->file(), 0644);
        $fixed = $this->cache();
        self::assertSame('/second-version', $this->target($fixed, '/old'), 'readable again: rebuilt');
        self::assertNull($fixed->lastError());
    }

    public function testAnUnreadableRulesFileWithoutHistoryIsAnEmptySet(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/one'));
        chmod($this->repo->file(), 0000);
        if (is_readable($this->repo->file())) {
            self::markTestSkipped('The file stays readable for this user (root), so the read error cannot be provoked.');
        }

        $cache = $this->cache();

        self::assertSame(0, $cache->load()->count());
        self::assertNotNull($cache->lastError());
    }

    public function testAnOldRulesFileIsTrustedWithoutReadingItsContent(): void
    {
        $this->repo->upsert(new Rule('a', '/old', '/new'));
        $first = $this->cache();
        self::assertSame('/new', $this->target($first, '/old'));

        // Both mtime and ctime must be older than the two second window; ctime cannot be set, so wait.
        sleep(3);
        $before = stat($first->cacheFile());
        clearstatcache();

        $warm = $this->cache();
        self::assertSame('/new', $this->target($warm, '/old'));
        clearstatcache();
        self::assertSame($before['ino'] ?? 0, stat($first->cacheFile())['ino'] ?? -1, 'served from the compiled file, not rebuilt');
        self::assertSame([], $this->logged);
    }

    public function testACacheAnotherProcessCompletesWhileWaitingForTheLockIsUsedAsIs(): void
    {
        // State 1: cache built for the old rules. State 2: rules changed and a cache built for them (kept as $ready).
        $this->repo->upsert(new Rule('a', '/old', '/first'));
        $probe = $this->cache();
        $probe->load();
        $stale = (string) file_get_contents($probe->cacheFile());

        $this->repo->upsert(new Rule('a', '/old', '/second-and-longer'));
        $this->cache()->load();
        $readyFile = $this->tmp . '/ready.php';
        copy($probe->cacheFile(), $readyFile);

        // Put the stale file back and let a helper process hold the rebuild lock, finish the cache and release it.
        file_put_contents($probe->cacheFile(), $stale);
        $lockFile = $this->tmp . '/cache/rules-' . md5($this->repo->file()) . '.lock';
        $script = $this->tmp . '/holder.php';
        file_put_contents($script, <<<'PHP'
<?php
[, $lock, $cacheFile, $readyFile] = $argv;
$handle = fopen($lock, 'cb');
flock($handle, LOCK_EX);
echo "locked\n";
fflush(STDOUT);
usleep(300000);
copy($readyFile, $cacheFile);
flock($handle, LOCK_UN);
fclose($handle);
PHP);
        $process = proc_open([PHP_BINARY, $script, $lockFile, $probe->cacheFile(), $readyFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        try {
            self::assertSame("locked\n", fgets($pipes[1]));
            $inodeBefore = stat($probe->cacheFile())['ino'] ?? 0;

            $cache = $this->cache();
            self::assertSame('/second-and-longer', $this->target($cache, '/old'));

            clearstatcache();
            self::assertSame($inodeBefore, stat($probe->cacheFile())['ino'] ?? -1, 'this process did not rebuild, it took the finished cache');
            self::assertSame([], $this->logged);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
