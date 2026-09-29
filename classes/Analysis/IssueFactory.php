<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Builds the ValidationIssue objects for analysis findings, in one place so the analyzer and the validator
 * word them the same way. Messages are English fallbacks; the UI translates by code.
 */
final class IssueFactory
{
    /**
     * Issues for the chain walker's outcomes of one rule. Language variants with the same result are merged.
     *
     * @param list<ChainOutcome> $outcomes
     * @return list<ValidationIssue>
     */
    public static function fromOutcomes(array $outcomes, int $maxDepth): array
    {
        /** @var array<string, array{outcome: ChainOutcome, languages: list<string>}> $merged */
        $merged = [];
        foreach ($outcomes as $outcome) {
            $key = $outcome->kind . '|' . json_encode([$outcome->paths, $outcome->cycleIds, $outcome->shortcut, $outcome->shortcutStatus]);
            $merged[$key] ??= ['outcome' => $outcome, 'languages' => []];
            if ($outcome->language !== null) {
                $merged[$key]['languages'][] = $outcome->language;
            }
        }

        $issues = [];
        foreach ($merged as $entry) {
            $issues[] = self::fromOutcome($entry['outcome'], $entry['languages'], $maxDepth);
        }

        return $issues;
    }

    /**
     * Conflict and duplicate warnings for $rule inside a group of overlapping rules.
     *
     * @param list<Rule> $group
     * @return list<ValidationIssue>
     */
    public static function relations(Rule $rule, array $group, ConflictDetector $detector): array
    {
        $conflicts = [];
        $duplicates = [];
        foreach ($group as $other) {
            if ($other->id === $rule->id || !$detector->overlaps($rule, $other)) {
                continue;
            }
            if ($other->target === $rule->target && $other->status === $rule->status && $other->targetType === $rule->targetType) {
                $duplicates[] = $other->id;
            } else {
                $conflicts[] = $other->id;
            }
        }

        $issues = [];
        if ($conflicts !== []) {
            $issues[] = ValidationIssue::warning(
                'conflict',
                'source',
                'Other rules match the same requests with a different target: ' . implode(', ', $conflicts) . '. Only the highest-ranked one applies.',
                ['rule_ids' => $conflicts],
            );
        }
        if ($duplicates !== []) {
            $issues[] = ValidationIssue::warning(
                'duplicate',
                'source',
                'Identical rule already exists: ' . implode(', ', $duplicates) . '.',
                ['rule_ids' => $duplicates],
            );
        }

        return $issues;
    }

    public static function shadowed(string $winnerId): ValidationIssue
    {
        return ValidationIssue::warning(
            'shadowed',
            'source',
            'Rule ' . $winnerId . ' is ranked higher and matches this rule\'s source first, so this rule never applies.',
            ['rule_id' => $winnerId],
        );
    }

    public static function expired(Rule $rule): ValidationIssue
    {
        return ValidationIssue::warning(
            'expired',
            'expires_at',
            'The rule expired on ' . ($rule->expiresAt?->format(Rule::DATE_FORMAT) ?? '') . ' and is not applied.',
            ['expires_at' => $rule->expiresAt?->format(Rule::DATE_FORMAT)],
        );
    }

    /**
     * A chain that reaches the candidate from another rule, seen from the candidate.
     *
     * @return ValidationIssue
     */
    public static function incoming(ChainOutcome $outcome): ValidationIssue
    {
        $params = self::chainParams($outcome, []);
        $params['incoming'] = true;

        return ValidationIssue::warning(
            'chain',
            'source',
            'Rule ' . $outcome->startId . ' redirects here and continues: ' . implode(' -> ', $outcome->paths) . '.',
            $params,
        );
    }

    /**
     * @param list<string> $languages
     */
    private static function fromOutcome(ChainOutcome $o, array $languages, int $maxDepth): ValidationIssue
    {
        $params = self::chainParams($o, $languages);
        switch ($o->kind) {
            case ChainOutcome::SELF:
                $message = 'The target resolves to the rule\'s own source: ' . implode(' -> ', $o->paths) . '.';
                if (self::differsOnlyInCase($o->paths)) {
                    // The most common way into this error (404 suggestions such as /shop/Zelte to /shop/zelte), and one
                    // with a one-click fix: the editor offers to make the rule case-sensitive (params.case_only).
                    $params['case_only'] = true;
                    $message = 'The target differs from the source only in letter case (' . implode(' -> ', $o->paths)
                        . '), and a case-insensitive rule matches its own target. Make the rule case-sensitive.';
                }

                return ValidationIssue::error('self_redirect', 'target', $message, $params);
            case ChainOutcome::LOOP:
                return ValidationIssue::error('loop', 'target', 'Redirect loop: ' . implode(' -> ', $o->paths) . '.', $params);
            case ChainOutcome::TOO_DEEP:
                $params['max_depth'] = $maxDepth;

                return ValidationIssue::error('chain_too_deep', 'target', 'The redirect chain is longer than the maximum of ' . $maxDepth . ' hops.', $params);
            case ChainOutcome::SKIPPED:
                return ValidationIssue::info('analysis_skipped', 'source', 'No sample path could be derived from this pattern, so its redirect chain was not analyzed.', $params);
        }

        $message = 'Redirect chain of ' . $o->hops . ' hops: ' . implode(' -> ', $o->paths) . '.';
        if ($o->shortcut !== null) {
            $message .= ' Point this rule at ' . $o->shortcut . ' directly.';
        } elseif ($o->shortcutStatus !== null) {
            $message .= ' The chain ends with status ' . $o->shortcutStatus . '; this rule can use that status.';
        }

        return ValidationIssue::warning('chain', 'target', $message, $params);
    }

    /**
     * True when the paths are not all identical but every one equals the first ignoring case.
     *
     * @param list<string> $paths
     */
    private static function differsOnlyInCase(array $paths): bool
    {
        $first = $paths[0] ?? null;
        if ($first === null || count($paths) < 2) {
            return false;
        }
        $differs = false;
        foreach ($paths as $path) {
            if ($first !== $path && !PathNormalizer::differsOnlyInCase($first, $path)) {
                return false;
            }
            $differs = $differs || $first !== $path;
        }

        return $differs;
    }

    /**
     * @param list<string> $languages
     * @return array<string, mixed>
     */
    private static function chainParams(ChainOutcome $o, array $languages): array
    {
        $params = ['chain' => $o->paths, 'rule_ids' => $o->ruleIds, 'hops' => $o->hops];
        if ($o->kind === ChainOutcome::LOOP || $o->kind === ChainOutcome::SELF) {
            $params['cycle'] = $o->cycleIds;
        }
        if ($o->kind === ChainOutcome::CHAIN) {
            $params['shortcut'] = $o->shortcut;
            $params['shortcut_status'] = $o->shortcutStatus;
        }
        if ($languages !== []) {
            $params['languages'] = $languages;
        }

        return $params;
    }
}
