<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HitRecorder against a real StatsStore::aggregate(), interleaved deterministically in one process: the hook of the
 * recorder runs an aggregation exactly in the window between "log opened" and "log locked", which is where a
 * writer holds a handle to a file the aggregator is about to rename, read and delete.
 */
#[CoversClass(HitRecorder::class)]
#[CoversClass(StatsStore::class)]
#[Group('stats')]
final class HitRecorderAggregationRaceTest extends TestCase
{
    use TempDirTrait;

    private StatsStore $store;

    private FixedClock $clock;

    private string $hitsDir;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
        $this->hitsDir = $this->tmp . '/data/hits';
        $this->store = new StatsStore($this->tmp . '/data/stats.json', $this->hitsDir, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->hitsDir) ?: [], ['.', '..']));
    }

    public function testAHitWrittenAfterTheAggregatorRenamedTheOpenFileIsNotLost(): void
    {
        mkdir($this->hitsDir, 0775, true);
        file_put_contents($this->hitsDir . '/2026-09-29.log', "x\nx\n");
        $merged = 0;
        $recorder = new HitRecorder($this->hitsDir, $this->clock, function (int $attempt) use (&$merged): void {
            if ($attempt === 0) {
                // the writer holds a handle to the file; the aggregator renames it, reads it and deletes it
                $merged += $this->store->aggregate();
                // and another writer has already started a new file at the same path
                file_put_contents($this->hitsDir . '/2026-09-29.log', "y\n");
            }
        });

        $recorder->record('a');
        $merged += $this->store->aggregate();

        self::assertSame(4, $merged);
        self::assertSame(2, $this->store->forRule('x')->total);
        self::assertSame(1, $this->store->forRule('y')->total);
        self::assertSame(1, $this->store->forRule('a')->total, 'the hit must not end up in the deleted file');
        self::assertSame([], $this->leftovers());
    }

    public function testAHitSurvivesAnAggregationAfterEveryOpen(): void
    {
        // The hot aggregator: it renames the file the writer just (re)created on each of 49 attempts.
        // A limit of five attempts made record() throw and lose the hit under load.
        $opens = 0;
        $recorder = new HitRecorder($this->hitsDir, $this->clock, function (int $attempt) use (&$opens): void {
            $opens++;
            if ($attempt < 50) {
                $this->store->aggregate();
            }
        });

        $recorder->record('a');
        $this->store->aggregate();

        self::assertSame(50, $opens, 'the first attempt only created the missing directory; 49 renamed files, the 50th stuck');
        self::assertSame(1, $this->store->forRule('a')->total);
        self::assertSame([], $this->leftovers());
    }

    public function testAggregationWaitsForAWriterThatIsInsideItsLock(): void
    {
        // A writer holds the lock of the live file; the aggregator renames the file and would have to wait for
        // that lock before reading. Simulated with a second handle on the renamed file: the aggregator in a child
        // process blocks until the writer's lock is released, then sees the line the writer added meanwhile.
        mkdir($this->hitsDir, 0775, true);
        $live = $this->hitsDir . '/2026-09-29.log';
        file_put_contents($live, "a\n");
        $writer = fopen($live, 'ab');
        self::assertIsResource($writer);
        self::assertTrue(flock($writer, LOCK_EX));

        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $clock = new Grav\Plugin\RedirectManager\Util\FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
            echo (new Grav\Plugin\RedirectManager\Stats\StatsStore($argv[2], $argv[3], $clock))->aggregate();
            PHP;
        $proc = proc_open(
            [PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 3), $this->tmp . '/data/stats.json', $this->hitsDir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        // Wait until the aggregator renamed the file (it is then blocked on the lock), then finish the write.
        $deadline = microtime(true) + 10;
        while (in_array('2026-09-29.log', $this->leftovers(), true) && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertNotContains('2026-09-29.log', $this->leftovers(), 'the aggregator renamed the live file');
        usleep(100_000);
        self::assertTrue(proc_get_status($proc)['running'], 'the aggregator must wait for the writer\'s lock');
        fwrite($writer, "b\n");
        flock($writer, LOCK_UN);
        fclose($writer);

        $merged = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        self::assertSame('2', $merged, 'both lines, including the one written under the lock after the rename');
        self::assertSame(1, $this->store->forRule('a')->total);
        self::assertSame(1, $this->store->forRule('b')->total);
    }
}
