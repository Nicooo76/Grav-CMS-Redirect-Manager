<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\Rule;

/**
 * One-click fix for a redirect chain: point the rule at the end of its chain.
 */
final class ChainShortener
{
    /**
     * The rule with its target set to the final location of its chain (status kept), or the rule turned into a
     * 410/451 without target when the chain ends there. Null when the rule is not the start of a chain, or when its
     * language variants end in different places (there is no single target then).
     */
    public function shorten(Rule $rule, AnalysisReport $report): ?Rule
    {
        $entries = $report->chainsFor($rule->id);
        if ($entries === []) {
            return null;
        }
        $first = $entries[0];
        foreach ($entries as $entry) {
            if (($entry['shortcut'] ?? null) !== ($first['shortcut'] ?? null) || ($entry['shortcut_status'] ?? null) !== ($first['shortcut_status'] ?? null)) {
                return null;
            }
        }

        $shortcut = $first['shortcut'] ?? null;
        if (is_string($shortcut) && $shortcut !== '') {
            $external = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $shortcut) === 1;

            return $rule->with(['target' => $shortcut, 'target_type' => $external ? 'url' : $rule->targetType->value]);
        }
        $status = $first['shortcut_status'] ?? null;
        if (is_int($status)) {
            return $rule->with(['status' => $status, 'target' => '']);
        }

        return null;
    }
}
