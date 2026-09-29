<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Cleans request data before it is stored. Everything a visitor sends is hostile input for a log file.
 *
 * - text(): valid UTF-8 (invalid bytes become "?"), no control characters (incl. CR, LF, NUL, DEL),
 *   ANSI escape sequences removed, invisible and bidi override characters removed, cut to a byte
 *   limit without splitting a multibyte character.
 * - referer(): scheme + host (+ port) + path only. User info, query and fragment are dropped
 *   because they may carry credentials or personal data. Anything but http(s) becomes "".
 * - query(): parameters whose name looks sensitive get the value "***": names that are or end in
 *   email / mail / token / password / passwd / pwd / pass / secret / key / apikey / session / sessionid /
 *   sid / phpsessid / auth / otp / sig / signature / credential / jwt / bearer / cookie. Matching is
 *   done on whole name parts ("api_key", "accessToken", "user-email"), so "keyword" or "inside"
 *   are not touched. Values that look like an email address are redacted whatever the name.
 */
final class FieldSanitizer
{
    private const SENSITIVE_PARTS = [
        'email', 'mail', 'token', 'password', 'passwd', 'pwd', 'pass', 'passphrase', 'secret', 'key', 'apikey',
        'session', 'sessionid', 'sid', 'phpsessid', 'auth', 'authorization', 'otp', 'sig', 'signature',
        'credential', 'credentials', 'jwt', 'bearer', 'cookie',
    ];

    private const SENSITIVE_SUFFIXES = ['token', 'password', 'passwd', 'secret', 'apikey', 'sessionid', 'email', 'phpsessid'];

    public static function text(string $value, int $maxBytes): string
    {
        $value = substr($value, 0, $maxBytes * 4 + 16);
        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace(
            ['/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?/', '/\x1B\[[0-?]*[ -\/]*[@-~]?/'],
            '',
            $value,
        );
        $value = (string) preg_replace(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u',
            '',
            $value,
        );

        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }

    public static function referer(?string $referer, int $maxBytes): string
    {
        if ($referer === null || $referer === '') {
            return '';
        }
        $clean = self::text($referer, 4096);
        $parts = parse_url($clean);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return self::text($scheme . '://' . $host . $port . ($parts['path'] ?? ''), $maxBytes);
    }

    public static function query(string $query, int $maxBytes): string
    {
        $query = self::text(ltrim($query, '?'), $maxBytes * 4);
        if ($query === '') {
            return '';
        }

        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = substr($pair, 0, $eq);
            $value = substr($pair, $eq + 1);
            if ($value !== '' && (self::isSensitiveName($name) || self::looksLikeEmail($value))) {
                $pairs[$i] = $name . '=***';
            }
        }

        return mb_strcut(implode('&', $pairs), 0, $maxBytes, 'UTF-8');
    }

    /** Language tag such as "de", "de-DE", "pt_BR"; null when it does not look like one. */
    public static function language(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }
        $language = strtolower(trim($language));

        return preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8}){0,3}$/', $language) === 1 ? $language : null;
    }

    public static function host(string $host): string
    {
        return strtolower(self::text(trim($host), 255));
    }

    private static function isSensitiveName(string $rawName): bool
    {
        $name = rawurldecode(str_replace('+', ' ', $rawName));
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name);
        $spaced = strtolower($spaced);

        $parts = preg_split('/[^a-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts === false ? [] : $parts as $part) {
            if (in_array($part, self::SENSITIVE_PARTS, true)) {
                return true;
            }
        }

        $compact = (string) preg_replace('/[^a-z0-9]+/', '', $spaced);
        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($compact, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeEmail(string $rawValue): bool
    {
        $value = rawurldecode(str_replace('+', ' ', $rawValue));

        return preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $value) === 1;
    }
}
