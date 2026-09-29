<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;

/** Converts between regex tests on headers/cookies (Apache, nginx, WordPress) and Condition objects. */
final class HeaderCond
{
    /**
     * Picks the simplest operator that expresses the pattern; falls back to Regex.
     */
    public static function fromPattern(ConditionKind $kind, string $name, string $pattern, bool $insensitive, bool $negate = false): Condition
    {
        [$body, $ci] = Pattern::stripCaseFlag($pattern);
        $insensitive = $insensitive || $ci;

        if (!$insensitive) {
            if ($body === '.' || $body === '.+') {
                return new Condition($kind, $name, ConditionOperator::Exists, '', $negate);
            }
            if (str_starts_with($body, '^') && str_ends_with($body, '$')) {
                $lit = Pattern::unescape(substr($body, 1, -1));
                if ($lit !== null) {
                    return new Condition($kind, $name, ConditionOperator::Equals, $lit, $negate);
                }
            }
            if (str_starts_with($body, '^')) {
                $lit = Pattern::unescape(substr($body, 1));
                if ($lit !== null && $lit !== '') {
                    return new Condition($kind, $name, ConditionOperator::StartsWith, $lit, $negate);
                }
            }
            $lit = Pattern::unescape($body);
            if ($lit !== null && $lit !== '') {
                return new Condition($kind, $name, ConditionOperator::Contains, $lit, $negate);
            }
        }

        return new Condition($kind, $name, ConditionOperator::Regex, ($insensitive ? '(?i)' : '') . $body, $negate);
    }

    /**
     * The regex that tests the condition's value, and whether it is case-insensitive.
     * Exists conditions return ".+".
     *
     * @return array{0: string, 1: bool}
     */
    public static function toPattern(Condition $cond): array
    {
        return match ($cond->operator) {
            ConditionOperator::Exists => ['.+', false],
            ConditionOperator::Equals => ['^' . Pattern::escape($cond->value) . '$', false],
            ConditionOperator::Contains => [Pattern::escape($cond->value), false],
            ConditionOperator::StartsWith => ['^' . Pattern::escape($cond->value), false],
            ConditionOperator::Regex => Pattern::stripCaseFlag($cond->value),
        };
    }
}
