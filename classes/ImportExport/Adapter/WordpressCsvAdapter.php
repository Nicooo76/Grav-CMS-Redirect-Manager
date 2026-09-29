<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
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

/**
 * CSV export of the WordPress "Redirection" plugin: source, target, regex, code, type, hits, title, status.
 * Column order is read from the header; without header the order above is assumed.
 */
final class WordpressCsvAdapter implements ImportAdapter, ExportAdapter
{
    public const HEADER = ['source', 'target', 'regex', 'code', 'type', 'hits', 'title', 'status'];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $delimiter = Csv::detectDelimiter($content);
        $records = Csv::stream($content, $delimiter, $options->maxRows + 2, false, $options->maxRows);
        if (!$records->valid()) {
            return [];
        }
        $first = array_map(static fn (string $c): string => strtolower(trim($c)), $records->current()['cells']);
        $columns = array_flip(self::HEADER);
        if (in_array('source', $first, true) || in_array('target', $first, true)) {
            $records->next();
            $columns = [];
            foreach ($first as $i => $name) {
                $columns[$name] = $i;
            }
        }
        if (!isset($columns['source'])) {
            throw new ImportException(new ImportIssue('missing_column', 'No source column was found.', ['field' => 'source']));
        }

        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        for (; $records->valid(); $records->next()) {
            $record = $records->current();
            $cells = array_map(static fn (string $c): string => trim(Csv::unsanitize($c)), $record['cells']);
            $get = static fn (string $name): string => isset($columns[$name]) ? ($cells[$columns[$name]] ?? '') : '';
            $regex = in_array(strtolower($get('regex')), ['1', 'true', 'yes'], true);
            $type = strtolower($get('type'));
            $code = $get('code');
            $match = strtolower($get('match'));

            if ($match !== '' && $match !== 'url') {
                $rows[] = $factory->skipped($record['line'], $record['raw'], new ImportIssue('unsupported_match_type', 'This Redirection match type is not supported and was skipped.', ['match_type' => $match]));
                continue;
            }
            $status = $code === '' ? 301 : $code;
            if ($type === 'error') {
                if ($code !== '410') {
                    $rows[] = $factory->skipped($record['line'], $record['raw'], new ImportIssue('unsupported_status', 'Only 410 error rules are supported.', ['status' => $code]));
                    continue;
                }
            } elseif ($type === 'pass') {
                $status = 200;
            } elseif ($type !== '' && $type !== 'url') {
                $rows[] = $factory->skipped($record['line'], $record['raw'], new ImportIssue('unsupported_action', 'This Redirection action is not supported and was skipped.', ['action' => $type]));
                continue;
            }

            $fields = [
                'source' => $get('source'),
                'target' => $get('target'),
                'status' => $status,
                'match_type' => $regex ? 'regex' : 'exact',
                'note' => $get('title'),
                'enabled' => strtolower($get('status')) !== 'disabled',
                'group' => $get('group'),
            ];
            $rows[] = $factory->make($record['line'], $record['raw'], $fields);
        }

        return $rows;
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $lines = [];
        if ($options->includeHeader) {
            $lines[] = self::HEADER;
        }
        $skipped = [];
        $lossy = [];
        $count = 0;
        foreach ($rules as $rule) {
            $reason = self::unsupported($rule);
            $record = $reason === null ? WordpressJsonAdapter::record($rule) : $reason;
            if (is_string($record)) {
                $skipped[] = new ExportNote($rule->id, $record, 'The rule cannot be expressed in this format.');
                continue;
            }
            ++$count;
            $lines[] = [
                (string) $record['url'],
                is_array($record['action_data']) ? (string) ($record['action_data']['url'] ?? '') : '',
                $record['regex'] === true ? '1' : '0',
                (string) $record['action_code'],
                (string) $record['action_type'],
                '0',
                $rule->note,
                $rule->enabled ? 'enabled' : 'disabled',
            ];
            array_push($lossy, ...Lossy::notes($rule, ['case', 'trailing_slash', 'query_pass', 'active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page', 'query_ignore']));
            if ($rule->queryMode === QueryMode::Exact) {
                $lossy[] = new ExportNote($rule->id, 'dropped_query', 'The CSV has no query flags.');
            }
        }

        return new ExportResult(Csv::write($lines), skipped: $skipped, lossy: $lossy, exported: $count);
    }

    private static function unsupported(Rule $rule): ?string
    {
        return $rule->conditions->isEmpty() ? null : 'conditions_not_supported';
    }
}
