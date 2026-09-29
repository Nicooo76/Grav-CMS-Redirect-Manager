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
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Netlify `_redirects`: `from [query=value ...] to [status[!]] [Country=..]`.
 * `:name` placeholders become named regex groups, a trailing `*` (`:splat`) becomes a wildcard.
 */
final class NetlifyAdapter implements ImportAdapter, ExportAdapter
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        foreach (explode("\n", $content) as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (count($rows) >= $options->maxRows + 1) {
                throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows]));
            }
            $rows[] = $this->line($i + 1, $line, $factory);
        }

        return $rows;
    }

    private function line(int $no, string $line, RowFactory $factory): ImportRow
    {
        $tokens = preg_split('/\s+/', $line) ?: [];
        $from = array_shift($tokens);
        if ($from === null || $from === '' || $tokens === []) {
            return $factory->error($no, $line, new ImportIssue('invalid_line', 'A redirect line needs a source and a target.'));
        }

        $params = [];
        while ($tokens !== [] && preg_match('/^[A-Za-z0-9_\-\[\]]+=\S*$/', $tokens[0]) === 1 && !str_contains($tokens[0], '/')) {
            [$name, $value] = explode('=', array_shift($tokens), 2);
            $params[$name] = $value;
        }
        $to = '';
        if ($tokens !== [] && preg_match('/^\d{3}!?$/', $tokens[0]) !== 1) {
            $to = array_shift($tokens);
        }
        $status = null;
        if ($tokens !== [] && preg_match('/^(\d{3})!?$/', $tokens[0], $m) === 1) {
            $status = (int) $m[1];
            array_shift($tokens);
        }
        $warnings = [];
        foreach ($tokens as $token) {
            $warnings[] = new ImportIssue('unsupported_condition', 'Netlify conditions (Country, Language, Role) are not supported and were ignored.', ['condition' => $token]);
        }
        [$path, $hosts] = $factory->stripHost($from, $warnings);
        $fields = [
            'target' => $to,
            'status' => $status ?? 301,
            'conditions' => ['hosts' => $hosts],
        ];

        $names = [];
        $hasStar = str_contains($path, '*');
        $hasPlaceholder = preg_match('/(?<![A-Za-z0-9_]):' . self::NAME . '/', $path) === 1;
        if ($hasPlaceholder) {
            [$fields['source'], $names] = self::placeholderRegex($path);
            $fields['match_type'] = 'regex';
            $fields['target'] = (string) preg_replace('/(?<![A-Za-z0-9_]):(' . self::NAME . ')/', '{$1}', $to);
        } elseif ($hasStar) {
            $fields['source'] = $path;
            $fields['match_type'] = 'wildcard';
            $fields['target'] = str_replace(':splat', '$1', $to);
            if (substr_count($path, '*') > 1) {
                $warnings[] = new ImportIssue('splat_multiple', 'Netlify allows one splat; the target uses the first capture.');
            }
        } else {
            $fields['source'] = $path;
            $fields['match_type'] = 'exact';
        }

        if ($params !== []) {
            $fields['query_mode'] = QueryMode::Params->value;
            $fields['query_params'] = [];
            foreach ($params as $name => $value) {
                $isCapture = str_starts_with($value, ':');
                $fields['query_params'][$name] = $isCapture || $value === '' ? null : $value;
                if ($isCapture && str_contains($to, $value)) {
                    $warnings[] = new ImportIssue('query_capture_unsupported', 'The target uses a query parameter value; captured query values are not supported.', ['param' => $name]);
                }
            }
        }

        $allowed = array_merge($names, $hasStar ? ['splat'] : [], array_map(static fn (string $v): string => ltrim($v, ':'), $params));
        $unknown = array_values(array_diff(self::placeholders($to), $allowed));
        if ($unknown !== []) {
            return $factory->error($no, $line, new ImportIssue('unknown_placeholder', 'The target uses a placeholder that the source does not define.', ['placeholder' => $unknown[0]]));
        }
        if ($status === 200 && preg_match('~^(?:https?:)?//~i', $to) === 1) {
            return $factory->error($no, $line, new ImportIssue('proxy_not_supported', 'Proxying to another site (status 200 with a URL) is not supported.'));
        }

        return $factory->make($no, $line, $fields, $warnings);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        if ($options->includeHeader) {
            $lines[] = '# Redirects exported by Redirect Manager. Save as "_redirects" in the publish directory.';
        }
        $skipped = [];
        $lossy = [];
        $count = 0;
        foreach ($rules as $rule) {
            $result = $this->rule($rule);
            if (is_string($result)) {
                $skipped[] = new ExportNote($rule->id, $result, self::reason($result));
                $lines[] = '# skipped ' . $rule->id . ' (' . $result . '): ' . self::commentSafe($rule->source);
                continue;
            }
            ++$count;
            array_push($lines, ...$result);
            array_push($lossy, ...Lossy::notes($rule, ['case', 'trailing_slash', 'query_pass', 'active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page', 'query_ignore']));
            if ($rule->queryMode === QueryMode::Exact) {
                $lossy[] = new ExportNote($rule->id, 'query_approximated', 'Netlify matches the listed query parameters but tolerates others.');
            }
        }

        return new ExportResult(implode("\n", $lines) . "\n", skipped: $skipped, lossy: $lossy, exported: $count);
    }

    /**
     * @return list<string>|string lines, or a skip code
     */
    private function rule(Rule $rule): array|string
    {
        if ($rule->status === StatusCode::Gone || $rule->status === StatusCode::UnavailableForLegalReasons) {
            return 'status_not_supported';
        }
        $c = $rule->conditions;
        if ($c->languages !== [] || $c->rules !== [] || $c->schemes !== []) {
            return 'conditions_not_supported';
        }
        foreach ($c->hosts as $host) {
            if (str_starts_with($host, '*')) {
                return 'wildcard_host_not_supported';
            }
        }

        $source = $rule->source;
        $target = $rule->target;
        $query = '';
        if ($rule->matchType === MatchType::Regex) {
            $converted = self::regexToNetlify($rule->source, $target);
            if ($converted === null) {
                return 'regex_not_representable';
            }
            [$source, $target] = $converted;
        } elseif ($rule->matchType === MatchType::Wildcard) {
            if (substr_count($source, '*') !== 1 || !str_ends_with($source, '*') || preg_match('/\$(?!1(?!\d))|\$\{(?!1\})/', $target) === 1) {
                return 'wildcard_not_representable';
            }
            $target = str_replace(['${1}', '$1'], ':splat', $target);
        }

        if ($rule->queryMode === QueryMode::Exact || $rule->queryMode === QueryMode::Params) {
            $pairs = [];
            if ($rule->queryMode === QueryMode::Exact) {
                $q = strpos($source, '?');
                if ($q !== false) {
                    foreach (RowFactory::parseQuery(substr($source, $q + 1)) as $name => $value) {
                        $pairs[] = $name . '=' . ($value ?? ':' . $name);
                    }
                    $source = substr($source, 0, $q);
                }
            } else {
                foreach ($rule->queryParams as $name => $value) {
                    $pairs[] = $name . '=' . ($value ?? ':' . $name);
                }
            }
            $query = $pairs === [] ? '' : ' ' . implode(' ', $pairs);
        }
        if (preg_match('/\s/', $target) === 1) {
            return 'invalid_target';
        }

        $source = str_replace(' ', '%20', $source);
        $status = $rule->status->value;
        $lines = [];
        $prefixes = $c->hosts === [] ? [''] : array_map(static fn (string $h): string => 'https://' . $h, $c->hosts);
        foreach ($prefixes as $prefix) {
            $lines[] = $prefix . $source . $query . ' ' . $target . ' ' . $status;
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: list<string>} regex and the placeholder names it defines
     */
    private static function placeholderRegex(string $path): array
    {
        $names = [];
        $out = '';
        $parts = preg_split('/((?<![A-Za-z0-9_]):' . self::NAME . '|\*)/', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            if ($part === '*') {
                $out .= '(?<splat>.*)';
                $names[] = 'splat';
            } elseif ($part[0] === ':' && strlen($part) > 1 && preg_match('/^:' . self::NAME . '$/', $part) === 1) {
                $name = substr($part, 1);
                $out .= '(?<' . $name . '>[^/]+)';
                $names[] = $name;
            } else {
                $out .= Pattern::escape($part);
            }
        }
        $slash = str_ends_with($path, '*') || str_ends_with($path, '/') ? '' : '/?';

        return ['^' . $out . $slash . '$', $names];
    }

    /**
     * Placeholder names used in a target (`:name`), ignoring the scheme and host part.
     *
     * @return list<string>
     */
    private static function placeholders(string $target): array
    {
        $scan = $target;
        if (preg_match('~^(?:https?:)?//[^/]*~i', $scan, $m) === 1) {
            $scan = substr($scan, strlen($m[0]));
        }
        preg_match_all('/(?<![A-Za-z0-9_]):(' . self::NAME . ')/', $scan, $found);

        return $found[1];
    }

    /**
     * @return array{0: string, 1: string}|null netlify source and target
     */
    private static function regexToNetlify(string $regex, string $target): ?array
    {
        [$body] = Pattern::stripCaseFlag($regex);
        if (!str_starts_with($body, '^') || !str_ends_with($body, '$')) {
            return null;
        }
        $inner = substr($body, 1, -1);
        if (str_ends_with($inner, '/?')) {
            $inner = substr($inner, 0, -2);
        }
        $found = preg_match_all('/\(\?P?<(' . self::NAME . ')>(\[\^\/\]\+|\.\*)\)/', $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($found === false) {
            return null;
        }
        $source = '';
        $names = [];
        $pos = 0;
        foreach ($matches as $i => $match) {
            [$whole, $offset] = $match[0];
            $literal = Pattern::unescape(substr($inner, $pos, $offset - $pos));
            if ($literal === null) {
                return null;
            }
            $source .= $literal;
            $pos = $offset + strlen($whole);
            $name = $match[1][0];
            if ($match[2][0] === '.*') {
                if ($name !== 'splat' || $i !== count($matches) - 1 || $pos !== strlen($inner)) {
                    return null;
                }
                $source .= '*';
            } else {
                $source .= ':' . $name;
            }
            $names[] = $name;
        }
        $tail = Pattern::unescape(substr($inner, $pos));
        if ($tail === null) {
            return null;
        }
        $source .= $tail;
        if (str_contains($source, ':splat') && !str_ends_with($source, '*')) {
            return null;
        }
        $groups = Pattern::groups($regex);
        $ok = true;
        $netlifyTarget = preg_replace_callback('/\{(' . self::NAME . ')\}|\$\{?(\d+)\}?/', static function (array $m) use ($groups, &$ok): string {
            if ($m[1] !== '') {
                return ':' . $m[1];
            }
            $name = $groups[(int) ($m[2] ?? 0) - 1] ?? null;
            if ($name === null) {
                $ok = false;

                return $m[0];
            }

            return ':' . $name;
        }, $target);

        return $ok && $netlifyTarget !== null ? [$source, $netlifyTarget] : null;
    }

    private static function commentSafe(string $text): string
    {
        return str_replace(["\n", "\r"], ' ', $text);
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'status_not_supported' => 'Netlify redirects need a target; 410 and 451 cannot be expressed.',
            'conditions_not_supported' => 'Only host conditions can be expressed.',
            'wildcard_host_not_supported' => 'Wildcard hosts cannot be expressed.',
            'wildcard_not_representable' => 'Only one trailing * with $1 in the target can be expressed.',
            'regex_not_representable' => 'The regular expression cannot be written as Netlify placeholders.',
            default => 'The rule cannot be expressed in this format.',
        };
    }
}
