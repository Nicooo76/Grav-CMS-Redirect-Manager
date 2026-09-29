<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * A second, tiny `php -S` server with a static docroot (one `ok.html`), used as the site's base URL for the
 * live target checker: the main test server is single threaded, so a check that requests the site from inside
 * an API request would deadlock. `/ok.html` answers 200, everything else 404.
 */
final class StaticServer
{
    /** @var resource|null */
    private $process = null;
    private string $docroot;
    public readonly int $port;

    private function __construct()
    {
        $this->docroot = sys_get_temp_dir() . '/rm-static-' . bin2hex(random_bytes(5));
        $this->port = self::freePort();
    }

    public static function start(): self
    {
        $server = new self();
        mkdir($server->docroot, 0775, true);
        file_put_contents($server->docroot . '/ok.html', '<!doctype html><title>ok</title><p>ok</p>');
        $process = proc_open(
            [TestSite::phpBinary(), '-S', '127.0.0.1:' . $server->port, '-t', $server->docroot],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the static server.');
        }
        $server->process = $process;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $server->port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }
        $server->stop();

        throw new RuntimeException('The static server did not start.');
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
                usleep(100_000);
                if (proc_get_status($this->process)['running']) {
                    posix_kill($status['pid'], SIGKILL);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->docroot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->docroot);
    }

    private static function freePort(): int
    {
        for ($i = 0; $i < 50; $i++) {
            $port = random_int(8200, 8299);
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException('No free port found.');
    }
}
