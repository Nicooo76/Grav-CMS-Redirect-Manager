<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * A tiny HTTP server (php -S) that stores every request it receives, for webhook tests: method, URI, headers and raw
 * body as one JSON line per request in a file. Always answers 200 (or the status set with respondWith()).
 */
final class CaptureServer
{
    public readonly int $port;
    private string $dir;
    private string $file;

    /** @var resource|null */
    private $process = null;

    public function __construct(private int $status = 200)
    {
        $this->dir = sys_get_temp_dir() . '/rm-capture-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0775, true);
        $this->file = $this->dir . '/requests.jsonl';
        $this->port = self::freePort();
        $this->writeRouter();
        $this->start();
    }

    public function url(string $path = '/hook'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function respondWith(int $status): void
    {
        $this->status = $status;
        $this->writeRouter();
    }

    /**
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $out = [];
        foreach (is_file($this->file) ? (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $headers = [];
                foreach ($row['headers'] ?? [] as $name => $value) {
                    $headers[strtolower((string) $name)] = (string) $value;
                }
                $out[] = ['method' => (string) $row['method'], 'uri' => (string) $row['uri'], 'headers' => $headers, 'body' => (string) $row['body']];
            }
        }

        return $out;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
                usleep(100_000);
            }
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function writeRouter(): void
    {
        file_put_contents($this->dir . '/router.php', '<?php
$headers = function_exists("getallheaders") ? getallheaders() : [];
file_put_contents(' . var_export($this->file, true) . ', json_encode([
    "method" => $_SERVER["REQUEST_METHOD"],
    "uri" => $_SERVER["REQUEST_URI"],
    "headers" => $headers,
    "body" => file_get_contents("php://input"),
]) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(' . $this->status . ');
echo "captured";
');
    }

    private function start(): void
    {
        $process = proc_open(
            [TestSite::phpBinary(), '-S', '127.0.0.1:' . $this->port, '-t', $this->dir, $this->dir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'w'], 2 => ['file', $this->dir . '/server.log', 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the capture server.');
        }
        $this->process = $process;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();

        throw new RuntimeException('The capture server did not start.');
    }

    private static function freePort(): int
    {
        for ($i = 0; $i < 50; $i++) {
            $port = random_int(8300, 8399);
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException('No free port found.');
    }
}
