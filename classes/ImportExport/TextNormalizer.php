<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

/** Turns raw file bytes into clean UTF-8 text with LF line ends, or says that the input is not text. */
final class TextNormalizer
{
    /**
     * @return array{text: string, converted: string|null, binary: bool}
     */
    public static function normalize(string $raw): array
    {
        $converted = null;
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") && !str_starts_with($raw, "\xFF\xFE\x00\x00")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
            $converted = 'UTF-16LE';
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
            $converted = 'UTF-16BE';
        }

        if (self::looksBinary($raw)) {
            return ['text' => '', 'converted' => null, 'binary' => true];
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $converted = 'Windows-1252';
        }

        return [
            'text' => str_replace(["\r\n", "\r"], "\n", $raw),
            'converted' => $converted,
            'binary' => false,
        ];
    }

    public static function looksBinary(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        foreach (["%PDF-", "PK\x03\x04", "\x89PNG", "GIF8", "\xFF\xD8\xFF", "\x1F\x8B", "\x7FELF", "SQLite format 3"] as $magic) {
            if (str_starts_with($raw, $magic)) {
                return true;
            }
        }
        $sample = substr($raw, 0, 65536);
        if (str_contains($sample, "\0")) {
            return true;
        }
        $length = strlen($sample);
        $control = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sample);
        if ($control !== false && $control > 2 && $control / $length > 0.01) {
            return true;
        }
        if (!mb_check_encoding($sample, 'UTF-8')) {
            $high = preg_match_all('/[\x80-\xFF]/', $sample);
            if ($high !== false && $high / $length > 0.4) {
                return true;
            }
        }

        return false;
    }
}
