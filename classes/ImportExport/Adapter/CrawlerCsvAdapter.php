<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\NotFoundPathProvider;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Crawler exports (Screaming Frog "Response Codes", Sitebulb URL lists). They list broken URLs, not rules:
 * the result is a set of 404/410 paths that feed the 404 monitor and the suggestion engine.
 */
final class CrawlerCsvAdapter implements ImportAdapter, NotFoundPathProvider
{
    private const URL_COLUMNS = ['address', 'url', 'destination', 'broken_url', 'target_url'];
    private const STATUS_COLUMNS = ['status_code', 'http_status_code', 'response_code', 'http_status', 'status_code_http', 'statuscode'];
    private const TEXT_COLUMNS = ['status', 'response_status', 'status_text'];

    /** @var array<string, int> */
    private const TEXT_CODES = ['not found' => 404, 'gone' => 410];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $rows = [];
        $this->scan($content, $options, $rows);

        return $rows;
    }

    public function notFoundPaths(string $content, ImportOptions $options): array
    {
        $rows = [];

        return $this->scan($content, $options, $rows);
    }

    /**
     * @param list<ImportRow> $rows receives rows for records that could not be read
     * @return list<string>
     */
    private function scan(string $content, ImportOptions $options, array &$rows): array
    {
        $delimiter = $options->delimiter ?? Csv::detectDelimiter($content);

        // The header is usually the first record; some exports put a title line before it.
        $urlCol = $statusCol = $textCol = null;
        $records = Csv::stream($content, $delimiter, $options->maxRows + 5, false, $options->maxRows);
        for ($i = 0; $records->valid() && $i < 5 && $urlCol === null; ++$i, $records->next()) {
            $names = array_map(static fn (string $c): string => str_replace([' ', '-', '(', ')'], ['_', '_', '', ''], strtolower(trim($c))), $records->current()['cells']);
            $u = self::find($names, self::URL_COLUMNS);
            $s = self::find($names, self::STATUS_COLUMNS);
            $t = self::find($names, self::TEXT_COLUMNS);
            if ($u !== null && ($s !== null || $t !== null)) {
                [$urlCol, $statusCol, $textCol] = [$u, $s, $t];
            }
        }
        if ($urlCol === null) {
            throw new ImportException(new ImportIssue('missing_column', 'The file needs an address/URL column and a status code column.', ['field' => 'address']));
        }

        $factory = new \Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory($options, $this->clock);
        $paths = [];
        for (; $records->valid(); $records->next()) {
            $cells = $records->current()['cells'];
            $url = trim($cells[$urlCol] ?? '');
            if ($url === '') {
                continue;
            }
            $status = null;
            if ($statusCol !== null && isset($cells[$statusCol]) && ctype_digit(trim($cells[$statusCol]))) {
                $status = (int) trim($cells[$statusCol]);
            } elseif ($textCol !== null) {
                $status = self::TEXT_CODES[strtolower(trim($cells[$textCol] ?? ''))] ?? null;
            }
            if ($status !== 404 && $status !== 410) {
                continue;
            }
            $path = $this->path($url, $options, $factory);
            if ($path === null) {
                continue;
            }
            $paths[$path] = true;
        }

        return array_map('strval', array_keys($paths));
    }

    /**
     * Path of a URL on this site; null for other hosts (when hosts are known) and unusable values.
     */
    private function path(string $url, ImportOptions $options, \Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory $factory): ?string
    {
        if (preg_match('~^(?:https?:)?//([^/?#]*)(.*)$~i', $url, $m) === 1) {
            $host = strtolower((string) preg_replace('/:\d+$/', '', $m[1]));
            if ($options->baseHosts !== [] && !$factory->isBaseHost($host)) {
                return null;
            }
            $url = $m[2] === '' ? '/' : $m[2];
        }
        $url = (string) preg_replace('/[?#].*$/s', '', $url);
        if ($url === '' || $url[0] !== '/') {
            return null;
        }
        $decoded = rawurldecode($url);

        return mb_check_encoding($decoded, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $decoded) !== 1 ? $decoded : $url;
    }

    /**
     * @param list<string> $names
     * @param list<string> $candidates
     */
    private static function find(array $names, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $names, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }
}
