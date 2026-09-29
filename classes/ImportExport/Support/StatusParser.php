<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/** Maps status words and numbers found in configs to HTTP status integers. */
final class StatusParser
{
    private const WORDS = [
        'permanent' => 301,
        'moved' => 301,
        'temp' => 302,
        'temporary' => 302,
        'redirect' => 302,
        'found' => 302,
        'seeother' => 303,
        'see_other' => 303,
        'gone' => 410,
    ];

    /**
     * @return int|null null when the value is neither a number nor a known word
     */
    public static function code(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) && !is_float($value)) {
            return null;
        }
        $text = strtolower(trim((string) $value));
        $text = rtrim($text, '!');
        if ($text === '') {
            return null;
        }
        if (ctype_digit($text)) {
            return (int) $text;
        }

        return self::WORDS[$text] ?? null;
    }
}
