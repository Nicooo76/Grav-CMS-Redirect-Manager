<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\DayRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DayRange::class)]
#[Group('notfound')]
final class DayRangeTest extends TestCase
{
    public function testKeyIsTheUtcDay(): void
    {
        self::assertSame('2026-09-29', DayRange::key((int) (new DateTimeImmutable('2026-09-29 23:59:59 UTC'))->format('U')));
        self::assertSame('2026-09-30', DayRange::key((int) (new DateTimeImmutable('2026-09-30 00:00:00 UTC'))->format('U')));
    }

    public function testZeroFilledIncludesBothEndsOldestFirst(): void
    {
        $days = DayRange::zeroFilled(new DateTimeImmutable('2026-09-27 15:00 UTC'), new DateTimeImmutable('2026-09-29 01:00 UTC'));

        self::assertSame(['2026-09-27' => 0, '2026-09-28' => 0, '2026-09-29' => 0], $days);
    }

    public function testSingleDayAndInvertedRange(): void
    {
        $day = new DateTimeImmutable('2026-09-29 12:00 UTC');

        self::assertSame(['2026-09-29' => 0], DayRange::zeroFilled($day, $day));
        self::assertSame([], DayRange::zeroFilled(new DateTimeImmutable('2026-10-05 UTC'), new DateTimeImmutable('2026-09-29 UTC')));
    }

    public function testVeryLongRangesAreCappedToTheNewestDays(): void
    {
        $days = DayRange::zeroFilled(new DateTimeImmutable('@0'), new DateTimeImmutable('2026-09-29 UTC'));

        self::assertCount(DayRange::MAX_DAYS, $days);
        self::assertSame('2026-09-29', array_key_last($days));
    }
}
