<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\Security\RegexSafety;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Analyzes a whole rule list: chains, loops, self references, conflicts, duplicates, shadowed and expired rules.
 *
 * The rules are compiled once. Every active redirect is followed through the compiled set with the real
 * Matcher (ChainWalker), so chains through wildcard and regex rules resolve exactly like live requests.
 */
final class ChainAnalyzer
{
    private readonly RuleCompiler $compiler;
    private readonly ChainWalker $walker;
    private readonly ConflictDetector $conflicts;
    private readonly ShadowDetector $shadows;

    public function __construct(
        private readonly MatcherOptions $options,
        private readonly Clock $clock,
    ) {
        $this->compiler = new RuleCompiler();
        $this->walker = new ChainWalker($options, $clock);
        $this->conflicts = new ConflictDetector($options, $clock);
        $this->shadows = new ShadowDetector();
    }

    /**
     * @param list<Rule> $rules
     */
    public function analyze(array $rules): AnalysisReport
    {
        $now = $this->clock->now();
        $set = $this->compiler->compile($rules);
        $skipped = $set->skipped();
        $matcher = new Matcher($set, $this->clock, $this->options);
        $matcherFor = static fn (string $path): Matcher => $matcher;
        $max = max(1, $this->options->maxChainDepth);

        /** @var array<string, list<ValidationIssue>> $issues */
        $issues = [];
        $states = [];
        $expired = [];
        foreach ($rules as $rule) {
            if (!$rule->enabled) {
                $states[$rule->id] = 'disabled';
            } elseif ($rule->isExpired($now)) {
                $states[$rule->id] = 'expired';
                $expired[] = $rule->id;
                $issues[$rule->id][] = IssueFactory::expired($rule);
            } elseif ($rule->isScheduled($now)) {
                $states[$rule->id] = 'scheduled';
            } else {
                $states[$rule->id] = 'active';
            }
            if (isset($skipped[$rule->id])) {
                $issues[$rule->id][] = self::skippedIssue($skipped[$rule->id]);
            }
        }

        $conflictGroups = [];
        foreach ($this->conflicts->detect($rules) as $group) {
            $conflictGroups[] = array_map(static fn (Rule $r): string => $r->id, $group);
            foreach ($group as $rule) {
                foreach (IssueFactory::relations($rule, $group, $this->conflicts) as $issue) {
                    $issues[$rule->id][] = $issue;
                }
            }
        }

        $chains = [];
        $loops = [];
        foreach ($rules as $rule) {
            if (!$rule->isActive($now) || isset($skipped[$rule->id])) {
                continue;
            }

            $outcomes = $this->walker->walk($rule, $matcherFor);
            if ($outcomes !== []) {
                foreach (IssueFactory::fromOutcomes($outcomes, $max) as $issue) {
                    $issues[$rule->id][] = $issue;
                }
                foreach ($outcomes as $outcome) {
                    if ($outcome->kind === ChainOutcome::CHAIN) {
                        $entry = [
                            'rule_ids' => $outcome->ruleIds,
                            'paths' => $outcome->paths,
                            'shortcut' => $outcome->shortcut,
                            'shortcut_status' => $outcome->shortcutStatus,
                        ];
                        if ($outcome->language !== null) {
                            $entry['language'] = $outcome->language;
                        }
                        $chains[] = $entry;
                    } elseif ($outcome->kind === ChainOutcome::LOOP || $outcome->kind === ChainOutcome::SELF) {
                        $cycle = $outcome->cycleIds;
                        sort($cycle);
                        $loops[implode(',', $cycle)] ??= ['rule_ids' => $outcome->cycleIds, 'paths' => $outcome->paths];
                    }
                }
            }

            $winner = $this->shadows->shadowedBy($rule, $matcherFor);
            if ($winner !== null) {
                $issues[$rule->id][] = IssueFactory::shadowed($winner);
            }
        }

        return new AnalysisReport($issues, $states, $chains, array_values($loops), $conflictGroups, $expired);
    }

    private static function skippedIssue(string $reason): ValidationIssue
    {
        $params = ['reason' => $reason];
        switch ($reason) {
            case RegexSafety::ERR_INVALID:
            case RegexSafety::ERR_TOO_LONG:
                return ValidationIssue::error($reason, 'source', 'The rule is ignored: its regular expression is not usable (' . $reason . ').', $params);
            case RuleCompiler::SKIP_CONDITION_REGEX:
                return ValidationIssue::error('regex_invalid', 'conditions', 'The rule is ignored: a header or cookie condition has an invalid regular expression.', $params);
            default:
                return ValidationIssue::error('source_invalid', 'source', 'The rule is ignored: its source path is not usable.', $params);
        }
    }
}
