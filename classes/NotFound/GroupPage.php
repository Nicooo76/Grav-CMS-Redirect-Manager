<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/** One page of grouped 404 paths. */
final readonly class GroupPage
{
    /**
     * @param list<GroupRow> $rows  rows of the requested page
     * @param int            $total number of groups over all pages
     */
    public function __construct(
        public array $rows,
        public int $total,
        public GroupTotals $totals,
    ) {
    }
}
