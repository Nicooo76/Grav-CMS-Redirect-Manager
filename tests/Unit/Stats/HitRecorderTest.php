<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\WorkerRunner;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HitRecorder::class)]
#[Group('stats')]
final class HitRecorderTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 23:59:59 UTC'));
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testRecordAppendsOneLinePerHitToTheFileOfTheUtcDay(): void
    {
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);

        $recorder->record('r0123');
        $recorder->record('r0123');
        $recorder->record('other-rule');

        self::assertSame("r0123\nr0123\nother-rule\n", file_get_contents($this->tmp . '/hits/2026-09-29.log'));
    }

    public function testANewDayStartsANewFile(): void
    {
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);
        $recorder->record('a');
        $this->clock->set(new DateTimeImmutable('2026-09-30 00:00:01 UTC'));
        $recorder->record('b');

        self::assertSame("a\n", file_get_contents($this->tmp . '/hits/2026-09-29.log'));
        self::assertSame("b\n", file_get_contents($this->tmp . '/hits/2026-09-30.log'));
    }

    public function testDirectoryIsCreatedOnFirstUse(): void
    {
        self::assertDirectoryDoesNotExist($this->tmp . '/deep/hits');

        (new HitRecorder($this->tmp . '/deep/hits', $this->clock))->record('a');

        self::assertFileExists($this->tmp . '/deep/hits/2026-09-29.log');
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'newline injection' => ["a\nfake-rule"];
        yield 'carriage return' => ["a\rb"];
        yield 'space' => ['a b'];
        yield 'nul' => ["a\0b"];
        yield 'slash' => ['a/b'];
        yield 'unicode' => ['regel-ü'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('badIds')]
    public function testInvalidRuleIdsAreRejectedBeforeAnythingIsWritten(string $id): void
    {
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);

        try {
            $recorder->record($id);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($this->tmp . '/hits');
        }
    }

    public function testTypicalIdsAreAccepted(): void
    {
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);
        foreach (['r01a2b3c4d5e6f7a8', 'imported:42', 'rule_1.2-x', str_repeat('a', 128)] as $id) {
            $recorder->record($id);
        }

        self::assertCount(4, file($this->tmp . '/hits/2026-09-29.log', FILE_IGNORE_NEW_LINES));
    }

    public function testUnwritableLocationThrowsRuntimeException(): void
    {
        file_put_contents($this->tmp . '/blocker', 'x');

        $this->expectException(RuntimeException::class);
        (new HitRecorder($this->tmp . '/blocker/hits', $this->clock))->record('a');
    }

    public function testDayFileThatCannotBeOpenedThrows(): void
    {
        mkdir($this->tmp . '/hits/2026-09-29.log', 0775, true);

        $this->expectException(RuntimeException::class);
        @(new HitRecorder($this->tmp . '/hits', $this->clock))->record('a');
    }

    public function testEightProcessesRecordingConcurrentlyLoseNoHit(): void
    {
        mkdir($this->tmp . '/scratch');

        WorkerRunner::run('hits', $this->tmp . '/hits', 8, 500, $this->tmp . '/scratch');

        $lines = file($this->tmp . '/hits/2026-09-29.log', FILE_IGNORE_NEW_LINES);
        self::assertCount(4000, $lines);
        self::assertEquals(['rule-0' => 1500, 'rule-1' => 1500, 'rule-2' => 1000], array_count_values($lines));
    }

    public function testWriterReopensWhenTheFileIsRenamedAwayWhileItWaitsForTheLock(): void
    {
        mkdir($this->tmp . '/hits', 0775, true);
        mkdir($this->tmp . '/scratch');
        $file = $this->tmp . '/hits/2026-09-29.log';
        file_put_contents($file, "before\n");
        $held = fopen($file, 'r+b');
        self::assertIsResource($held);
        flock($held, LOCK_EX);
        $done = false;

        WorkerRunner::run('hits', $this->tmp . '/hits', 1, 1, $this->tmp . '/scratch', function () use (&$done, $held, $file): void {
            if ($done) {
                usleep(1000);

                return;
            }
            $done = true;
            usleep(400_000);
            rename($file, $file . '.processing.abc');
            flock($held, LOCK_UN);
            fclose($held);
        });

        self::assertSame("before\n", file_get_contents($file . '.processing.abc'));
        self::assertSame("rule-0\n", file_get_contents($file), 'the hit went to a fresh file, not into the renamed one');
    }
}
