<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Support;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A server request for the API controller tests: query string, JSON body (the `json_body` attribute the API plugin's
 * middleware sets), route parameters and headers.
 */
final class FakeServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $routeParams
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $routeParams = [],
        private readonly array $headers = [],
    ) {
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getUri(): UriInterface
    {
        return new class ($this->query) implements UriInterface {
            /** @param array<string, mixed> $query */
            public function __construct(private readonly array $query)
            {
            }

            public function getPath(): string
            {
                return '/';
            }

            public function getQuery(): string
            {
                return http_build_query($this->query);
            }

            public function __toString(): string
            {
                return '/?' . $this->getQuery();
            }
        };
    }

    public function getCookieParams(): array
    {
        return [];
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return match ($name) {
            'json_body' => $this->body === [] ? $default : $this->body,
            'route_params' => $this->routeParams,
            default => $default,
        };
    }

    /** @return array<string, mixed> */
    public function getParsedBody(): array
    {
        return $this->body;
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
        $json = (string) json_encode($this->body);

        return new class ($json) implements StreamInterface {
            public function __construct(private readonly string $content)
            {
            }

            public function getContents(): string
            {
                return $this->content;
            }

            public function __toString(): string
            {
                return $this->content;
            }
        };
    }
}
