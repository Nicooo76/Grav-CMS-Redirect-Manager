<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;

/** Finds attributes of a rule that a format cannot carry, so exports can report them. */
final class Lossy
{
    /**
     * @param list<string> $unsupported feature names: conditions, query, query_pass, query_ignore, case,
     *                                  trailing_slash, active_from, expires_at, continue, only_if_not_found,
     *                                  target_page, disabled
     * @return list<ExportNote>
     */
    public static function notes(Rule $rule, array $unsupported): array
    {
        $checks = [
            'conditions' => !$rule->conditions->isEmpty(),
            'query' => $rule->queryMode === QueryMode::Exact || $rule->queryMode === QueryMode::Params,
            'query_pass' => $rule->queryMode === QueryMode::Pass,
            'query_ignore' => $rule->queryIgnore !== [],
            'case' => $rule->caseSensitive && $rule->matchType !== MatchType::Regex,
            'trailing_slash' => !$rule->ignoreTrailingSlash,
            'active_from' => $rule->activeFrom !== null,
            'expires_at' => $rule->expiresAt !== null,
            'continue' => $rule->continueMatching,
            'only_if_not_found' => $rule->onlyIfNotFound,
            'target_page' => $rule->targetType === TargetType::Page,
        ];
        $notes = [];
        foreach ($unsupported as $feature) {
            if (($checks[$feature] ?? false) === true) {
                $notes[] = new ExportNote($rule->id, 'dropped_' . $feature, 'The format cannot express: ' . $feature);
            }
        }

        return $notes;
    }

    /** True when the target uses the {lang} placeholder, which no foreign format knows. */
    public static function usesLangPlaceholder(Rule $rule): bool
    {
        return str_contains($rule->target, '{lang}');
    }
}
