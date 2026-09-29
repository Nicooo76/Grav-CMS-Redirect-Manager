<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/** Where a rule came from. */
enum RuleSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Auto = 'auto';
    case Suggestion = 'suggestion';
}
