<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\StructuredRules;
use Grav\Plugin\RedirectManager\Util\Clock;

/** `{"version": 1, "rules": [...]}` or a bare array of rules, in the plugin's own field names. */
final class JsonAdapter implements ImportAdapter, ExportAdapter
{
    public const MAX_DEPTH = 32;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $data = json_decode($content, true, self::MAX_DEPTH);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            if (json_last_error() === JSON_ERROR_DEPTH) {
                throw new ImportException(new ImportIssue('too_deep', 'The JSON is nested too deeply.', ['max' => self::MAX_DEPTH]));
            }
            throw new ImportException(new ImportIssue('invalid_json', 'The file is not valid JSON: ' . json_last_error_msg()));
        }

        return StructuredRules::rows($data, new RowFactory($options, $this->clock), $options->maxRows);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $data = ['version' => StructuredRules::VERSION, 'rules' => array_map(static fn ($r): array => $r->toArray(), $rules)];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new ExportResult($json . "\n", exported: count($rules));
    }
}
