<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

use Normalizer;

/**
 * Turns request paths and rule sources into one canonical form.
 *
 * The input is a raw URL path WITHOUT query string. It is percent-decoded exactly once, so "%2520"
 * becomes "%20" and never a space. Never feed an already normalized path back into normalize().
 */
final class PathNormalizer
{
    public const MAX_LENGTH = 2048;

    /**
     * @throws InvalidPathException for NUL bytes, control characters, invalid UTF-8 and paths over MAX_LENGTH
     */
    public static function normalize(string $raw): string
    {
        if (strlen($raw) > self::MAX_LENGTH * 3) {
            throw new InvalidPathException('Path is too long.');
        }
        if (str_contains($raw, "\0") || stripos($raw, '%00') !== false) {
            throw new InvalidPathException('Path contains a NUL byte.');
        }

        $path = rawurldecode($raw);

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidPathException('Path contains control characters.');
        }
        if (!mb_check_encoding($path, 'UTF-8')) {
            throw new InvalidPathException('Path is not valid UTF-8.');
        }
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
            if ($normalized === false) {
                throw new InvalidPathException('Path cannot be normalized.');
            }
            $path = $normalized;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = self::resolveDotSegments($path);

        if (strlen($path) > self::MAX_LENGTH) {
            throw new InvalidPathException('Path is too long.');
        }

        return $path;
    }

    /** Removes one trailing slash; the root "/" stays. */
    public static function stripTrailingSlash(string $path): string
    {
        if (strlen($path) <= 1 || !str_ends_with($path, '/')) {
            return $path;
        }
        $stripped = rtrim($path, '/');

        return $stripped === '' ? '/' : $stripped;
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * True when two paths are not identical but equal ignoring case (/shop/Zelte and /shop/zelte). A
     * case-insensitive rule with such a target matches its own target, which is a loop; the rule has to be
     * case-sensitive.
     */
    public static function differsOnlyInCase(string $a, string $b): bool
    {
        return $a !== $b && self::lower($a) === self::lower($b);
    }

    /**
     * Splits a rule source into path and query string (without "?").
     *
     * @return array{0: string, 1: string}
     */
    public static function splitSource(string $source): array
    {
        $pos = strpos($source, '?');
        if ($pos === false) {
            return [$source, ''];
        }

        return [substr($source, 0, $pos), substr($source, $pos + 1)];
    }

    private static function resolveDotSegments(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $segments = explode('/', $path);
        array_shift($segments); // empty part before the leading slash
        $last = count($segments) - 1;
        $out = [];
        $trailingSlash = false;
        foreach ($segments as $i => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..') {
                    array_pop($out);
                }
                $trailingSlash = $i === $last;
                continue;
            }
            if ($segment === '') {
                $trailingSlash = $i === $last;
                continue;
            }
            $out[] = $segment;
            $trailingSlash = false;
        }
        if ($out === []) {
            return '/';
        }

        return '/' . implode('/', $out) . ($trailingSlash ? '/' : '');
    }
}
