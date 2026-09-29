<?php

declare(strict_types=1);

/**
 * Renders the JSON files of scripts/benchmark-request.sh as Markdown tables.
 *
 *   php scripts/benchmark/report.php <enabled-timing.json> <enabled.json> <disabled.json>
 */

[, $timingFile, $enabledFile, $disabledFile] = array_pad($argv, 4, '');
$load = static function (string $file): array {
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Cannot read $file\n");
        exit(2);
    }

    return $data;
};
$timing = $load($timingFile);
$enabled = $load($enabledFile);
$disabled = $load($disabledFile);

$fmt = static fn (mixed $v, string $unit = ''): string => $v === null ? 'n/a' : (is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string) $v) . $unit;
$statuses = static fn (array $g): string => implode(', ', array_map(static fn ($code, $n) => "$code x$n", array_keys($g['statuses']), $g['statuses']));

echo "Plugin time per request (header X-Redirect-Manager-Time: request context, compiled rule cache, match), microseconds:\n\n";
echo "| Request kind | Requests | Median | p95 | Max | Status codes |\n|---|---:|---:|---:|---:|---|\n";
foreach ($timing['groups'] as $kind => $g) {
    printf("| %s | %d | %s | %s | %s | %s |\n", $kind, $g['requests'], $fmt($g['header_us_median']), $fmt($g['header_us_p95']), $fmt($g['header_us_max']), $statuses($g));
}

echo "\nTotal response time per request as measured by curl (ms), same requests, three server runs:\n\n";
echo "| Request kind | Plugin disabled (median / p95) | Plugin enabled (median / p95) | Enabled + timing header (median / p95) |\n|---|---:|---:|---:|\n";
foreach ($timing['groups'] as $kind => $g) {
    $cell = static fn (array $run): string => isset($run['groups'][$kind]) ? $fmt($run['groups'][$kind]['total_ms_median']) . ' / ' . $fmt($run['groups'][$kind]['total_ms_p95']) : 'n/a';
    printf("| %s | %s | %s | %s |\n", $kind, $cell($disabled), $cell($enabled), $cell($timing));
}
echo "\nWith the plugin disabled the redirect URLs are plain 404s, so only the miss rows are comparable one to one.\n";
