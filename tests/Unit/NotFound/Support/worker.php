<?php

/**
 * Child process for the concurrency tests.
 *
 * Usage: php worker.php <kind> <target> <workerId> <count> <goFile> [<mode>]
 *   kind jsonl  target = log directory        appends <count> entries
 *   kind sqlite target = database file        appends <count> entries
 *   kind hits   target = hits directory       records <count> hits for rule "rule-<workerId % 3>"
 *   mode "mixed" (jsonl only): every other entry goes to path /gone/... instead of /keep/...
 */

declare(strict_types=1);

use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\SqliteLogStore;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Util\FixedClock;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

[$script, $kind, $target, $workerId, $count, $goFile] = $argv;
$mode = $argv[6] ?? '';
$count = (int) $count;

$clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));

$deadline = microtime(true) + 20;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "go file never appeared\n");
        exit(2);
    }
    usleep(200);
}

try {
    if ($kind === 'hits') {
        $recorder = new HitRecorder($target, $clock);
        for ($i = 0; $i < $count; $i++) {
            $recorder->record('rule-' . ((int) $workerId % 3));
        }
        file_put_contents($goFile . '.done.' . $workerId, 'ok');
        exit(0);
    }

    $store = $kind === 'sqlite' ? new SqliteLogStore($target, $clock) : new JsonlLogStore($target, $clock);
    for ($i = 0; $i < $count; $i++) {
        $bucket = $mode === 'mixed' && $i % 2 === 1 ? 'gone' : 'keep';
        $store->append(new NotFoundEntry(
            $clock->now(),
            "/{$bucket}/w{$workerId}/{$i}",
            'n=' . $i . '&pad=' . str_repeat('x', 300),
            'https://ref.example/w' . $workerId,
            'Mozilla/5.0 (worker ' . $workerId . ') AppleWebKit/537.36',
            UserAgentClass::Browser,
            null,
            'de',
            'example.org',
        ));
    }
    file_put_contents($goFile . '.done.' . $workerId, 'ok');
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
