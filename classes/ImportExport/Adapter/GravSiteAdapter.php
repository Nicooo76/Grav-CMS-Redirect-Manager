<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

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
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\SafeYaml;
use Grav\Plugin\RedirectManager\Util\Clock;
use Symfony\Component\Yaml\Yaml;

/**
 * Grav's own redirect config in `site.yaml`.
 *
 * `redirects`: regex patterns (Grav prepends ^) with an optional `[301]` suffix on the target; the
 * default code is `system.pages.redirect_default_code` (ImportOptions::$redirectDefaultCode).
 * `routes`: aliases; Grav serves the target page under the alias, so they become status 200 rules.
 */
final class GravSiteAdapter implements ImportAdapter, ExportAdapter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $data = SafeYaml::parse($content);
        if (!is_array($data)) {
            throw new ImportException(new ImportIssue('invalid_structure', 'The file is not a site.yaml with redirects or routes.'));
        }
        if (isset($data['site']) && is_array($data['site']) && !isset($data['redirects']) && !isset($data['routes'])) {
            $data = $data['site'];
        }
        if (!isset($data['redirects']) && !isset($data['routes'])) {
            if ($data !== [] && !array_is_list($data) && self::allPaths(array_keys($data))) {
                $data = ['redirects' => $data];
            } else {
                throw new ImportException(new ImportIssue('invalid_structure', 'The file has neither a "redirects" nor a "routes" section.'));
            }
        }

        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        $n = 0;
        foreach (['redirects' => false, 'routes' => true] as $section => $isRoute) {
            $map = $data[$section] ?? [];
            if (!is_array($map) || array_is_list($map) && $map !== []) {
                throw new ImportException(new ImportIssue('invalid_structure', 'The section must be a map.', ['section' => $section]));
            }
            foreach ($map as $key => $value) {
                ++$n;
                if ($n > $options->maxRows + 1) {
                    throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows]));
                }
                $key = (string) $key;
                $raw = $key . ': ' . (is_scalar($value) ? (string) $value : '');
                if (!is_string($value) || trim($value) === '') {
                    $rows[] = $factory->error($n, $raw, new ImportIssue('invalid_row', 'The target must be a non-empty string.'));
                    continue;
                }
                $rows[] = $isRoute ? $this->alias($n, $raw, $key, $value, $factory) : $this->redirect($n, $raw, $key, $value, $options, $factory);
            }
        }

        return $rows;
    }

    private function alias(int $n, string $raw, string $alias, string $target, RowFactory $factory): ImportRow
    {
        return $factory->make($n, $raw, [
            'source' => $alias,
            'target' => trim($target),
            'status' => 200,
            'match_type' => 'exact',
            'case_sensitive' => true,
        ], [new ImportIssue('alias_pass_through', 'A Grav route alias serves the target page under the alias URL. Consider a 301 redirect instead.')]);
    }

    private function redirect(int $n, string $raw, string $pattern, string $value, ImportOptions $options, RowFactory $factory): ImportRow
    {
        $status = $options->redirectDefaultCode;
        $target = trim($value);
        if (preg_match('/^(.*?)\s*\[(30[1-7])\]$/s', $target, $m) === 1) {
            $target = $m[1];
            $status = (int) $m[2];
        }

        $pattern = ltrim(trim($pattern), '^');
        [$body, $insensitive] = Pattern::stripCaseFlag($pattern);
        $end = str_ends_with($body, '$') && !self::escapedEnd($body);
        $inner = $end ? substr($body, 0, -1) : $body;
        $warnings = [];

        $fields = ['target' => $target, 'status' => $status, 'case_sensitive' => !$insensitive];
        $literal = Pattern::unescape($inner) ?? (preg_match('/^[^\^$*+?()\[\]{}|\\\\]+$/', $inner) === 1 ? $inner : null);
        $wildcard = $literal === null ? Pattern::wildcardOf('^' . $inner . ($end ? '$' : '')) : null;
        if ($literal !== null && $inner !== '') {
            $fields['source'] = $literal;
            $fields['match_type'] = MatchType::Exact->value;
        } elseif ($wildcard !== null) {
            $fields['source'] = $wildcard['wildcard'];
            $fields['match_type'] = MatchType::Wildcard->value;
        } else {
            $fields['source'] = '^' . $inner . ($end ? '$' : '');
            $fields['match_type'] = MatchType::Regex->value;
            if (!$end) {
                $warnings[] = new ImportIssue('grav_prefix_semantics', 'Grav matches this pattern as a path prefix and replaces only the matched part; the imported rule matches the whole path.');
            }
        }

        return $factory->make($n, $raw, $fields, $warnings);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $redirects = [];
        $routes = [];
        $skipped = [];
        $lossy = [];
        $comments = [];
        foreach ($rules as $rule) {
            $entry = $this->entry($rule);
            if (is_string($entry)) {
                $skipped[] = new ExportNote($rule->id, $entry, self::reason($entry));
                $comments[] = '# skipped ' . $rule->id . ' (' . $entry . '): ' . str_replace(["\n", "\r"], ' ', $rule->source);
                continue;
            }
            [$isRoute, $key, $value] = $entry;
            $map = $isRoute ? $routes : $redirects;
            if (isset($map[$key])) {
                $skipped[] = new ExportNote($rule->id, 'duplicate_key', 'Another rule with the same pattern comes first and wins.');
                continue;
            }
            if ($isRoute) {
                $routes[$key] = $value;
            } else {
                $redirects[$key] = $value;
            }
            array_push($lossy, ...Lossy::notes($rule, ['query_pass', 'query_ignore', 'trailing_slash', 'active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page']));
        }

        $out = '';
        if ($options->includeHeader) {
            $out .= "# Redirects exported by Redirect Manager. Merge into user/config/site.yaml.\n";
            $out .= "# Grav prepends ^ to every pattern; patterns end in \$ so they match the whole route.\n";
        }
        if ($comments !== []) {
            $out .= implode("\n", $comments) . "\n";
        }
        $data = [];
        if ($redirects !== []) {
            $data['redirects'] = $redirects;
        }
        if ($routes !== []) {
            $data['routes'] = $routes;
        }
        $out .= $data === [] ? "redirects: {}\n" : Yaml::dump($data, 2, 4);

        return new ExportResult($out, skipped: $skipped, lossy: $lossy, exported: count($redirects) + count($routes));
    }

    /**
     * @return array{0: bool, 1: string, 2: string}|string [isRoute, key, value] or a skip code
     */
    private function entry(Rule $rule): array|string
    {
        if (!$rule->conditions->isEmpty()) {
            return 'conditions_not_supported';
        }
        if ($rule->queryMode === QueryMode::Exact || $rule->queryMode === QueryMode::Params) {
            return 'query_not_supported';
        }
        if ($rule->status === StatusCode::Gone || $rule->status === StatusCode::UnavailableForLegalReasons || $rule->status === StatusCode::PermanentRedirect) {
            return 'status_not_supported';
        }
        $target = $rule->target;

        if ($rule->status === StatusCode::PassThrough) {
            if ($rule->matchType !== MatchType::Exact) {
                return 'pass_through_not_representable';
            }

            return [true, $rule->source, $target];
        }

        $flag = $rule->caseSensitive ? '' : '(?i)';
        switch ($rule->matchType) {
            case MatchType::Exact:
                $key = $flag . Pattern::escape($rule->source) . '$';
                break;
            case MatchType::Wildcard:
                $key = $flag . substr(Pattern::wildcardToRegex($rule->source), 1);
                break;
            default:
                if ($rule->source === '' || ($rule->source[0] !== '^' && $rule->source[0] !== '/')) {
                    return 'regex_not_anchored';
                }
                $key = $flag . ltrim($rule->source, '^');
                $named = Pattern::namedToNumbered($target, $rule->source);
                if ($named === null) {
                    return 'named_group_unresolved';
                }
                $target = $named;
        }

        return [false, $key, $target . ' [' . $rule->status->value . ']'];
    }

    /** @param list<int|string> $keys */
    private static function allPaths(array $keys): bool
    {
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key === '' || ($key[0] !== '/' && $key[0] !== '^')) {
                return false;
            }
        }

        return true;
    }

    private static function escapedEnd(string $body): bool
    {
        $stripped = substr($body, 0, -1);

        return (strlen($stripped) - strlen(rtrim($stripped, '\\'))) % 2 === 1;
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'conditions_not_supported' => 'site.yaml redirects cannot carry host, language, scheme or header conditions.',
            'query_not_supported' => 'site.yaml redirects match the route only, not the query string.',
            'status_not_supported' => 'site.yaml redirects support 301 to 307; 308, 410 and 451 are not available.',
            'pass_through_not_representable' => 'Only exact pass-through rules can be written as route aliases.',
            'regex_not_anchored' => 'Grav anchors patterns at the start; an unanchored regex cannot be expressed.',
            'named_group_unresolved' => 'The target uses a named placeholder that the regex does not define.',
            default => 'The rule cannot be expressed in this format.',
        };
    }
}
