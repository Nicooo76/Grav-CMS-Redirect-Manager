<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Matching;

use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Security\RegexSafety;
use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Sorts rules by precedence and builds the lookup indexes of a CompiledRuleSet.
 *
 * Rules that cannot work (unusable source, invalid regex, invalid condition regex) are left out and
 * reported through CompiledRuleSet::skipped(). Catastrophic patterns are NOT detected here (that is
 * RegexSafety::validate() at save time); at match time the PCRE limits contain them.
 */
final class RuleCompiler
{
    public const SKIP_SOURCE = 'source_invalid';
    public const SKIP_CONDITION_REGEX = 'condition_regex_invalid';

    /**
     * @param list<Rule> $rules
     */
    public function compile(array $rules): CompiledRuleSet
    {
        $sorted = array_values($rules);
        usort($sorted, self::compareRank(...));

        $defaults = (new Rule('', ''))->toArray();
        $rows = [];
        $meta = [];
        $disabled = [];
        $skipped = [];
        $exact = ['cs_t' => [], 'cs_s' => [], 'ci_t' => [], 'ci_s' => []];
        $trie = ['cs' => [], 'ci' => []];
        $rx = ['cs' => [], 'ci' => [], 'any' => []];

        foreach ($sorted as $rule) {
            $compiled = $this->compileRule($rule);
            if (is_string($compiled)) {
                $skipped[$rule->id] = $compiled;
                continue;
            }
            $index = count($rows);
            $rows[] = self::slim($rule, $defaults);
            $meta[] = $compiled['meta'];

            if (!$rule->enabled) {
                $disabled[] = $index;
                continue;
            }

            switch ($rule->matchType) {
                case MatchType::Exact:
                    $variant = ($rule->caseSensitive ? 'cs' : 'ci') . '_' . ($rule->ignoreTrailingSlash ? 't' : 's');
                    $key = $compiled['key'];
                    $exact[$variant][$key] = [...($exact[$variant][$key] ?? []), $index];
                    break;
                case MatchType::Wildcard:
                    $bucket = $rule->caseSensitive ? 'cs' : 'ci';
                    $trie[$bucket] = self::addToTrie($trie[$bucket], $compiled['segments'], $index);
                    break;
                case MatchType::Regex:
                    $segment = $compiled['segment'];
                    if ($segment === null) {
                        $rx['any'][] = $index;
                    } else {
                        $bucket = $rule->caseSensitive ? 'cs' : 'ci';
                        $rx[$bucket][$segment] = [...($rx[$bucket][$segment] ?? []), $index];
                    }
                    break;
            }
        }

        return CompiledRuleSet::fromArray([
            'v' => CompiledRuleSet::VERSION,
            'rules' => $rows,
            'meta' => $meta,
            'disabled' => $disabled,
            'skipped' => $skipped,
            'exact' => $exact,
            'trie' => $trie,
            'rx' => $rx,
        ]);
    }

    /**
     * The rule as a row without fields that hold their default value: Rule::fromArray() fills them in again.
     * Keeps the exported file small (about a third of the full rows).
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private static function slim(Rule $rule, array $defaults): array
    {
        $row = [];
        foreach ($rule->toArray() as $key => $value) {
            if ($key === 'id' || $key === 'source' || $key === 'target' || $value !== $defaults[$key]) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    /**
     * Precedence: priority DESC, match type (exact, wildcard, regex), created_at ASC (missing last), id ASC.
     */
    private static function compareRank(Rule $a, Rule $b): int
    {
        if ($a->priority !== $b->priority) {
            return $b->priority <=> $a->priority;
        }
        if ($a->matchType !== $b->matchType) {
            return $a->matchType->order() <=> $b->matchType->order();
        }
        $ta = $a->createdAt?->getTimestamp();
        $tb = $b->createdAt?->getTimestamp();
        if ($ta !== $tb) {
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return $ta <=> $tb;
        }

        return strcmp($a->id, $b->id);
    }

    /**
     * @return string|array{meta: array<string, mixed>, key: string, segments: list<string>, segment: string|null}
     *         a skip reason, or the compiled data of the rule
     */
    private function compileRule(Rule $rule): string|array
    {
        foreach ($rule->conditions->rules as $condition) {
            if ($condition->operator === ConditionOperator::Regex && $condition->value !== ''
                && RegexSafety::syntaxError($condition->value, true) !== null) {
                return self::SKIP_CONDITION_REGEX;
            }
        }

        $out = ['meta' => [], 'key' => '', 'segments' => [], 'segment' => null];

        if ($rule->matchType === MatchType::Regex) {
            $error = RegexSafety::syntaxError($rule->source, $rule->caseSensitive);
            if ($error !== null) {
                return $error;
            }
            $out['meta'] = ['re' => RegexSafety::compile($rule->source, $rule->caseSensitive)];
            $segment = self::literalFirstSegment($rule->source);
            $out['segment'] = $segment !== null && !$rule->caseSensitive ? PathNormalizer::lower($segment) : $segment;

            return $out;
        }

        [$rawPath, $rawQuery] = PathNormalizer::splitSource($rule->source);
        $rawPath = trim($rawPath, " \t\n\r"); // not trim()'s default: that would strip a NUL byte and hide it
        if ($rawPath === '') {
            return self::SKIP_SOURCE;
        }
        try {
            $path = PathNormalizer::normalize($rawPath);
        } catch (InvalidPathException) {
            return self::SKIP_SOURCE;
        }

        $meta = [];
        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
            $meta['q'] = $query;
        }

        if ($rule->matchType === MatchType::Exact) {
            $meta['p'] = $path;
            $key = $rule->ignoreTrailingSlash ? PathNormalizer::stripTrailingSlash($path) : $path;
            $out['key'] = $rule->caseSensitive ? $key : PathNormalizer::lower($key);
            $out['meta'] = $meta;

            return $out;
        }

        $meta['re'] = self::wildcardRegex($path, $rule->caseSensitive);
        $star = strpos($path, '*');
        $literal = $star === false ? $path : substr($path, 0, $star);
        $slash = strrpos($literal, '/');
        $prefix = $slash === false ? '' : substr($literal, 0, $slash);
        $out['segments'] = CompiledRuleSet::segments($rule->caseSensitive ? $prefix : PathNormalizer::lower($prefix));
        $out['meta'] = $meta;

        return $out;
    }

    private static function wildcardRegex(string $path, bool $caseSensitive): string
    {
        $parts = explode('*', (string) preg_replace('/\*+/', '*', $path));
        $quoted = array_map(static fn (string $part): string => preg_quote($part, '#'), $parts);

        return '#^' . implode('(.*)', $quoted) . '$#Du' . ($caseSensitive ? '' : 'i');
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $segments
     * @return array<string, mixed>
     */
    private static function addToTrie(array $node, array $segments, int $index): array
    {
        if ($segments === []) {
            /** @var list<int> $rules */
            $rules = $node['r'] ?? [];
            $node['r'] = [...$rules, $index];

            return $node;
        }
        $segment = array_shift($segments);
        /** @var array<string, mixed> $children */
        $children = $node['c'] ?? [];
        /** @var array<string, mixed> $child */
        $child = $children[$segment] ?? [];
        $children[$segment] = self::addToTrie($child, $segments, $index);
        $node['c'] = $children;

        return $node;
    }

    /**
     * First path segment of a regex when the pattern starts with "^/literal" followed by "/" or "$",
     * has no top-level alternation, so it can only match paths starting with that segment.
     */
    private static function literalFirstSegment(string $pattern): ?string
    {
        if (preg_match('#^\^/([A-Za-z0-9_\-]+)(?=/|\\\\/|\$|$)#', $pattern, $m) !== 1) {
            return null;
        }

        return self::hasTopLevelAlternation($pattern) ? null : $m[1];
    }

    private static function hasTopLevelAlternation(string $pattern): bool
    {
        $depth = 0;
        $inClass = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];
            if ($char === '\\') {
                ++$i;
                continue;
            }
            if ($inClass) {
                $inClass = $char !== ']';
                continue;
            }
            if ($char === '[') {
                $inClass = true;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === '|' && $depth <= 0) {
                return true;
            }
        }

        return false;
    }
}
