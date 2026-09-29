<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

/** One answer of the REST API: status, lowercase header names, raw body and the decoded JSON. */
final readonly class ApiResponse
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed>|null   $json
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?array $json,
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }

    /** The `data` member of the envelope (list or map), null when absent. */
    public function data(): mixed
    {
        return $this->json['data'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->json['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    /**
     * The `errors` of a 422 problem document.
     *
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        $errors = $this->json['errors'] ?? [];

        return is_array($errors) ? array_values($errors) : [];
    }

    /** Codes of the `errors` entries, for compact assertions. */
    public function errorCodes(): array
    {
        return array_map(static fn (array $e): string => (string) ($e['code'] ?? ''), $this->errors());
    }

    public function describe(): string
    {
        return sprintf('HTTP %d %s', $this->status, substr($this->body, 0, 600));
    }
}
