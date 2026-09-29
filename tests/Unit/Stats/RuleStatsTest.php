<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Stats\RuleStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleStats::class)]
#[Group('stats')]
final class RuleStatsTest extends TestCase
{
    public function testDefaultsAreEmpty(): void
    {
        $stats = new RuleStats();

        self::assertSame(0, $stats->total);
        self::assertNull($stats->lastHit);
        self::assertSame([], $stats->daily);
    }

    public function testHitsBetweenSumsDailyBucketsInclusive(): void
    {
        $stats = new RuleStats(20, null, ['2026-09-25' => 1, '2026-09-26' => 2, '2026-09-27' => 4, '2026-09-28' => 8, '2026-09-29' => 5]);

        self::assertSame(14, $stats->hitsBetween(new DateTimeImmutable('2026-09-26 23:00 UTC'), new DateTimeImmutable('2026-09-28 01:00 UTC')));
        self::assertSame(20, $stats->hitsBetween(new DateTimeImmutable('2026-01-01 UTC'), new DateTimeImmutable('2027-01-01 UTC')));
        self::assertSame(0, $stats->hitsBetween(new DateTimeImmutable('2027-01-01 UTC'), new DateTimeImmutable('2027-02-01 UTC')));
    }
}
