<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Turns per-path aggregates into a sorted, filtered and paged plan. Shared by all LogStore
 * implementations so that they order and page identically.
 *
 * @internal
 */
final class GroupPlanner
{
    /** @param array<string, array{0: int, 1: int, 2: int}> $aggregates path => [hits, firstSeen, lastSeen] */
    public static function plan(array $aggregates, GroupQuery $q): GroupPlan
    {
        $visible = [];
        $hits = 0;
        foreach ($aggregates as $path => $agg) {
            $path = (string) $path;
            if (!$q->acceptsPath($path)) {
                continue;
            }
            if (isset($q->hidePaths[$path]) && $agg[2] <= $q->hidePaths[$path]->getTimestamp()) {
                continue;
            }
            $visible[$path] = $agg;
            $hits += $agg[0];
        }

        $paths = array_map('strval', array_keys($visible));
        $sign = $q->direction === SortDirection::Asc ? 1 : -1;
        usort($paths, static function (string $a, string $b) use ($visible, $q, $sign): int {
            $cmp = match ($q->sort) {
                GroupSort::Hits => $visible[$a][0] <=> $visible[$b][0],
                GroupSort::First => $visible[$a][1] <=> $visible[$b][1],
                GroupSort::Last => $visible[$a][2] <=> $visible[$b][2],
                GroupSort::Path => strcmp($a, $b),
            };

            return $cmp !== 0 ? $cmp * $sign : strcmp($a, $b);
        });

        $page = array_slice($paths, ($q->pageNumber() - 1) * $q->pageSize(), $q->pageSize());

        return new GroupPlan($visible, $page, $hits);
    }
}
