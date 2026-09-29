<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Cheap path helpers shared by the analysis classes. Not for request matching (that is the Matcher).
 */
final class PathKey
{
    /**
     * Normalized path of a rule source or a target path, or null when it cannot be normalized.
     * Plain printable ASCII paths skip the full normalizer, which is the common case with 10,000 rules.
     */
    public static function normalized(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '/' && strlen($raw) <= PathNormalizer::MAX_LENGTH
            && strpbrk($raw, '%\\') === false
            && !str_contains($raw, '//') && !str_contains($raw, '/.')
            && preg_match('~^[\x20-\x7E]+$~D', $raw) === 1) {
            return $raw;
        }
        try {
            return PathNormalizer::normalize($raw);
        } catch (InvalidPathException) {
            return null;
        }
    }

    /**
     * Comparison key: no trailing slash, lower case. Two paths that any rule variant could treat as equal share a key.
     */
    public static function fold(string $normalized): string
    {
        $stripped = PathNormalizer::stripTrailingSlash($normalized);

        return preg_match('~[^\x00-\x7F]~', $stripped) === 1 ? PathNormalizer::lower($stripped) : strtolower($stripped);
    }

    /** Path part of a location ("/a/b?x=1#f" gives "/a/b"). */
    public static function pathOf(string $location): string
    {
        return substr($location, 0, strcspn($location, '?#'));
    }

    /**
     * Query parameters of a location or of a raw query string.
     *
     * @return array<string, mixed>
     */
    public static function queryOf(string $location): array
    {
        $mark = strpos($location, '?');
        if ($mark === false) {
            return [];
        }
        $query = substr($location, $mark + 1);
        $hash = strpos($query, '#');
        if ($hash !== false) {
            $query = substr($query, 0, $hash);
        }

        return self::parseQuery($query);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }
        parse_str($query, $parsed);
        $out = [];
        foreach ($parsed as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }
}
