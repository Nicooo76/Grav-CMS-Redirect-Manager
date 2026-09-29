<?php

declare(strict_types=1);

/**
 * Request runner for scripts/benchmark-request.sh.
 *
 *   php scripts/benchmark/client.php <base-url> <requests.tsv> <warmup> <label> <out.json>
 *
 * Sends the first <warmup> lines unmeasured, then every following line once over one keep-alive connection
 * (no redirects followed). Records per request: kind, status, total time (curl, ms) and the value of the
 * X-Redirect-Manager-Time header (microseconds, when present). Writes count, median and p95 per kind and overall.
 */

[, $base, $file, $warmup, $label, $out] = array_pad($argv, 6, '');
if ($out === '') {
    fwrite(STDERR, "Usage: php client.php <base-url> <requests.tsv> <warmup> <label> <out.json>\n");
    exit(2);
}
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$warmup = (int) $warmup;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PATH_AS_IS => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_FORBID_REUSE => false,
    CURLOPT_FRESH_CONNECT => false,
    CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (benchmark)'],
]);

/** @return array{status: int, total_ms: float, header_us: float|null} */
$send = static function (string $path) use ($ch, $base): array {
    curl_setopt($ch, CURLOPT_URL, rtrim($base, '/') . $path);
    $raw = curl_exec($ch);
    if (!is_string($raw)) {
        fwrite(STDERR, 'Request failed: ' . $path . ': ' . curl_error($ch) . "\n");
        exit(1);
    }
    $head = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    $us = preg_match('/^X-Redirect-Manager-Time:\s*([0-9.]+)/mi', $head, $m) === 1 ? (float) $m[1] : null;

    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'total_ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 'header_us' => $us];
};

foreach (array_slice($lines, 0, $warmup) as $line) {
    $send(explode("\t", $line, 2)[1]);
}

/** @var array<string, array{total: list<float>, header: list<float>, statuses: array<int, int>}> $groups */
$groups = [];
foreach (array_slice($lines, $warmup) as $line) {
    [$kind, $path] = explode("\t", $line, 2);
    $r = $send($path);
    foreach ([$kind, 'all'] as $key) {
        $groups[$key] ??= ['total' => [], 'header' => [], 'statuses' => []];
        $groups[$key]['total'][] = $r['total_ms'];
        if ($r['header_us'] !== null) {
            $groups[$key]['header'][] = $r['header_us'];
        }
        $groups[$key]['statuses'][$r['status']] = ($groups[$key]['statuses'][$r['status']] ?? 0) + 1;
    }
}

$percentile = static function (array $values, float $p): ?float {
    if ($values === []) {
        return null;
    }
    sort($values);

    return $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
};
$round = static fn (?float $v, int $d): ?float => $v === null ? null : round($v, $d);

$result = ['label' => $label, 'php' => PHP_VERSION, 'groups' => []];
ksort($groups);
foreach ($groups as $key => $g) {
    ksort($g['statuses']);
    $result['groups'][$key] = [
        'requests' => count($g['total']),
        'statuses' => $g['statuses'],
        'header_us_median' => $round($percentile($g['header'], 50), 1),
        'header_us_p95' => $round($percentile($g['header'], 95), 1),
        'header_us_max' => $round($g['header'] === [] ? null : max($g['header']), 1),
        'total_ms_median' => $round($percentile($g['total'], 50), 2),
        'total_ms_p95' => $round($percentile($g['total'], 95), 2),
    ];
}
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$all = $result['groups']['all'];
printf("%-26s %d requests, total median %.2f ms, p95 %.2f ms%s\n", $label, $all['requests'], $all['total_ms_median'], $all['total_ms_p95'], $all['header_us_median'] !== null ? sprintf(', plugin median %.1f us, p95 %.1f us', $all['header_us_median'], $all['header_us_p95']) : '');
