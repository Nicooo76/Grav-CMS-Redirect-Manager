<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/** Totals over all groups of a query (not just the current page). */
final readonly class GroupTotals
{
    /** @param array<string, int> $byDay "YYYY-MM-DD" => hits, zero-filled over the range */
    public function __construct(
        public int $hits = 0,
        public int $uniquePaths = 0,
        public array $byDay = [],
    ) {
    }
}
