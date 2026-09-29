<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit;

use FilesystemIterator;
use Grav\Plugin\RedirectManager\Grav\RouteRegistrar;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\RouteCollector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/openapi.yaml must be a well-formed OpenAPI 3.1 document and must describe exactly the routes the plugin
 * registers in onApiRegisterRoutes() (and any other place that registers routes the same way).
 */
#[CoversNothing]
#[Group('api')]
final class OpenApiTest extends TestCase
{
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
    private const PERMISSIONS = ['api.redirects.read', 'api.redirects.manage'];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        if (self::$spec === null) {
            /** @var array<string, mixed> $spec */
            $spec = Yaml::parseFile(self::root() . '/docs/openapi.yaml');
            self::$spec = $spec;
        }

        return self::$spec;
    }

    /**
     * Every operation of the document as "METHOD /path" => operation array.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function operations(): array
    {
        $out = [];
        $paths = self::spec()['paths'] ?? [];
        self::assertIsArray($paths);
        foreach ($paths as $path => $item) {
            self::assertIsArray($item, sprintf('Path item %s must be a mapping.', (string) $path));
            foreach (self::HTTP_METHODS as $method) {
                if (isset($item[$method])) {
                    self::assertIsArray($item[$method], sprintf('%s %s must be a mapping.', strtoupper($method), (string) $path));
                    $out[strtoupper($method) . ' ' . $path] = $item[$method];
                }
            }
        }

        return $out;
    }

    /**
     * Routes registered in the plugin: `$routes->get('/redirects/...', [...])` in redirect-manager.php and classes/.
     *
     * @return list<string> "METHOD /path", in file order, duplicates kept
     */
    private static function registeredRoutes(): array
    {
        $files = [self::root() . '/redirect-manager.php'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/classes', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $routes = [];
        foreach ($files as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code, 'Cannot read ' . $file);
            if (preg_match_all('/\$\w+->(get|post|patch|put|delete)\(\s*\'(\/[^\']*)\'/', $code, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $routes[] = strtoupper($m[1]) . ' ' . $m[2];
            }
        }

        return $routes;
    }

    public function testDocumentParsesAndHasTheRequiredSections(): void
    {
        $spec = self::spec();

        self::assertMatchesRegularExpression('/^3\.1\.\d+$/', (string) ($spec['openapi'] ?? ''), 'openapi must be 3.1.x');
        self::assertIsArray($spec['info'] ?? null);
        self::assertSame('Redirect Manager API', $spec['info']['title'] ?? null);
        self::assertNotSame('', (string) ($spec['info']['version'] ?? ''));
        self::assertIsArray($spec['paths'] ?? null);
        self::assertNotSame([], $spec['paths']);
        self::assertIsArray($spec['components'] ?? null);
        self::assertSame('/api/v1', $spec['servers'][0]['url'] ?? null);
    }

    public function testSecuritySchemesAndGlobalSecurity(): void
    {
        $spec = self::spec();
        $schemes = $spec['components']['securitySchemes'] ?? [];

        self::assertEquals(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'], self::pick($schemes['apiKey'] ?? [], ['type', 'in', 'name']));
        self::assertEquals(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Token'], self::pick($schemes['jwtHeader'] ?? [], ['type', 'in', 'name']));
        self::assertEquals(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], self::pick($schemes['bearerAuth'] ?? [], ['type', 'scheme', 'bearerFormat']));

        $global = [];
        foreach ($spec['security'] ?? [] as $requirement) {
            $global = [...$global, ...array_keys($requirement)];
        }
        sort($global);
        self::assertSame(['apiKey', 'bearerAuth', 'jwtHeader'], $global, 'The global security must offer each scheme as an alternative.');
    }

    public function testEveryReferenceResolves(): void
    {
        $spec = self::spec();
        $broken = [];
        $count = 0;
        self::walk($spec, '#', static function (string $ref, string $where) use ($spec, &$broken, &$count): void {
            ++$count;
            if (!self::resolves($spec, $ref)) {
                $broken[] = sprintf('%s -> %s', $where, $ref);
            }
        });

        self::assertGreaterThan(50, $count, 'The document should reuse components through $ref.');
        self::assertSame([], $broken, "Unresolved \$ref:\n" . implode("\n", $broken));
    }

    public function testEveryOperationIsDescribedCompletely(): void
    {
        $spec = self::spec();
        $problems = [];
        $ids = [];
        $tags = array_column($spec['tags'] ?? [], 'name');

        foreach (self::operations() as $key => $op) {
            $id = $op['operationId'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-zA-Z0-9]*$/', $id) !== 1) {
                $problems[] = $key . ': operationId must be camelCase.';
            } else {
                $ids[$id][] = $key;
            }
            if (!in_array($op['x-permission'] ?? null, self::PERMISSIONS, true)) {
                $problems[] = $key . ': x-permission must be one of ' . implode(', ', self::PERMISSIONS) . '.';
            } elseif (!str_contains((string) ($op['description'] ?? ''), $op['x-permission'])) {
                $problems[] = $key . ': the description must mention ' . $op['x-permission'] . '.';
            }
            if (trim((string) ($op['summary'] ?? '')) === '') {
                $problems[] = $key . ': summary is missing.';
            }
            if (!is_array($op['tags'] ?? null) || $op['tags'] === [] || array_diff($op['tags'], $tags) !== []) {
                $problems[] = $key . ': tags must be listed in the top-level tags.';
            }
            $responses = is_array($op['responses'] ?? null) ? array_keys($op['responses']) : [];
            $success = array_filter($responses, static fn (int|string $code): bool => preg_match('/^2\d\d$/', (string) $code) === 1);
            if ($success === []) {
                $problems[] = $key . ': needs at least one 2xx response.';
            }
            foreach (['401', '403'] as $code) {
                if (!in_array($code, array_map('strval', $responses), true)) {
                    $problems[] = $key . ': responses must include ' . $code . '.';
                }
            }
            $method = strtolower(strtok($key, ' ') ?: '');
            if (in_array($method, ['post', 'patch'], true) && isset($op['requestBody']) && ($op['requestBody']['content']['application/json']['schema'] ?? null) === null) {
                $problems[] = $key . ': a request body needs an application/json schema.';
            }
            if ($method === 'get' && $op['x-permission'] === 'api.redirects.manage') {
                $problems[] = $key . ': a GET should not need the manage permission.';
            }
        }
        foreach ($ids as $id => $keys) {
            if (count($keys) > 1) {
                $problems[] = sprintf('operationId %s is used by %s.', $id, implode(' and ', $keys));
            }
        }

        self::assertSame([], $problems, "OpenAPI operation problems:\n" . implode("\n", $problems));
    }

    public function testPathParametersAreDeclared(): void
    {
        $spec = self::spec();
        $problems = [];

        foreach ($spec['paths'] as $path => $item) {
            preg_match_all('/\{([^}]+)\}/', (string) $path, $found);
            $templated = $found[1];
            sort($templated);

            foreach (self::HTTP_METHODS as $method) {
                if (!isset($item[$method])) {
                    continue;
                }
                $declared = [];
                $all = [...($item['parameters'] ?? []), ...($item[$method]['parameters'] ?? [])];
                foreach ($all as $parameter) {
                    if (isset($parameter['$ref'])) {
                        $parameter = self::lookup($spec, (string) $parameter['$ref']);
                    }
                    if (is_array($parameter) && ($parameter['in'] ?? null) === 'path') {
                        $declared[] = (string) $parameter['name'];
                        if (($parameter['required'] ?? false) !== true) {
                            $problems[] = sprintf('%s %s: path parameter %s must be required.', strtoupper($method), $path, $parameter['name']);
                        }
                    }
                }
                sort($declared);
                if ($declared !== $templated) {
                    $problems[] = sprintf(
                        '%s %s: template has {%s}, declared path parameters are [%s].',
                        strtoupper($method),
                        $path,
                        implode('}, {', $templated),
                        implode(', ', $declared),
                    );
                }
            }
        }

        self::assertSame([], $problems, "Path parameter problems:\n" . implode("\n", $problems));
    }

    public function testNoRouteIsRegisteredTwice(): void
    {
        $routes = self::registeredRoutes();
        $duplicates = array_keys(array_filter(array_count_values($routes), static fn (int $n): bool => $n > 1));

        self::assertNotSame([], $routes, 'No route registration found in redirect-manager.php; did the pattern change?');
        self::assertSame([], $duplicates, "Routes registered more than once:\n" . implode("\n", $duplicates));
    }

    public function testTheRealRegistrationMatchesTheDocumentAndTheSourceScan(): void
    {
        // What the plugin registers when the API plugin fires onApiRegisterRoutes: both listeners, in that order.
        $collector = new RouteCollector();
        RouteRegistrar::register($collector);
        RouteRegistrar::registerAuto($collector);
        $registered = array_keys($collector->routes);

        $documented = array_keys(self::operations());
        sort($registered);
        sort($documented);
        self::assertSame($documented, $registered, 'the routes the plugin registers and the routes docs/openapi.yaml describes differ');

        $scanned = self::registeredRoutes();
        sort($scanned);
        self::assertSame($scanned, $registered, 'a route is registered outside RouteRegistrar (the integration suite derives its route lists from the registrars)');
    }

    public function testOpenApiDescribesExactlyTheRegisteredRoutes(): void
    {
        $registered = array_values(array_unique(self::registeredRoutes()));
        $documented = array_keys(self::operations());

        $undocumented = array_values(array_diff($registered, $documented));
        $unregistered = array_values(array_diff($documented, $registered));
        sort($undocumented);
        sort($unregistered);

        $message = '';
        if ($undocumented !== []) {
            $message .= "Registered in PHP but missing in docs/openapi.yaml:\n  " . implode("\n  ", $undocumented) . "\n";
        }
        if ($unregistered !== []) {
            $message .= "Described in docs/openapi.yaml but not registered in PHP:\n  " . implode("\n  ", $unregistered) . "\n";
        }

        self::assertSame([], [...$undocumented, ...$unregistered], $message);
        self::assertCount(count($registered), $documented);
    }

    /**
     * @param array<mixed>         $node
     * @param callable(string, string): void $onRef
     */
    private static function walk(array $node, string $where, callable $onRef): void
    {
        foreach ($node as $key => $value) {
            $here = $where . '/' . $key;
            if ($key === '$ref' && is_string($value)) {
                $onRef($value, $where);
                continue;
            }
            if (is_array($value)) {
                self::walk($value, $here, $onRef);
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function resolves(array $spec, string $ref): bool
    {
        return str_starts_with($ref, '#/') && self::lookup($spec, $ref) !== null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function lookup(array $spec, string $ref): mixed
    {
        $node = $spec;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<mixed>  $from
     * @param list<string>  $keys
     *
     * @return array<string, mixed>
     */
    private static function pick(array $from, array $keys): array
    {
        return array_intersect_key($from, array_flip($keys));
    }
}
