<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum MatchPhase: string
{
    /** Before Grav resolves the page: rules without the "only if not found" flag. */
    case Early = 'early';
    /** After Grav found no page: only rules with the "only if not found" flag. */
    case NotFound = 'not_found';
    /** Rule tester and chain analysis: every rule, as if no page existed. */
    case Any = 'any';
}
