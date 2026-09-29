<?php

declare(strict_types=1);

// Child process of RuleRepositoryConcurrencyTest: adds $count rules through transaction().
// Usage: php repo-worker.php <dataDir> <workerId> <count> <goFile>

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Util\SystemClock;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $dataDir, $worker, $count, $goFile] = $argv;

$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) {
    usleep(500);
}

$repository = new RuleRepository($dataDir, new SystemClock());
for ($i = 0; $i < (int) $count; $i++) {
    $repository->transaction(static function (array $rules) use ($worker, $i): array {
        $rules[] = new Rule(sprintf('w%s-%d', $worker, $i), sprintf('/from-%s-%d', $worker, $i), '/to');

        return $rules;
    });
}
