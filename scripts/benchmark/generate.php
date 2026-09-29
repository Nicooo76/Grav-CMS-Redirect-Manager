<?php

declare(strict_types=1);

/**
 * Deterministic data for scripts/benchmark-request.sh.
 *
 *   php scripts/benchmark/generate.php <out-dir> [rules=10000] [requests=2000] [warmup=300]
 *
 * Writes <out-dir>/rules.csv (85 % exact, 10 % wildcard, 5 % regex) and <out-dir>/requests.tsv
 * ("kind<TAB>path" per line; the first <warmup> lines are the warm-up set, then <requests> measured lines).
 * Kinds: hit-exact, hit-wildcard, hit-regex, miss-page (a real page), miss-404.
 */

[$script, $out] = [$argv[0], $argv[1] ?? ''];
if ($out === '') {
    fwrite(STDERR, "Usage: php $script <out-dir> [rules] [requests] [warmup]\n");
    exit(2);
}
$rules = max(20, (int) ($argv[2] ?? 10000));
$requests = max(10, (int) ($argv[3] ?? 2000));
$warmup = max(0, (int) ($argv[4] ?? 300));
if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}
mt_srand(20260929);

$exact = (int) round($rules * 0.85);
$wildcard = (int) round($rules * 0.10);
$regex = $rules - $exact - $wildcard;

$csv = fopen($out . '/rules.csv', 'wb');
if ($csv === false) {
    exit(1);
}
fputcsv($csv, ['source', 'target', 'status', 'match_type', 'group', 'note'], ',', '"', '');
for ($i = 1; $i <= $exact; $i++) {
    fputcsv($csv, ["/old/section-" . intdiv($i, 50) . "/page-$i", "/new/section-" . intdiv($i, 50) . "/page-$i", $i % 7 === 0 ? 302 : 301, 'exact', 'bench-exact', ''], ',', '"', '');
}
for ($i = 1; $i <= $wildcard; $i++) {
    fputcsv($csv, ["/legacy-$i/*", "/moved-$i/\$1", 301, 'wildcard', 'bench-wildcard', ''], ',', '"', '');
}
for ($i = 1; $i <= $regex; $i++) {
    fputcsv($csv, ["^/archive-$i/(\\d{4})/([a-z0-9-]+)\$", "/blog-$i/\$1/\$2", 301, 'regex', 'bench-regex', ''], ',', '"', '');
}
fclose($csv);

$pick = static fn (int $max): int => mt_rand(1, $max);
$lines = [];
for ($n = 0; $n < $warmup + $requests; $n++) {
    $roll = mt_rand(1, 100);
    if ($roll <= 45) {
        $i = $pick($exact);
        $lines[] = "hit-exact\t/old/section-" . intdiv($i, 50) . "/page-$i";
    } elseif ($roll <= 60) {
        $lines[] = "hit-wildcard\t/legacy-" . $pick($wildcard) . '/deep/path-' . mt_rand(1, 999) . '/file.html';
    } elseif ($roll <= 70) {
        $lines[] = "hit-regex\t/archive-" . $pick($regex) . '/' . mt_rand(2010, 2026) . '/post-' . mt_rand(1, 999);
    } elseif ($roll <= 82) {
        $lines[] = "miss-page\t" . (mt_rand(0, 1) === 0 ? '/' : '/typography');
    } else {
        // Near misses: same prefixes as the rules, but no rule matches.
        $variants = [
            '/old/section-' . mt_rand(0, 200) . '/nothing-' . mt_rand(1, 99999),
            '/legacy-' . ($wildcard + mt_rand(1, 50)) . '/x',
            '/archive-' . $pick($regex) . '/20xx/no',
            '/no-such-page-' . mt_rand(1, 99999),
        ];
        $lines[] = "miss-404\t" . $variants[mt_rand(0, 3)];
    }
}
file_put_contents($out . '/requests.tsv', implode("\n", $lines) . "\n");
printf("%d rules (%d exact, %d wildcard, %d regex), %d warm-up + %d measured requests in %s\n", $rules, $exact, $wildcard, $regex, $warmup, $requests, $out);
