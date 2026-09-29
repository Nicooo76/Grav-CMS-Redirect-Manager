<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

/**
 * Decides whether an IP address is a public internet address.
 *
 * Private, loopback, link-local, carrier-grade NAT, multicast and reserved ranges are refused,
 * for IPv4 and IPv6, including IPv4-mapped IPv6 addresses.
 */
final class IpGuard
{
    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                $mapped = inet_ntop(substr($packed, 12));

                return $mapped !== false && self::isPublic($mapped);
            }
            if (str_starts_with($packed, str_repeat("\0", 12))) {
                return false;
            }
            if ((ord($packed[0]) & 0xFF) === 0xFF) {
                return false;
            }
        } else {
            $long = unpack('N', $packed);
            $value = is_array($long) ? (int) $long[1] : 0;
            // 100.64.0.0/10 (carrier-grade NAT)
            if (($value & 0xFFC00000) === 0x64400000) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
