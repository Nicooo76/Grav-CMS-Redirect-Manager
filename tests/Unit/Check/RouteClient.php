<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Check;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test double: answers requests from a route table and records them.
 *
 * Route keys are "METHOD url" or just "url". Values are ['code' => int, 'location' => string],
 * ['error' => string], or a callable (method, url, options) returning a response.
 */
final class RouteClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<int> size of the response set of every stream() call */
    public array $streamSizes = [];

    /** @var (callable(list<ResponseInterface>): iterable<array{0: ResponseInterface, 1: ChunkInterface}>)|null Chunks yielded before the real ones */
    public $streamHook;

    private MockHttpClient $inner;

    /**
     * @param array<string, mixed> $routes
     */
    public function __construct(private array $routes = [])
    {
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $normalized = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $lines) {
                $normalized[$name] = implode(', ', array_map(static fn (string $l): string => substr($l, (int) strpos($l, ':') + 2), $lines));
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options + ['flat_headers' => $normalized]];
            $route = $this->routes[$method . ' ' . $url] ?? $this->routes[$url] ?? ['code' => 200];
            if (is_callable($route)) {
                $route = $route($method, $url, $options);
            }
            if ($route instanceof ResponseInterface) {
                return $route;
            }
            if (isset($route['error'])) {
                return new MockResponse('', ['error' => $route['error']]);
            }
            $headers = isset($route['location']) ? ['Location: ' . $route['location']] : [];

            return new MockResponse('', ['http_code' => $route['code'], 'response_headers' => $headers]);
        });
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $list = $responses instanceof ResponseInterface ? [$responses] : array_values(is_array($responses) ? $responses : iterator_to_array($responses));
        $this->streamSizes[] = count($list);
        $real = $this->inner->stream($list, $timeout);
        if ($this->streamHook === null) {
            return $real;
        }
        $pre = ($this->streamHook)($list);
        $generator = (static function () use ($pre, $real) {
            foreach ($pre as [$response, $chunk]) {
                yield $response => $chunk;
            }
            yield from $real;
        })();

        return new ResponseStream($generator);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $this->requests);
    }

    public function header(int $index, string $name): ?string
    {
        return $this->requests[$index]['options']['flat_headers'][strtolower($name)] ?? null;
    }
}
