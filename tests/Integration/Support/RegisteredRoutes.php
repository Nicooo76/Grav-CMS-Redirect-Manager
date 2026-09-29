<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use Grav\Plugin\RedirectManager\Grav\RouteRegistrar;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\RouteCollector;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The plugin's REST routes as the plugin registers them (RouteRegistrar, the code behind both onApiRegisterRoutes
 * listeners), each with the permission docs/openapi.yaml assigns. The API security tests loop over this list, so a
 * new route is checked for authentication, permission and the same-origin rule without anyone adding it to a test.
 */
final class RegisteredRoutes
{
    /** @var array<string, array{method: string, path: string, permission: string}>|null */
    private static ?array $routes = null;

    /**
     * Extra query parameters and JSON bodies a route needs to get past validation ("METHOD /path" => [query, body]).
     * Routes not listed need none.
     */
    private const INPUT = [
        'GET /redirects/404/entries' => [['path' => '/x'], null],
        'GET /redirects/suggest' => [['path' => '/x'], null],
        'POST /redirects/rules/validate' => [[], ['source' => '/v', 'target' => '/typography']],
        'POST /redirects/test' => [[], ['url' => '/x']],
        'GET /redirects/page-context' => [['route' => '/x'], null],
        'GET /redirects/page-context/badge' => [['route' => '/x'], null],
        'POST /redirects/page-context/seen' => [[], ['route' => '/x']],
    ];

    /**
     * @return array<string, array{method: string, path: string, permission: string}> "METHOD /path" => route
     */
    public static function all(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }
        $collector = new RouteCollector();
        RouteRegistrar::register($collector);
        RouteRegistrar::registerAuto($collector);

        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/docs/openapi.yaml');
        $routes = [];
        foreach (array_keys($collector->routes) as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $permission = $spec['paths'][$path][strtolower($method)]['x-permission'] ?? null;
            if (!is_string($permission)) {
                throw new RuntimeException($key . ' has no x-permission in docs/openapi.yaml');
            }
            $routes[$key] = ['method' => $method, 'path' => $path, 'permission' => $permission];
        }

        return self::$routes = $routes;
    }

    /**
     * Routes that need the given permission.
     *
     * @return list<array{method: string, path: string, permission: string}>
     */
    public static function needing(string $permission): array
    {
        return array_values(array_filter(self::all(), static fn (array $r): bool => $r['permission'] === $permission));
    }

    /**
     * Routes that change state (every method but GET), whatever permission they need.
     *
     * @return list<array{method: string, path: string, permission: string}>
     */
    public static function writes(): array
    {
        return array_values(array_filter(self::all(), static fn (array $r): bool => $r['method'] !== 'GET'));
    }

    /**
     * @param array{method: string, path: string, permission: string} $route
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null} query and body
     */
    public static function input(array $route): array
    {
        return self::INPUT[$route['method'] . ' ' . $route['path']] ?? [[], null];
    }

    /**
     * Fills the path parameters: {id} of a rule route gets $ruleId, of a pending-decision route $pendingId, any other
     * {id} an id that does not exist.
     */
    public static function fill(string $path, string $ruleId, string $pendingId = 'nope'): string
    {
        $id = match (true) {
            str_starts_with($path, '/redirects/rules/') => $ruleId,
            str_starts_with($path, '/redirects/pending/') => $pendingId,
            default => 'nope',
        };

        return str_replace('{id}', $id, $path);
    }
}
