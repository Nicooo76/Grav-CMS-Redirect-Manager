<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\Util\Clock;

/** Generic CSV: header names in English or German, any delimiter, own export columns. */
final class CsvAdapter implements ImportAdapter, ExportAdapter
{
    public const HEADER = [
        'source', 'target', 'status', 'match_type', 'enabled', 'priority', 'group', 'note', 'query_mode',
        'case_sensitive', 'ignore_trailing_slash', 'only_if_not_found', 'expires_at', 'tags',
    ];

    /** Header names per field, most specific first. */
    private const SYNONYMS = [
        'source' => ['source', 'from', 'old', 'url', 'quelle', 'alt', 'old_url', 'oldurl', 'source_url', 'alte_url', 'request', 'request_url'],
        'target' => ['target', 'to', 'new', 'destination', 'ziel', 'neu', 'target_url', 'new_url', 'newurl', 'redirect_to', 'redirect', 'neue_url', 'ziel_url'],
        'status' => ['status', 'code', 'status_code', 'statuscode', 'http_code', 'http_status', 'type'],
        'match_type' => ['match_type', 'match', 'matchtype', 'match-type', 'mode'],
        'regex' => ['regex', 'is_regex', 'regexp'],
        'group' => ['group', 'gruppe', 'category', 'kategorie'],
        'note' => ['note', 'notiz', 'notes', 'comment', 'kommentar', 'description'],
        'enabled' => ['enabled', 'active', 'aktiv'],
        'priority' => ['priority', 'prioritaet', 'priorität'],
        'query_mode' => ['query_mode', 'querymode'],
        'case_sensitive' => ['case_sensitive', 'casesensitive'],
        'ignore_trailing_slash' => ['ignore_trailing_slash'],
        'only_if_not_found' => ['only_if_not_found'],
        'expires_at' => ['expires_at', 'expires', 'ablaufdatum'],
        'tags' => ['tags'],
    ];

    /** Column order when the file has no header and no mapping is given. */
    private const POSITIONAL = ['source', 'target', 'status', 'match_type', 'group', 'note'];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $delimiter = self::delimiter($options->delimiter) ?? Csv::detectDelimiter($content);
        $records = Csv::stream($content, $delimiter, $options->maxRows + 2, false, $options->maxRows);
        if (!$records->valid()) {
            return [];
        }

        $first = $records->current()['cells'];
        $hasHeader = $options->hasHeader ?? (self::mapsByName($options) || self::looksLikeHeader($first));
        $header = null;
        if ($hasHeader) {
            $header = $first;
            $records->next();
        }
        $columns = $this->resolveColumns($header, $options, count($first));
        if (!isset($columns['source'])) {
            throw new ImportException(new ImportIssue('missing_column', 'No source column was found.', ['field' => 'source']));
        }

        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        for (; $records->valid(); $records->next()) {
            $rows[] = $this->row($records->current(), $columns, $factory);
        }

        return $rows;
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        if ($options->includeHeader) {
            $lines[] = self::HEADER;
        }
        $lossy = [];
        foreach ($rules as $rule) {
            $lines[] = self::line($rule);
            array_push($lossy, ...Lossy::notes($rule, ['conditions', 'active_from', 'continue', 'query_ignore', 'target_page']));
        }

        return new ExportResult(Csv::write($lines), lossy: $lossy, exported: count($rules));
    }

    /**
     * @return list<string>
     */
    public static function line(Rule $rule): array
    {
        $source = $rule->source;
        if ($rule->queryMode === QueryMode::Params && $rule->queryParams !== []) {
            $pairs = [];
            foreach ($rule->queryParams as $name => $value) {
                $pairs[] = $value === null ? rawurlencode($name) : rawurlencode($name) . '=' . rawurlencode($value);
            }
            $source .= '?' . implode('&', $pairs);
        }

        return [
            $source,
            $rule->target,
            (string) $rule->status->value,
            $rule->matchType->value,
            $rule->enabled ? 'true' : 'false',
            (string) $rule->priority,
            $rule->group,
            $rule->note,
            $rule->queryMode->value,
            $rule->caseSensitive ? 'true' : 'false',
            $rule->ignoreTrailingSlash ? 'true' : 'false',
            $rule->onlyIfNotFound ? 'true' : 'false',
            $rule->expiresAt?->format(Rule::DATE_FORMAT) ?? '',
            implode('|', $rule->tags),
        ];
    }

    /**
     * @param array{line: int, raw: string, cells: list<string>} $record
     * @param array<string, int>                                  $columns
     */
    private function row(array $record, array $columns, RowFactory $factory): \Grav\Plugin\RedirectManager\ImportExport\ImportRow
    {
        $cells = array_map(static fn (string $c): string => trim(Csv::unsanitize($c)), $record['cells']);
        $fields = [];
        foreach ($columns as $field => $index) {
            if (!array_key_exists($index, $cells)) {
                if ($field === 'source') {
                    return $factory->error($record['line'], $record['raw'], new ImportIssue('missing_column', 'The row has no source column.', ['field' => 'source', 'column' => $index + 1]));
                }
                continue;
            }
            $fields[$field] = $cells[$index];
        }
        if (isset($fields['regex'])) {
            if (in_array(strtolower($fields['regex']), ['1', 'true', 'yes', 'on', 'y', 'ja'], true) && ($fields['match_type'] ?? '') === '') {
                $fields['match_type'] = 'regex';
            }
            unset($fields['regex']);
        }
        if (isset($fields['tags'])) {
            $fields['tags'] = array_values(array_filter(array_map('trim', explode('|', $fields['tags'])), static fn (string $t): bool => $t !== ''));
        }

        return $factory->make($record['line'], $record['raw'], $fields);
    }

    /**
     * @param list<string>|null $header
     * @return array<string, int>
     */
    private function resolveColumns(?array $header, ImportOptions $options, int $width): array
    {
        $columns = [];
        $names = [];
        foreach ($header ?? [] as $i => $name) {
            $names[$i] = self::headerKey($name);
        }

        foreach ($options->csvMapping as $field => $ref) {
            if (is_int($ref)) {
                $columns[$field] = $ref;
            } elseif (ctype_digit($ref)) {
                $columns[$field] = (int) $ref;
            } else {
                $found = array_search(self::headerKey($ref), $names, true);
                if ($found !== false) {
                    $columns[$field] = $found;
                }
            }
        }

        if ($header !== null) {
            $taken = array_flip($columns);
            foreach (self::SYNONYMS as $field => $synonyms) {
                if (isset($columns[$field])) {
                    continue;
                }
                foreach ($synonyms as $synonym) {
                    $index = array_search($synonym, $names, true);
                    if ($index !== false && !isset($taken[$index])) {
                        $columns[$field] = $index;
                        $taken[$index] = $field;
                        break;
                    }
                }
            }
        } elseif ($options->csvMapping === []) {
            foreach (self::POSITIONAL as $i => $field) {
                if ($i < max($width, 2)) {
                    $columns[$field] = $i;
                }
            }
        }

        return $columns;
    }

    /** A mapping that names columns implies that the file has a header row. */
    private static function mapsByName(ImportOptions $options): bool
    {
        foreach ($options->csvMapping as $ref) {
            if (is_string($ref) && !ctype_digit($ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $cells
     */
    private static function looksLikeHeader(array $cells): bool
    {
        $known = [];
        foreach (self::SYNONYMS as $synonyms) {
            array_push($known, ...$synonyms);
        }
        $hits = 0;
        foreach ($cells as $cell) {
            if (in_array(self::headerKey($cell), $known, true)) {
                ++$hits;
            }
        }

        return $hits > 0 && !str_starts_with(trim($cells[0] ?? ''), '/');
    }

    private static function headerKey(string $name): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($name)));
    }

    private static function delimiter(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $map = ['tab' => "\t", '\\t' => "\t", 'comma' => ',', 'semicolon' => ';', 'pipe' => '|'];

        return $map[strtolower($value)] ?? $value[0];
    }
}
