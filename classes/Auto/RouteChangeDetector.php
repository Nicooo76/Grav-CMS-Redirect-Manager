<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/**
 * Tells from an update request whether a page's URL could change, so autosaves and ordinary edits never pay for a
 * snapshot. Only the front matter keys that decide the route matter: "slug" and "routes" (custom default route,
 * aliases and canonical). A false positive costs one snapshot, a false negative would miss a redirect, so
 * anything that differs counts.
 */
final class RouteChangeDetector
{
    /** Header keys that change a page's route. */
    public const KEYS = ['slug', 'routes'];

    /**
     * @param array<mixed> $current  the page header before the update
     * @param array<mixed> $incoming the request body ("header", optionally "header_mode": "replace")
     */
    public static function affectsRoute(array $current, array $incoming): bool
    {
        if (!isset($incoming['header']) || !is_array($incoming['header'])) {
            return false;
        }
        $header = $incoming['header'];
        $replace = ($incoming['header_mode'] ?? null) === 'replace';
        foreach (self::KEYS as $key) {
            $has = array_key_exists($key, $header);
            if (!$has && !$replace) {
                continue;
            }
            if (self::normalize($current[$key] ?? null) !== self::normalize($has ? $header[$key] : null)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
