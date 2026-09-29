<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/**
 * Small helpers to move between exact paths, wildcards and regular expressions.
 * Regexes here are PCRE bodies without delimiters, like Rule::$source for regex rules.
 */
final class Pattern
{
    private const META = '.^$*+?()[]{}|';

    /** Escapes a literal path for use inside a regex, leaving readable characters alone. */
    public static function escape(string $literal): string
    {
        return strtr(preg_quote($literal), [
            '\\-' => '-', '\\#' => '#', '\\:' => ':', '\\=' => '=', '\\!' => '!', '\\<' => '<', '\\>' => '>',
        ]);
    }

    /** `/blog/*` becomes `^/blog/(.*)$`. */
    public static function wildcardToRegex(string $wildcard): string
    {
        return '^' . implode('(.*)', array_map(self::escape(...), explode('*', $wildcard))) . '$';
    }

    /** Whether the regex body compiles (with the unicode flag, like the matcher uses it). */
    public static function isValid(string $regex): bool
    {
        if ($regex === '') {
            return false;
        }

        return @preg_match("\x01" . $regex . "\x01u", '') !== false;
    }

    /**
     * Recognises `^/path$` and `^/path/?$` (optionally with a leading `(?i)`).
     *
     * @return array{path: string, trailingSlash: bool, caseInsensitive: bool}|null
     */
    public static function literalOf(string $regex): ?array
    {
        [$body, $ci] = self::stripCaseFlag($regex);
        $anchored = self::anchored($body);
        if ($anchored === null) {
            return null;
        }
        $slash = false;
        if (str_ends_with($anchored, '/?')) {
            $slash = true;
            $anchored = substr($anchored, 0, -2);
        }
        $path = self::unescape($anchored);
        if ($path === null) {
            return null;
        }

        return ['path' => $path, 'trailingSlash' => $slash, 'caseInsensitive' => $ci];
    }

    /**
     * Recognises `^/blog/(.*)$` and returns the wildcard `/blog/*`.
     *
     * @return array{wildcard: string, caseInsensitive: bool}|null
     */
    public static function wildcardOf(string $regex): ?array
    {
        [$body, $ci] = self::stripCaseFlag($regex);
        if (!str_starts_with($body, '^')) {
            return null;
        }
        $body = substr($body, 1);
        if (str_ends_with($body, '$') && !self::endsWithEscape(substr($body, 0, -1))) {
            $body = substr($body, 0, -1);
        } elseif (!str_ends_with($body, '(.*)')) {
            return null;
        }
        $parts = explode('(.*)', $body);
        if (count($parts) < 2) {
            return null;
        }
        $out = [];
        foreach ($parts as $part) {
            $literal = self::unescape($part);
            if ($literal === null) {
                return null;
            }
            $out[] = $literal;
        }

        return ['wildcard' => implode('*', $out), 'caseInsensitive' => $ci];
    }

    /**
     * Strips a leading `(?i)`.
     *
     * @return array{0: string, 1: bool}
     */
    public static function stripCaseFlag(string $regex): array
    {
        if (str_starts_with($regex, '(?i)')) {
            return [substr($regex, 4), true];
        }

        return [$regex, false];
    }

    /**
     * Capture group names in order of appearance: name for named groups, null for numbered ones.
     *
     * @return list<string|null>
     */
    public static function groups(string $regex): array
    {
        $groups = [];
        $length = strlen($regex);
        $inClass = false;
        for ($i = 0; $i < $length; ++$i) {
            $c = $regex[$i];
            if ($c === '\\') {
                ++$i;
                continue;
            }
            if ($inClass) {
                if ($c === ']') {
                    $inClass = false;
                }
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                if (($regex[$i + 1] ?? '') === '^') {
                    ++$i;
                }
                if (($regex[$i + 1] ?? '') === ']') {
                    ++$i;
                }
                continue;
            }
            if ($c !== '(') {
                continue;
            }
            if (($regex[$i + 1] ?? '') !== '?') {
                $groups[] = null;
                continue;
            }
            if (preg_match('/\G\(\?(?:P?<([A-Za-z_][A-Za-z0-9_]*)>|\'([A-Za-z_][A-Za-z0-9_]*)\')/', $regex, $m, 0, $i) === 1) {
                $groups[] = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            }
        }

        return $groups;
    }

    /**
     * Replaces `{name}` placeholders in a target by `$n` using the regex's group order.
     * Returns null when a name is not a group of the regex.
     */
    public static function namedToNumbered(string $target, string $regex): ?string
    {
        $groups = self::groups($regex);
        $failed = false;
        $out = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function (array $m) use ($groups, &$failed): string {
            $index = array_search($m[1], $groups, true);
            if ($index === false) {
                $failed = true;

                return $m[0];
            }

            return '$' . ($index + 1);
        }, $target);

        return $failed || $out === null ? null : $out;
    }

    /**
     * Host list from a host regex: `^example\.com$`, `^(www\.)?example\.com$`, `^(a\.com|b\.com)$`,
     * `^[^.]+\.example\.com$` (becomes `*.example.com`). Null when the pattern is not that simple.
     *
     * @return list<string>|null
     */
    public static function hostsOf(string $regex): ?array
    {
        [$body] = self::stripCaseFlag($regex);
        $anchored = self::anchored($body);
        if ($anchored === null) {
            return null;
        }
        $hosts = self::hostAlternatives($anchored);
        if ($hosts === null) {
            return null;
        }
        $hosts = array_values(array_unique($hosts));

        return $hosts === [] ? null : $hosts;
    }

    /**
     * @param list<string> $hosts
     */
    public static function hostsToRegex(array $hosts): string
    {
        $parts = array_map(static function (string $host): string {
            if (str_starts_with($host, '*.')) {
                return '[^.]+\.' . self::escape(substr($host, 2));
            }

            return self::escape($host);
        }, $hosts);

        return count($parts) === 1 ? '^' . $parts[0] . '$' : '^(' . implode('|', $parts) . ')$';
    }

    /**
     * Body between `^` and `$`, or null when the regex is not anchored on both ends.
     */
    private static function anchored(string $body): ?string
    {
        if (!str_starts_with($body, '^') || !str_ends_with($body, '$')) {
            return null;
        }
        $inner = substr($body, 1, -1);
        if (self::endsWithEscape($inner)) {
            return null;
        }

        return $inner;
    }

    /** True when the string ends in an odd number of backslashes (the next char would be escaped). */
    private static function endsWithEscape(string $s): bool
    {
        $n = strlen($s) - strlen(rtrim($s, '\\'));

        return $n % 2 === 1;
    }

    /** Unescapes a run of literal characters; null when it contains regex syntax. */
    public static function unescape(string $s): ?string
    {
        $out = '';
        $length = strlen($s);
        for ($i = 0; $i < $length; ++$i) {
            $c = $s[$i];
            if ($c === '\\') {
                $next = $s[$i + 1] ?? null;
                if ($next === null || ctype_alnum($next)) {
                    return null;
                }
                $out .= $next;
                ++$i;
                continue;
            }
            if (str_contains(self::META, $c)) {
                return null;
            }
            $out .= $c;
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    private static function hostAlternatives(string $body): ?array
    {
        if (preg_match('/^\(www\\\\\.\)\?(.+)$/', $body, $m) === 1) {
            $base = self::hostLiteral($m[1]);

            return $base === null ? null : [$base, 'www.' . $base];
        }
        if (preg_match('/^(?:\(\.\+\\\\\.\)\?|\(\.\*\\\\\.\)\?|\(\[\^\.\]\+\\\\\.\)\?)(.+)$/', $body, $m) === 1) {
            $base = self::hostLiteral($m[1]);

            return $base === null ? null : [$base, '*.' . $base];
        }
        if (preg_match('/^(?:\[\^\.\]\+|\.\+|\.\*)\\\\\.(.+)$/', $body, $m) === 1) {
            $base = self::hostLiteral($m[1]);

            return $base === null ? null : ['*.' . $base];
        }
        if (str_starts_with($body, '(') && str_ends_with($body, ')') && substr_count($body, '(') === 1) {
            $out = [];
            foreach (explode('|', substr($body, 1, -1)) as $alt) {
                $sub = self::hostAlternatives($alt);
                if ($sub === null) {
                    return null;
                }
                array_push($out, ...$sub);
            }

            return $out;
        }
        $host = self::hostLiteral($body);

        return $host === null ? null : [$host];
    }

    private static function hostLiteral(string $escaped): ?string
    {
        $host = self::unescape($escaped);
        if ($host === null) {
            return null;
        }
        $host = strtolower($host);

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) === 1 ? $host : null;
    }
}
