<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/**
 * Everything the matcher may look at, detached from Grav and PSR-7.
 *
 * $path is the normalized request path WITHOUT base path and WITHOUT the language prefix
 * (see PathNormalizer). $query holds the decoded query parameters as PHP parses them.
 * Header names are lowercase.
 */
final readonly class RequestContext
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public string $path,
        public array $query = [],
        public string $host = '',
        public string $scheme = 'https',
        public ?string $language = null,
        public array $headers = [],
        public array $cookies = [],
    ) {
    }

    public function withPath(string $path): self
    {
        return new self($path, $this->query, $this->host, $this->scheme, $this->language, $this->headers, $this->cookies);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        return new self($this->path, $query, $this->host, $this->scheme, $this->language, $this->headers, $this->cookies);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function queryString(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }
}
