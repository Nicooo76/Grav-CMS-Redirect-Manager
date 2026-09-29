<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

final class Ids
{
    /**
     * Time-sortable rule id: "r" + 10 hex chars of milliseconds + 6 random hex chars.
     */
    public static function rule(): string
    {
        return 'r' . self::sortable();
    }

    public static function sortable(): string
    {
        $ms = (int) floor(microtime(true) * 1000);

        return str_pad(dechex($ms), 11, '0', STR_PAD_LEFT) . bin2hex(random_bytes(3));
    }
}
