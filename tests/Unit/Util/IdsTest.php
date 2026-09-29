<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Util;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use Grav\Plugin\RedirectManager\Util\Ids;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ids::class)]
#[CoversClass(FixedClock::class)]
#[CoversClass(SystemClock::class)]
#[Group('util')]
final class IdsTest extends TestCase
{
    public function testSortableIdHas11TimeChars3CounterCharsAnd8RandomChars(): void
    {
        $id = Ids::sortable();

        self::assertMatchesRegularExpression('/^[0-9a-f]{22}$/', $id);
    }

    public function testRuleIdIsPrefixedSortableId(): void
    {
        self::assertMatchesRegularExpression('/^r[0-9a-f]{22}$/', Ids::rule());
    }

    public function testTimePartEncodesTheMillisecondsSinceTheEpoch(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $id = Ids::sortable();
        $after = (int) floor(microtime(true) * 1000);

        $ms = (int) hexdec(substr($id, 0, 11));

        self::assertGreaterThanOrEqual($before, $ms);
        self::assertLessThanOrEqual($after, $ms);
    }

    public function testIdsCreatedLaterSortAfterEarlierOnes(): void
    {
        $first = Ids::rule();
        usleep(3000);
        $second = Ids::rule();

        self::assertLessThan(0, strcmp($first, $second));
    }

    public function testIdsCreatedInTheSameMillisecondStillDiffer(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[Ids::rule()] = true;
        }

        self::assertCount(200, $ids, 'the per-millisecond counter keeps ids unique');
    }

    public function testAMillionIdsAreUniqueAndIncreasing(): void
    {
        $seen = [];
        $previous = '';
        for ($i = 0; $i < 1_000_000; $i++) {
            $id = Ids::rule();
            $seen[$id] = true;
            if ($id <= $previous) {
                self::fail(sprintf('id #%d (%s) does not sort after its predecessor (%s)', $i, $id, $previous));
            }
            $previous = $id;
        }

        self::assertCount(1_000_000, $seen);
    }

    public function testTheCounterRunsAheadOfTheClockInsteadOfRepeating(): void
    {
        // More ids than the counter holds per millisecond (4096) within one millisecond: the id clock moves on.
        $first = Ids::sortable();
        $last = $first;
        for ($i = 0; $i < 10000; $i++) {
            $last = Ids::sortable();
        }

        self::assertGreaterThan(0, strcmp($last, $first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{22}$/', $last);
    }

    public function testDifferentProcessesDoNotShareTheirRandomPart(): void
    {
        // The random tail differs between two ids with the same time and counter, which is what keeps two
        // processes (two CLI runs, two requests) apart.
        $tails = [];
        for ($i = 0; $i < 500; $i++) {
            $tails[substr(Ids::sortable(), 14)] = true;
        }

        self::assertGreaterThan(495, count($tails));
    }

    public function testFixedClockReturnsTheGivenTimeUntilItIsSet(): void
    {
        $t1 = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $t2 = new DateTimeImmutable('2027-05-05T12:30:00+00:00');
        $clock = new FixedClock($t1);

        self::assertInstanceOf(Clock::class, $clock);
        self::assertSame($t1, $clock->now());
        self::assertSame($t1, $clock->now());

        $clock->set($t2);

        self::assertSame($t2, $clock->now());
    }

    public function testSystemClockReturnsTheCurrentTime(): void
    {
        $clock = new SystemClock();
        $before = time();
        $now = $clock->now();
        $after = time();

        self::assertInstanceOf(Clock::class, $clock);
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
        self::assertNotSame($now, $clock->now(), 'every call returns a fresh instance');
    }
}
