<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * Outcome of RuleValidator::validate(): all findings for a candidate rule.
 */
final readonly class ValidationResult
{
    /**
     * @param list<ValidationIssue> $issues
     */
    public function __construct(public array $issues = [])
    {
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function errors(): array
    {
        return $this->bySeverity(Severity::Error);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function warnings(): array
    {
        return $this->bySeverity(Severity::Warning);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function infos(): array
    {
        return $this->bySeverity(Severity::Info);
    }

    /**
     * @return list<array{code: string, severity: string, field: string, message: string, params: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ValidationIssue $i): array => $i->toArray(), $this->issues);
    }

    /**
     * @return list<ValidationIssue>
     */
    private function bySeverity(Severity $severity): array
    {
        return array_values(array_filter($this->issues, static fn (ValidationIssue $i): bool => $i->severity === $severity));
    }
}
