<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * A fresh, untouched Grav 2 site (unpacked from the grav-admin release ZIP) for the release test.
 *
 * Unlike TestSite it shares nothing with the repository: no symlinks, no copied plugin. The plugin arrives only
 * through GPM (or a manual unzip), exactly as it would for a user. Owns a `php -S` server on a free port, a
 * small HTTP and API client, and a log watcher that reports new problem lines in logs/grav.log and in the
 * server's own log since the last check.
 *
 * Environment: RM_PHP_BIN (PHP binary, default: the running one), RM_PORT_RANGE ("8400-8499").
 */
final class ReleaseSite
{
    /** Grav log levels and PHP error markers that count as a problem. */
    private const GRAV_PROBLEM = '/\.(WARNING|NOTICE|ERROR|CRITICAL|ALERT|EMERGENCY):|deprecat/i';
    private const SERVER_PROBLEM = '/PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)|Stack trace:|\[5\d\d\]: /i';

    public readonly string $root;
    public readonly string $dir;
    public readonly int $port;
    public readonly string $serverLogFile;

    /** @var resource|null */
    private $process = null;
    /** @var array<string, int> byte offsets already examined, per log file */
    private array $offsets = [];
    /** @var array<string, true> */
    private array $baseline = [];
    private ?string $token = null;
    /** @var array{0: string, 1: string}|null */
    private ?array $credentials = null;

    private function __construct()
    {
        $this->root = sys_get_temp_dir() . '/rm-release-' . bin2hex(random_bytes(5));
        $this->dir = $this->root . '/grav-admin';
        $this->serverLogFile = $this->root . '/server.log';
        $this->port = self::freePort();
    }

    public static function phpBinary(): string
    {
        $bin = getenv('RM_PHP_BIN');

        return is_string($bin) && $bin !== '' ? $bin : PHP_BINARY;
    }

    /**
     * Unpacks the grav-admin ZIP into a new temp directory.
     */
    public static function create(string $gravZip): self
    {
        $site = new self();
        mkdir($site->root, 0775, true);
        $result = ProcessRunner::run(['unzip', '-q', $gravZip, '-d', $site->root], $site->root, 300);
        if ($result['code'] !== 0 || !is_file($site->dir . '/index.php')) {
            $site->cleanup();

            throw new RuntimeException('Cannot unpack ' . $gravZip . ': ' . $result['stderr']);
        }

        return $site;
    }

    /**
     * Runs bin/<script> with the PHP binary under test, e.g. run('gpm', ['uninstall', 'redirect-manager', '-y']).
     *
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function bin(string $script, array $args, int $timeout = 180): array
    {
        return ProcessRunner::run([self::phpBinary(), 'bin/' . $script, ...$args], $this->dir, $timeout);
    }

    public function pluginDir(): string
    {
        return $this->dir . '/user/plugins/redirect-manager';
    }

    public function dataDir(): string
    {
        return $this->dir . '/user/data/redirect-manager';
    }

    /**
     * Folder names below user/plugins.
     *
     * @return list<string>
     */
    public function pluginFolders(): array
    {
        $names = array_values(array_filter(scandir($this->dir . '/user/plugins') ?: [], static fn (string $n): bool => $n[0] !== '.'));
        sort($names);

        return $names;
    }

    /**
     * Relative paths of all files below a directory, sorted.
     *
     * @return list<string>
     */
    public function filesBelow(string $directory): array
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() || $file->isLink()) {
                $files[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }
        sort($files);

        return $files;
    }

    public function start(): void
    {
        $process = proc_open(
            [self::phpBinary(), '-S', '127.0.0.1:' . $this->port, 'system/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLogFile, 'w'], 2 => ['file', $this->serverLogFile, 'a']],
            $pipes,
            $this->dir,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the PHP server.');
        }
        $this->process = $process;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('The PHP server did not start: ' . (string) @file_get_contents($this->serverLogFile));
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
    }

    /** Stops the server and deletes the whole temp tree. */
    public function cleanup(): void
    {
        $this->stop();
        if (str_starts_with(basename($this->root), 'rm-release-') && is_dir($this->root)) {
            ProcessRunner::run(['rm', '-rf', $this->root], sys_get_temp_dir(), 120);
        }
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $path, string $method = 'GET', array $headers = []): HttpResponse
    {
        [$status, $parsed, $body] = $this->curl($method, $path, $headers, null);

        return new HttpResponse($status, $parsed, $body);
    }

    /**
     * Creates the API user with the Login plugin's CLI (a fresh Grav without any account sends every frontend
     * request to the Admin 2 setup). The password stays in memory and is never printed; the JWT is fetched on
     * the first api() call.
     */
    public function createApiUser(string $username = 'rmrelease'): void
    {
        $password = bin2hex(random_bytes(12)) . 'Aa1';
        $result = $this->bin('plugin', [
            'login', 'new-user', '-n', '-u', $username, '-p', $password, '-e', $username . '@example.invalid',
            '-P', 'a', '--admin-type=api', '-N', 'Release Test', '-t', 'Test', '-s', 'enabled',
        ]);
        if ($result['code'] !== 0 || !is_file($this->dir . '/user/accounts/' . $username . '.yaml')) {
            throw new RuntimeException('Creating the API user failed: ' . substr($result['stdout'] . $result['stderr'], 0, 500));
        }
        $this->credentials = [$username, $password];
    }

    private function login(): string
    {
        if ($this->credentials === null) {
            throw new RuntimeException('Call createApiUser() first.');
        }
        [$username, $password] = $this->credentials;
        [$status, , $body] = $this->curl('POST', '/api/v1/auth/token', ['Accept' => 'application/json'], ['username' => $username, 'password' => $password]);
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if ($status !== 200 || !is_string($token)) {
            throw new RuntimeException('API login failed with HTTP ' . $status . ' ' . substr($body, 0, 300));
        }

        return $token;
    }

    /**
     * Calls /api/v1<path> with the JWT of createApiUser().
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     */
    public function api(string $method, string $path, ?array $body = null, array $query = []): ApiResponse
    {
        $url = '/api/v1' . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $this->token ??= $this->login();
        $headers = ['Accept' => 'application/json', 'X-API-Token' => $this->token];
        [$status, $parsed, $text] = $this->curl($method, $url, $headers, $body);
        $decoded = json_decode($text, true);

        return new ApiResponse($status, $parsed, $text, is_array($decoded) ? $decoded : null);
    }

    /**
     * Remembers the current end of both logs and the problems already in them (a fresh Grav may log noise on its
     * own). Later checks report only new problem lines that were not part of this baseline.
     *
     * @return list<string> the baseline problem lines
     */
    public function markLogBaseline(): array
    {
        $this->baseline = [];

        return $this->newProblemLines(false);
    }

    /**
     * Problem lines in logs/grav.log and in the server log since the last call, without baseline lines.
     *
     * @return list<string> lines prefixed with the log name
     */
    public function newLogProblems(): array
    {
        return $this->newProblemLines(true);
    }

    /**
     * @return list<string>
     */
    private function newProblemLines(bool $useBaseline): array
    {
        $problems = [];
        $logs = ['grav.log' => [$this->dir . '/logs/grav.log', self::GRAV_PROBLEM], 'server.log' => [$this->serverLogFile, self::SERVER_PROBLEM]];
        foreach ($logs as $name => [$file, $pattern]) {
            clearstatcache(true, $file);
            $size = is_file($file) ? (int) filesize($file) : 0;
            $offset = $this->offsets[$file] ?? 0;
            if ($size < $offset) {
                $offset = 0;
            }
            $chunk = $size > $offset ? (string) file_get_contents($file, false, null, $offset, $size - $offset) : '';
            $this->offsets[$file] = $size;
            foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
                if ($line === '' || preg_match($pattern, $line) !== 1) {
                    continue;
                }
                if ($useBaseline && isset($this->baseline[self::fingerprint($line)])) {
                    continue;
                }
                if (!$useBaseline) {
                    $this->baseline[self::fingerprint($line)] = true;
                }
                $problems[] = $name . ': ' . mb_substr($line, 0, 600);
            }
        }

        return $problems;
    }

    /** The line without its leading "[timestamp]" and without digits that change (ports, ids, times). */
    private static function fingerprint(string $line): string
    {
        $line = (string) preg_replace('/^\[[^\]]*\]\s*/', '', $line);

        return (string) preg_replace('/\d+/', '#', $line);
    }

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $json
     *
     * @return array{0: int, 1: array<string, list<string>>, 2: string}
     */
    private function curl(string $method, string $path, array $headers, ?array $json): array
    {
        $ch = curl_init($this->url($path));
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        if ($json !== null) {
            $lines[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json === [] ? new \stdClass() : $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            throw new RuntimeException(sprintf('%s %s failed: %s', $method, $path, curl_error($ch)));
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $parsed = [];
        foreach (explode("\r\n", trim(substr($raw, 0, $size))) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $parsed[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
            }
        }

        return [$status, $parsed, substr($raw, $size)];
    }

    private static function freePort(): int
    {
        $min = 8400;
        $max = 8999;
        if (preg_match('/^(\d+)-(\d+)$/', (string) getenv('RM_PORT_RANGE'), $m) === 1 && (int) $m[1] <= (int) $m[2]) {
            [$min, $max] = [(int) $m[1], (int) $m[2]];
        }
        for ($i = 0; $i < 50; $i++) {
            $port = random_int($min, $max);
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException('No free port found.');
    }
}
