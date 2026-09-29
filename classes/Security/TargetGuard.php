<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Security;

use Grav\Plugin\RedirectManager\Domain\TargetType;

/**
 * Open-redirect protection, in two places:
 *
 * - checkTarget() runs when a rule is saved and returns an error code for targets that must not be stored.
 * - isSafeLocation() runs for every redirect, after placeholders were substituted, because a capture
 *   such as "/evil.com" turns the harmless template "/$1" into "//evil.com".
 *
 * Allowlist entries are host names. "example.com" matches exactly that host. "*.example.com" matches
 * subdomains only (www.example.com, a.b.example.com), NOT the apex; list both to allow both.
 */
final class TargetGuard
{
    public const ERR_EMPTY = 'target_empty';
    public const ERR_PROTOCOL_RELATIVE = 'target_protocol_relative';
    public const ERR_SCHEME = 'target_scheme';
    public const ERR_HOST_NOT_ALLOWED = 'target_host_not_allowed';
    public const ERR_INVALID = 'target_invalid';

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(array $allowedHosts = [], private readonly bool $allowAnyExternal = false)
    {
        $hosts = [];
        foreach ($allowedHosts as $host) {
            $host = self::normalizeHost($host);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        $this->allowedHosts = $hosts;
    }

    /**
     * Save-time check. Returns an error code or null when the target may be stored.
     */
    public function checkTarget(string $target, TargetType $type): ?string
    {
        if (trim($target) === '') {
            return self::ERR_EMPTY;
        }
        if (self::hasControlChars($target) || self::hasControlChars(rawurldecode($target))) {
            return self::ERR_INVALID;
        }

        return $type === TargetType::Url ? $this->checkUrl($target) : $this->internalError($target);
    }

    /**
     * Runtime check of a fully built location. Relative locations must be a local absolute path,
     * absolute ones must be http(s) to the current host or an allowed host.
     */
    public function isSafeLocation(string $location, string $currentHost): bool
    {
        if ($location === '' || self::hasControlChars($location)) {
            return false;
        }
        if ($location[0] === '/') {
            return $this->internalError($location) === null;
        }

        $parts = self::parseAbsolute($location);
        if ($parts === null) {
            return false;
        }
        $host = $parts['host'];
        $current = self::normalizeHost((string) preg_replace('/:\d*$/', '', $currentHost));
        if ($current !== '' && $host === $current) {
            return true;
        }

        return $this->isHostAllowed($host);
    }

    public function isHostAllowed(string $host): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }
        if ($this->allowAnyExternal) {
            return true;
        }
        foreach ($this->allowedHosts as $allowed) {
            if ($allowed === $host) {
                return true;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".example.com"
                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function internalError(string $target): ?string
    {
        if ($target[0] !== '/') {
            if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) === 1) {
                return self::ERR_SCHEME;
            }
            if (str_starts_with($target, '\\\\') || str_starts_with($target, '\\/')) {
                return self::ERR_PROTOCOL_RELATIVE;
            }

            return self::ERR_INVALID;
        }
        if (self::startsWithSecondSeparator($target)) {
            return self::ERR_PROTOCOL_RELATIVE;
        }
        // "/%2F%2Fevil.com" and "/%5Cevil.com": look at the decoded form too (repeatedly, for double encoding).
        $decoded = $target;
        for ($i = 0; $i < 3; ++$i) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
            if (self::startsWithSecondSeparator($decoded)) {
                return self::ERR_PROTOCOL_RELATIVE;
            }
        }

        return null;
    }

    private function checkUrl(string $target): ?string
    {
        if (str_contains($target, '\\')) {
            return self::ERR_INVALID;
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $target, $m) !== 1) {
            return str_starts_with($target, '//') ? self::ERR_PROTOCOL_RELATIVE : self::ERR_INVALID;
        }
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return self::ERR_SCHEME;
        }
        $parts = self::parseAbsolute($target);
        if ($parts === null) {
            return self::ERR_INVALID;
        }
        // A placeholder in the authority would let a capture choose the host.
        if (preg_match('/[${}]/', $parts['authority']) === 1) {
            return self::ERR_INVALID;
        }
        if (!$this->isHostAllowed($parts['host'])) {
            return self::ERR_HOST_NOT_ALLOWED;
        }

        return null;
    }

    /**
     * Strictly parses "http(s)://host[:port]/path". Rejects user info, backslashes, missing hosts and
     * the sloppy forms browsers repair ("https:evil.com", "http:/evil.com").
     *
     * @return array{host: string, authority: string}|null
     */
    private static function parseAbsolute(string $url): ?array
    {
        if (preg_match('#^(https?)://([^/?\#]*)#i', $url, $m) !== 1) {
            return null;
        }
        $authority = $m[2];
        if ($authority === '' || str_contains($authority, '@') || str_contains($url, '\\')) {
            return null;
        }
        if (preg_match('#^(\[[0-9a-f:.]+\]|[A-Za-z0-9._\-\x80-\xFF]+)(:\d{0,5})?$#', $authority, $h) !== 1) {
            return null;
        }
        $host = self::normalizeHost($h[1]);

        return $host === '' ? null : ['host' => $host, 'authority' => $authority];
    }

    private static function startsWithSecondSeparator(string $path): bool
    {
        return isset($path[1]) && ($path[1] === '/' || $path[1] === '\\');
    }

    private static function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $wildcard = str_starts_with($host, '*.');
        if ($wildcard) {
            $host = substr($host, 2);
        }
        $host = rtrim($host, '.');
        if ($host !== '' && preg_match('/[^\x20-\x7E]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        return ($wildcard && $host !== '' ? '*.' : '') . $host;
    }
}
