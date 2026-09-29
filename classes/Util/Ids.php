<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

final class Ids
{
    /** Ids per millisecond before the id clock moves on to the next one (3 hex characters). */
    private const PER_MILLISECOND = 4096;

    private static int $lastMs = 0;

    private static int $sequence = 0;

    /**
     * Time-sortable rule id: "r" + 11 hex chars of milliseconds + 3 hex chars of a per-millisecond counter
     * + 8 random hex chars (22 characters in total).
     *
     * The first 14 characters are strictly increasing within a process, so two ids of one process never
     * collide, however many are created in one millisecond (a bulk import makes thousands). The random
     * part keeps ids of different processes (two CLI runs, two requests) apart.
     */
    public static function rule(): string
    {
        return 'r' . self::sortable();
    }

    public static function sortable(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs) {
            // Same millisecond (or a clock that stepped back): keep counting. When the counter is used up
            // the id clock runs ahead of the real one instead of repeating a value.
            $ms = self::$lastMs;
            if (++self::$sequence >= self::PER_MILLISECOND) {
                ++$ms;
                self::$sequence = 0;
            }
        } else {
            self::$sequence = 0;
        }
        self::$lastMs = $ms;

        return str_pad(dechex($ms), 11, '0', STR_PAD_LEFT)
            . str_pad(dechex(self::$sequence), 3, '0', STR_PAD_LEFT)
            . bin2hex(random_bytes(4));
    }
}
