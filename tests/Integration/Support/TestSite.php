<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A throwaway copy of the Grav test site (scripts/setup-test-site.sh) with its own PHP built-in server.
 *
 * system/, vendor/, bin/ and every plugin are symlinked to the shared base site; user/config, pages, accounts,
 * data and themes are copied so tests can change them freely. reset() returns the site to a clean state between
 * tests. The Grav cache is off so config and page edits show up on the next request; the plugin's own compiled
 * rule cache under cache/redirect-manager is what the tests exercise.
 *
 * Environment: RM_PHP_BIN (PHP binary for the server, default: the running one), RM_GRAV_BASE (base site
 * directory, default .grav/<RM_GRAV_VERSION|2.2.2>).
 */
final class TestSite
{
    private const BASELINE_PAGES = ['01.home', '02.typography'];

    public readonly string $dir;
    public readonly int $port;

    /** @var resource|null */
    private $process = null;

    private function __construct(private readonly string $base)
    {
        $this->dir = sys_get_temp_dir() . '/rm-site-' . bin2hex(random_bytes(5));
        $this->port = self::freePort();
    }

    public static function baseDir(): ?string
    {
        $base = getenv('RM_GRAV_BASE') ?: dirname(__DIR__, 3) . '/.grav/' . (getenv('RM_GRAV_VERSION') ?: '2.2.2');

        return is_file($base . '/index.php') ? $base : null;
    }

    public static function phpBinary(): string
    {
        $bin = getenv('RM_PHP_BIN');

        return is_string($bin) && $bin !== '' ? $bin : PHP_BINARY;
    }

    public static function create(): self
    {
        $base = self::baseDir();
        if ($base === null) {
            throw new RuntimeException('No Grav test site found. Run scripts/setup-test-site.sh first.');
        }
        $site = new self($base);
        $site->build();
        $site->start();

        return $site;
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function dataDir(): string
    {
        return $this->dir . '/user/data/redirect-manager';
    }

    public function repository(): RuleRepository
    {
        return new RuleRepository($this->dataDir(), new SystemClock());
    }

    /**
     * Writes rules.yaml from rule rows (serialized field names, see Rule::toArray()).
     *
     * @param list<array<string, mixed>> $rows
     */
    public function writeRules(array $rows): void
    {
        $this->repository()->saveAll(array_map(static fn (array $row): Rule => Rule::fromArray($row), $rows));
    }

    /**
     * Writes user/config/plugins/redirect-manager.yaml (merged over the plugin defaults by Grav).
     *
     * @param array<string, mixed> $config
     */
    public function writePluginConfig(array $config): void
    {
        $this->writeFile('user/config/plugins/redirect-manager.yaml', Yaml::dump($config, 6, 2));
    }

    /**
     * Replaces user/config/system.yaml (test defaults plus the given settings).
     *
     * @param array<string, mixed> $extra
     */
    public function writeSystemConfig(array $extra = []): void
    {
        $system = array_replace_recursive([
            'home' => ['alias' => '/home'],
            'pages' => ['theme' => 'quark2'],
            'cache' => ['enabled' => false],
            'errors' => ['display' => 0, 'log' => true],
            'debugger' => ['enabled' => false],
        ], $extra);
        $this->writeFile('user/config/system.yaml', Yaml::dump($system, 6, 2));
    }

    public function writeFile(string $relative, string $content): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content);
        if (str_starts_with($relative, 'user/config/')) {
            // Grav's compiled config is keyed by file mtimes (one second resolution).
            self::rmTree($this->dir . '/cache/compiled');
        }
    }

    public function readFile(string $relative): ?string
    {
        $path = $this->dir . '/' . $relative;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Adds a page (user/pages/<order>.<slug>/default[.lang].md).
     */
    public function writePage(string $slug, string $title, string $body = '', ?string $language = null, string $order = '10', string $extraHeader = ''): void
    {
        $file = 'default' . ($language !== null ? '.' . $language : '') . '.md';
        $this->writeFile(sprintf('user/pages/%s.%s/%s', $order, $slug, $file), "---\ntitle: $title\n$extraHeader---\n$body\n");
    }

    /**
     * @param array{headers?: array<string, string>, cookies?: array<string, string>, method?: string} $options
     */
    public function request(string $path, array $options = []): HttpResponse
    {
        $ch = curl_init($this->url($path));
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $headers = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $method = strtoupper($options['method'] ?? 'GET');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if (($options['cookies'] ?? []) !== []) {
            curl_setopt($ch, CURLOPT_COOKIE, http_build_query($options['cookies'], '', '; ', PHP_QUERY_RFC3986));
        }

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            throw new RuntimeException('Request to ' . $path . ' failed: ' . $error . "\n" . $this->serverLog());
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $parsed = [];
        foreach (explode("\r\n", trim(substr($raw, 0, $headerSize))) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $parsed[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
            }
        }

        return new HttpResponse($status, $parsed, substr($raw, $headerSize));
    }

    /**
     * Log lines of Grav (logs/grav.log).
     */
    public function gravLog(): string
    {
        return $this->readFile('logs/grav.log') ?? '';
    }

    /**
     * Entries of the JSONL 404 log, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function notFoundEntries(): array
    {
        $entries = [];
        foreach (glob($this->dataDir() . '/404/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $entries[] = $decoded;
                }
            }
        }

        return $entries;
    }

    /**
     * Rule ids recorded by the hit recorder, one per hit.
     *
     * @return list<string>
     */
    public function recordedHits(): array
    {
        $hits = [];
        foreach (glob($this->dataDir() . '/hits/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    /** Back to a clean site: default config, baseline pages, no rules, no logs, no test templates. */
    public function reset(): void
    {
        self::rmTree($this->dir . '/user/config');
        self::copyTree($this->base . '/user/config', $this->dir . '/user/config');
        $this->writeSystemConfig();
        @unlink($this->dir . '/user/config/plugins/redirect-manager.yaml');

        self::rmTree($this->dataDir());
        self::rmTree($this->dir . '/cache');
        mkdir($this->dir . '/cache', 0775, true);
        self::rmTree($this->dir . '/tmp');
        mkdir($this->dir . '/tmp', 0775, true);
        self::rmTree($this->dir . '/user/themes/quark2/templates/redirect-manager');
        foreach (glob($this->dir . '/user/themes/quark2/templates/rm-*.html.twig') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/user/plugins/rm-*') ?: [] as $extra) {
            if (!is_link($extra)) {
                self::rmTree($extra);
            }
        }
        foreach (glob($this->dir . '/user/pages/*', GLOB_ONLYDIR) ?: [] as $page) {
            if (!in_array(basename($page), self::BASELINE_PAGES, true)) {
                self::rmTree($page);
            }
        }
        foreach (glob($this->dir . '/logs/*.log') ?: [] as $log) {
            if (basename($log) !== 'server.log') {
                @unlink($log);
            }
        }
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
        self::rmTree($this->dir);
    }

    public function serverLog(): string
    {
        $log = $this->readFile('logs/server.log') ?? '';

        return substr($log, -3000);
    }

    private function build(): void
    {
        mkdir($this->dir, 0775, true);
        foreach (['system', 'vendor', 'bin', 'webserver-configs'] as $shared) {
            if (file_exists($this->base . '/' . $shared)) {
                symlink($this->base . '/' . $shared, $this->dir . '/' . $shared);
            }
        }
        copy($this->base . '/index.php', $this->dir . '/index.php');
        foreach (['cache', 'logs', 'tmp', 'backup', 'assets', 'images', 'user', 'user/plugins', 'user/data'] as $folder) {
            mkdir($this->dir . '/' . $folder, 0775, true);
        }
        foreach (['config', 'pages', 'accounts', 'themes'] as $folder) {
            if (is_dir($this->base . '/user/' . $folder)) {
                self::copyTree($this->base . '/user/' . $folder, $this->dir . '/user/' . $folder);
            }
        }
        foreach (scandir($this->base . '/user/plugins') ?: [] as $plugin) {
            if ($plugin !== '.' && $plugin !== '..') {
                symlink($this->base . '/user/plugins/' . $plugin, $this->dir . '/user/plugins/' . $plugin);
            }
        }
        $this->writeSystemConfig();
    }

    private function start(): void
    {
        $command = [self::phpBinary(), '-S', '127.0.0.1:' . $this->port, 'system/router.php'];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/logs/server.log', 'w'], 2 => ['file', $this->dir . '/logs/server.log', 'a']],
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
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        $log = $this->serverLog();
        $this->stop();

        throw new RuntimeException('The PHP server did not start: ' . $log);
    }

    private static function freePort(): int
    {
        // RM_PORT_RANGE="8300-8399" keeps parallel test runs (other suites, the dev site) apart.
        $min = 8100;
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

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }
        exec('cp -R ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('cp failed: ' . implode("\n", $out));
        }
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::rmTree($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}
