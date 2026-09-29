<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Matching;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Domain\TraceStep;
use Grav\Plugin\RedirectManager\Security\RegexSafety;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Finds the rule for a request. Pure: no Grav, no I/O, never throws for bad input.
 *
 * The request path in RequestContext must already be normalized (PathNormalizer::normalize, once). The
 * matcher never decodes it again. Evaluation order per candidate: phase, active window, path, query mode,
 * host / language / scheme, header and cookie conditions. The first candidate in rank order that passes
 * all of them and yields a safe location wins.
 *
 * Placeholders in targets: $1..$9, ${n}, {name}, {lang}. Captured text is percent-encoded when it is
 * substituted (path context: everything except unreserved characters, sub-delims, ":", "@" and "/";
 * query context: everything), so a capture cannot add "?", "#" or "&" to the target. An empty {lang}
 * removes its path segment. Duplicate slashes inside an internal path collapse; a leading "//" does
 * not, it fails TargetGuard and the rule counts as "unsafe_target".
 *
 * Reasons besides those in TraceStep: "unsafe_target" (the built location failed TargetGuard::isSafeLocation).
 */
final class Matcher
{
    private const PLACEHOLDER = '/\$(\d)|\$\{(\d+)\}|\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /** @var array<string, string> glob pattern => PCRE */
    private array $globCache = [];

    /** @var array<string, string> condition value => PCRE */
    private array $conditionRegexCache = [];

    public function __construct(
        private readonly CompiledRuleSet $set,
        private readonly Clock $clock,
        private readonly MatcherOptions $options,
    ) {
    }

    /**
     * @param list<TraceStep>|null $traceOut receives the evaluated candidates when $withTrace is set, also when nothing matched
     */
    public function match(RequestContext $ctx, MatchPhase $phase = MatchPhase::Early, bool $withTrace = false, ?array &$traceOut = null): ?MatchResult
    {
        return RegexSafety::withLimits(function () use ($ctx, $phase, $withTrace, &$traceOut): ?MatchResult {
            set_error_handler(static fn (int $no, string $message): bool => str_starts_with($message, 'preg_'), E_WARNING);
            try {
                return $this->run($ctx, $phase, $withTrace, $traceOut);
            } finally {
                restore_error_handler();
            }
        }, $this->options->backtrackLimit, $this->options->recursionLimit);
    }

    /**
     * @param-out list<TraceStep> $traceOut
     * @param list<TraceStep>|null $traceOut
     */
    private function run(RequestContext $ctx, MatchPhase $phase, bool $withTrace, ?array &$traceOut = null): ?MatchResult
    {
        $now = $this->clock->now();
        $current = $ctx;
        if ($ctx->path === '' || $ctx->path[0] !== '/') {
            $current = $ctx->withPath('/' . $ctx->path);
        }

        /** @var list<TraceStep> $trace */
        $trace = [];
        /** @var list<Rule> $applied */
        $applied = [];
        $hit = null;
        $after = -1;

        while (count($applied) < max(1, $this->options->maxChainDepth)) {
            $next = $this->next($current, $after, $phase, $now, $withTrace, $trace);
            if ($next === null) {
                break;
            }
            $hit = $next;
            $applied[] = $next['rule'];
            $after = $next['index'];

            $rule = $next['rule'];
            if (!$rule->continueMatching || !($rule->status->isRedirect() || $rule->status === StatusCode::PassThrough)
                || !str_starts_with($next['location'], '/')) {
                break;
            }
            try {
                $current = $current->withPath(PathNormalizer::normalize(self::pathOf($next['location'])));
            } catch (InvalidPathException) {
                break;
            }
        }

        $traceOut = $trace;
        if ($hit === null) {
            return null;
        }

        return new MatchResult($hit['rule'], $hit['rule']->status, $hit['location'], $applied, $hit['captures'], $trace);
    }

    /**
     * @param list<TraceStep> $trace
     * @return array{index: int, rule: Rule, captures: array<int|string, string>, location: string}|null
     */
    private function next(RequestContext $ctx, int $after, MatchPhase $phase, DateTimeImmutable $now, bool $withTrace, array &$trace): ?array
    {
        $candidates = $this->set->candidates($ctx->path);
        if ($withTrace) {
            foreach ($this->set->disabledIndexes() as $index) {
                $captures = $this->matchPath($index, $this->set->rule($index), $ctx->path);
                if (is_array($captures)) {
                    $candidates[] = $index;
                }
            }
            sort($candidates);
        }

        foreach ($candidates as $index) {
            if ($index <= $after) {
                continue;
            }
            $rule = $this->set->rule($index);
            [$reason, $captures] = $this->evaluate($index, $rule, $ctx, $phase, $now);
            $location = null;
            if ($reason === 'matched') {
                $location = $this->buildLocation($rule, $captures, $ctx);
                if ($location === null) {
                    $reason = 'unsafe_target';
                }
            }
            if ($withTrace) {
                $trace[] = new TraceStep($rule->id, $rule->source, $rule->matchType, $rule->priority, $location !== null, $reason, $ctx->path);
            }
            if ($location !== null) {
                return ['index' => $index, 'rule' => $rule, 'captures' => $captures, 'location' => $location];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array<int|string, string>} reason code and captures
     */
    private function evaluate(int $index, Rule $rule, RequestContext $ctx, MatchPhase $phase, DateTimeImmutable $now): array
    {
        if (($phase === MatchPhase::Early && $rule->onlyIfNotFound) || ($phase === MatchPhase::NotFound && !$rule->onlyIfNotFound)) {
            return ['phase', []];
        }
        if (!$rule->enabled) {
            return ['disabled', []];
        }
        if ($rule->isExpired($now)) {
            return ['expired', []];
        }
        if ($rule->isScheduled($now)) {
            return ['scheduled', []];
        }

        $captures = $this->matchPath($index, $rule, $ctx->path);
        if ($captures === false) {
            return ['regex_error', []];
        }
        if ($captures === null) {
            return ['no_match', []];
        }
        if (!$this->queryMatches($index, $rule, $ctx)) {
            return ['query', []];
        }
        $reason = $this->conditionReason($rule, $ctx);
        if ($reason !== null) {
            return [$reason, []];
        }

        return ['matched', $captures];
    }

    /**
     * @return array<int|string, string>|null|false captures, null for no match, false for a PCRE error
     */
    private function matchPath(int $index, Rule $rule, string $path): array|null|false
    {
        if ($rule->matchType === MatchType::Exact) {
            $source = $this->set->exactPath($index);
            if ($rule->ignoreTrailingSlash) {
                $source = PathNormalizer::stripTrailingSlash($source);
                $path = PathNormalizer::stripTrailingSlash($path);
            }
            if (!$rule->caseSensitive) {
                $source = PathNormalizer::lower($source);
                $path = PathNormalizer::lower($path);
            }

            return $source === $path ? [] : null;
        }

        $pattern = $this->set->pattern($index);
        $subjects = [$path];
        if ($rule->ignoreTrailingSlash) {
            $subjects[] = str_ends_with($path, '/') ? PathNormalizer::stripTrailingSlash($path) : $path . '/';
        }
        foreach ($subjects as $subject) {
            $found = preg_match($pattern, $subject, $m);
            if ($found === false) {
                return false;
            }
            if ($found === 1) {
                $captures = [];
                foreach ($m as $key => $value) {
                    if ($key !== 0) {
                        $captures[$key] = $value;
                    }
                }

                return $captures;
            }
        }

        return null;
    }

    private function queryMatches(int $index, Rule $rule, RequestContext $ctx): bool
    {
        if ($rule->queryMode === QueryMode::Ignore || $rule->queryMode === QueryMode::Pass) {
            return true;
        }
        $source = $this->set->sourceQuery($index) ?? [];

        if ($rule->queryMode === QueryMode::Exact) {
            $ignore = [...$this->options->globalQueryIgnore, ...$rule->queryIgnore];

            return $this->normalizeQuery($ctx->query, $ignore) === $this->normalizeQuery($source, $ignore);
        }

        /** @var array<string, string|null> $required */
        $required = $rule->queryParams;
        foreach ($source as $name => $value) {
            if (!array_key_exists((string) $name, $required)) {
                $required[(string) $name] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
            }
        }
        foreach ($required as $name => $expected) {
            if (!array_key_exists($name, $ctx->query)) {
                return false;
            }
            if ($expected === null) {
                continue;
            }
            $actual = $ctx->query[$name];
            if (is_array($actual)) {
                $values = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $actual);
                if (!in_array($expected, $values, true)) {
                    return false;
                }
            } elseif (!is_scalar($actual) || (string) $actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $query
     * @param list<string>             $ignore
     * @return array<string, mixed>
     */
    private function normalizeQuery(array $query, array $ignore): array
    {
        $out = [];
        foreach ($query as $name => $value) {
            if (!$this->isIgnored((string) $name, $ignore)) {
                $out[(string) $name] = $this->normalizeQueryValue($value);
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    private function normalizeQueryValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->normalizeQueryValue($item);
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /**
     * @param list<string> $patterns
     */
    private function isIgnored(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!str_contains($pattern, '*')) {
                if (strcasecmp($pattern, $name) === 0) {
                    return true;
                }
                continue;
            }
            $regex = $this->globCache[$pattern] ??= '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#iDs';
            if (preg_match($regex, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the reason code of the first failing condition, or null when all hold.
     */
    private function conditionReason(Rule $rule, RequestContext $ctx): ?string
    {
        $conditions = $rule->conditions;
        if ($conditions->isEmpty()) {
            return null;
        }
        if ($conditions->hosts !== [] && !self::hostMatches($ctx->host, $conditions->hosts)) {
            return 'host';
        }
        if ($conditions->languages !== []) {
            $language = strtolower($ctx->language ?? $this->options->defaultLanguage ?? '');
            if ($language === '' || !in_array($language, $conditions->languages, true)) {
                return 'language';
            }
        }
        if ($conditions->schemes !== [] && !in_array(strtolower($ctx->scheme), $conditions->schemes, true)) {
            return 'scheme';
        }
        foreach ($conditions->rules as $condition) {
            $result = $this->conditionHolds($condition, $ctx);
            if ($result === null) {
                return 'regex_error';
            }
            if (!$result) {
                return 'condition';
            }
        }

        return null;
    }

    /**
     * @param list<string> $hosts
     */
    private static function hostMatches(string $host, array $hosts): bool
    {
        $host = rtrim(strtolower((string) preg_replace('/:\d+$/', '', $host)), '.');
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $pattern) {
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && strlen($host) > strlen($pattern) - 1 && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool|null null on a PCRE error
     */
    private function conditionHolds(Condition $condition, RequestContext $ctx): ?bool
    {
        $actual = $condition->kind === ConditionKind::Header
            ? $ctx->header($condition->name)
            : ($ctx->cookies[$condition->name] ?? null);

        if ($condition->operator === ConditionOperator::Exists) {
            $result = $actual !== null;
        } elseif ($actual === null) {
            $result = false;
        } elseif ($condition->operator === ConditionOperator::Regex) {
            $pattern = $this->conditionRegexCache[$condition->value] ??= RegexSafety::compile($condition->value, true);
            $found = preg_match($pattern, $actual);
            if ($found === false) {
                return null;
            }
            $result = $found === 1;
        } else {
            $haystack = PathNormalizer::lower($actual);
            $needle = PathNormalizer::lower($condition->value);
            $result = match ($condition->operator) {
                ConditionOperator::Equals => $haystack === $needle,
                ConditionOperator::Contains => str_contains($haystack, $needle),
                default => str_starts_with($haystack, $needle),
            };
        }

        return $condition->negate ? !$result : $result;
    }

    /**
     * Builds the final location, or null when it would not be safe (or the rule has no usable target).
     *
     * @param array<int|string, string> $captures
     */
    private function buildLocation(Rule $rule, array $captures, RequestContext $ctx): ?string
    {
        if (!$rule->status->needsTarget()) {
            return '';
        }
        if (trim($rule->target) === '') {
            return null;
        }

        $language = $ctx->language ?? $this->options->defaultLanguage ?? '';
        $location = self::substitute($rule->target, $captures, $language);
        $internal = str_starts_with($location, '/');
        if ($internal) {
            $location = self::collapseSlashes($location);
        } elseif ($rule->targetType !== TargetType::Url || $rule->status === StatusCode::PassThrough) {
            return null;
        }
        if ($rule->queryMode === QueryMode::Pass) {
            $location = self::appendQuery($location, $ctx->query);
        }
        if (!$this->options->guard->isSafeLocation($location, $ctx->host)) {
            return null;
        }

        return LocationEncoder::encode($location);
    }

    /**
     * @param array<int|string, string> $captures
     */
    private static function substitute(string $template, array $captures, string $language): string
    {
        if ($language === '' && !isset($captures['lang'])) {
            $template = (string) preg_replace('~/\{lang\}(?=[/?#]|$)~', '', $template);
            if ($template === '' || $template[0] === '?' || $template[0] === '#') {
                $template = '/' . $template;
            }
        }
        $queryPos = strpos($template, '?');

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $m) use ($captures, $language, $queryPos): string {
                $inQuery = $queryPos !== false && $m[0][1] > $queryPos;
                if (isset($m[3]) && $m[3][1] >= 0) {
                    $name = $m[3][0];
                    if (array_key_exists($name, $captures)) {
                        $value = $captures[$name];
                    } elseif ($name === 'lang') {
                        $value = $language;
                    } else {
                        return $m[0][0];
                    }
                } else {
                    $number = $m[1][1] >= 0 ? $m[1][0] : ($m[2][0] ?? '0');
                    $value = $captures[(int) $number] ?? '';
                }

                return self::encodeCapture($value, $inQuery);
            },
            $template,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );
    }

    private static function encodeCapture(string $value, bool $inQuery): string
    {
        if ($inQuery) {
            return rawurlencode($value);
        }

        return (string) preg_replace_callback(
            '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/]/',
            static fn (array $m): string => sprintf('%%%02X', ord($m[0])),
            $value,
        );
    }

    /** Collapses "//" inside an internal path; a leading "//" or "/\" stays so the guard rejects it. */
    private static function collapseSlashes(string $location): string
    {
        $end = strcspn($location, '?#');
        $path = substr($location, 0, $end);
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $location;
        }

        return preg_replace('#/{2,}#', '/', $path) . substr($location, $end);
    }

    /**
     * Appends the request query to the target. The target's own parameters win on conflict.
     *
     * @param array<string, mixed> $query
     */
    private static function appendQuery(string $location, array $query): string
    {
        if ($query === []) {
            return $location;
        }
        $fragment = '';
        $hash = strpos($location, '#');
        if ($hash !== false) {
            $fragment = substr($location, $hash);
            $location = substr($location, 0, $hash);
        }
        $own = '';
        $mark = strpos($location, '?');
        if ($mark !== false) {
            $own = substr($location, $mark + 1);
            $location = substr($location, 0, $mark);
        }
        $ownNames = [];
        foreach (explode('&', $own) as $pair) {
            if ($pair !== '') {
                $name = urldecode(explode('=', $pair, 2)[0]);
                $ownNames[strstr($name, '[', true) ?: $name] = true;
            }
        }
        $extra = array_filter($query, static fn (int|string $name): bool => !isset($ownNames[(string) $name]), ARRAY_FILTER_USE_KEY);
        $queryString = implode('&', array_filter([$own, http_build_query($extra, '', '&', PHP_QUERY_RFC3986)], static fn (string $part): bool => $part !== ''));

        return $location . ($queryString === '' ? '' : '?' . $queryString) . $fragment;
    }

    private static function pathOf(string $location): string
    {
        return substr($location, 0, strcspn($location, '?#'));
    }
}
