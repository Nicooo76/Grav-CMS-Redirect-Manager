<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\Support\Args;
use Grav\Plugin\RedirectManager\ImportExport\Support\HeaderCond;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use Grav\Plugin\RedirectManager\ImportExport\Support\PatternFields;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\StatusParser;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Apache `.htaccess` / server config: Redirect, RedirectPermanent, RedirectTemp, RedirectMatch and
 * RewriteRule with RewriteCond.
 *
 * Deviations from Apache, on purpose: `Redirect /a /b` is imported as an exact rule (Apache matches
 * the prefix too), and a RewriteRule without the QSA flag is imported with the query dropped
 * (Apache passes it through unless the target ends in "?").
 */
final class HtaccessAdapter implements ImportAdapter, ExportAdapter
{
    /** Blocks whose content is read as if the block were not there. */
    private const TRANSPARENT = ['ifmodule', 'ifdefine', 'ifversion', 'virtualhost'];

    private const STATUS_WORD = '/^(\d{3}|permanent|temp|temporary|seeother|gone)$/i';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        $base = '';
        /** @var list<array{line: int, text: string}> $conds */
        $conds = [];
        $skipDepth = 0;
        $skipName = '';

        foreach (self::logicalLines($content) as $entry) {
            $no = $entry['line'];
            $text = $entry['text'];
            if ($text === '' || $text[0] === '#') {
                continue;
            }
            if (count($rows) > $options->maxRows) {
                throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows]));
            }

            if (preg_match('~^<(/?)\s*([A-Za-z]+)~', $text, $m) === 1) {
                $name = strtolower($m[2]);
                $closing = $m[1] === '/';
                if ($skipDepth > 0) {
                    if ($name === $skipName) {
                        $skipDepth += $closing ? -1 : 1;
                    }
                    continue;
                }
                if (!$closing && !in_array($name, self::TRANSPARENT, true)) {
                    $skipDepth = 1;
                    $skipName = $name;
                    $rows[] = $factory->skipped($no, $text, new ImportIssue('block_ignored', 'The directives inside this block were ignored.', ['block' => $name]));
                }
                continue;
            }
            if ($skipDepth > 0) {
                continue;
            }

            $args = Args::split($text);
            $directive = strtolower((string) array_shift($args));
            switch ($directive) {
                case 'rewritebase':
                    $base = rtrim($args[0] ?? '', '/');
                    break;
                case 'rewritecond':
                    $conds[] = ['line' => $no, 'text' => $text];
                    break;
                case 'rewriterule':
                    $rows[] = $this->rewriteRule($no, $text, $args, $conds, $base, $factory);
                    $conds = [];
                    break;
                case 'redirect':
                case 'redirectpermanent':
                case 'redirecttemp':
                case 'redirectmatch':
                    $rows[] = $this->redirect($no, $text, $directive, $args, $factory);
                    break;
                default:
                    // Everything else (RewriteEngine, Header, ErrorDocument, ...) is not a redirect.
                    break;
            }
        }

        return $rows;
    }

    /**
     * Joins continuation lines (trailing backslash) and numbers every logical line.
     *
     * @return list<array{line: int, text: string}>
     */
    private static function logicalLines(string $content): array
    {
        $out = [];
        $buffer = '';
        $start = 0;
        foreach (explode("\n", $content) as $i => $line) {
            if ($buffer === '') {
                $start = $i + 1;
            }
            $trimmed = rtrim($line);
            $slashes = strlen($trimmed) - strlen(rtrim($trimmed, '\\'));
            if ($slashes % 2 === 1) {
                $buffer .= substr($trimmed, 0, -1);
                continue;
            }
            $out[] = ['line' => $start, 'text' => trim($buffer . $line)];
            $buffer = '';
        }
        if ($buffer !== '') {
            $out[] = ['line' => $start, 'text' => trim($buffer)];
        }

        return $out;
    }

    /**
     * @param list<string> $args
     */
    private function redirect(int $no, string $raw, string $directive, array $args, RowFactory $factory): ImportRow
    {
        $status = match ($directive) {
            'redirectpermanent' => 301,
            'redirecttemp' => 302,
            default => null,
        };
        if ($status === null && $args !== [] && preg_match(self::STATUS_WORD, $args[0]) === 1 && count($args) >= 2) {
            $status = StatusParser::code(array_shift($args));
        }
        $status ??= 302;
        $path = $args[0] ?? '';
        $target = $args[1] ?? '';
        if ($path === '') {
            return $factory->error($no, $raw, new ImportIssue('invalid_line', 'The directive has no path.'));
        }
        if ($directive === 'redirectmatch') {
            return $this->fromRegex($no, $raw, $path, $target, $status, false, false, $factory);
        }
        if ($path[0] !== '/') {
            return $factory->error($no, $raw, new ImportIssue('invalid_line', 'The path must start with a slash.', ['path' => $path]));
        }

        return $factory->make($no, $raw, [
            'source' => $path,
            'match_type' => 'exact',
            'target' => $target,
            'status' => $status,
            'case_sensitive' => true,
            'ignore_trailing_slash' => false,
        ]);
    }

    /**
     * @param list<string>                            $args
     * @param list<array{line: int, text: string}>    $conds
     */
    private function rewriteRule(int $no, string $raw, array $args, array $conds, string $base, RowFactory $factory): ImportRow
    {
        $warnings = [];
        $rawAll = $raw;
        foreach (array_reverse($conds) as $cond) {
            $rawAll = $cond['text'] . "\n" . $rawAll;
        }
        if (count($args) < 2) {
            return $factory->error($no, $rawAll, new ImportIssue('invalid_line', 'RewriteRule needs a pattern and a substitution.'));
        }
        [$pattern, $substitution] = $args;
        $flags = [];
        if (isset($args[2]) && preg_match('/^\[(.*)\]$/', $args[2], $m) === 1) {
            foreach (explode(',', $m[1]) as $flag) {
                $flag = trim($flag);
                $eq = strpos($flag, '=');
                $flags[strtolower($eq === false ? $flag : substr($flag, 0, $eq))] = $eq === false ? true : substr($flag, $eq + 1);
            }
        }
        foreach (array_keys($flags) as $flag) {
            if (!in_array($flag, ['r', 'l', 'nc', 'qsa', 'qsd', 'g', 'f', 'ne', 'end', 'ns', 'nv', ''], true)) {
                $warnings[] = new ImportIssue('unsupported_flag', 'The RewriteRule flag is not supported and was ignored.', ['flag' => $flag]);
            }
        }

        if (isset($flags['f'])) {
            return $factory->skipped($no, $rawAll, new ImportIssue('forbidden_not_supported', 'Rules answering 403 (flag F) are not supported and were skipped.'));
        }
        if (isset($flags['p'])) {
            return $factory->skipped($no, $rawAll, new ImportIssue('proxy_not_supported', 'Proxy rules (flag P) are not supported and were skipped.'));
        }
        $status = null;
        if (isset($flags['g'])) {
            $status = 410;
        } elseif (isset($flags['r'])) {
            $status = $flags['r'] === true || $flags['r'] === '' ? 302 : StatusParser::code($flags['r']);
            if ($status === null) {
                return $factory->error($no, $rawAll, new ImportIssue('invalid_status', 'The R= status is not known.', ['value' => (string) $flags['r']]));
            }
        }
        if ($status === null) {
            return $factory->skipped($no, $rawAll, new ImportIssue('internal_rewrite_skipped', 'An internal rewrite (no R flag) is not a redirect and was skipped.'));
        }
        if ($pattern !== '' && $pattern[0] === '!') {
            return $factory->skipped($no, $rawAll, new ImportIssue('negated_pattern', 'Negated patterns are not supported and the rule was skipped.'));
        }
        $needsTarget = $status >= 300 && $status < 400;
        if ($substitution === '-' && $needsTarget) {
            return $factory->skipped($no, $rawAll, new ImportIssue('internal_rewrite_skipped', 'A rule without substitution is not a redirect and was skipped.'));
        }
        if (preg_match('/%\{|%\d/', $substitution) === 1) {
            return $factory->skipped($no, $rawAll, new ImportIssue('unsupported_variable', 'The substitution uses server variables or condition captures, which are not supported.', ['substitution' => $substitution]));
        }

        $effects = $this->conditions($conds, $warnings);

        $regex = self::perDirToRegex($pattern, $base, $warnings);
        $fields = PatternFields::fromRegex($regex, isset($flags['nc']));
        $fields['status'] = $status;
        $fields['target'] = $needsTarget ? self::substitution($substitution, $base, $fields) : '';
        if (isset($flags['qsa'])) {
            $fields['query_mode'] = QueryMode::Pass->value;
        }
        if ($effects['query'] !== null) {
            $fields['query_mode'] = $effects['query']['mode'];
            if ($effects['query']['mode'] === QueryMode::Exact->value && $fields['match_type'] === 'regex') {
                unset($fields['query_mode']);
                $warnings[] = new ImportIssue('unsupported_condition', 'A query condition on a regex rule is not supported and was ignored.');
            } elseif ($effects['query']['mode'] === QueryMode::Exact->value) {
                $fields['source'] .= '?' . $effects['query']['exact'];
            } else {
                $fields['query_params'] = $effects['query']['params'];
            }
        }
        $fields['conditions'] = ['hosts' => $effects['hosts'], 'schemes' => $effects['schemes'], 'rules' => array_map(static fn (Condition $c): array => $c->toArray(), $effects['rules'])];
        if ($effects['notFound']) {
            $fields['only_if_not_found'] = true;
        }
        if (str_ends_with($fields['target'], '?')) {
            $fields['target'] = substr($fields['target'], 0, -1);
        }

        return $factory->make($no, $rawAll, $fields, $warnings);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function substitution(string $substitution, string $base, array $fields): string
    {
        if ($substitution !== '' && $substitution[0] !== '/' && preg_match('~^(?:https?:)?//~i', $substitution) !== 1 && $substitution[0] !== '$') {
            return ($base === '' ? '' : $base) . '/' . $substitution;
        }

        return $substitution;
    }

    private function fromRegex(int $no, string $raw, string $pattern, string $target, int $status, bool $nc, bool $qsa, RowFactory $factory): ImportRow
    {
        $fields = PatternFields::fromRegex($pattern, $nc);
        $fields['status'] = $status;
        $fields['target'] = $target;
        if ($qsa) {
            $fields['query_mode'] = QueryMode::Pass->value;
        }

        return $factory->make($no, $raw, $fields);
    }

    /**
     * Per-directory RewriteRule patterns have no leading slash; turn them into paths with one.
     *
     * @param list<ImportIssue> $warnings
     */
    private static function perDirToRegex(string $pattern, string $base, array &$warnings): string
    {
        [$body, $ci] = Pattern::stripCaseFlag($pattern);
        $flag = $ci ? '(?i)' : '';
        if (!str_starts_with($body, '^')) {
            $warnings[] = new ImportIssue('unanchored_pattern', 'The pattern is not anchored with ^ and was imported as written.', ['pattern' => $pattern]);

            return $flag . $body;
        }
        $rest = substr($body, 1);
        if (str_starts_with($rest, '/?')) {
            $rest = substr($rest, 2);
        } elseif (str_starts_with($rest, '/')) {
            $rest = substr($rest, 1);
        }
        $prefix = $base === '' || $base === '/' ? '' : Pattern::escape($base);

        return $flag . '^' . $prefix . '/' . $rest;
    }

    /**
     * Interprets the RewriteCond lines in front of a rule.
     *
     * @param list<array{line: int, text: string}> $conds
     * @param list<ImportIssue>                    $warnings
     * @return array{hosts: list<string>, schemes: list<string>, rules: list<Condition>, query: array{mode: string, exact: string, params: array<string, string|null>}|null, notFound: bool}
     */
    private function conditions(array $conds, array &$warnings): array
    {
        $hosts = [];
        $schemes = [];
        $rules = [];
        $query = null;
        $notFound = false;
        $hostGroups = 0;

        // Group conditions joined by [OR].
        $groups = [];
        $current = [];
        foreach ($conds as $cond) {
            $args = Args::split($cond['text']);
            array_shift($args);
            $flags = isset($args[2]) && preg_match('/^\[(.*)\]$/', $args[2], $m) === 1 ? array_map('strtolower', array_map('trim', explode(',', $m[1]))) : [];
            $current[] = ['test' => $args[0] ?? '', 'pattern' => $args[1] ?? '', 'nc' => in_array('nc', $flags, true), 'text' => $cond['text']];
            if (!in_array('or', $flags, true)) {
                $groups[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $groupHosts = [];
                $ok = true;
                foreach ($group as $c) {
                    $h = strcasecmp($c['test'], '%{HTTP_HOST}') === 0 ? self::hostsFromCond($c['pattern'], $c['nc']) : null;
                    if ($h === null) {
                        $ok = false;
                        break;
                    }
                    array_push($groupHosts, ...$h);
                }
                if ($ok) {
                    $hosts = array_merge($hosts, $groupHosts);
                    ++$hostGroups;
                    continue;
                }
                foreach ($group as $c) {
                    $warnings[] = new ImportIssue('unsupported_condition', 'This RewriteCond combination (OR) is not supported and was ignored.', ['condition' => $c['text']]);
                }
                continue;
            }
            $c = $group[0];
            $test = $c['test'];
            $pattern = $c['pattern'];
            $upper = strtoupper($test);

            if ($upper === '%{HTTP_HOST}' || $upper === '%{SERVER_NAME}') {
                $found = self::hostsFromCond($pattern, $c['nc']);
                if ($found !== null && $hostGroups === 0) {
                    $hosts = $found;
                    ++$hostGroups;
                    continue;
                }
            } elseif ($upper === '%{HTTPS}' || $upper === '%{SERVER_PORT}' || $upper === '%{REQUEST_SCHEME}') {
                $scheme = self::schemeFromCond($upper, $pattern);
                if ($scheme !== null) {
                    $schemes = [$scheme];
                    continue;
                }
            } elseif ($upper === '%{QUERY_STRING}') {
                $parsed = self::queryFromCond($pattern);
                if ($parsed !== null && ($query === null || $query['mode'] === 'params') && !($parsed['mode'] === 'exact' && $query !== null)) {
                    if ($query === null) {
                        $query = $parsed;
                    } else {
                        $query['params'] = array_merge($query['params'], $parsed['params']);
                    }
                    continue;
                }
            } elseif ($upper === '%{REQUEST_FILENAME}' && in_array($pattern, ['!-f', '!-d', '!-l', '!-s'], true)) {
                $notFound = true;
                continue;
            } elseif (preg_match('/^%\{HTTP(?::([A-Za-z0-9-]+)|_(USER_AGENT|REFERER))\}$/i', $test, $m) === 1) {
                $name = $m[1] !== '' ? $m[1] : (strtoupper($m[2] ?? '') === 'USER_AGENT' ? 'User-Agent' : 'Referer');
                $rules[] = self::headerCondition($name, $pattern, $c['nc']);
                continue;
            }
            $warnings[] = new ImportIssue('unsupported_condition', 'This RewriteCond is not supported and was ignored; the rule is imported without it.', ['condition' => $c['text']]);
        }

        return ['hosts' => array_values(array_unique($hosts)), 'schemes' => $schemes, 'rules' => $rules, 'query' => $query, 'notFound' => $notFound];
    }

    /**
     * @return list<string>|null
     */
    private static function hostsFromCond(string $pattern, bool $nc): ?array
    {
        if ($pattern === '' || $pattern[0] === '!') {
            return null;
        }
        if ($pattern[0] === '=') {
            $host = strtolower(substr($pattern, 1));

            return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) === 1 ? [$host] : null;
        }

        return Pattern::hostsOf($pattern);
    }

    private static function schemeFromCond(string $var, string $pattern): ?string
    {
        $p = strtolower(trim($pattern, '^$'));
        if ($var === '%{HTTPS}') {
            return match ($p) {
                'on', '=on', '!off', '!=off' => 'https',
                'off', '=off', '!on', '!=on' => 'http',
                default => null,
            };
        }
        if ($var === '%{SERVER_PORT}') {
            return match ($p) {
                '80', '=80' => 'http',
                '443', '=443' => 'https',
                default => null,
            };
        }

        return match ($p) {
            'https', '=https' => 'https',
            'http', '=http' => 'http',
            default => null,
        };
    }

    /**
     * @return array{mode: string, exact: string, params: array<string, string|null>}|null
     */
    private static function queryFromCond(string $pattern): ?array
    {
        if ($pattern === '' || $pattern[0] === '!') {
            return null;
        }
        [$body] = Pattern::stripCaseFlag($pattern);
        if (preg_match('/^\((?:\?:)?\^\|&\)(.+?)\((?:\?:)?=\|&\|\$\)$/', $body, $m) === 1) {
            $name = Pattern::unescape($m[1]);

            return $name === null ? null : ['mode' => 'params', 'exact' => '', 'params' => [urldecode($name) => null]];
        }
        if (preg_match('/^\((?:\?:)?\^\|&\)(.+?)\((?:\?:)?&\|\$\)$/', $body, $m) === 1) {
            $pair = Pattern::unescape($m[1]);
            if ($pair === null) {
                return null;
            }
            $parts = explode('=', $pair, 2);

            return ['mode' => 'params', 'exact' => '', 'params' => [urldecode($parts[0]) => isset($parts[1]) && $parts[1] !== '' ? urldecode($parts[1]) : null]];
        }
        if (str_starts_with($body, '^') && str_ends_with($body, '$')) {
            $literal = Pattern::unescape(substr($body, 1, -1));

            return $literal === null || $literal === '' ? null : ['mode' => 'exact', 'exact' => $literal, 'params' => []];
        }
        $literal = Pattern::unescape($body);
        if ($literal === null || $literal === '') {
            return null;
        }
        $parts = explode('=', $literal, 2);

        return ['mode' => 'params', 'exact' => '', 'params' => [urldecode($parts[0]) => isset($parts[1]) && $parts[1] !== '' ? urldecode($parts[1]) : null]];
    }

    private static function headerCondition(string $name, string $pattern, bool $nc): Condition
    {
        $negate = false;
        if ($pattern !== '' && $pattern[0] === '!') {
            $negate = true;
            $pattern = substr($pattern, 1);
        }

        return HeaderCond::fromPattern(ConditionKind::Header, $name, $pattern, $nc, $negate);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        $body = [];
        $skipped = [];
        $lossy = [];
        $count = 0;
        $needsEngine = false;

        foreach ($rules as $rule) {
            $result = $this->rule($rule);
            if (is_string($result)) {
                $skipped[] = new ExportNote($rule->id, $result, self::reason($result));
                $body[] = '# skipped ' . $rule->id . ' (' . $result . '): ' . str_replace(["\n", "\r"], ' ', $rule->source);
                continue;
            }
            ++$count;
            array_push($body, ...$result['lines']);
            $needsEngine = $needsEngine || $result['rewrite'];
            array_push($lossy, ...Lossy::notes($rule, ['active_from', 'expires_at', 'continue', 'target_page', 'query_ignore']));
        }

        if ($options->includeHeader) {
            $lines[] = '# Redirects exported by Redirect Manager.';
            $lines[] = '# Redirect matches the path prefix as well; RedirectMatch and RewriteRule patterns are exact.';
        }
        if ($needsEngine) {
            $lines[] = 'RewriteEngine On';
        }
        array_push($lines, ...$body);

        return new ExportResult(implode("\n", $lines) . "\n", skipped: $skipped, lossy: $lossy, exported: $count);
    }

    /**
     * @return array{lines: list<string>, rewrite: bool}|string lines or a skip code
     */
    private function rule(Rule $rule): array|string
    {
        $c = $rule->conditions;
        if ($c->languages !== []) {
            return 'language_not_supported';
        }
        foreach ($c->rules as $cond) {
            if ($cond->kind === ConditionKind::Cookie) {
                return 'cookie_condition_not_supported';
            }
        }
        if ($rule->status === StatusCode::PassThrough) {
            return 'pass_through_not_supported';
        }

        $target = $rule->target;
        $regex = null;
        $path = $rule->source;
        switch ($rule->matchType) {
            case MatchType::Exact:
                $q = strpos($path, '?');
                $path = $q === false ? $path : substr($path, 0, $q);
                $regex = '^' . Pattern::escape($path) . ($rule->ignoreTrailingSlash && $path !== '/' ? '/?' : '') . '$';
                break;
            case MatchType::Wildcard:
                $regex = Pattern::wildcardToRegex($rule->source);
                break;
            case MatchType::Regex:
                $regex = $rule->source;
                if (preg_match('/\(\?P?<[A-Za-z_]/', $regex) === 1 || str_contains($target, '{')) {
                    $converted = Pattern::namedToNumbered($target, $regex);
                    if ($converted === null) {
                        return 'named_group_unresolved';
                    }
                    $target = $converted;
                }
                break;
        }

        $queryConds = [];
        $queryMode = $rule->queryMode;
        if ($queryMode === QueryMode::Exact) {
            $q = strpos($rule->source, '?');
            if ($q !== false) {
                $queryConds[] = '^' . Pattern::escape(substr($rule->source, $q + 1)) . '$';
            }
        } elseif ($queryMode === QueryMode::Params) {
            foreach ($rule->queryParams as $name => $value) {
                $n = Pattern::escape(rawurlencode($name));
                $queryConds[] = $value === null ? '(^|&)' . $n . '(=|&|$)' : '(^|&)' . $n . '=' . Pattern::escape(rawurlencode($value)) . '(&|$)';
            }
        }

        $needsRewrite = !$rule->caseSensitive
            || $c->hosts !== [] || $c->schemes !== [] || $c->rules !== []
            || $queryConds !== [] || $queryMode === QueryMode::Pass || $rule->onlyIfNotFound
            || $rule->status === StatusCode::UnavailableForLegalReasons;

        $code = $rule->status->value;
        if (!$needsRewrite) {
            $lines = [];
            if ($rule->matchType === MatchType::Exact && !$rule->ignoreTrailingSlash) {
                $lines[] = $rule->status === StatusCode::Gone
                    ? 'Redirect gone ' . Args::quote($path)
                    : 'Redirect ' . $code . ' ' . Args::quote($path) . ' ' . Args::quote($target);
            } else {
                $lines[] = 'RedirectMatch ' . ($rule->status === StatusCode::Gone ? 'gone' : (string) $code) . ' ' . Args::quote($regex) . ($rule->status === StatusCode::Gone ? '' : ' ' . Args::quote($target));
            }

            return ['lines' => $lines, 'rewrite' => false];
        }

        // RewriteRule: per-directory patterns have no leading slash.
        $pattern = $regex;
        if (str_starts_with($pattern, '^/')) {
            $pattern = '^' . substr($pattern, 2);
        } elseif (str_starts_with($pattern, '^')) {
            return 'regex_not_translatable';
        }
        $lines = [];
        if ($c->hosts !== []) {
            $lines[] = 'RewriteCond %{HTTP_HOST} ' . Args::quote(Pattern::hostsToRegex($c->hosts)) . ' [NC]';
        }
        if (count($c->schemes) === 1) {
            $lines[] = 'RewriteCond %{HTTPS} ' . ($c->schemes[0] === 'https' ? 'on' : 'off');
        }
        foreach ($c->rules as $cond) {
            $lines[] = self::headerCondLine($cond);
        }
        foreach ($queryConds as $qc) {
            $lines[] = 'RewriteCond %{QUERY_STRING} ' . Args::quote($qc);
        }
        if ($rule->onlyIfNotFound) {
            $lines[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
            $lines[] = 'RewriteCond %{REQUEST_FILENAME} !-d';
        }

        $flags = [];
        if ($rule->status === StatusCode::Gone) {
            $flags[] = 'G';
            $substitution = '-';
        } elseif ($rule->status === StatusCode::UnavailableForLegalReasons) {
            $flags = ['R=451', 'L'];
            $substitution = '-';
        } else {
            $flags = ['R=' . $code, 'L'];
            $substitution = $target;
            if (($queryMode === QueryMode::Exact || $queryMode === QueryMode::Params) && !str_contains($substitution, '?')) {
                $substitution .= '?';
            }
        }
        if (!$rule->caseSensitive) {
            $flags[] = 'NC';
        }
        if ($queryMode === QueryMode::Pass && !in_array($rule->status, [StatusCode::Gone, StatusCode::UnavailableForLegalReasons], true)) {
            $flags[] = 'QSA';
        }
        $lines[] = 'RewriteRule ' . Args::quote($pattern) . ' ' . Args::quote($substitution) . ' [' . implode(',', $flags) . ']';

        return ['lines' => $lines, 'rewrite' => true];
    }

    private static function headerCondLine(Condition $cond): string
    {
        $var = match (strtolower($cond->name)) {
            'user-agent' => '%{HTTP_USER_AGENT}',
            'referer' => '%{HTTP_REFERER}',
            default => '%{HTTP:' . $cond->name . '}',
        };
        [$pattern, $nc] = HeaderCond::toPattern($cond);

        return 'RewriteCond ' . $var . ' ' . Args::quote(($cond->negate ? '!' : '') . $pattern) . ($nc ? ' [NC]' : '');
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'language_not_supported' => 'Apache has no language condition; the rule would apply to every language.',
            'cookie_condition_not_supported' => 'Cookie conditions are not exported to .htaccess.',
            'pass_through_not_supported' => 'Pass-through (200) is an internal rewrite, not a redirect.',
            'named_group_unresolved' => 'The target uses a named placeholder that the regex does not define.',
            'regex_not_translatable' => 'The regular expression cannot be written as a per-directory RewriteRule pattern.',
            default => 'The rule cannot be expressed in this format.',
        };
    }
}
