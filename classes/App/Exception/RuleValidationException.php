<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use RuntimeException;

/**
 * A rule (or a row of an import) failed validation. Carries every finding, errors and warnings alike,
 * so the caller can show them all. Maps to HTTP 422 and CLI exit code 2.
 */
final class RuleValidationException extends RuntimeException
{
    /**
     * @param list<ValidationIssue> $issues
     */
    public function __construct(public readonly array $issues, string $message = 'The rule is invalid.')
    {
        parent::__construct($message);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ValidationIssue $i): bool => $i->isError()));
    }
}
