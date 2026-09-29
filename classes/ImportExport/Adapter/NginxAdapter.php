<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
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
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxConfig;
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxNode;
use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use Grav\Plugin\RedirectManager\ImportExport\Support\PatternFields;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * nginx config: `location` blocks with `return`/`rewrite`, `if` on host, scheme, header, cookie or query,
 * and server-level domain redirects. Only redirect directives are read; the rest is ignored.
 *
 * `return` drops the query string, `rewrite` keeps it unless the target ends in "?"; the query mode of
 * imported rules follows that (`$is_args$args` in a return target means "pass").
 */
final class NginxAdapter implements ImportAdapter, ExportAdapter
{
    /** Directives that route requests; they cannot become redirects and are reported. */
    private const REPORTED = ['try_files', 'proxy_pass', 'fastcgi_pass', 'uwsgi_pass', 'scgi_pass', 'alias', 'error_page', 'map', 'include', 'set', 'auth_basic', 'proxy_redirect'];

    private const DESCEND = ['http', 'server', 'location', 'if'];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $nodes = NginxConfig::parse($content);
        if (self::hasCode($content) && !self::recognizes($nodes)) {
            throw new ImportException(new ImportIssue('no_directives', 'No nginx directives were recognized (server, location, return, rewrite). This is probably not an nginx config.', ['format' => 'nginx']));
        }
        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        $this->walk($nodes, ['hosts' => [], 'conds' => [], 'location' => null, 'unsupported' => null], $factory, $rows, $options);

        return $rows;
    }

    /** True when the text has a line that is neither blank nor a comment. */
    private static function hasCode(string $content): bool
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any directive of the config, at any depth, is one this adapter reads or reports.
     *
     * @param list<NginxNode> $nodes
     */
    private static function recognizes(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (in_array($node->name, ['return', 'rewrite', 'location', 'if', 'server', 'http'], true) || in_array($node->name, self::REPORTED, true)) {
                return true;
            }
            if ($node->children !== null && self::recognizes($node->children)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<NginxNode>      $nodes
     * @param array<string, mixed> $ctx
     * @param list<ImportRow>      $rows
     */
    private function walk(array $nodes, array $ctx, RowFactory $factory, array &$rows, ImportOptions $options): void
    {
        $serverHosts = [];
        if ($ctx['location'] === null) {
            foreach ($nodes as $node) {
                if ($node->name === 'server_name') {
                    foreach ($node->args as $host) {
                        if ($host !== '_' && preg_match('/^(\*\.)?[A-Za-z0-9][A-Za-z0-9.-]*$/', $host) === 1) {
                            $serverHosts[] = strtolower($host);
                        }
                    }
                }
            }
        }
        $ctx['serverHosts'] = $serverHosts !== [] ? $serverHosts : ($ctx['serverHosts'] ?? []);

        foreach ($nodes as $node) {
            if (count($rows) > $options->maxRows) {
                throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows]));
            }
            $name = $node->name;
            if ($name === 'return') {
                $rows[] = $this->returnRule($node, $ctx, $factory);
            } elseif ($name === 'rewrite') {
                $rows[] = $this->rewriteRule($node, $ctx, $factory);
            } elseif ($name === 'location' && $node->children !== null) {
                $head = $node->args;
                $modifier = '';
                if ($head !== [] && in_array($head[0], ['=', '~', '~*', '^~'], true)) {
                    $modifier = (string) array_shift($head);
                }
                $pattern = $head[0] ?? '';
                if ($pattern === '' || $pattern[0] === '@') {
                    $rows[] = $factory->skipped($node->line, $node->text(), new ImportIssue('location_ignored', 'Named or empty locations are not redirects and were ignored.'));
                    continue;
                }
                $sub = $ctx;
                $sub['location'] = ['modifier' => $modifier, 'pattern' => $pattern, 'head' => 'location ' . implode(' ', array_map(Args::quote(...), $node->args))];
                $this->walk($node->children, $sub, $factory, $rows, $options);
            } elseif ($name === 'if' && $node->children !== null) {
                $sub = $ctx;
                $effect = self::condition($node->args);
                if ($effect === null) {
                    $sub['unsupported'] = $node->text();
                } else {
                    $sub['conds'][] = $effect;
                }
                $this->walk($node->children, $sub, $factory, $rows, $options);
            } elseif (in_array($name, self::DESCEND, true) && $node->children !== null) {
                $this->walk($node->children, $ctx, $factory, $rows, $options);
            } elseif (in_array($name, self::REPORTED, true)) {
                $rows[] = $factory->skipped($node->line, $node->text(), new ImportIssue('directive_ignored', 'This directive is not a redirect and was ignored.', ['directive' => $name]));
            }
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function returnRule(NginxNode $node, array $ctx, RowFactory $factory): ImportRow
    {
        $args = $node->args;
        $raw = ($ctx['location']['head'] ?? '') !== '' ? $ctx['location']['head'] . ' { ' . $node->text() . '; }' : $node->text() . ';';
        if ($args === []) {
            return $factory->error($node->line, $raw, new ImportIssue('invalid_line', 'return needs a status or URL.'));
        }
        if (preg_match('/^\d{3}$/', $args[0]) === 1) {
            $status = (int) $args[0];
            $target = $args[1] ?? '';
        } else {
            $status = 302;
            $target = $args[0];
        }
        if (!in_array($status, [301, 302, 303, 307, 308, 410, 451], true)) {
            return $factory->skipped($node->line, $raw, new ImportIssue('unsupported_status', 'This return status is not a redirect and was ignored.', ['status' => $status]));
        }

        return $this->build($node->line, $raw, $ctx, $status, $target, null, $factory, false);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function rewriteRule(NginxNode $node, array $ctx, RowFactory $factory): ImportRow
    {
        $raw = $node->text() . ';';
        $args = $node->args;
        if (count($args) < 2) {
            return $factory->error($node->line, $raw, new ImportIssue('invalid_line', 'rewrite needs a regex and a replacement.'));
        }
        [$regex, $replacement] = $args;
        $flag = strtolower($args[2] ?? '');
        $status = match ($flag) {
            'permanent' => 301,
            'redirect' => 302,
            default => preg_match('~^https?://~i', $replacement) === 1 && !in_array($flag, ['last', 'break'], true) ? 302 : null,
        };
        if ($status === null) {
            return $factory->skipped($node->line, $raw, new ImportIssue('internal_rewrite_skipped', 'An internal rewrite (last/break) is not a redirect and was skipped.'));
        }

        return $this->build($node->line, $raw, $ctx, $status, $replacement, $regex, $factory, true);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function build(int $line, string $raw, array $ctx, int $status, string $target, ?string $regex, RowFactory $factory, bool $isRewrite): ImportRow
    {
        $warnings = [];
        if ($ctx['unsupported'] !== null) {
            return $factory->skipped($line, $raw, new ImportIssue('unsupported_condition', 'This rule sits inside an unsupported if condition and was skipped.', ['condition' => (string) $ctx['unsupported']]));
        }

        $location = $ctx['location'];
        $serverLevel = false;
        if ($regex !== null) {
            $fields = PatternFields::fromRegex($regex, false);
        } elseif (is_array($location)) {
            $fields = self::locationFields((string) $location['modifier'], (string) $location['pattern']);
        } else {
            $fields = ['source' => '/*', 'match_type' => 'wildcard', 'case_sensitive' => true];
            $serverLevel = true;
        }

        $hosts = [];
        $schemes = [];
        $rules = [];
        $query = null;
        foreach ($ctx['conds'] as $effect) {
            $hosts = array_merge($hosts, $effect['hosts']);
            $schemes = array_merge($schemes, $effect['schemes']);
            $rules = array_merge($rules, $effect['rules']);
            $query = $effect['query'] ?? $query;
        }
        if ($serverLevel || ($fields['source'] === '/*' && $regex === null)) {
            if ($hosts === []) {
                $hosts = $ctx['serverHosts'];
            }
            if ($hosts === []) {
                return $factory->skipped($line, $raw, new ImportIssue('unconditional_redirect', 'A server-wide redirect without host condition would redirect every request and was skipped.'));
            }
        }

        $groups = Pattern::groups($fields['match_type'] === 'regex' ? (string) $fields['source'] : '');
        $converted = self::target($target, $groups, $fields['source'] === '/*' && $fields['match_type'] === 'wildcard', $isRewrite);
        if (isset($converted['error'])) {
            return $factory->skipped($line, $raw, new ImportIssue('unsupported_variable', 'The target uses nginx variables that are not supported.', ['variable' => $converted['error']]));
        }
        $fields['status'] = $status;
        $fields['target'] = $status === 410 || $status === 451 ? '' : $converted['target'];
        $fields['query_mode'] = ($converted['pass'] ? QueryMode::Pass : QueryMode::Ignore)->value;
        if ($query !== null) {
            $fields['query_mode'] = $query['mode'];
            if ($query['mode'] === 'exact' && $fields['match_type'] !== 'regex') {
                $fields['source'] .= '?' . $query['exact'];
            } elseif ($query['mode'] === 'params') {
                $fields['query_params'] = $query['params'];
            } else {
                $fields['query_mode'] = QueryMode::Ignore->value;
                $warnings[] = new ImportIssue('unsupported_condition', 'A query condition on a regex rule is not supported and was ignored.');
            }
        }
        $fields['conditions'] = [
            'hosts' => $hosts,
            'schemes' => $schemes,
            'rules' => array_map(static fn (Condition $c): array => $c->toArray(), $rules),
        ];

        return $factory->make($line, $raw, $fields, $warnings);
    }

    /**
     * @return array{source: string, match_type: string, case_sensitive: bool, ignore_trailing_slash?: bool}
     */
    private static function locationFields(string $modifier, string $pattern): array
    {
        if ($modifier === '=') {
            return ['source' => $pattern, 'match_type' => 'exact', 'case_sensitive' => true, 'ignore_trailing_slash' => false];
        }
        if ($modifier === '~' || $modifier === '~*') {
            return PatternFields::fromRegex($pattern, $modifier === '~*');
        }

        return ['source' => $pattern . '*', 'match_type' => 'wildcard', 'case_sensitive' => true];
    }

    /**
     * Converts nginx variables in a target to placeholders.
     *
     * @param list<string|null> $groups
     * @return array{target: string, pass: bool, error?: string}
     */
    private static function target(string $target, array $groups, bool $catchAll, bool $isRewrite): array
    {
        $pass = $isRewrite;
        if ($isRewrite && str_ends_with($target, '?')) {
            $target = substr($target, 0, -1);
            $pass = false;
        }
        foreach (['$is_args$args', '?$args', '$is_args${args}'] as $suffix) {
            if (str_ends_with($target, $suffix)) {
                $target = substr($target, 0, -strlen($suffix));
                $pass = true;
            }
        }
        if ($catchAll && str_ends_with($target, '$request_uri')) {
            $target = substr($target, 0, -strlen('$request_uri')) . '/$1';
            $pass = true;
        }
        $error = null;
        $out = preg_replace_callback('/\$(?:\{([A-Za-z_][A-Za-z0-9_]*|\d+)\}|([A-Za-z_][A-Za-z0-9_]*|\d+))/', static function (array $m) use ($groups, &$error): string {
            $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if (ctype_digit($name)) {
                return '$' . $name;
            }
            if (in_array($name, $groups, true)) {
                return '{' . $name . '}';
            }
            $error ??= $name;

            return $m[0];
        }, $target);
        if ($error !== null || $out === null) {
            return ['target' => $target, 'pass' => $pass, 'error' => $error ?? 'unknown'];
        }

        return ['target' => $out, 'pass' => $pass];
    }

    /**
     * Parses the arguments of `if (...)`.
     *
     * @param list<string> $args
     * @return array{hosts: list<string>, schemes: list<string>, rules: list<Condition>, query: array{mode: string, exact: string, params: array<string, string|null>}|null}|null
     */
    private static function condition(array $args): ?array
    {
        $text = trim(implode(' ', $args));
        if (preg_match('/^\(\s*\$([A-Za-z0-9_]+)\s*(?:(!?~\*?|!?=)\s*(.*?))?\s*\)$/s', $text, $m) !== 1) {
            return null;
        }
        $var = strtolower($m[1]);
        $op = $m[2] ?? '';
        $value = $m[3] ?? '';
        $negate = str_starts_with($op, '!');
        $bare = ltrim($op, '!');
        $ci = str_ends_with($bare, '*');
        $empty = ['hosts' => [], 'schemes' => [], 'rules' => [], 'query' => null];

        if (in_array($var, ['host', 'http_host', 'server_name'], true)) {
            if ($negate || $op === '') {
                return null;
            }
            $hosts = $bare === '=' ? (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $value) === 1 ? [strtolower($value)] : null) : Pattern::hostsOf($value);

            return $hosts === null ? null : ['hosts' => $hosts] + $empty;
        }
        if ($var === 'scheme' && $bare === '=' && in_array(strtolower($value), ['http', 'https'], true)) {
            return $negate ? null : ['schemes' => [strtolower($value)]] + $empty;
        }
        if (str_starts_with($var, 'http_') || str_starts_with($var, 'cookie_')) {
            $isCookie = str_starts_with($var, 'cookie_');
            $raw = substr($var, $isCookie ? 7 : 5);
            $name = $isCookie ? $raw : implode('-', array_map('ucfirst', explode('_', $raw)));
            $kind = $isCookie ? ConditionKind::Cookie : ConditionKind::Header;
            if ($op === '') {
                $cond = new Condition($kind, $name, ConditionOperator::Exists);
            } elseif ($bare === '=' && $value === '') {
                $cond = new Condition($kind, $name, ConditionOperator::Exists, '', !$negate);
            } elseif ($bare === '=') {
                $cond = new Condition($kind, $name, ConditionOperator::Equals, $value, $negate);
            } else {
                $cond = HeaderCond::fromPattern($kind, $name, $value, $ci, $negate);
            }

            return ['rules' => [$cond]] + $empty;
        }
        if (str_starts_with($var, 'arg_') && !$negate && ($op === '' || $bare === '=')) {
            $name = substr($var, 4);

            return ['query' => ['mode' => 'params', 'exact' => '', 'params' => [$name => $op === '' || $value === '' ? null : $value]]] + $empty;
        }
        if (($var === 'args' || $var === 'query_string') && !$negate && $bare === '=' && $value !== '') {
            return ['query' => ['mode' => 'exact', 'exact' => $value, 'params' => []]] + $empty;
        }

        return null;
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        if ($options->includeHeader) {
            $lines[] = '# Redirects exported by Redirect Manager. Include this file inside a server { } block.';
        }
        $skipped = [];
        $lossy = [];
        $count = 0;
        foreach ($rules as $rule) {
            $block = $this->rule($rule);
            if (is_string($block)) {
                $skipped[] = new ExportNote($rule->id, $block, self::reason($block));
                $lines[] = '# skipped ' . $rule->id . ' (' . $block . '): ' . str_replace(["\n", "\r"], ' ', $rule->source);
                continue;
            }
            ++$count;
            array_push($lines, ...$block);
            array_push($lossy, ...Lossy::notes($rule, ['active_from', 'expires_at', 'continue', 'target_page', 'query_ignore']));
        }

        return new ExportResult(implode("\n", $lines) . "\n", skipped: $skipped, lossy: $lossy, exported: $count);
    }

    /**
     * @return list<string>|string lines or a skip code
     */
    private function rule(Rule $rule): array|string
    {
        $c = $rule->conditions;
        if ($c->languages !== []) {
            return 'language_not_supported';
        }
        if ($rule->status === StatusCode::PassThrough) {
            return 'pass_through_not_supported';
        }
        if ($rule->onlyIfNotFound) {
            return 'only_if_not_found_not_supported';
        }

        $target = $rule->target;
        $path = $rule->source;
        $q = strpos($path, '?');
        $sourceQuery = '';
        if ($rule->matchType !== MatchType::Regex && $q !== false) {
            $sourceQuery = substr($path, $q + 1);
            $path = substr($path, 0, $q);
        }

        // Location line.
        $insensitive = !$rule->caseSensitive;
        if ($rule->matchType === MatchType::Exact) {
            if (!$insensitive && !$rule->ignoreTrailingSlash) {
                $location = 'location = ' . Args::quote($path);
            } else {
                $regex = '^' . Pattern::escape($path) . ($rule->ignoreTrailingSlash && $path !== '/' ? '/?' : '') . '$';
                $location = 'location ' . ($insensitive ? '~*' : '~') . ' ' . Args::quote($regex);
            }
        } else {
            $regex = $rule->matchType === MatchType::Wildcard ? Pattern::wildcardToRegex($rule->source) : $rule->source;
            if (str_starts_with($regex, '(?i)')) {
                $regex = substr($regex, 4);
                $insensitive = true;
            }
            $location = 'location ' . ($insensitive ? '~*' : '~') . ' ' . Args::quote($regex);
            if ($rule->matchType === MatchType::Regex) {
                $converted = self::namedToVariables($target, $regex);
                if ($converted === null) {
                    return 'named_group_unresolved';
                }
                $target = $converted;
            }
        }

        // One condition at most: nginx has no AND for if.
        $conds = [];
        if ($c->hosts !== []) {
            $conds[] = count($c->hosts) === 1 && !str_starts_with($c->hosts[0], '*')
                ? '$host = ' . Args::quote($c->hosts[0])
                : '$host ~* ' . Args::quote(Pattern::hostsToRegex($c->hosts));
        }
        if (count($c->schemes) === 1) {
            $conds[] = '$scheme = ' . $c->schemes[0];
        }
        foreach ($c->rules as $cond) {
            $conds[] = self::conditionLine($cond);
        }
        if ($rule->queryMode === QueryMode::Exact && $sourceQuery !== '') {
            $conds[] = '$args = ' . Args::quote($sourceQuery);
        } elseif ($rule->queryMode === QueryMode::Params && $rule->queryParams !== []) {
            if (count($rule->queryParams) > 1) {
                return 'query_not_supported';
            }
            $name = (string) array_key_first($rule->queryParams);
            $value = $rule->queryParams[$name];
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                return 'query_not_supported';
            }
            $conds[] = '$arg_' . $name . ($value === null ? '' : ' = ' . Args::quote($value));
        }
        if (count($conds) > 1) {
            return 'multiple_conditions_not_supported';
        }
        foreach ($conds as $cond) {
            if ($cond === '') {
                return 'condition_not_supported';
            }
        }

        $status = $rule->status;
        $return = $status === StatusCode::Gone || $status === StatusCode::UnavailableForLegalReasons
            ? 'return ' . $status->value . ';'
            : 'return ' . $status->value . ' ' . Args::quote($target . ($rule->queryMode === QueryMode::Pass ? '$is_args$args' : '')) . ';';

        $lines = [$location . ' {'];
        if ($conds === []) {
            $lines[] = '    ' . $return;
        } else {
            $lines[] = '    if (' . $conds[0] . ') {';
            $lines[] = '        ' . $return;
            $lines[] = '    }';
        }
        $lines[] = '}';

        return $lines;
    }

    private static function conditionLine(Condition $cond): string
    {
        $var = $cond->kind === ConditionKind::Cookie
            ? '$cookie_' . $cond->name
            : '$http_' . strtolower(str_replace('-', '_', $cond->name));
        $not = $cond->negate;
        if ($cond->operator === ConditionOperator::Exists) {
            return $not ? $var . ' = ""' : $var;
        }
        if ($cond->operator === ConditionOperator::Equals) {
            return $var . ($not ? ' != ' : ' = ') . Args::quote($cond->value);
        }
        [$pattern, $ci] = HeaderCond::toPattern($cond);

        return $var . ' ' . ($not ? '!' : '') . ($ci ? '~*' : '~') . ' ' . Args::quote($pattern);
    }

    /** `{name}` becomes `$name`; null when the regex has no such named group. */
    private static function namedToVariables(string $target, string $regex): ?string
    {
        $groups = Pattern::groups($regex);
        $failed = false;
        $out = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function (array $m) use ($groups, &$failed): string {
            if (!in_array($m[1], $groups, true)) {
                $failed = true;

                return $m[0];
            }

            return '$' . $m[1];
        }, $target);

        return $failed || $out === null ? null : $out;
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'language_not_supported' => 'nginx has no language condition; the rule would apply to every language.',
            'pass_through_not_supported' => 'Pass-through (200) is an internal rewrite, not a redirect.',
            'only_if_not_found_not_supported' => 'The "only if no page exists" flag has no nginx equivalent here.',
            'named_group_unresolved' => 'The target uses a named placeholder that the regex does not define.',
            'query_not_supported' => 'Only one query parameter can be tested.',
            'multiple_conditions_not_supported' => 'nginx if cannot combine several conditions.',
            default => 'The rule cannot be expressed in this format.',
        };
    }
}
