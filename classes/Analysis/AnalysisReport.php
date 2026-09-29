<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * Result of ChainAnalyzer::analyze() over a whole rule list.
 *
 * chains: one entry per rule (and language) whose redirect passes through two or more hops:
 *   {rule_ids, paths, shortcut, shortcut_status, language?}
 * loops: one entry per distinct cycle {rule_ids, paths}; conflicts: groups of rule ids.
 */
final class AnalysisReport
{
    /**
     * @param array<string, list<ValidationIssue>>                          $issues     by rule id
     * @param array<string, string>                                         $states     rule id => disabled|expired|scheduled|active
     * @param list<array<string, mixed>>                                    $chains
     * @param list<array{rule_ids: list<string>, paths: list<string>}>      $loops
     * @param list<list<string>>                                            $conflicts
     * @param list<string>                                                  $expired
     */
    public function __construct(
        private readonly array $issues,
        private readonly array $states,
        private readonly array $chains,
        private readonly array $loops,
        private readonly array $conflicts,
        private readonly array $expired,
    ) {
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issuesFor(string $ruleId): array
    {
        return $this->issues[$ruleId] ?? [];
    }

    /**
     * @return array<string, list<ValidationIssue>>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function chains(): array
    {
        return $this->chains;
    }

    /**
     * Chain entries that start at the given rule (one per language variant).
     *
     * @return list<array<string, mixed>>
     */
    public function chainsFor(string $ruleId): array
    {
        return array_values(array_filter($this->chains, static fn (array $c): bool => ($c['rule_ids'][0] ?? null) === $ruleId));
    }

    /**
     * @return list<array{rule_ids: list<string>, paths: list<string>}>
     */
    public function loops(): array
    {
        return $this->loops;
    }

    /**
     * @return list<list<string>>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return list<string>
     */
    public function expired(): array
    {
        return $this->expired;
    }

    /**
     * State badge (disabled, expired, scheduled or active) followed by chain, loop and conflict where they apply.
     * "dead_target" and "unused" come from other services and are added by the caller.
     *
     * @return list<string>
     */
    public function badges(string $ruleId): array
    {
        $badges = [$this->states[$ruleId] ?? 'active'];
        $extra = [];
        foreach ($this->issues[$ruleId] ?? [] as $issue) {
            $badge = match ($issue->code) {
                'chain', 'chain_too_deep' => 'chain',
                'loop', 'self_redirect' => 'loop',
                'conflict', 'duplicate' => 'conflict',
                default => null,
            };
            if ($badge !== null) {
                $extra[$badge] = true;
            }
        }
        foreach (['chain', 'loop', 'conflict'] as $badge) {
            if (isset($extra[$badge])) {
                $badges[] = $badge;
            }
        }

        return $badges;
    }

    /**
     * @return array{chains: list<array<string, mixed>>, loops: list<array{rule_ids: list<string>, paths: list<string>}>, conflicts: list<list<string>>, expired: list<string>, issues: array<string, list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        $issues = [];
        foreach ($this->issues as $id => $list) {
            $issues[$id] = array_map(static fn (ValidationIssue $i): array => $i->toArray(), $list);
        }

        return [
            'chains' => $this->chains,
            'loops' => $this->loops,
            'conflicts' => $this->conflicts,
            'expired' => $this->expired,
            'issues' => $issues,
        ];
    }
}
