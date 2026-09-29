<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;

/** RFC 4180 reader and writer with formula-injection protection. */
final class Csv
{
    /** Cell prefixes that spreadsheets interpret as formulas. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Parses text into records. Blank lines are skipped. Quoted fields may contain delimiters,
     * quotes ("") and line breaks.
     *
     * @param int      $limit       maximum number of records
     * @param bool     $truncate    true: stop quietly at the limit (sniffing); false: more records throw too_many_rows
     * @param int|null $reportedMax the limit named in the error (defaults to $limit)
     * @return list<array{line: int, raw: string, cells: list<string>}>
     */
    public static function parse(string $content, string $delimiter = ',', int $limit = PHP_INT_MAX, bool $truncate = false, ?int $reportedMax = null): array
    {
        return iterator_to_array(self::stream($content, $delimiter, $limit, $truncate, $reportedMax), false);
    }

    /**
     * Like parse(), but yields one record at a time so large files need no second copy of all records.
     *
     * @return \Generator<int, array{line: int, raw: string, cells: list<string>}>
     */
    public static function stream(string $content, string $delimiter = ',', int $limit = PHP_INT_MAX, bool $truncate = false, ?int $reportedMax = null): \Generator
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new ImportException(new ImportIssue('read_failed', 'The file could not be processed.'));
        }
        fwrite($handle, $content);
        rewind($handle);

        $count = 0;
        $lastPos = 0;
        $line = 1;
        try {
            while (true) {
                $start = (int) ftell($handle);
                $cells = fgetcsv($handle, null, $delimiter, '"', '');
                if ($cells === false) {
                    break;
                }
                $end = (int) ftell($handle);
                $line += substr_count($content, "\n", $lastPos, $start - $lastPos);
                $lastPos = $start;
                if ($cells === [null]) {
                    continue;
                }
                $clean = [];
                $empty = true;
                foreach ($cells as $cell) {
                    $cell = (string) $cell;
                    if (trim($cell) !== '') {
                        $empty = false;
                    }
                    $clean[] = $cell;
                }
                if ($empty) {
                    continue;
                }
                if ($count >= $limit) {
                    if ($truncate) {
                        break;
                    }
                    throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $reportedMax ?? $limit]));
                }
                ++$count;
                yield ['line' => $line, 'raw' => rtrim(substr($content, $start, $end - $start), "\n"), 'cells' => $clean];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Picks the delimiter that splits the first records into the most consistent number of columns.
     */
    public static function detectDelimiter(string $content): string
    {
        $sample = substr($content, 0, 16384);
        if (strlen($content) > 16384) {
            $cut = strrpos($sample, "\n");
            $sample = $cut === false ? $sample : substr($sample, 0, $cut);
        }
        $best = ',';
        $bestScore = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $records = self::parse($sample, $delimiter, 10, true);
            if ($records === []) {
                continue;
            }
            // The most common column count wins; preface lines (report titles) do not decide.
            $counts = [];
            foreach ($records as $record) {
                $width = count($record['cells']);
                if ($width >= 2) {
                    $counts[$width] = ($counts[$width] ?? 0) + 1;
                }
            }
            if ($counts === []) {
                continue;
            }
            arsort($counts);
            $first = (int) array_key_first($counts);
            $same = $counts[$first];
            $score = $same * 1000 + $first;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * @param list<list<string>> $rows
     */
    public static function write(array $rows, string $delimiter = ','): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::sanitize(...), $row), $delimiter, '"', '', "\n");
        }
        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);

        return $out;
    }

    /** Protects against CSV formula injection: a leading apostrophe makes spreadsheets treat the cell as text. */
    public static function sanitize(string $cell): string
    {
        if ($cell !== '' && in_array($cell[0], self::FORMULA_START, true)) {
            return "'" . $cell;
        }

        return $cell;
    }

    /** Reverses sanitize(). Only removes an apostrophe that sanitize() could have added. */
    public static function unsanitize(string $cell): string
    {
        if (strlen($cell) > 1 && $cell[0] === "'" && in_array($cell[1], self::FORMULA_START, true)) {
            return substr($cell, 1);
        }

        return $cell;
    }
}
