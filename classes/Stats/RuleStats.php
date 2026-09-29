<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Stats;

use DateTimeImmutable;

/** Aggregated hits of one redirect rule. */
final readonly class RuleStats
{
    /**
     * @param int                     $total   all hits since the rule was first seen (never trimmed)
     * @param DateTimeImmutable|null  $lastHit UTC; end of the day for past days (only the day is known), aggregation time for today
     * @param array<string, int>      $daily   "YYYY-MM-DD" => hits, only the last keepDays days
     */
    public function __construct(
        public int $total = 0,
        public ?DateTimeImmutable $lastHit = null,
        public array $daily = [],
    ) {
    }

    /** Hits on days from $from to $to (inclusive), from the daily buckets. */
    public function hitsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $fromDay = gmdate('Y-m-d', $from->getTimestamp());
        $toDay = gmdate('Y-m-d', $to->getTimestamp());
        $sum = 0;
        foreach ($this->daily as $day => $count) {
            if ((string) $day >= $fromDay && (string) $day <= $toDay) {
                $sum += $count;
            }
        }

        return $sum;
    }
}
