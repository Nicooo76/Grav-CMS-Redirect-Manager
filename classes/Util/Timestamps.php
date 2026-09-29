<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

use DateTimeImmutable;
use Exception;

/**
 * Parses stored timestamps. Rules written by one import or one edit share their timestamps and DateTimeImmutable
 * is immutable, so each distinct ISO string is parsed once: hydrating 10,000 rules takes about a third less time.
 *
 * Only absolute times with an explicit offset (what the plugin writes) are remembered. "tomorrow" or a time without
 * a zone depends on the moment or the default time zone and is read again every time.
 */
final class Timestamps
{
    private const MEMO_LIMIT = 4096;
    private const ISO = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:\d\d)$/';

    /** @var array<string, DateTimeImmutable> */
    private static array $memo = [];

    public static function parse(string $text): ?DateTimeImmutable
    {
        $remember = preg_match(self::ISO, $text) === 1;
        if ($remember && isset(self::$memo[$text])) {
            return self::$memo[$text];
        }
        try {
            $date = new DateTimeImmutable($text);
        } catch (Exception) {
            return null;
        }
        if ($remember) {
            if (count(self::$memo) >= self::MEMO_LIMIT) {
                self::$memo = [];
            }
            self::$memo[$text] = $date;
        }

        return $date;
    }
}
