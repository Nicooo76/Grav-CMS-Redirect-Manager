<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/** A frontend request as Grav's PSR-7 request object presents it: method, path, query, headers, cookies. */
final class FakeFrontendRequest implements ServerRequestInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        private readonly string $path,
        private readonly string $query = '',
        private readonly array $headers = [],
        private readonly string $method = 'GET',
        private readonly array $cookies = [],
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return new class ($this->path, $this->query) implements UriInterface {
            public function __construct(private readonly string $path, private readonly string $query)
            {
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getQuery(): string
            {
                return $this->query;
            }

            public function __toString(): string
            {
                return $this->path . ($this->query !== '' ? '?' . $this->query : '');
            }
        };
    }

    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    public function getQueryParams(): array
    {
        parse_str($this->query, $query);

        return $query;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $default;
    }

    public function getHeaders(): array
    {
        return array_map(static fn (string $v): array => [$v], $this->headers);
    }

    public function getHeaderLine(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    public function getBody(): StreamInterface
    {
        return new class () implements StreamInterface {
            public function getContents(): string
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }
        };
    }
}
