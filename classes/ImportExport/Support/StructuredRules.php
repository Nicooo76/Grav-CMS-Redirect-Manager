<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;

/** Shared by the JSON and YAML adapters: rule lists in the plugin's own format. */
final class StructuredRules
{
    public const VERSION = 1;

    /**
     * @return list<ImportRow>
     */
    public static function rows(mixed $data, RowFactory $factory, int $maxRows): array
    {
        if (!is_array($data)) {
            throw new ImportException(new ImportIssue('invalid_structure', 'The file does not contain a list of rules.'));
        }
        $list = $data;
        if (!array_is_list($data)) {
            $version = $data['version'] ?? self::VERSION;
            if (is_numeric($version) && (int) $version > self::VERSION) {
                throw new ImportException(new ImportIssue('unsupported_version', 'The file was written by a newer version of the plugin.', ['version' => (int) $version]));
            }
            if (!isset($data['rules']) || !is_array($data['rules'])) {
                throw new ImportException(new ImportIssue('invalid_structure', 'The file has no "rules" list.'));
            }
            $list = $data['rules'];
        }
        if (count($list) > $maxRows) {
            throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $maxRows]));
        }

        $rows = [];
        $n = 0;
        foreach ($list as $item) {
            ++$n;
            $raw = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $raw = $raw === false ? '' : $raw;
            if (!is_array($item) || array_is_list($item) && $item !== []) {
                $rows[] = $factory->error($n, $raw, new ImportIssue('invalid_row', 'The entry is not a rule object.'));
                continue;
            }
            /** @var array<string, mixed> $item */
            $rows[] = $factory->make($n, $raw, $item);
        }

        return $rows;
    }
}
