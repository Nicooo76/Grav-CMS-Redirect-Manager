<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;

/**
 * Cloudflare Bulk Redirects CSV (export only). Sources need a host: ExportOptions::$exportHost or the
 * rule's own host condition. Regex rules, unusual wildcards and non-3xx statuses are skipped.
 */
final class CloudflareCsvAdapter implements ExportAdapter
{
    public const HEADER = ['source_url', 'target_url', 'status_code', 'preserve_query_string', 'include_subdomains', 'subpath_matching', 'preserve_path_suffix'];

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        if ($options->includeHeader) {
            $lines[] = self::HEADER;
        }
        $skipped = [];
        $lossy = [];
        $count = 0;
        $defaultHost = self::cleanHost($options->exportHost ?? '');

        foreach ($rules as $rule) {
            $built = self::rows($rule, $defaultHost);
            if (is_string($built)) {
                $skipped[] = new ExportNote($rule->id, $built, self::reason($built));
                continue;
            }
            ++$count;
            array_push($lines, ...$built);
            array_push($lossy, ...Lossy::notes($rule, ['case', 'trailing_slash', 'active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page', 'query_ignore']));
            if ($rule->queryMode === QueryMode::Exact || $rule->queryMode === QueryMode::Params) {
                $lossy[] = new ExportNote($rule->id, 'dropped_query', 'Cloudflare matches the path only.');
            }
        }

        return new ExportResult(Csv::write($lines), skipped: $skipped, lossy: $lossy, exported: $count);
    }

    /**
     * @return list<list<string>>|string rows (one per host) or a skip code
     */
    private static function rows(Rule $rule, string $defaultHost): array|string
    {
        if (!in_array($rule->status->value, [301, 302, 307, 308], true)) {
            return 'status_not_supported';
        }
        $c = $rule->conditions;
        if ($c->languages !== [] || $c->rules !== [] || $c->schemes !== []) {
            return 'conditions_not_supported';
        }
        if ($rule->matchType === MatchType::Regex) {
            return 'regex_not_supported';
        }

        $hosts = $c->hosts !== [] ? $c->hosts : ($defaultHost !== '' ? [$defaultHost] : []);
        if ($hosts === []) {
            return 'export_host_required';
        }

        $path = $rule->source;
        $q = strpos($path, '?');
        $path = $q === false ? $path : substr($path, 0, $q);
        $target = $rule->target;
        $subpath = false;
        $suffix = false;
        if ($rule->matchType === MatchType::Wildcard) {
            if (substr_count($path, '*') !== 1 || !str_ends_with($path, '/*')) {
                return 'wildcard_not_representable';
            }
            $path = substr($path, 0, -2);
            $subpath = true;
            if (str_ends_with($target, '/$1') || str_ends_with($target, '/${1}')) {
                $target = (string) preg_replace('~/\$\{?1\}?$~', '', $target);
                $suffix = true;
            } elseif (str_contains($target, '$')) {
                return 'wildcard_not_representable';
            }
        } elseif (str_contains($target, '$') && preg_match('/\$\{?\d/', $target) === 1) {
            return 'placeholder_not_supported';
        }
        if ($path === '') {
            $path = '/';
        }

        $out = [];
        foreach ($hosts as $host) {
            $wild = str_starts_with($host, '*.');
            $base = $wild ? substr($host, 2) : $host;
            $absolute = $rule->targetType === TargetType::Url || preg_match('~^https?://~i', $target) === 1
                ? $target
                : 'https://' . ($defaultHost !== '' ? $defaultHost : $base) . ($target === '' || $target[0] !== '/' ? '/' : '') . $target;
            $out[] = [
                $base . $path,
                $absolute,
                (string) $rule->status->value,
                $rule->queryMode === QueryMode::Pass ? 'TRUE' : 'FALSE',
                $wild ? 'TRUE' : 'FALSE',
                $subpath ? 'TRUE' : 'FALSE',
                $suffix ? 'TRUE' : 'FALSE',
            ];
        }

        return $out;
    }

    private static function cleanHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('~^https?://~', '', $host);

        return rtrim(explode('/', $host)[0], '.');
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'status_not_supported' => 'Cloudflare Bulk Redirects support 301, 302, 307 and 308 only.',
            'conditions_not_supported' => 'Cloudflare Bulk Redirects match host and path only.',
            'regex_not_supported' => 'Regular expressions are not supported by Bulk Redirects.',
            'export_host_required' => 'Set the export host: Cloudflare sources need a hostname.',
            'wildcard_not_representable' => 'Only a trailing /* (with $1 at the end of the target) can be expressed as subpath matching.',
            'placeholder_not_supported' => 'Placeholders are not supported by Bulk Redirects.',
            default => 'The rule cannot be expressed in this format.',
        };
    }
}
