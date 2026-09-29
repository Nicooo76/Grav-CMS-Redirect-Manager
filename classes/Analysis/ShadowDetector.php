<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\Matcher;

/**
 * Finds exact rules that never apply because another rule answers their own source first.
 *
 * The check asks the real Matcher about the rule's source (with a request that satisfies the rule's query mode
 * and conditions). Rules of the "only if not found" kind run after every early rule, so an early match shadows
 * them regardless of priority. Wildcard and regex rules are not checked: a sample path says nothing about all the
 * paths they cover.
 */
final class ShadowDetector
{
    /**
     * Id of the rule that decides the rule's own source instead of the rule itself, or null.
     *
     * @param callable(string): Matcher $matcherFor
     */
    public function shadowedBy(Rule $rule, callable $matcherFor): ?string
    {
        if ($rule->matchType !== MatchType::Exact) {
            return null;
        }
        $sample = SampleGenerator::generate($rule);
        if ($sample === null) {
            return null;
        }
        $ctx = SampleGenerator::context($rule, $sample, $rule->conditions->languages[0] ?? null);
        if ($ctx === null) {
            return null;
        }
        $matcher = $matcherFor($ctx->path);

        if ($rule->onlyIfNotFound) {
            $early = $matcher->match($ctx, MatchPhase::Early);
            if ($early !== null) {
                return self::winner($early);
            }
            $result = $matcher->match($ctx, MatchPhase::NotFound);
        } else {
            $result = $matcher->match($ctx, MatchPhase::Early);
        }
        if ($result === null) {
            return null;
        }
        foreach ($result->rules as $applied) {
            if ($applied->id === $rule->id) {
                return null;
            }
        }

        return self::winner($result);
    }

    private static function winner(MatchResult $result): ?string
    {
        $first = $result->rules[0] ?? null;

        return $first?->id;
    }
}
