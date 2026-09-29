<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/** Something the planner skipped or wants the editor to know. */
final readonly class PlanNote
{
    /** An enabled manual rule with the same source exists. */
    public const CONFLICT = 'conflict';
    /** A manual rule from the new route back to the old one would make a loop. */
    public const LOOP = 'loop';
    /** The source route is served by another page now. */
    public const OCCUPIED = 'occupied';
    /** A manual rule sends the new route somewhere else; the page is unreachable until the rule is changed. */
    public const SHADOWED = 'shadowed';
    /** A rule pointing at the page could not be updated unambiguously. */
    public const AMBIGUOUS = 'ambiguous';
    /** A rule for this source exists already. */
    public const EXISTS = 'exists';
    public const SKIPPED = 'skipped';

    public function __construct(public string $kind, public string $source, public string $message = '')
    {
    }
}
