<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/** Splits and quotes directive arguments the way Apache and nginx read them. */
final class Args
{
    /**
     * Splits on whitespace; single or double quotes group words. Inside quotes a backslash escapes
     * the quote character and itself; everywhere else backslashes are literal (regex escapes).
     *
     * @return list<string>
     */
    public static function split(string $line): array
    {
        $args = [];
        $length = strlen($line);
        $i = 0;
        while ($i < $length) {
            while ($i < $length && ctype_space($line[$i])) {
                ++$i;
            }
            if ($i >= $length) {
                break;
            }
            $word = '';
            if ($line[$i] === '"' || $line[$i] === "'") {
                $quote = $line[$i++];
                while ($i < $length && $line[$i] !== $quote) {
                    if ($line[$i] === '\\' && $i + 1 < $length && ($line[$i + 1] === $quote || $line[$i + 1] === '\\')) {
                        ++$i;
                    }
                    $word .= $line[$i++];
                }
                ++$i;
            } else {
                while ($i < $length && !ctype_space($line[$i])) {
                    $word .= $line[$i++];
                }
            }
            $args[] = $word;
        }

        return $args;
    }

    /** Quotes an argument when it contains whitespace, quotes or braces. */
    public static function quote(string $arg): string
    {
        if ($arg === '') {
            return '""';
        }
        if (preg_match('/[\s"\';{}]/', $arg) !== 1) {
            return $arg;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $arg) . '"';
    }
}
