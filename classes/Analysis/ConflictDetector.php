<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\Conditions;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Finds rules that compete for the same requests: same match type, same source (compared the way the
 * compiler compares it: normalized, case and trailing slash folded when either rule tolerates it), same query
 * requirement and conditions that can hold together.
 *
 * Only rules that are enabled and not expired take part. Rules are put into hash buckets first, so the
 * pairwise comparison only runs inside a bucket and the whole pass is linear.
 */
final class ConflictDetector
{
    public function __construct(
        private readonly MatcherOptions $options,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Groups of two or more rules that overlap (transitively), members in input order.
     *
     * @param list<Rule> $rules
     * @return list<list<Rule>>
     */
    public function detect(array $rules): array
    {
        $now = $this->clock->now();
        /** @var array<string, list<Rule>> $buckets */
        $buckets = [];
        foreach ($rules as $rule) {
            if (!$rule->enabled || $rule->isExpired($now)) {
                continue;
            }
            $key = $this->bucketKey($rule);
            if ($key !== null) {
                $buckets[$key][] = $rule;
            }
        }

        $groups = [];
        foreach ($buckets as $members) {
            if (count($members) > 1) {
                foreach ($this->components($members) as $group) {
                    $groups[] = $group;
                }
            }
        }

        return $groups;
    }

    /**
     * Hash key that every pair of overlapping rules shares. Null for rules without a usable source.
     */
    public function bucketKey(Rule $rule): ?string
    {
        if (trim($rule->source) === '') {
            return null;
        }
        if ($rule->matchType === MatchType::Regex) {
            return 'regex|' . $rule->source . '|' . $this->queryKey($rule, '');
        }
        [$path, $query] = self::split($rule);
        $normalized = PathKey::normalized($path);
        if ($normalized === null) {
            return null;
        }

        return $rule->matchType->value . '|' . PathKey::fold($normalized) . '|' . $this->queryKey($rule, $query);
    }

    /**
     * Whether two rules of the same bucket can both match one request.
     */
    public function overlaps(Rule $a, Rule $b): bool
    {
        if ($a->matchType !== $b->matchType) {
            return false;
        }
        if ($a->matchType === MatchType::Regex) {
            if ($a->source !== $b->source || $a->caseSensitive !== $b->caseSensitive) {
                return false;
            }
        } else {
            $pa = PathKey::normalized(self::split($a)[0]);
            $pb = PathKey::normalized(self::split($b)[0]);
            if ($pa === null || $pb === null) {
                return false;
            }
            if ($a->ignoreTrailingSlash || $b->ignoreTrailingSlash) {
                $pa = PathNormalizer::stripTrailingSlash($pa);
                $pb = PathNormalizer::stripTrailingSlash($pb);
            }
            if (!$a->caseSensitive || !$b->caseSensitive) {
                $pa = PathNormalizer::lower($pa);
                $pb = PathNormalizer::lower($pb);
            }
            if ($pa !== $pb) {
                return false;
            }
        }

        return self::conditionsOverlap($a->conditions, $b->conditions);
    }

    /**
     * Empty lists mean "any". Two non-empty lists overlap when they share a value (hosts: also through
     * a "*.example.com" pattern). Header and cookie conditions are compared literally: two different
     * non-empty sets are treated as disjoint, an empty set overlaps everything.
     */
    public static function conditionsOverlap(Conditions $a, Conditions $b): bool
    {
        if ($a->hosts !== [] && $b->hosts !== []) {
            $found = false;
            foreach ($a->hosts as $x) {
                foreach ($b->hosts as $y) {
                    if (self::hostsOverlap($x, $y)) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                return false;
            }
        }
        if ($a->languages !== [] && $b->languages !== [] && array_intersect($a->languages, $b->languages) === []) {
            return false;
        }
        if ($a->schemes !== [] && $b->schemes !== [] && array_intersect($a->schemes, $b->schemes) === []) {
            return false;
        }
        if ($a->rules !== [] && $b->rules !== []) {
            return self::canonicalRules($a) === self::canonicalRules($b);
        }

        return true;
    }

    /**
     * @param list<Rule> $members
     * @return list<list<Rule>>
     */
    private function components(array $members): array
    {
        $count = count($members);
        $parent = range(0, $count - 1);
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($this->overlaps($members[$i], $members[$j])) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        /** @var array<int, list<Rule>> $groups */
        $groups = [];
        foreach ($members as $i => $rule) {
            $groups[$find($i)][] = $rule;
        }

        return array_values(array_filter($groups, static fn (array $g): bool => count($g) > 1));
    }

    /**
     * @return array{0: string, 1: string} path and raw query of the source
     */
    private static function split(Rule $rule): array
    {
        [$path, $query] = PathNormalizer::splitSource($rule->source);

        return [trim($path, " \t\n\r"), $query];
    }

    /**
     * What the rule demands of the query string. Query modes "ignore" and "pass" both accept any query.
     */
    private function queryKey(Rule $rule, string $rawQuery): string
    {
        if ($rule->queryMode === QueryMode::Ignore || $rule->queryMode === QueryMode::Pass) {
            return '';
        }
        $query = PathKey::parseQuery($rawQuery);

        if ($rule->queryMode === QueryMode::Exact) {
            $ignore = [...$this->options->globalQueryIgnore, ...$rule->queryIgnore];
            $kept = [];
            foreach ($query as $name => $value) {
                if (!self::ignored($name, $ignore)) {
                    $kept[$name] = $value;
                }
            }
            ksort($kept, SORT_STRING);

            return 'e:' . http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
        }

        /** @var array<string, string|null> $required */
        $required = $rule->queryParams;
        foreach ($query as $name => $value) {
            if (!array_key_exists($name, $required)) {
                $required[$name] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
            }
        }
        ksort($required, SORT_STRING);

        return 'p:' . json_encode($required, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<string> $patterns
     */
    private static function ignored(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!str_contains($pattern, '*')) {
                if (strcasecmp($pattern, $name) === 0) {
                    return true;
                }
                continue;
            }
            if (preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#iDs', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function hostsOverlap(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $aWild = str_starts_with($a, '*.');
        $bWild = str_starts_with($b, '*.');
        if ($aWild && $bWild) {
            return str_ends_with($a, substr($b, 1)) || str_ends_with($b, substr($a, 1));
        }
        if ($aWild) {
            return self::covers($a, $b);
        }
        if ($bWild) {
            return self::covers($b, $a);
        }

        return false;
    }

    private static function covers(string $pattern, string $host): bool
    {
        $suffix = substr($pattern, 1);

        return strlen($host) > strlen($suffix) && str_ends_with($host, $suffix);
    }

    private static function canonicalRules(Conditions $conditions): string
    {
        $rows = array_map(
            static fn ($c): string => (string) json_encode($c->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $conditions->rules,
        );
        sort($rows, SORT_STRING);

        return implode("\n", $rows);
    }
}
