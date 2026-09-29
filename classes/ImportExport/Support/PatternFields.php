<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/** Chooses the simplest rule type (exact, wildcard, regex) for a regex found in a server config. */
final class PatternFields
{
    /**
     * @param bool $insensitive true when the config compares case-insensitively (NC flag, ~*, ...)
     * @return array{source: string, match_type: string, case_sensitive: bool, ignore_trailing_slash?: bool}
     */
    public static function fromRegex(string $regex, bool $insensitive): array
    {
        $literal = Pattern::literalOf($regex);
        if ($literal !== null) {
            return [
                'source' => $literal['path'],
                'match_type' => 'exact',
                'case_sensitive' => !($insensitive || $literal['caseInsensitive']),
                'ignore_trailing_slash' => $literal['trailingSlash'],
            ];
        }
        $wildcard = Pattern::wildcardOf($regex);
        if ($wildcard !== null) {
            return [
                'source' => $wildcard['wildcard'],
                'match_type' => 'wildcard',
                'case_sensitive' => !($insensitive || $wildcard['caseInsensitive']),
            ];
        }
        [$body, $ci] = Pattern::stripCaseFlag($regex);

        return ['source' => $body, 'match_type' => 'regex', 'case_sensitive' => !($insensitive || $ci)];
    }
}
