<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;

/** UTC day keys ("YYYY-MM-DD") for charts and sparklines. */
final class DayRange
{
    /** Longest zero-filled range: real data outside it is still reported, only the zeros are capped. */
    public const MAX_DAYS = 366;

    public static function key(int $timestamp): string
    {
        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Every day from $from to $to (inclusive) mapped to 0, oldest first. At most MAX_DAYS days ending at $to.
     *
     * @return array<string, int>
     */
    public static function zeroFilled(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $end = intdiv($to->getTimestamp(), 86400);
        $start = max(intdiv($from->getTimestamp(), 86400), $end - (self::MAX_DAYS - 1));
        $days = [];
        for ($d = $start; $d <= $end; $d++) {
            $days[gmdate('Y-m-d', $d * 86400)] = 0;
        }

        return $days;
    }
}
