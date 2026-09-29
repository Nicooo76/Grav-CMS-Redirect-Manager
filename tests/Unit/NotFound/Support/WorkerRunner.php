<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support;

use PHPUnit\Framework\Assert;

/** Starts N php processes (worker.php) that begin working at the same moment. */
final class WorkerRunner
{
    /**
     * @param callable(): void|null $whileRunning called repeatedly while at least one child is alive
     */
    public static function run(
        string $kind,
        string $target,
        int $workers,
        int $count,
        string $scratchDir,
        ?callable $whileRunning = null,
        string $mode = '',
    ): void {
        $goFile = $scratchDir . '/go-' . bin2hex(random_bytes(4));
        $script = __DIR__ . '/worker.php';
        $procs = [];
        $errFiles = [];
        for ($i = 0; $i < $workers; $i++) {
            $errFile = $scratchDir . '/err-' . $i . '.txt';
            $errFiles[$i] = $errFile;
            $proc = proc_open(
                [PHP_BINARY, $script, $kind, $target, (string) $i, (string) $count, $goFile, $mode],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errFile, 'w']],
                $pipes,
            );
            Assert::assertIsResource($proc);
            $procs[$i] = $proc;
        }

        touch($goFile);

        $deadline = microtime(true) + 90;
        do {
            $alive = 0;
            foreach ($procs as $proc) {
                if (proc_get_status($proc)['running']) {
                    $alive++;
                }
            }
            if ($whileRunning !== null) {
                $whileRunning();
            } elseif ($alive > 0) {
                usleep(2000);
            }
        } while ($alive > 0 && microtime(true) < $deadline);

        foreach ($procs as $i => $proc) {
            proc_close($proc);
            $err = is_file($errFiles[$i]) ? (string) file_get_contents($errFiles[$i]) : '';
            Assert::assertSame('', $err, 'worker ' . $i . ' reported an error: ' . $err);
            Assert::assertFileExists($goFile . '.done.' . $i, 'worker ' . $i . ' did not finish');
        }
    }
}
