<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * One cheap pass over a rule list that produces the lookups RuleValidator needs, so validating a single
 * candidate never has to compile 10,000 rules. Only rules that are enabled and not expired are indexed.
 *
 * - exactByFold: exact rules by folded source path (lower case, no trailing slash): a superset of every rule
 *   that could match a request for that path, whatever its case and slash flags.
 * - wide: all wildcard and regex rules.
 * - byTarget: redirect rules with a plain internal target (no placeholders) by the folded target path.
 * - captureTargets: wildcard and regex redirect rules whose target has placeholders.
 */
final class RuleIndex
{
    /** @var array<string, list<Rule>> */
    public array $exactByFold = [];

    /** @var list<Rule> */
    public array $wide = [];

    /** @var array<string, list<Rule>> */
    public array $byTarget = [];

    /** @var list<Rule> */
    public array $captureTargets = [];

    /**
     * @param list<Rule> $rules
     */
    public static function build(array $rules, ?string $excludeId, DateTimeImmutable $now): self
    {
        $index = new self();
        foreach ($rules as $rule) {
            if ($rule->id === $excludeId || !$rule->enabled || $rule->isExpired($now)) {
                continue;
            }
            if ($rule->matchType === MatchType::Exact) {
                $path = PathKey::normalized(trim(PathNormalizer::splitSource($rule->source)[0], " \t\n\r"));
                if ($path !== null) {
                    $index->exactByFold[PathKey::fold($path)][] = $rule;
                }
            } else {
                $index->wide[] = $rule;
            }
            if ($rule->status->isRedirect()) {
                $fold = self::plainTargetFold($rule);
                if ($fold !== null) {
                    $index->byTarget[$fold][] = $rule;
                } elseif ($rule->matchType !== MatchType::Exact && $rule->target !== '' && $rule->target[0] === '/') {
                    $index->captureTargets[] = $rule;
                }
            }
        }

        return $index;
    }

    /**
     * Folded target path of a rule whose target is an internal path without placeholders, else null.
     */
    public static function plainTargetFold(Rule $rule): ?string
    {
        $target = $rule->target;
        if ($target === '' || $target[0] !== '/' || strpbrk($target, '${') !== false || !$rule->status->needsTarget()) {
            return null;
        }
        $path = PathKey::normalized(PathKey::pathOf($target));

        return $path === null ? null : PathKey::fold($path);
    }
}
