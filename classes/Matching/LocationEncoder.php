<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Matching;

/**
 * Makes a location safe to put into a Location header.
 *
 * Characters that are not allowed in a URL (spaces, non-ASCII, control characters and "<>\^`{|}) are
 * percent-encoded byte by byte. Existing valid %XX sequences, reserved characters (":/?#[]@!$&'()*+,;=")
 * and the scheme and port of absolute URLs stay as they are. A lone "%" becomes "%25". IDN hosts turn
 * into punycode when ext-intl is available.
 */
final class LocationEncoder
{
    public static function encode(string $location): string
    {
        if (preg_match('#^([a-z][a-z0-9+.\-]*://)([^/?\\#]*)(.*)$#is', $location, $m) === 1) {
            return $m[1] . self::encodeAuthority($m[2]) . self::encodePart($m[3]);
        }

        return self::encodePart($location);
    }

    private static function encodePart(string $part): string
    {
        return (string) preg_replace_callback(
            '/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/',
            static fn (array $m): string => sprintf('%%%02X', ord($m[0])),
            $part,
        );
    }

    private static function encodeAuthority(string $authority): string
    {
        $at = strrpos($authority, '@');
        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);

        $port = '';
        if (preg_match('/^(\[[^\]]*\]|[^:]*)(:\d*)?$/', $hostPort, $m) === 1) {
            $hostPort = $m[1];
            $port = $m[2] ?? '';
        }
        if (preg_match('/[^\x20-\x7E]/', $hostPort) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($hostPort, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $hostPort = $ascii;
            }
        }

        return self::encodePart($userInfo) . self::encodePart($hostPort) . $port;
    }
}
