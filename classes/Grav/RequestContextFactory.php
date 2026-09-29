<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a RequestContext from a raw request. Runs at PluginsLoadedEvent, before Grav's Uri and Language
 * services exist, so it strips the base path and the language prefix itself (see docs/GRAV2-NOTES.md, section 2).
 *
 * Base path: PHP_SELF up to "index.php" like Uri::buildRootPath(); a path in system.custom_base_url wins.
 * Language prefix: first path segment equal to one of system.languages.supported (only when languages are
 * configured). Invalid paths (NUL bytes, bad UTF-8, too long) return null: never redirect those.
 *
 * Settings:
 *  custom_base_url      system.custom_base_url
 *  languages            system.languages.supported
 *  trust_proxy_headers  security.trust_proxy_headers (X-Forwarded-Host / -Proto)
 *  excluded_paths       redirects.excluded_paths
 *  api_route            plugins.api.route (default /api)
 *  admin_route          plugins.admin2.route (default /admin)
 *
 * @phpstan-type Settings array{
 *     custom_base_url?: string,
 *     languages?: list<string>,
 *     trust_proxy_headers?: bool,
 *     excluded_paths?: list<string>,
 *     api_route?: string,
 *     admin_route?: string
 * }
 */
final class RequestContextFactory
{
    /** Path prefixes of Grav's own directories; never redirected. */
    private const RESERVED = ['/user/', '/system/', '/vendor/', '/cache/', '/logs/'];

    /** @var list<string> */
    private array $languages;

    /** @var list<string> */
    private array $excluded;

    /**
     * @param Settings $settings
     */
    public function __construct(private readonly array $settings = [])
    {
        $languages = [];
        foreach ($settings['languages'] ?? [] as $code) {
            $code = strtolower(trim($code));
            if ($code !== '') {
                $languages[] = $code;
            }
        }
        $this->languages = array_values(array_unique($languages));

        $excluded = [];
        foreach ([$settings['api_route'] ?? '/api', $settings['admin_route'] ?? '/admin', ...($settings['excluded_paths'] ?? [])] as $prefix) {
            $prefix = '/' . trim(rtrim(trim($prefix), '*'), '/');
            if ($prefix !== '/') {
                $excluded[] = strtolower($prefix);
            }
        }
        $this->excluded = array_values(array_unique($excluded));
    }

    /**
     * @param array<string, mixed> $server $_SERVER or an equivalent
     */
    public function fromRequest(ServerRequestInterface $request, array $server): ?RequestContextResult
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $cookies = [];
        foreach ($request->getCookieParams() as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }
        $uri = $request->getUri();

        return $this->create($uri->getPath(), $uri->getQuery(), $headers, $cookies, $server, $request->getMethod());
    }

    /**
     * @param array<string, string> $headers any header name casing
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     */
    public function create(
        string $rawPath,
        string $rawQuery,
        array $headers,
        array $cookies,
        array $server,
        string $method = 'GET',
    ): ?RequestContextResult {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = (string) $value;
        }

        $basePath = $this->basePath($server);
        $path = $rawPath === '' ? '/' : $rawPath;
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $next = substr($path, strlen($basePath), 1);
            if ($next === '' || $next === '/') {
                $path = substr($path, strlen($basePath));
            }
        }
        $path = preg_replace('#^/*#', '/', $path) ?? '/';

        $language = null;
        $prefix = '';
        if ($this->languages !== []) {
            $first = explode('/', ltrim($path, '/'), 2)[0];
            $segment = strtolower(rawurldecode($first));
            if ($segment !== '' && in_array($segment, $this->languages, true)) {
                $language = $segment;
                $prefix = '/' . $segment;
                $path = substr($path, strlen('/' . $first));
                if ($path === '') {
                    $path = '/';
                }
            }
        }

        try {
            $normalized = PathNormalizer::normalize($path);
        } catch (InvalidPathException) {
            return null;
        }

        parse_str($rawQuery, $query);
        /** @var array<string, mixed> $query */

        $context = new RequestContext(
            path: $normalized,
            query: $query,
            host: $this->host($lower, $server),
            scheme: $this->scheme($lower, $server),
            language: $language,
            headers: $lower,
            cookies: $cookies,
        );

        return new RequestContextResult($context, $basePath, $prefix, $rawPath, $rawQuery, strtoupper($method));
    }

    /**
     * Whether a normalized path (no base path, no language prefix) must never be redirected: the API route,
     * the Admin 2 route, configured excluded paths and Grav's own directories.
     */
    public function isExcluded(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::RESERVED as $reserved) {
            if (str_starts_with($lower, $reserved)) {
                return true;
            }
        }
        foreach ($this->excluded as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function basePath(array $server): string
    {
        $custom = rtrim(trim($this->settings['custom_base_url'] ?? ''), '/');
        if ($custom !== '') {
            $parts = parse_url($custom);
            $path = is_array($parts) && isset($parts['path']) ? rtrim($parts['path'], '/') : '';

            return $path;
        }

        $self = $server['PHP_SELF'] ?? $server['SCRIPT_NAME'] ?? '';
        $self = str_replace('\\', '/', is_string($self) ? $self : '');
        $pos = strpos($self, 'index.php');
        if ($pos === false) {
            return '';
        }

        return str_replace(' ', '%20', rtrim(substr($self, 0, $pos), '/'));
    }

    /**
     * @param array<string, string> $headers lowercase names
     * @param array<string, mixed>  $server
     */
    private function host(array $headers, array $server): string
    {
        $host = '';
        if (($this->settings['trust_proxy_headers'] ?? false) && ($headers['x-forwarded-host'] ?? '') !== '') {
            $host = trim(explode(',', $headers['x-forwarded-host'])[0]);
        }
        if ($host === '') {
            // Grav's PSR-7 request may carry Host twice (header and URI): the first value wins.
            $host = trim(explode(',', $headers['host'] ?? '')[0]);
        }
        if ($host === '') {
            $fallback = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
            $host = is_string($fallback) ? $fallback : '';
        }
        $host = strtolower(trim($host));
        if (preg_match('/^(\[[^\]]+\]|[^:]+)(?::\d+)?$/', $host, $m) === 1) {
            $host = $m[1];
        }

        return $host;
    }

    /**
     * @param array<string, string> $headers lowercase names
     * @param array<string, mixed>  $server
     */
    private function scheme(array $headers, array $server): string
    {
        if (($this->settings['trust_proxy_headers'] ?? false) && ($headers['x-forwarded-proto'] ?? '') !== '') {
            $proto = strtolower(trim(explode(',', $headers['x-forwarded-proto'])[0]));
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        $requestScheme = $server['REQUEST_SCHEME'] ?? '';
        if (is_string($requestScheme) && in_array(strtolower($requestScheme), ['http', 'https'], true)) {
            return strtolower($requestScheme);
        }

        return ((string) ($server['SERVER_PORT'] ?? '')) === '443' ? 'https' : 'http';
    }
}
