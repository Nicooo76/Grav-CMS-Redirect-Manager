<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Conditions;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Turns loosely typed field arrays from any adapter into validated rules: normalises sources
 * (absolute URLs, language prefixes, queries), resolves status codes, checks regexes and targets.
 * Ids are time-sortable and increase in file order, so rules of equal priority keep the file order.
 */
final class RowFactory
{
    private const PASS_KEYS = [
        'enabled', 'priority', 'case_sensitive', 'ignore_trailing_slash', 'continue', 'only_if_not_found',
        'active_from', 'expires_at', 'note', 'tags', 'query_ignore', 'created_at', 'updated_at',
    ];

    private int $baseMs;
    private int $seq = 0;
    /** One instance shared by all rules of an import: 50,000 rules would otherwise hold 100,000 date objects. */
    private DateTimeImmutable $now;

    public function __construct(private readonly ImportOptions $options, Clock $clock)
    {
        $this->baseMs = (int) floor(microtime(true) * 1000);
        $this->now = $clock->now();
    }

    /**
     * @param array<string, mixed> $fields   keys as in Rule::toArray(); status/match_type may be words
     * @param list<ImportIssue>    $warnings warnings the adapter already collected for this row
     */
    public function make(int $line, string $raw, array $fields, array $warnings = []): ImportRow
    {
        $errors = [];
        $hosts = [];
        $languages = [];

        $source = trim(self::str($fields['source'] ?? ''));
        if ($source === '') {
            return new ImportRow($line, $raw, null, [new ImportIssue('missing_source', 'The source is empty.')], $warnings);
        }

        $matchType = $this->matchType($fields['match_type'] ?? null, $source, $errors);
        $queryMode = $this->queryMode($fields['query_mode'] ?? null, $errors);
        $status = $this->status($fields, $errors, $warnings);
        $targetType = $this->targetType($fields['target_type'] ?? null, $errors);
        $queryParams = $this->params($fields['query_params'] ?? []);

        if ($matchType === MatchType::Regex) {
            if (!Pattern::isValid($source)) {
                $errors[] = new ImportIssue('invalid_regex', 'The regular expression is not valid.', ['pattern' => $source]);
            }
        } else {
            $source = $this->source($source, $hosts, $languages, $queryMode, $queryParams, $warnings);
        }

        $target = trim(self::str($fields['target'] ?? ''));
        if ($status !== null && $status->needsTarget()) {
            if ($target === '') {
                $errors[] = new ImportIssue('missing_target', 'The target is empty.');
            } else {
                $target = $this->target($target, $targetType, $errors);
            }
        } else {
            $target = '';
        }

        if ($errors !== [] || $status === null) {
            return new ImportRow($line, $raw, null, $errors, $warnings);
        }

        $conditions = $this->conditions($fields['conditions'] ?? null, $hosts, $languages);
        $data = [
            'id' => $this->nextId(),
            'source' => $source,
            'target' => $target,
            'match_type' => $matchType->value,
            'status' => $status->value,
            'target_type' => $targetType->value ?? ($this->isExternal($target) ? TargetType::Url->value : TargetType::Route->value),
            'query_mode' => $queryMode->value ?? ($this->sourceHasQuery($source, $matchType) ? QueryMode::Exact->value : QueryMode::Ignore->value),
            'query_params' => $queryParams,
            'origin' => $this->options->origin->value,
            'conditions' => $conditions->toArray(),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
        foreach (self::PASS_KEYS as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null && $fields[$key] !== '') {
                $data[$key] = $fields[$key];
            }
        }
        foreach (['active_from', 'expires_at'] as $key) {
            if (isset($data[$key]) && !$this->validDate($data[$key])) {
                $warnings[] = new ImportIssue('invalid_date', 'The date could not be read and was ignored.', ['field' => $key, 'value' => self::str($data[$key])]);
                unset($data[$key]);
            }
        }
        $group = trim(self::str($fields['group'] ?? ''));
        $data['group'] = $group !== '' ? $group : $this->options->defaultGroup;

        return new ImportRow($line, $raw, Rule::fromArray($data), [], $warnings);
    }

    public function error(int $line, string $raw, ImportIssue $issue): ImportRow
    {
        return new ImportRow($line, $raw, null, [$issue]);
    }

    public function skipped(int $line, string $raw, ImportIssue $warning): ImportRow
    {
        return new ImportRow($line, $raw, null, [], [$warning]);
    }

    /**
     * Converts an absolute URL on one of the site's hosts to a path. Other URLs are returned unchanged.
     */
    public function toPath(string $url): string
    {
        if (preg_match('~^(?:https?:)?//([^/?#]*)(.*)$~i', $url, $m) !== 1) {
            return $url;
        }
        if (!$this->isBaseHost(self::hostOf($m[1]))) {
            return $url;
        }
        $rest = $m[2];

        return $rest === '' || $rest[0] !== '/' ? '/' . $rest : $rest;
    }

    public function isBaseHost(string $host): bool
    {
        $host = strtolower($host);
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        foreach ($this->options->baseHosts as $base) {
            $base = strtolower(trim($base));
            $baseBare = str_starts_with($base, 'www.') ? substr($base, 4) : $base;
            if ($base !== '' && ($base === $host || $baseBare === $bare)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes scheme and host from an absolute source URL. A host that is not one of the site's
     * hosts becomes a host condition (or is dropped with a warning when no site hosts are known).
     *
     * @param list<ImportIssue> $warnings
     * @return array{0: string, 1: list<string>} path and host conditions
     */
    public function stripHost(string $source, array &$warnings): array
    {
        if (preg_match('~^(?:https?:)?//([^/?#]*)(.*)$~i', $source, $m) !== 1) {
            return [$source, []];
        }
        $host = self::hostOf($m[1]);
        $path = $m[2] === '' ? '/' : $m[2];
        if ($host === '' || $this->isBaseHost($host)) {
            return [$path, []];
        }
        if ($this->options->baseHosts === []) {
            $warnings[] = new ImportIssue('source_host_stripped', 'The host of the source URL was removed.', ['host' => $host]);

            return [$path, []];
        }
        $warnings[] = new ImportIssue('source_host_condition', 'The host of the source URL became a host condition.', ['host' => $host]);

        return [$path, [$host]];
    }

    private function nextId(): string
    {
        return 'r' . str_pad(dechex($this->baseMs + $this->seq++), 11, '0', STR_PAD_LEFT) . bin2hex(random_bytes(3));
    }

    /**
     * @param list<ImportIssue> $errors
     */
    private function matchType(mixed $value, string $source, array &$errors): MatchType
    {
        $text = strtolower(trim(self::str($value)));
        if ($value instanceof MatchType) {
            return $value;
        }
        $map = [
            'exact' => MatchType::Exact, 'literal' => MatchType::Exact, 'plain' => MatchType::Exact,
            'wildcard' => MatchType::Wildcard, 'glob' => MatchType::Wildcard, 'prefix' => MatchType::Wildcard,
            'regex' => MatchType::Regex, 'regexp' => MatchType::Regex, 'pattern' => MatchType::Regex,
        ];
        if ($text === '') {
            return str_contains($source, '*') ? MatchType::Wildcard : MatchType::Exact;
        }
        if (!isset($map[$text])) {
            $errors[] = new ImportIssue('invalid_match_type', 'The match type is not known.', ['value' => $text]);

            return MatchType::Exact;
        }

        return $map[$text];
    }

    /**
     * @param list<ImportIssue> $errors
     */
    private function queryMode(mixed $value, array &$errors): ?QueryMode
    {
        if ($value instanceof QueryMode) {
            return $value;
        }
        $text = strtolower(trim(self::str($value)));
        if ($text === '') {
            return null;
        }
        $mode = QueryMode::tryFrom($text);
        if ($mode === null) {
            $errors[] = new ImportIssue('invalid_query_mode', 'The query mode is not known.', ['value' => $text]);
        }

        return $mode;
    }

    /**
     * @param list<ImportIssue> $errors
     */
    private function targetType(mixed $value, array &$errors): ?TargetType
    {
        $text = strtolower(trim(self::str($value)));
        if ($text === '') {
            return null;
        }
        $type = TargetType::tryFrom($text);
        if ($type === null) {
            $errors[] = new ImportIssue('invalid_target_type', 'The target type is not known.', ['value' => $text]);
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<ImportIssue>    $errors
     * @param list<ImportIssue>    $warnings
     */
    private function status(array $fields, array &$errors, array &$warnings): ?StatusCode
    {
        $raw = $fields['status'] ?? null;
        $default = is_int($fields['default_status'] ?? null) ? $fields['default_status'] : $this->options->defaultStatus;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            $code = $default;
        } else {
            $code = StatusParser::code($raw);
            if ($code === null) {
                $errors[] = new ImportIssue('invalid_status', 'The status code is not known.', ['value' => self::str($raw)]);

                return null;
            }
        }
        if ($code === 303) {
            $warnings[] = new ImportIssue('status_mapped', '303 See Other is not supported and was imported as 302.', ['from' => 303, 'to' => 302]);
            $code = 302;
        }
        $status = StatusCode::tryFrom($code);
        if ($status === null) {
            $errors[] = new ImportIssue('invalid_status', 'The status code is not supported.', ['value' => $code]);
        }

        return $status;
    }

    /**
     * @param list<string>                $hosts
     * @param list<string>                $languages
     * @param array<string, string|null>  $queryParams
     * @param list<ImportIssue>           $warnings
     */
    private function source(string $source, array &$hosts, array &$languages, ?QueryMode &$queryMode, array &$queryParams, array &$warnings): string
    {
        [$source, $found] = $this->stripHost($source, $warnings);
        array_push($hosts, ...$found);
        $hash = strpos($source, '#');
        if ($hash !== false) {
            $source = substr($source, 0, $hash);
            $warnings[] = new ImportIssue('fragment_removed', 'The #fragment of the source was removed (servers never see it).');
        }
        $query = '';
        $q = strpos($source, '?');
        if ($q !== false) {
            $query = substr($source, $q + 1);
            $source = substr($source, 0, $q);
        }
        if ($source === '' || $source[0] !== '/') {
            $source = '/' . $source;
        }
        $source = $this->decode($source);

        foreach ($this->options->siteLanguages as $language) {
            $language = strtolower(trim($language));
            if ($language === '') {
                continue;
            }
            if (strcasecmp($source, '/' . $language) === 0 || stripos($source, '/' . $language . '/') === 0) {
                $source = substr($source, strlen($language) + 1);
                $source = $source === '' ? '/' : $source;
                $languages[] = $language;
                break;
            }
        }

        if ($query !== '') {
            if ($queryMode === QueryMode::Params) {
                $queryParams = array_replace($queryParams, self::parseQuery($query));
            } elseif ($queryMode === null || $queryMode === QueryMode::Exact) {
                $queryMode = QueryMode::Exact;
                $source .= '?' . $query;
            } else {
                $warnings[] = new ImportIssue('source_query_dropped', 'The query string of the source was ignored because the query mode does not match on it.');
            }
        }

        return $source;
    }

    private function sourceHasQuery(string $source, MatchType $type): bool
    {
        return $type !== MatchType::Regex && str_contains($source, '?');
    }

    /**
     * @param list<ImportIssue> $errors
     */
    private function target(string $target, ?TargetType $type, array &$errors): string
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $target) === 1) {
            $errors[] = new ImportIssue('invalid_target', 'The target contains spaces or control characters.', ['target' => $target]);

            return $target;
        }
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $target, $m) === 1 && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
            $errors[] = new ImportIssue('unsafe_target', 'Only http and https targets are allowed.', ['scheme' => strtolower($m[1])]);

            return $target;
        }
        if ($type === TargetType::Url) {
            return $target;
        }
        $target = $this->toPath($target);
        if ($this->isExternal($target)) {
            return $target;
        }
        if ($target[0] === '.') {
            $errors[] = new ImportIssue('invalid_target', 'Relative targets with dot segments are not supported.', ['target' => $target]);

            return $target;
        }
        if (!in_array($target[0], ['/', '$', '{', '?', '#'], true)) {
            $target = '/' . $target;
        }

        return $target;
    }

    private function isExternal(string $target): bool
    {
        return preg_match('~^(?:https?:)?//~i', $target) === 1;
    }

    private function decode(string $path): string
    {
        if (!str_contains($path, '%')) {
            return $path;
        }

        return (string) preg_replace_callback('/(?:%[0-9A-Fa-f]{2})+/', static function (array $m): string {
            $decoded = rawurldecode($m[0]);
            if (!mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\/?#%*\x00-\x1F\x7F]/', $decoded) === 1) {
                return $m[0];
            }

            return $decoded;
        }, $path);
    }

    /**
     * @param list<string> $hosts
     * @param list<string> $languages
     */
    private function conditions(mixed $given, array $hosts, array $languages): Conditions
    {
        $data = $given instanceof Conditions ? $given->toArray() : (is_array($given) ? $given : []);
        /** @var array<string, mixed> $data */
        $data['hosts'] = array_merge(self::list($data['hosts'] ?? []), $hosts);
        $data['languages'] = array_merge(self::list($data['languages'] ?? []), $languages);

        return Conditions::fromArray($data);
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string|null>
     */
    private function params(mixed $value): array
    {
        if (is_string($value)) {
            return self::parseQuery($value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $name => $v) {
            $out[(string) $name] = is_scalar($v) && (string) $v !== '' ? (string) $v : null;
        }

        return $out;
    }

    /**
     * Query string to params; a bare name means "present, any value".
     *
     * @return array<string, string|null>
     */
    public static function parseQuery(string $query): array
    {
        $out = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            if ($name === '') {
                continue;
            }
            $out[$name] = isset($parts[1]) && $parts[1] !== '' ? urldecode($parts[1]) : null;
        }

        return $out;
    }

    private function validDate(mixed $value): bool
    {
        if (is_int($value) || $value instanceof DateTimeImmutable) {
            return true;
        }
        try {
            new DateTimeImmutable(self::str($value));

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private static function hostOf(string $authority): string
    {
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        return strtolower(preg_replace('/:\d+$/', '', $authority) ?? $authority);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
