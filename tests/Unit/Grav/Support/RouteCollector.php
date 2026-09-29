<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support;

/**
 * Stands in for the API plugin's route collector: records "METHOD /path" => [controller class, method].
 * The same class is used by the integration suite to derive its route lists from the real registration.
 */
final class RouteCollector
{
    /** @var array<string, array{0: string, 1: string}> */
    public array $routes = [];

    /** @param array{0: string, 1: string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param array{0: string, 1: string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param array{0: string, 1: string} $handler */
    public function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    /** @param array{0: string, 1: string} $handler */
    public function patch(string $path, array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    /** @param array{0: string, 1: string} $handler */
    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    /** @param array{0: string, 1: string} $handler */
    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[$method . ' ' . $path] = $handler;
    }
}
