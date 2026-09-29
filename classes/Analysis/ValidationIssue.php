<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * One finding about a rule: a save-time validation problem or a result of the rule analysis.
 *
 * $code is a stable machine-readable identifier (source_empty, regex_invalid, loop, chain, ...), $field names the
 * rule field the finding belongs to (source, target, status, match_type, conditions, expires_at, ...),
 * $message is an English fallback text; the UI translates by code and uses $params for the details.
 */
final readonly class ValidationIssue
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $field,
        public string $message,
        public array $params = [],
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function error(string $code, string $field, string $message, array $params = []): self
    {
        return new self(Severity::Error, $code, $field, $message, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function warning(string $code, string $field, string $message, array $params = []): self
    {
        return new self(Severity::Warning, $code, $field, $message, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function info(string $code, string $field, string $message, array $params = []): self
    {
        return new self(Severity::Info, $code, $field, $message, $params);
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    /**
     * @return array{code: string, severity: string, field: string, message: string, params: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'field' => $this->field,
            'message' => $this->message,
            'params' => $this->params,
        ];
    }
}
