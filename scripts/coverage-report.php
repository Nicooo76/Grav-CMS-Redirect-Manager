#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Line coverage of classes/ from one or more Clover files, overall and per directory.
 *
 *   php scripts/coverage-report.php [--min=90] [--files] build/clover.xml [more.xml ...] [dump-dir ...]
 *
 * With several Clover files a line counts as covered when any of them covers it. A directory argument is a dump
 * directory of the integration run (RM_COVERAGE=<dir>, one <pid>.json per PHP process, {file: [executed lines]}):
 * a line also counts as covered when any dump has it. The denominator is always the statement lines of the Clover
 * files. With dumps the table shows the Clover-only column ("unit") next to the merged one ("merged"); --min
 * applies to the merged number.
 * Exit code 1 when the overall coverage is below --min.
 */

$min = null;
$showFiles = false;
$inputs = [];
$dumps = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $min = (float) substr($arg, 6);
    } elseif ($arg === '--files') {
        $showFiles = true;
    } elseif (is_dir($arg)) {
        $dumps[] = $arg;
    } else {
        $inputs[] = $arg;
    }
}
if ($inputs === []) {
    fwrite(STDERR, "Usage: coverage-report.php [--min=N] [--files] clover.xml [...] [dump-dir ...]\n");
    exit(2);
}

$root = dirname(__DIR__) . '/classes/';
/** @var array<string, array<int, bool>> $lines file => line => covered */
$lines = [];
foreach ($inputs as $input) {
    $xml = @simplexml_load_file($input);
    if ($xml === false) {
        fwrite(STDERR, "Cannot read $input\n");
        exit(2);
    }
    foreach ($xml->xpath('//file') ?: [] as $file) {
        $path = (string) $file['name'];
        if (!str_starts_with($path, $root)) {
            continue;
        }
        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }
            $n = (int) $line['num'];
            $lines[$path][$n] = ($lines[$path][$n] ?? false) || (int) $line['count'] > 0;
        }
    }
}
ksort($lines);

/** @var array<string, array<int, true>> $dumped file => executed line => true (integration dumps) */
$dumped = [];
foreach ($dumps as $dumpDir) {
    foreach (glob(rtrim($dumpDir, '/') . '/*.json') ?: [] as $dump) {
        $data = json_decode((string) file_get_contents($dump), true);
        foreach (is_array($data) ? $data : [] as $path => $executed) {
            if (!is_string($path) || !is_array($executed) || !isset($lines[$path])) {
                continue;
            }
            foreach ($executed as $n) {
                $dumped[$path][(int) $n] = true;
            }
        }
    }
}
$merge = $dumps !== [];

$dirs = [];
$total = [0, 0, 0];
foreach ($lines as $path => $file) {
    $relative = substr($path, strlen($root));
    $dir = str_contains($relative, '/') ? dirname($relative) : '.';
    $count = count($file);
    $covered = count(array_filter($file));
    $both = 0;
    foreach ($file as $n => $isCovered) {
        if ($isCovered || isset($dumped[$path][$n])) {
            ++$both;
        }
    }
    $dirs[$dir] ??= [0, 0, 0];
    $dirs[$dir][0] += $covered;
    $dirs[$dir][1] += $count;
    $dirs[$dir][2] += $both;
    $total[0] += $covered;
    $total[1] += $count;
    $total[2] += $both;
    if ($showFiles) {
        $pct = static fn (int $n): float => $count > 0 ? 100 * $n / $count : 100;
        if ($merge) {
            printf("  %-55s %6.2f%% -> %6.2f%% (%d/%d)\n", $relative, $pct($covered), $pct($both), $both, $count);
        } else {
            printf("  %-55s %6.2f%% (%d/%d)\n", $relative, $pct($covered), $covered, $count);
        }
    }
}
ksort($dirs);
$row = static function (string $label, array $d) use ($merge): void {
    [$unit, $count, $both] = $d;
    $pct = static fn (int $n): float => $count > 0 ? 100 * $n / $count : 100;
    if ($merge) {
        printf("%-22s unit %6.2f%%   merged %6.2f%%  (%d/%d)\n", $label, $pct($unit), $pct($both), $both, $count);
    } else {
        printf("%-22s %6.2f%%  (%d/%d)\n", $label, $pct($unit), $unit, $count);
    }
};
foreach ($dirs as $dir => $d) {
    $row($dir, $d);
}
$row('classes/', $total);
$overall = $total[1] > 0 ? 100 * ($merge ? $total[2] : $total[0]) / $total[1] : 100.0;

if ($min !== null && $overall < $min) {
    fprintf(STDERR, "Line coverage %.2f%% is below the required %.2f%%.\n", $overall, $min);
    exit(1);
}
