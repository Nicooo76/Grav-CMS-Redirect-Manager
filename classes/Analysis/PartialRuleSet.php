<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * A small rule set compiled on demand for one candidate: the candidate, all wildcard and regex rules, and
 * the exact rules whose folded source equals a path the analysis has asked about (plus the exact rules
 * reachable from those through plain targets). That is enough for the Matcher to give the same answer as
 * with the full set, at a fraction of the compile cost.
 *
 * matcherFor($path) makes sure the exact rules for $path are in the set and returns the Matcher; it recompiles
 * only when the set grew. Chains that pass through a capture-rewriting rule into a path nobody asked about
 * before cause one more (small) compile, not a wrong answer.
 */
final class PartialRuleSet
{
    /** @var array<string, true> */
    private array $included = [];

    /** @var array<string, Rule> */
    private array $exact = [];

    private ?Matcher $matcher = null;

    private readonly RuleCompiler $compiler;

    public function __construct(
        private readonly MatcherOptions $options,
        private readonly Clock $clock,
        private readonly ?Rule $candidate,
        private readonly RuleIndex $index,
    ) {
        $this->compiler = new RuleCompiler();
        foreach ($index->wide as $rule) {
            $fold = RuleIndex::plainTargetFold($rule);
            if ($fold !== null) {
                $this->include($fold, 0);
            }
        }
        if ($candidate !== null) {
            $fold = RuleIndex::plainTargetFold($candidate);
            if ($fold !== null) {
                $this->include($fold, 0);
            }
        }
    }

    public function matcherFor(string $path): Matcher
    {
        $normalized = PathKey::normalized($path);
        if ($normalized !== null) {
            $this->include(PathKey::fold($normalized), 0);
        }

        return $this->matcher ??= $this->build();
    }

    private function include(string $fold, int $depth): void
    {
        if (isset($this->included[$fold])) {
            return;
        }
        $this->included[$fold] = true;
        foreach ($this->index->exactByFold[$fold] ?? [] as $rule) {
            $this->exact[$rule->id] = $rule;
            $this->matcher = null;
            $next = RuleIndex::plainTargetFold($rule);
            if ($next !== null && $depth < max(2, $this->options->maxChainDepth)) {
                $this->include($next, $depth + 1);
            }
        }
    }

    private function build(): Matcher
    {
        $rules = $this->candidate === null ? [] : [$this->candidate];
        foreach ($this->index->wide as $rule) {
            $rules[] = $rule;
        }
        foreach ($this->exact as $rule) {
            $rules[] = $rule;
        }

        return new Matcher($this->compiler->compile($rules), $this->clock, $this->options);
    }
}
