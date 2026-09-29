<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Result of the first grouping pass: which paths are visible and which of them are on the requested page.
 *
 * @internal shared by the LogStore implementations
 */
final readonly class GroupPlan
{
    /**
     * @param array<string, array{0: int, 1: int, 2: int}> $visible path => [hits, firstSeen, lastSeen] (unix seconds)
     * @param list<string>                                $page    paths of the requested page, in display order
     */
    public function __construct(
        public array $visible,
        public array $page,
        public int $hits,
    ) {
    }
}
