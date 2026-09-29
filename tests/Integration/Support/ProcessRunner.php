<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * Runs one external command to completion and returns its exit code and output.
 *
 * The subprocess gets PATH prefixed with the directory of the PHP binary under test, so a `php` found through
 * PATH (Grav's bin scripts, the scheduler) is the same version as the one that started the command.
 */
final class ProcessRunner
{
    /**
     * @param list<string>         $command
     * @param array<string,string> $env     extra environment variables
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $command, string $cwd, int $timeoutSeconds = 180, array $env = []): array
    {
        $out = tempnam(sys_get_temp_dir(), 'rm-out-');
        $err = tempnam(sys_get_temp_dir(), 'rm-err-');
        if ($out === false || $err === false) {
            throw new RuntimeException('Cannot create temp files.');
        }

        try {
            $php = getenv('RM_PHP_BIN');
            $path = (is_string($php) && str_contains($php, '/') ? dirname($php) . PATH_SEPARATOR : '') . (string) getenv('PATH');
            $process = proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes,
                $cwd,
                array_replace(self::environment(), ['PATH' => $path], $env),
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start: ' . implode(' ', $command));
            }
            $deadline = microtime(true) + $timeoutSeconds;
            $code = -1;
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // proc_get_status() reports the exit code only once; proc_close() then returns -1.
                    $code = $status['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    throw new RuntimeException('Timed out after ' . $timeoutSeconds . ' s: ' . implode(' ', $command));
                }
                usleep(50_000);
            }
            $closed = proc_close($process);

            return [
                'code' => $code >= 0 ? $code : $closed,
                'stdout' => (string) file_get_contents($out),
                'stderr' => (string) file_get_contents($err),
            ];
        } finally {
            @unlink($out);
            @unlink($err);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = getenv();

        return is_array($env) ? array_map('strval', $env) : [];
    }
}
