<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

enum IpMode: string
{
    /** Zero the last IPv4 octet / the last 80 bits of an IPv6 address. */
    case Anonymize = 'anonymize';

    /** Store no address at all. */
    case None = 'none';

    /** Unknown values fall back to None: never store more than the admin asked for. */
    public static function fromConfig(mixed $value): self
    {
        return $value === self::Anonymize->value ? self::Anonymize : self::None;
    }
}
