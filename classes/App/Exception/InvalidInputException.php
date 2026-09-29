<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use RuntimeException;

/**
 * The request itself is malformed (unknown action, wrong type, missing field). Maps to HTTP 422 and CLI exit code 2.
 */
final class InvalidInputException extends RuntimeException
{
    /** @var list<ValidationIssue> */
    public readonly array $issues;

    /**
     * @param list<ValidationIssue>|null $issues
     */
    public function __construct(string $message, ?array $issues = null, public readonly string $field = '', public readonly string $errorCode = 'invalid_value')
    {
        parent::__construct($message);
        $this->issues = $issues ?? [ValidationIssue::error($errorCode, $field, $message)];
    }
}
