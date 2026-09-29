<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

/** One HTTP answer from the test server. Header names are lowercased. */
final readonly class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }

    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    /** Compact description for assertion messages. */
    public function describe(): string
    {
        return sprintf('%d Location=%s X-Redirect-By=%s', $this->status, $this->location() ?? '-', $this->header('x-redirect-by') ?? '-');
    }
}
