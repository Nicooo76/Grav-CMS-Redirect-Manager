<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;

/** Result of parsing an import file, before anything is stored. */
final readonly class ImportPreview
{
    /**
     * @param list<ImportRow>   $rows
     * @param list<ImportIssue> $errors        file-level problems; no rows are produced when present
     * @param list<ImportIssue> $warnings      file-level notes (encoding converted, no rows, ...)
     * @param list<string>      $notFoundPaths 404 candidates (crawler exports); these are not rules
     */
    public function __construct(
        public array $rows = [],
        public ?Format $format = null,
        public array $errors = [],
        public array $warnings = [],
        public array $notFoundPaths = [],
    ) {
    }

    /**
     * @return array{total: int, valid: int, errors: int, duplicates: int, warnings: int, skipped: int, not_found: int}
     */
    public function counts(): array
    {
        $valid = $errors = $duplicates = $warnings = $skipped = 0;
        foreach ($this->rows as $row) {
            if ($row->rule !== null) {
                ++$valid;
            } elseif ($row->errors === []) {
                ++$skipped;
            }
            if ($row->errors !== []) {
                ++$errors;
            }
            if ($row->isDuplicate()) {
                ++$duplicates;
            }
            if ($row->warnings !== []) {
                ++$warnings;
            }
        }

        return [
            'total' => count($this->rows),
            'valid' => $valid,
            'errors' => $errors,
            'duplicates' => $duplicates,
            'warnings' => $warnings,
            'skipped' => $skipped,
            'not_found' => count($this->notFoundPaths),
        ];
    }

    public function hasFileErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Rules of all valid rows, optionally without duplicates (in the file or already stored).
     *
     * @return list<Rule>
     */
    public function rules(bool $skipDuplicates = false): array
    {
        $rules = [];
        foreach ($this->rows as $row) {
            if ($row->rule !== null && !($skipDuplicates && $row->isDuplicate())) {
                $rules[] = $row->rule;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format?->value,
            'counts' => $this->counts(),
            'errors' => array_map(static fn (ImportIssue $i): array => $i->toArray(), $this->errors),
            'warnings' => array_map(static fn (ImportIssue $i): array => $i->toArray(), $this->warnings),
            'rows' => array_map(static fn (ImportRow $r): array => $r->toArray(), $this->rows),
            'not_found_paths' => $this->notFoundPaths,
        ];
    }
}
