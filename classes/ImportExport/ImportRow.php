<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;

/**
 * One parsed input record. A row with errors has no rule. A row without rule and without errors
 * was recognised but deliberately skipped (its warnings say why).
 */
final readonly class ImportRow
{
    public const RAW_LIMIT = 500;

    public int $line;
    public string $raw;
    public ?Rule $rule;
    /** @var list<ImportIssue> */
    public array $errors;
    /** @var list<ImportIssue> */
    public array $warnings;
    /** Id of an existing rule with the same source, match type and query. */
    public ?string $duplicateOf;
    public bool $duplicateInFile;

    /**
     * @param list<ImportIssue> $errors
     * @param list<ImportIssue> $warnings
     */
    public function __construct(
        int $line,
        string $raw,
        ?Rule $rule = null,
        array $errors = [],
        array $warnings = [],
        ?string $duplicateOf = null,
        bool $duplicateInFile = false,
    ) {
        $this->line = $line;
        $this->raw = self::truncate($raw);
        $this->rule = $errors === [] ? $rule : null;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->duplicateOf = $duplicateOf;
        $this->duplicateInFile = $duplicateInFile;
    }

    public function isValid(): bool
    {
        return $this->rule !== null;
    }

    public function isDuplicate(): bool
    {
        return $this->duplicateOf !== null || $this->duplicateInFile;
    }

    public function withDuplicate(?string $duplicateOf, bool $inFile): self
    {
        return new self($this->line, $this->raw, $this->rule, $this->errors, $this->warnings, $duplicateOf, $inFile);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'raw' => $this->raw,
            'rule' => $this->rule?->toArray(),
            'errors' => array_map(static fn (ImportIssue $i): array => $i->toArray(), $this->errors),
            'warnings' => array_map(static fn (ImportIssue $i): array => $i->toArray(), $this->warnings),
            'duplicate_of' => $this->duplicateOf,
            'duplicate_in_file' => $this->duplicateInFile,
        ];
    }

    private static function truncate(string $raw): string
    {
        if (strlen($raw) <= self::RAW_LIMIT) {
            return $raw;
        }
        $cut = mb_strcut($raw, 0, self::RAW_LIMIT, 'UTF-8');

        return $cut;
    }
}
