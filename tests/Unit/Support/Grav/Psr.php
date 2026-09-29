<?php

declare(strict_types=1);

namespace Psr\Http\Message {
    if (!interface_exists(StreamInterface::class, false)) {
        interface StreamInterface extends \Stringable
        {
            public function getContents(): string;
        }
    }

    if (!interface_exists(MessageInterface::class, false)) {
        interface MessageInterface
        {
            /** @return array<string, list<string>> */
            public function getHeaders(): array;

            public function getHeaderLine(string $name): string;

            public function getBody(): StreamInterface;
        }
    }

    if (!interface_exists(ResponseInterface::class, false)) {
        interface ResponseInterface extends MessageInterface
        {
            public function getStatusCode(): int;
        }
    }

    if (!interface_exists(UriInterface::class, false)) {
        interface UriInterface extends \Stringable
        {
            public function getPath(): string;

            public function getQuery(): string;
        }
    }

    if (!interface_exists(ServerRequestInterface::class, false)) {
        interface ServerRequestInterface extends MessageInterface
        {
            public function getMethod(): string;

            public function getUri(): UriInterface;

            /** @return array<string, mixed> */
            public function getCookieParams(): array;

            /** @return array<string, mixed> */
            public function getQueryParams(): array;

            public function getAttribute(string $name, mixed $default = null): mixed;
        }
    }
}

namespace Nyholm\Psr7 {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\StreamInterface;

    if (!class_exists(Response::class, false)) {
        final class Response implements ResponseInterface
        {
            /** @var array<string, list<string>> */
            private array $headers = [];

            /**
             * @param array<string, string|list<string>> $headers
             */
            public function __construct(private int $status = 200, array $headers = [], private string $body = '')
            {
                foreach ($headers as $name => $value) {
                    $this->headers[(string) $name] = is_array($value) ? array_values($value) : [$value];
                }
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->headers as $key => $values) {
                    if (strcasecmp($key, $name) === 0) {
                        return implode(', ', $values);
                    }
                }

                return '';
            }

            public function getBody(): StreamInterface
            {
                $body = $this->body;

                return new class ($body) implements StreamInterface {
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
    }
}
