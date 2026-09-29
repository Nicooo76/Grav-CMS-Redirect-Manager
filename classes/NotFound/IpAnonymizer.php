<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Truncates client addresses before they are stored. A full IP is never returned.
 *
 * IPv4: last octet becomes 0 (1.2.3.4 -> 1.2.3.0).
 * IPv6: only the first 48 bits are kept (2001:db8:abcd:1::5 -> 2001:db8:abcd::).
 * IPv4-mapped IPv6 (::ffff:1.2.3.4) is treated as IPv4. A zone id ("%eth0") is dropped.
 */
final readonly class IpAnonymizer
{
    public function __construct(private IpMode $mode = IpMode::Anonymize)
    {
    }

    public function mode(): IpMode
    {
        return $this->mode;
    }

    /** Returns the anonymized address, or null for mode None, null input and invalid addresses. */
    public function anonymize(?string $ip): ?string
    {
        if ($this->mode === IpMode::None || $ip === null) {
            return null;
        }
        $ip = trim($ip);
        $zone = strpos($ip, '%');
        if ($zone !== false) {
            $ip = substr($ip, 0, $zone);
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            $packed[3] = "\0";
        } else {
            $packed = substr($packed, 0, 6) . str_repeat("\0", 10);
        }

        $result = inet_ntop($packed);

        return $result === false ? null : $result;
    }
}
