<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Notify;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThresholdTracker::class)]
final class ThresholdTrackerTest extends TestCase
{
    private string $dir;
    private string $file;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rm-thr-' . bin2hex(random_bytes(4));
        $this->file = $this->dir . '/state/notified.json';
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/state/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/state');
        @rmdir($this->dir);
    }

    private function tracker(int $cooldownDays = 7): ThresholdTracker
    {
        return new ThresholdTracker($this->file, $this->clock, $cooldownDays);
    }

    private function advance(string $modify): void
    {
        $this->clock->set($this->clock->now()->modify($modify));
    }

    public function testPathBelowThresholdIsNotDue(): void
    {
        self::assertSame([], $this->tracker()->due(['/a' => 9], 10));
        self::assertFileDoesNotExist($this->file);
    }

    public function testPathAtThresholdIsDueOnceUntilMarked(): void
    {
        $t = $this->tracker();
        self::assertSame(['/a', '/c'], $t->due(['/a' => 10, '/b' => 3, '/c' => 50], 10));

        $t->markNotified(['/a', '/c'], ['/a' => 10, '/c' => 50]);
        self::assertSame([], $t->due(['/a' => 11, '/b' => 3, '/c' => 80], 10));
        self::assertSame(['/b'], $t->due(['/b' => 12], 10), 'other paths stay independent');
    }

    public function testStateSurvivesANewInstance(): void
    {
        $this->tracker()->markNotified(['/a']);

        self::assertSame([], $this->tracker()->due(['/a' => 99], 10));
        $doc = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(1, $doc['version']);
        self::assertSame(['notified_at' => $this->clock->now()->getTimestamp(), 'hits' => 0], $doc['not_found']['/a']);
    }

    public function testDueAgainAfterCooldown(): void
    {
        $t = $this->tracker(7);
        $t->markNotified(['/a'], ['/a' => 12]);

        $this->advance('+6 days 23 hours');
        self::assertSame([], $t->due(['/a' => 40], 10));

        $this->advance('+1 hour');
        self::assertSame(['/a'], $t->due(['/a' => 40], 10), 'cooldown of 7 days is over');
        self::assertSame([], $t->due(['/a' => 4], 10), 'but only while it is still above the threshold');
    }

    public function testCustomCooldown(): void
    {
        $t = $this->tracker(1);
        $t->markNotified(['/a']);
        $this->advance('+1 day');

        self::assertSame(['/a'], $t->due(['/a' => 10], 10));
    }

    public function testOldEntriesArePrunedOnMark(): void
    {
        $t = $this->tracker(7);
        $t->markNotified(['/old']);
        $this->advance('+10 days');
        $t->markNotified(['/new']);

        $doc = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(['/new'], array_keys($doc['not_found']));
    }

    public function testNumericLookingPathsAreStrings(): void
    {
        $t = $this->tracker();
        self::assertSame(['123'], $t->due([123 => 10], 10));
    }

    public function testMarkNothingWritesNothing(): void
    {
        $t = $this->tracker();
        $t->markNotified([]);
        $t->markDeadNotified([]);

        self::assertFileDoesNotExist($this->file);
    }

    public function testDeadTargetIsDueOnceUntilItRecovers(): void
    {
        $t = $this->tracker();
        self::assertSame(['r1', 'r2'], $t->dueDead(['r1', 'r2']));

        $t->markDeadNotified(['r1', 'r2']);
        self::assertSame([], $t->dueDead(['r1', 'r2']));

        $this->advance('+60 days');
        self::assertSame([], $t->dueDead(['r1', 'r2']), 'no cooldown for dead targets');
        self::assertSame(['r3'], $t->dueDead(['r1', 'r2', 'r3']), 'new deaths are reported');

        // r2 recovers (not in the dead list anymore) ...
        self::assertSame([], $t->dueDead(['r1']));
        // ... and dies again later.
        self::assertSame(['r2'], $t->dueDead(['r1', 'r2']));
    }

    public function testDueDeadWithoutMarkingKeepsReportingIt(): void
    {
        $t = $this->tracker();

        self::assertSame(['r1'], $t->dueDead(['r1']));
        self::assertSame(['r1'], $t->dueDead(['r1']), 'reporting failed, so it stays due');
        self::assertSame(['r1'], $t->dueDead(['r1', 'r1']), 'duplicates collapse');
    }

    public function testDeadAndNotFoundStateAreIndependent(): void
    {
        $t = $this->tracker();
        $t->markNotified(['/a']);
        $t->markDeadNotified(['/a']);
        $t->dueDead([]);

        self::assertSame([], $t->due(['/a' => 10], 10), '404 state kept when dead state is pruned');
    }

    public function testCorruptStateFileIsTreatedAsEmpty(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, '{nope');
        $t = $this->tracker();

        self::assertSame(['/a'], $t->due(['/a' => 10], 10));
        $t->markNotified(['/a']);
        self::assertSame([], $t->due(['/a' => 10], 10));
    }

    public function testMalformedEntriesAreIgnored(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode([
            'not_found' => ['/x' => 'nope', '/y' => ['notified_at' => 'yesterday'], '/z' => ['notified_at' => $this->clock->now()->getTimestamp()]],
            'dead' => ['a' => 5, 'b' => ['notified_at' => $this->clock->now()->getTimestamp()]],
        ]));
        $t = $this->tracker();

        self::assertSame(['/x', '/y'], $t->due(['/x' => 10, '/y' => 10, '/z' => 10], 10));
        self::assertSame(['a'], $t->dueDead(['a', 'b']));
    }

    public function testNonArraySectionsAreTolerated(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode(['not_found' => 'x', 'dead' => 7]));
        $t = $this->tracker();

        self::assertSame(['/a'], $t->due(['/a' => 10], 10));
        self::assertSame(['r'], $t->dueDead(['r']));
    }

    public function testUnwritableLocationThrows(): void
    {
        $blocker = $this->dir . '-file';
        file_put_contents($blocker, 'x');
        try {
            $t = new ThresholdTracker($blocker . '/sub/state.json', $this->clock);
            $this->expectException(\RuntimeException::class);
            $t->markNotified(['/a']);
        } finally {
            unlink($blocker);
        }
    }

    public function testLockFileThatCannotBeOpenedThrows(): void
    {
        mkdir($this->file . '.lock', 0775, true);
        try {
            $t = $this->tracker();
            $this->expectException(\RuntimeException::class);
            $t->markNotified(['/a']);
        } finally {
            rmdir($this->file . '.lock');
        }
    }

    public function testFailedWriteThrows(): void
    {
        mkdir($this->file, 0775, true);
        try {
            $t = $this->tracker();
            $this->expectException(\RuntimeException::class);
            $t->markNotified(['/a']);
        } finally {
            rmdir($this->file);
        }
    }
}
