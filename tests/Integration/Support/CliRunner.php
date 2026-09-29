<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * Runs `php bin/plugin redirect-manager <args>` inside a test site, the way an admin or a cron job would.
 */
final class CliRunner
{
    private const TIMEOUT_SECONDS = 90;

    public function __construct(private readonly TestSite $site)
    {
    }

    /**
     * @param list<string> $args command name and its arguments/options
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function run(array $args): array
    {
        $process = proc_open(
            TestSite::phpCommand('bin/plugin', 'redirect-manager', ...$args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->site->dir,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start bin/plugin.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('bin/plugin redirect-manager ' . implode(' ', $args) . ' timed out.');
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 100_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        // proc_get_status() reports the exit code only once; proc_close() then returns -1.
        $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
