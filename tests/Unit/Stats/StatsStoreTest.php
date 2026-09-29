<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\RuleStats;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\WorkerRunner;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatsStore::class)]
#[Group('stats')]
final class StatsStoreTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;

    private string $statsFile;

    private string $hitsDir;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
        $this->statsFile = $this->tmp . '/data/stats.json';
        $this->hitsDir = $this->tmp . '/data/hits';
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function store(int $keepDays = 90): StatsStore
    {
        return new StatsStore($this->statsFile, $this->hitsDir, $this->clock, $keepDays);
    }

    private function hitFile(string $day, string ...$ids): void
    {
        if (!is_dir($this->hitsDir)) {
            mkdir($this->hitsDir, 0775, true);
        }
        file_put_contents($this->hitsDir . '/' . $day . '.log', implode("\n", $ids) . "\n", FILE_APPEND);
    }

    /** @return list<string> */
    private function hitFiles(): array
    {
        return array_values(array_diff(scandir($this->hitsDir) ?: [], ['.', '..']));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) file_get_contents($this->statsFile), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAggregateWithoutHitsDoesNothing(): void
    {
        self::assertSame(0, $this->store()->aggregate());
        self::assertFileDoesNotExist($this->statsFile);
        self::assertSame([], $this->store()->all());
    }

    public function testAggregateMergesTotalsDailyBucketsAndLastHit(): void
    {
        $this->hitFile('2026-09-27', 'a', 'a', 'b');
        $this->hitFile('2026-09-28', 'a');
        $this->hitFile('2026-09-29', 'b', 'b');

        $merged = $this->store()->aggregate();

        self::assertSame(6, $merged);
        $a = $this->store()->forRule('a');
        $b = $this->store()->forRule('b');
        self::assertSame(3, $a->total);
        self::assertSame(['2026-09-27' => 2, '2026-09-28' => 1], $a->daily);
        self::assertSame('2026-09-28 23:59:59', $a->lastHit?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $a->lastHit->getTimezone()->getName());
        self::assertSame(3, $b->total);
        self::assertSame(['2026-09-27' => 1, '2026-09-29' => 2], $b->daily);
        self::assertSame('2026-09-29 12:00:00', $b->lastHit?->format('Y-m-d H:i:s'), "today's last hit is the aggregation time");
    }

    public function testProcessedFilesAreRemoved(): void
    {
        $this->hitFile('2026-09-27', 'a');
        $this->hitFile('2026-09-29', 'a');

        $this->store()->aggregate();

        self::assertSame([], $this->hitFiles());
    }

    public function testTodaysFileIsAggregatedToo(): void
    {
        $recorder = new HitRecorder($this->hitsDir, $this->clock);
        $recorder->record('a');
        $recorder->record('a');

        self::assertSame(2, $this->store()->aggregate());

        $recorder->record('a');
        self::assertSame(1, $this->store()->aggregate());
        self::assertSame(3, $this->store()->forRule('a')->total);
        self::assertSame(['2026-09-29' => 3], $this->store()->forRule('a')->daily);
    }

    public function testRepeatedAggregationAddsUpAndSecondRunWithoutNewHitsIsANoOp(): void
    {
        $this->hitFile('2026-09-28', 'a');
        $store = $this->store();
        $store->aggregate();
        $mtime = filemtime($this->statsFile);
        clearstatcache();

        self::assertSame(0, $store->aggregate());
        self::assertSame(1, $store->forRule('a')->total);

        $this->hitFile('2026-09-28', 'a', 'a');
        self::assertSame(2, $store->aggregate());
        self::assertSame(3, $store->forRule('a')->total);
        self::assertSame(['2026-09-28' => 3], $store->forRule('a')->daily);
        self::assertGreaterThanOrEqual($mtime, filemtime($this->statsFile));
    }

    public function testLastHitNeverMovesBackwards(): void
    {
        $this->hitFile('2026-09-28', 'a');
        $this->store()->aggregate();
        $this->hitFile('2026-09-20', 'a');
        $this->store()->aggregate();

        self::assertSame('2026-09-28 23:59:59', $this->store()->forRule('a')->lastHit?->format('Y-m-d H:i:s'));
        self::assertSame(2, $this->store()->forRule('a')->total);
    }

    public function testInvalidLinesInHitFilesAreIgnored(): void
    {
        mkdir($this->hitsDir, 0775, true);
        file_put_contents($this->hitsDir . '/2026-09-28.log', "a\n\n  \nbad id with spaces\n" . str_repeat('x', 500) . "\n\0\0\na\r\nb");

        self::assertSame(3, $this->store()->aggregate());
        self::assertSame(2, $this->store()->forRule('a')->total);
        self::assertSame(1, $this->store()->forRule('b')->total);
        self::assertCount(2, $this->store()->all());
    }

    public function testForeignFilesInTheHitsDirectoryAreLeftAlone(): void
    {
        $this->hitFile('2026-09-28', 'a');
        file_put_contents($this->hitsDir . '/notes.txt', 'hello');
        file_put_contents($this->hitsDir . '/2026-09-28.log.bak', "a\n");
        mkdir($this->hitsDir . '/2026-09-27.log.d');

        self::assertSame(1, $this->store()->aggregate());

        self::assertSame(['2026-09-27.log.d', '2026-09-28.log.bak', 'notes.txt'], $this->hitFiles());
    }

    public function testDailyBucketsOlderThanKeepDaysAreTrimmedButTotalsStay(): void
    {
        $this->hitFile('2026-07-01', 'a');   // 90 days before 09-29 -> dropped
        $this->hitFile('2026-07-02', 'a');   // 89 days before -> kept
        $this->hitFile('2026-05-01', 'a');   // far too old -> dropped
        $this->hitFile('2026-09-29', 'a');

        $this->store()->aggregate();

        $stats = $this->store()->forRule('a');
        self::assertSame(4, $stats->total);
        self::assertSame(['2026-07-02' => 1, '2026-09-29' => 1], $stats->daily);
    }

    public function testTrimAppliesToBucketsFromEarlierRuns(): void
    {
        $this->hitFile('2026-08-01', 'a');
        $this->store()->aggregate();
        $this->clock->set(new DateTimeImmutable('2026-12-01 00:00:00 UTC'));
        $this->hitFile('2026-12-01', 'b');

        $this->store()->aggregate();

        self::assertSame([], $this->store()->forRule('a')->daily);
        self::assertSame(1, $this->store()->forRule('a')->total);
        self::assertSame(['2026-12-01' => 1], $this->store()->forRule('b')->daily);
    }

    public function testKeepDaysOfOneKeepsOnlyToday(): void
    {
        $this->hitFile('2026-09-28', 'a');
        $this->hitFile('2026-09-29', 'a');

        $this->store(1)->aggregate();

        self::assertSame(['2026-09-29' => 1], $this->store()->forRule('a')->daily);
    }

    public function testLeftoverProcessingFileFromACrashBeforeTheStatsWriteIsMergedNextTime(): void
    {
        mkdir($this->hitsDir, 0775, true);
        file_put_contents($this->hitsDir . '/2026-09-27.processing.deadbeef', "a\na\nb\n");
        $this->hitFile('2026-09-28', 'a');

        self::assertSame(4, $this->store()->aggregate());

        self::assertSame(3, $this->store()->forRule('a')->total);
        self::assertSame(['2026-09-27' => 2, '2026-09-28' => 1], $this->store()->forRule('a')->daily);
        self::assertSame([], $this->hitFiles());
    }

    public function testLeftoverOfAnAlreadyMergedFileIsDeletedNotCountedTwice(): void
    {
        $this->hitFile('2026-09-27', 'a', 'a');
        $store = $this->store();
        $store->aggregate();
        $name = $this->json()['processed'][0];
        self::assertMatchesRegularExpression('/^2026-09-27\.processing\.[a-f0-9]+$/', $name);

        // crash after the stats write, before the unlink: the file is still there
        file_put_contents($this->hitsDir . '/' . $name, "a\na\n");
        self::assertSame(0, $store->aggregate());

        self::assertSame(2, $store->forRule('a')->total);
        self::assertSame([], $this->hitFiles());
    }

    public function testProcessedListIsPrunedOnLaterRuns(): void
    {
        $this->hitFile('2026-09-27', 'a');
        $this->store()->aggregate();
        self::assertCount(1, $this->json()['processed']);

        $this->hitFile('2026-09-28', 'a');
        $this->store()->aggregate();

        self::assertCount(1, $this->json()['processed']);
        self::assertStringContainsString('2026-09-28', $this->json()['processed'][0]);
    }

    public function testStalePrunableNamesAreDroppedEvenWithoutNewHits(): void
    {
        $this->hitFile('2026-09-27', 'a');
        $this->store()->aggregate();
        self::assertCount(1, $this->json()['processed']);
        // The recorded leftover name is stale (file already deleted) and the next run has nothing new to merge.

        $this->store()->aggregate();

        self::assertSame([], $this->json()['processed']);
        self::assertSame(1, $this->store()->forRule('a')->total);
    }

    public function testUnreadableProcessingFileIsKeptForALaterAttempt(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read everything.');
        }
        mkdir($this->hitsDir, 0775, true);
        $file = $this->hitsDir . '/2026-09-27.processing.abc123';
        file_put_contents($file, "a\n");
        chmod($file, 0000);

        self::assertSame(0, $this->store()->aggregate());
        self::assertFileExists($file);

        chmod($file, 0600);
        self::assertSame(1, $this->store()->aggregate());
        self::assertSame(1, $this->store()->forRule('a')->total);
    }

    public function testCorruptStatsFileIsQuarantinedAndCountingRestarts(): void
    {
        mkdir(dirname($this->statsFile), 0775, true);
        file_put_contents($this->statsFile, '{"rules": broken');
        $this->hitFile('2026-09-28', 'a');

        self::assertSame([], $this->store()->all(), 'reading never touches a corrupt file');
        self::assertSame(1, $this->store()->aggregate());

        self::assertSame(1, $this->store()->forRule('a')->total);
        $backups = glob($this->statsFile . '.corrupt-*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('{"rules": broken', file_get_contents($backups[0]));
    }

    public function testMalformedButValidJsonIsNormalized(): void
    {
        mkdir(dirname($this->statsFile), 0775, true);
        file_put_contents($this->statsFile, json_encode([
            'rules' => [
                'good' => ['total' => 5, 'last_hit' => '2026-09-01T00:00:00+00:00', 'daily' => ['2026-09-01' => 5, 'bad' => 'x', '2026-09-02' => 0]],
                'noarray' => 'x',
                'weird' => ['total' => 'many', 'last_hit' => 12, 'daily' => 'none'],
                'badtime' => ['total' => 1, 'last_hit' => 'not a date', 'daily' => []],
            ],
            'processed' => ['keep-name', 12, null],
        ]));

        $all = $this->store()->all();

        self::assertSame(['good', 'weird', 'badtime'], array_keys($all));
        self::assertSame(['2026-09-01' => 5], $all['good']->daily);
        self::assertSame(0, $all['weird']->total);
        self::assertNull($all['weird']->lastHit);
        self::assertNull($all['badtime']->lastHit);

        file_put_contents($this->statsFile, json_encode(['rules' => 'nonsense', 'processed' => 'nonsense']));
        self::assertSame([], $this->store()->all());
    }

    public function testStatsFileFormat(): void
    {
        $this->hitFile('2026-09-28', 'a');
        $this->store()->aggregate();

        $data = $this->json();

        self::assertSame(1, $data['version']);
        self::assertSame(['total' => 1, 'last_hit' => '2026-09-28T23:59:59+00:00', 'daily' => ['2026-09-28' => 1]], $data['rules']['a']);
    }

    public function testForRuleOfUnknownIdIsEmpty(): void
    {
        $stats = $this->store()->forRule('nope');

        self::assertEquals(new RuleStats(), $stats);
    }

    public function testTotalsByDayAreZeroFilledSummedAndRangeLimited(): void
    {
        $this->hitFile('2026-09-26', 'a', 'b');
        $this->hitFile('2026-09-28', 'a');
        $this->hitFile('2026-09-29', 'b', 'b', 'c');
        $this->store()->aggregate();

        $totals = $this->store()->totalsByDay(new DateTimeImmutable('2026-09-27 UTC'), new DateTimeImmutable('2026-09-29 UTC'));

        self::assertSame(['2026-09-27' => 0, '2026-09-28' => 1, '2026-09-29' => 3], $totals);
        self::assertSame([], array_diff_key($this->store()->totalsByDay(new DateTimeImmutable('2026-09-26 UTC'), new DateTimeImmutable('2026-09-26 UTC')), ['2026-09-26' => 2]));
    }

    public function testUnusedSince(): void
    {
        $since = new DateTimeImmutable('2026-09-01 00:00:00 UTC');
        $this->hitFile('2026-08-31', 'stale');
        $this->hitFile('2026-09-01', 'edge');
        $this->hitFile('2026-09-20', 'fresh');
        $this->store()->aggregate();

        $unused = $this->store()->unusedSince([
            'stale' => new DateTimeImmutable('2025-01-01 UTC'),
            'edge' => new DateTimeImmutable('2025-01-01 UTC'),
            'fresh' => new DateTimeImmutable('2025-01-01 UTC'),
            'never-old' => new DateTimeImmutable('2026-08-01 UTC'),
            'never-young' => new DateTimeImmutable('2026-09-15 UTC'),
            'never-unknown-age' => null,
            'never-created-exactly-at-since' => $since,
        ], $since);

        self::assertSame(['stale', 'never-old'], $unused);
    }

    public function testUnusedSinceWithoutAnyStats(): void
    {
        $unused = $this->store()->unusedSince(['a' => new DateTimeImmutable('2026-01-01 UTC')], new DateTimeImmutable('2026-06-01 UTC'));

        self::assertSame(['a'], $unused);
        self::assertSame([], $this->store()->unusedSince([], new DateTimeImmutable('2026-06-01 UTC')));
    }

    public function testForgetRemovesStatsOfDeletedRules(): void
    {
        $this->hitFile('2026-09-28', 'a', 'b', 'c');
        $this->store()->aggregate();

        $this->store()->forget(['a', 'c', 'never-existed']);

        self::assertSame(['b'], array_keys($this->store()->all()));
    }

    public function testForgetWithNothingToDoWritesNothing(): void
    {
        $this->store()->forget([]);
        $this->store()->forget(['x']);

        self::assertFileDoesNotExist($this->statsFile);
    }

    public function testRulesThatAreForgottenCanStartAgain(): void
    {
        $this->hitFile('2026-09-28', 'a');
        $this->store()->aggregate();
        $this->store()->forget(['a']);
        $this->hitFile('2026-09-29', 'a');
        $this->store()->aggregate();

        self::assertSame(1, $this->store()->forRule('a')->total);
    }

    public function testRecordingWhileAggregatingLosesAndDuplicatesNothing(): void
    {
        mkdir($this->tmp . '/scratch');
        $store = $this->store();
        $merged = 0;
        $runs = 0;

        WorkerRunner::run('hits', $this->hitsDir, 8, 500, $this->tmp . '/scratch', function () use ($store, &$merged, &$runs): void {
            $merged += $store->aggregate();
            $runs++;
        });
        $merged += $store->aggregate();

        self::assertGreaterThan(1, $runs, 'aggregation ran while the workers were recording');
        self::assertSame(4000, $merged);
        self::assertSame(1500, $store->forRule('rule-0')->total);
        self::assertSame(1500, $store->forRule('rule-1')->total);
        self::assertSame(1000, $store->forRule('rule-2')->total);
        self::assertSame(4000, array_sum($store->forRule('rule-0')->daily) + array_sum($store->forRule('rule-1')->daily) + array_sum($store->forRule('rule-2')->daily));
        self::assertSame([], $this->hitFiles());
    }

    public function testTwoAggregatorsAtTheSameTimeDoNotDoubleCount(): void
    {
        $this->hitFile('2026-09-28', ...array_fill(0, 500, 'a'));
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $clock = new Grav\Plugin\RedirectManager\Util\FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
            (new Grav\Plugin\RedirectManager\Stats\StatsStore($argv[2], $argv[3], $clock))->aggregate();
            PHP;

        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[] = proc_open(
                [PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 3), $this->statsFile, $this->hitsDir],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        }
        foreach ($procs as $proc) {
            self::assertIsResource($proc);
            proc_close($proc);
        }

        self::assertSame(500, $this->store()->forRule('a')->total);
        self::assertSame([], $this->hitFiles());
    }
}
