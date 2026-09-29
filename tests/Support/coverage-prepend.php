<?php

declare(strict_types=1);

/**
 * auto_prepend_file of the integration test sites (RM_COVERAGE, see docs/TESTING.md).
 *
 * pcov cannot instrument the `php -S` child of TestSite from PHPUnit, so the child collects itself: it starts pcov
 * before Grav boots and, when the request ends (also after $grav->close() and exit), merges the executed lines of
 * classes/ and redirect-manager.php into <RM_COVERAGE_DIR>/<pid>.json as {"<absolute file>": [line, ...]}.
 * One file per PHP process; a long-running server merges every request into its file.
 *
 * Must never break a response: no output, every failure is swallowed.
 */
(static function (): void {
    $dir = getenv('RM_COVERAGE_DIR');
    if (!is_string($dir) || $dir === '' || !function_exists('pcov\start') || !ini_get('pcov.enabled')) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $classes = $root . '/classes/';
    $plugin = $root . '/redirect-manager.php';

    \pcov\start();

    $collect = static function () use ($dir, $classes, $plugin): void {
        try {
            \pcov\stop();
            /** @var array<string, array<int, int>> $raw */
            $raw = \pcov\collect(\pcov\all);
            \pcov\clear();

            $executed = [];
            foreach ($raw as $file => $lines) {
                if (!str_starts_with($file, $classes) && $file !== $plugin) {
                    continue;
                }
                foreach ($lines as $line => $hits) {
                    if ($hits > 0) {
                        $executed[$file][] = $line;
                    }
                }
            }
            if ($executed === []) {
                return;
            }

            $target = rtrim($dir, '/') . '/' . getmypid() . '.json';
            if (is_file($target)) {
                $previous = json_decode((string) file_get_contents($target), true);
                foreach (is_array($previous) ? $previous : [] as $file => $lines) {
                    if (is_array($lines)) {
                        $executed[$file] = array_merge($executed[$file] ?? [], $lines);
                    }
                }
            }
            foreach ($executed as $file => $lines) {
                $lines = array_values(array_unique(array_map('intval', $lines)));
                sort($lines);
                $executed[$file] = $lines;
            }
            $tmp = $target . '.' . bin2hex(random_bytes(3)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($executed)) !== false) {
                @rename($tmp, $target);
            }
        } catch (\Throwable) {
            // coverage must never affect the request
        }
    };

    // Nested registration runs after the shutdown functions Grav registered while handling the request.
    register_shutdown_function(static function () use ($collect): void {
        register_shutdown_function($collect);
    });
})();
