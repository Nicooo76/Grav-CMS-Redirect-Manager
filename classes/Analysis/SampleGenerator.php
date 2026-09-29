<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Derives a request that a rule matches from the rule itself: a sample path (exact: the source; wildcard: each
 * "*" becomes a stand-in word; regex: RegexSampler) and a context that satisfies the rule's query mode and
 * conditions. The analysis feeds these to the real Matcher.
 */
final class SampleGenerator
{
    /**
     * @param string $base   stand-in word for wildcard and text captures
     * @param bool   $unique give every capture its own stand-in value ("sample", "sample2", ...)
     */
    public static function generate(Rule $rule, string $base = 'sample', bool $unique = true): ?Sample
    {
        if ($rule->matchType === MatchType::Regex) {
            return RegexSampler::sample($rule->source, $rule->caseSensitive, $base, $unique);
        }
        [$rawPath, $rawQuery] = PathNormalizer::splitSource($rule->source);
        $rawPath = trim($rawPath, " \t\n\r");
        if ($rawPath === '' || preg_match('~^[a-z][a-z0-9+.\-]*://~i', $rawPath) === 1) {
            return null;
        }
        $path = PathKey::normalized($rawPath);
        if ($path === null) {
            return null;
        }
        $query = PathKey::parseQuery($rawQuery);
        if ($rule->matchType === MatchType::Exact) {
            return new Sample($path, $query);
        }

        $parts = explode('*', (string) preg_replace('/\*+/', '*', $path));
        $out = $parts[0];
        $tokens = [];
        for ($i = 1, $count = count($parts); $i < $count; ++$i) {
            $token = $unique && $i > 1 ? $base . $i : $base;
            $out .= $token . $parts[$i];
            $tokens[] = ['token' => $token, 'placeholder' => $i <= 9 ? '$' . $i : '${' . $i . '}'];
        }

        return new Sample($out, $query, $tokens);
    }

    /**
     * A request context the rule matches for $sample, in $language. Null when the rule's header or cookie
     * conditions cannot be satisfied by a synthetic request.
     */
    public static function context(Rule $rule, Sample $sample, ?string $language = null, string $host = '', string $scheme = ''): ?RequestContext
    {
        $conditions = $rule->conditions;
        $query = $sample->query;
        if ($rule->queryMode === QueryMode::Params) {
            foreach ($rule->queryParams as $name => $value) {
                $query[$name] = $value ?? 'x';
            }
        }

        $headers = [];
        $cookies = [];
        foreach ($conditions->rules as $condition) {
            if ($condition->negate) {
                continue; // an absent header or cookie satisfies a negated condition
            }
            $value = match ($condition->operator) {
                ConditionOperator::Exists => 'x',
                ConditionOperator::Regex => RegexSampler::value($condition->value),
                default => $condition->value,
            };
            if ($value === null) {
                return null;
            }
            if ($condition->kind === ConditionKind::Cookie) {
                $cookies[$condition->name] = $value;
            } else {
                $headers[strtolower($condition->name)] = $value;
            }
        }

        if ($host === '') {
            $host = self::firstHost($rule);
        }
        if ($scheme === '') {
            $scheme = $conditions->schemes[0] ?? 'https';
        }

        return new RequestContext($sample->path, $query, $host, $scheme, $language, $headers, $cookies);
    }

    /**
     * An example URL path for the editor: exact sources as written, wildcard and regex sources with example
     * values. Null when no example can be derived.
     */
    public static function url(Rule $rule): ?string
    {
        if (trim($rule->source) === '') {
            return null;
        }
        if ($rule->matchType === MatchType::Exact) {
            return trim($rule->source);
        }
        $sample = self::generate($rule, 'example', false);

        return $sample?->path;
    }

    /** First host a request may use for the rule: a concrete host, or "www.x" for a "*.x" pattern. */
    public static function firstHost(Rule $rule): string
    {
        $host = $rule->conditions->hosts[0] ?? '';

        return str_starts_with($host, '*.') ? 'www.' . substr($host, 2) : $host;
    }
}
