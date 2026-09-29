<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * A response before it becomes PSR-7. Keeps RedirectResponder free of PSR-7 so it runs in plain unit tests;
 * toPsr7() builds the Nyholm response Grav 2 ships.
 */
final readonly class ResponseData
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function toPsr7(): ResponseInterface
    {
        return new Response($this->status, $this->headers, $this->body);
    }
}
