<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum MatchType: string
{
    case Exact = 'exact';
    case Wildcard = 'wildcard';
    case Regex = 'regex';

    /**
     * Evaluation order between rules of equal priority: exact before wildcard before regex.
     */
    public function order(): int
    {
        return match ($this) {
            self::Exact => 0,
            self::Wildcard => 1,
            self::Regex => 2,
        };
    }
}
