<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

/**
 * Small URL helpers for the checker. Pure functions, no I/O.
 *
 * @internal
 */
final class UrlTools
{
    /**
     * Normalizes an absolute http(s) URL: encodes unsafe bytes, drops the fragment, lowercases
     * scheme and host. Returns null for anything else (other scheme, no host, credentials).
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }
        if (preg_match('/^(?:[a-z0-9_.\-]+|\[[0-9a-f:.]+\])$/', $host) !== 1) {
            return null;
        }

        $out = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= self::encode($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $out .= '?' . self::encode($parts['query']);
        }

        return $out;
    }

    /** Lowercase host without IPv6 brackets, or '' when the URL has none. */
    public static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? trim(strtolower($host), '[]') : '';
    }

    /** Resolves a Location header value against the URL it came from. */
    public static function resolve(string $base, string $ref): string
    {
        $ref = trim($ref);
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref) === 1) {
            return $ref;
        }
        $parts = parse_url($base);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'http') : 'http';
        $origin = $scheme . '://' . (is_array($parts) ? ($parts['host'] ?? '') : '');
        if (is_array($parts) && isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        if ($path === '') {
            $path = '/';
        }

        if ($ref === '' || $ref[0] === '#') {
            return $base;
        }
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        if ($ref[0] === '/') {
            return $origin . self::removeDots($ref);
        }
        if ($ref[0] === '?') {
            return $origin . $path . $ref;
        }

        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . self::removeDots($dir . $ref);
    }

    /** Percent-encodes every byte outside printable ASCII. */
    public static function encode(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^\x21-\x7E]/',
            static fn (array $m): string => rawurlencode($m[0]),
            $value,
        );
    }

    private static function removeDots(string $pathAndQuery): string
    {
        $query = '';
        $qpos = strpos($pathAndQuery, '?');
        if ($qpos !== false) {
            $query = substr($pathAndQuery, $qpos);
            $pathAndQuery = substr($pathAndQuery, 0, $qpos);
        }
        $out = [];
        $segments = explode('/', $pathAndQuery);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '.') {
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out) . $query;
    }
}
