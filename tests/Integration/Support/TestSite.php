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

    /** Folder of the site's user/ directory, relative to $dir: "user", or "user/sites/<name>" in a multisite setup. */
    private string $userRoot = 'user';

    private string $cacheRoot = 'cache';

    private string $tmpRoot = 'tmp';

    /** Host header sent with every request of a site view of a multisite setup. */
    private ?string $host = null;

    /** @var list<string> site names of a multisite setup (empty: an ordinary site) */
    private array $sites = [];

    /** A view of one site of a multisite setup: it shares the server with the site it came from and never stops it. */
    private bool $isView = false;

    /**
     * @param array<string, string> $ini php.ini settings for the server process (-d key=value)
     */
    private function __construct(private readonly string $base, private readonly array $ini = [])
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

    /**
     * The PHP binary plus, in coverage mode (RM_COVERAGE=<dir>), the flags that make the child collect line coverage
     * (tests/Support/coverage-prepend.php), followed by $args. Use it for every PHP child that runs plugin code.
     *
     * @return list<string>
     */
    public static function phpCommand(string ...$args): array
    {
        return [self::phpBinary(), ...self::coverageFlags(), ...$args];
    }

    /**
     * @return list<string>
     */
    private static function coverageFlags(): array
    {
        static $flags = null;
        if ($flags !== null) {
            return $flags;
        }
        $dir = getenv('RM_COVERAGE');
        if (!is_string($dir) || $dir === '') {
            return $flags = [];
        }
        $root = dirname(__DIR__, 3);
        if (!str_starts_with($dir, '/')) {
            $dir = $root . '/' . $dir;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the coverage directory ' . $dir);
        }
        $bin = self::phpBinary();
        exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo extension_loaded("pcov") ? "1" : "0";') . ' 2>/dev/null', $out);
        if (($out[0] ?? '0') !== '1') {
            fwrite(STDERR, "RM_COVERAGE is set, but $bin has no pcov extension: the integration run is not measured.\n");

            return $flags = [];
        }
        // The children inherit the variable (proc_open without an explicit env).
        putenv('RM_COVERAGE_DIR=' . realpath($dir));

        return $flags = [
            '-d', 'auto_prepend_file=' . $root . '/tests/Support/coverage-prepend.php',
            // pcov overrides zend_execute_ex(); a configured JIT would then warn on stderr of every child.
            '-d', 'opcache.jit=disable',
            '-d', 'pcov.enabled=1',
            '-d', 'pcov.directory=' . $root . '/classes',
        ];
    }

    /**
     * @param array<string, string> $ini php.ini settings for the `php -S` process, e.g. ['opcache.enable_cli' => '1']
     */
    public static function create(array $ini = []): self
    {
        $base = self::baseDir();
        if ($base === null) {
            throw new RuntimeException('No Grav test site found. Run scripts/setup-test-site.sh first.');
        }
        $site = new self($base, $ini);
        $site->build();
        $site->start();

        return $site;
    }

    /**
     * A multisite installation: one Grav, one PHP server, one `setup.php` that maps the first label of the Host header
     * (site-a.test -> site-a) to its own `user/sites/<name>/`, `cache/<name>/` and `tmp/<name>/` (Grav's "multisite
     * setup"). Every site gets its own config, pages, themes, data and a link to every plugin of the base site.
     * Use forSite() to get a TestSite that talks to one site (Host header and paths included).
     *
     * @param list<string> $names
     */
    public static function createMultisite(array $names, array $ini = []): self
    {
        $base = self::baseDir();
        if ($base === null) {
            throw new RuntimeException('No Grav test site found. Run scripts/setup-test-site.sh first.');
        }
        $site = new self($base, $ini);
        $site->sites = $names;
        $site->build();
        $site->start();

        return $site;
    }

    /**
     * The site of a multisite setup that answers to <name>.test.
     */
    public function forSite(string $name): self
    {
        if (!in_array($name, $this->sites, true)) {
            throw new RuntimeException('Unknown site ' . $name);
        }
        $view = clone $this;
        $view->userRoot = 'user/sites/' . $name;
        $view->cacheRoot = 'cache/' . $name;
        $view->tmpRoot = 'tmp/' . $name;
        $view->host = $name . '.test';
        $view->isView = true;
        $view->sites = [];

        return $view;
    }

    /** Maps user/, cache/ and tmp/ paths to the folders of this site (they differ in a multisite setup). */
    private function path(string $relative): string
    {
        foreach (['user' => $this->userRoot, 'cache' => $this->cacheRoot, 'tmp' => $this->tmpRoot] as $from => $to) {
            if ($relative === $from || str_starts_with($relative, $from . '/')) {
                $relative = $to . substr($relative, strlen($from));
                break;
            }
        }

        return $this->dir . '/' . $relative;
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function dataDir(): string
    {
        return $this->path('user/data/redirect-manager');
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
        if ($this->isView) {
            // Grav's `problems` plugin looks for user/config, user/pages and so on under the webroot, which a multisite
            // setup does not have (Grav's multisite documentation tells to switch it off).
            $this->writeFile('user/config/plugins/problems.yaml', "enabled: false\n");
        }
    }

    public function writeFile(string $relative, string $content): void
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content);
        if (str_starts_with($relative, 'user/config/')) {
            // Grav's compiled config is keyed by file mtimes (one second resolution).
            self::rmTree($this->path('cache/compiled'));
        }
    }

    public function readFile(string $relative): ?string
    {
        $path = $this->path($relative);

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
        $given = array_change_key_case($options['headers'] ?? [], CASE_LOWER);
        if ($this->host !== null && !isset($given['host'])) {
            $headers[] = 'Host: ' . $this->host . ':' . $this->port;
        }
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
        if ($this->sites !== []) {
            // A multisite installation: every site back to baseline (the logs are shared, clear them once).
            foreach ($this->sites as $name) {
                $this->forSite($name)->reset();
            }

            return;
        }
        $this->dumpLogs();
        self::rmTree($this->path('user/config'));
        self::copyTree($this->base . '/user/config', $this->path('user/config'));
        $this->writeSystemConfig();
        @unlink($this->path('user/config/plugins/redirect-manager.yaml'));

        self::rmTree($this->dataDir());
        self::rmTree($this->path('cache'));
        mkdir($this->path('cache'), 0775, true);
        self::rmTree($this->path('tmp'));
        mkdir($this->path('tmp'), 0775, true);
        self::rmTree($this->path('user/themes/quark2/templates/redirect-manager'));
        foreach (glob($this->path('user/themes/quark2/templates/rm-*.html.twig')) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->path('user/plugins/rm-*')) ?: [] as $extra) {
            if (!is_link($extra)) {
                self::rmTree($extra);
            }
        }
        foreach (glob($this->path('user/pages/*'), GLOB_ONLYDIR) ?: [] as $page) {
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

    /**
     * RM_LOG_DUMP=<file>: appends the site's grav.log and server.log to that file before a reset or the end of the class,
     * so a run can be searched afterwards for PHP deprecations and warnings (docs/TESTING.md).
     */
    private function dumpLogs(bool $withServerLog = false): void
    {
        $file = getenv('RM_LOG_DUMP');
        if (!is_string($file) || $file === '') {
            return;
        }
        $out = $this->gravLog();
        if ($withServerLog) {
            $out .= "\n--- server.log\n" . ($this->readFile('logs/server.log') ?? '');
        }
        if (trim($out) !== '') {
            @file_put_contents($file, "### site {$this->port}\n" . $out . "\n", FILE_APPEND);
        }
    }

    public function stop(): void
    {
        if ($this->isView) {
            return;
        }
        $this->dumpLogs(true);
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
        foreach (['cache', 'logs', 'tmp', 'backup', 'assets', 'images', 'user'] as $folder) {
            mkdir($this->dir . '/' . $folder, 0775, true);
        }
        if ($this->sites === []) {
            $this->buildUserFolder($this);

            return;
        }
        $this->writeSetupFile();
        foreach ($this->sites as $name) {
            $site = $this->forSite($name);
            foreach (['cache', 'tmp', 'images'] as $folder) {
                mkdir($this->dir . '/' . $folder . '/' . $name, 0775, true);
            }
            $this->buildUserFolder($site);
        }
    }

    /** user/ of one site: config, pages, accounts and themes copied, data empty, every plugin linked. */
    private function buildUserFolder(self $site): void
    {
        foreach (['', 'plugins', 'data'] as $folder) {
            $dir = $site->path('user') . ($folder === '' ? '' : '/' . $folder);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        foreach (['config', 'pages', 'accounts', 'themes'] as $folder) {
            if (is_dir($this->base . '/user/' . $folder)) {
                self::copyTree($this->base . '/user/' . $folder, $site->path('user/' . $folder));
            }
        }
        foreach (scandir($this->base . '/user/plugins') ?: [] as $plugin) {
            if ($plugin !== '.' && $plugin !== '..') {
                symlink($this->base . '/user/plugins/' . $plugin, $site->path('user/plugins/' . $plugin));
            }
        }
        $site->writeSystemConfig();
    }

    /**
     * setup.php as in Grav's multisite documentation: the site name (first label of the host, or RM_SITE for
     * command line runs) picks user/, cache/, images/ and tmp/ of that site. Logs stay shared.
     */
    private function writeSetupFile(): void
    {
        $names = var_export($this->sites, true);
        $setup = <<<PHP
<?php

\$sites = $names;
\$host = strtolower((string) strtok((string) (\$_SERVER['HTTP_HOST'] ?? ''), ':'));
\$name = getenv('RM_SITE') ?: explode('.', \$host)[0];
if (!in_array(\$name, \$sites, true)) {
    \$name = \$sites[0];
}

return [
    'streams' => [
        'schemes' => [
            'user' => ['type' => 'ReadOnlyStream', 'force' => true, 'prefixes' => ['' => ["user/sites/\$name"]]],
            'cache' => ['type' => 'Stream', 'force' => true, 'prefixes' => ['' => ["cache/\$name"], 'images' => ["images/\$name"]]],
            'tmp' => ['type' => 'Stream', 'force' => true, 'prefixes' => ['' => ["tmp/\$name"]]],
        ],
    ],
];

PHP;
        file_put_contents($this->dir . '/setup.php', $setup);
    }

    private function start(): void
    {
        // Every notice, warning and deprecation goes to logs/server.log, never into a response body.
        $flags = ['-d', 'error_reporting=-1', '-d', 'display_errors=0', '-d', 'log_errors=1'];
        $router = 'system/router.php';
        foreach ($this->ini as $key => $value) {
            if ($key === 'auto_prepend_file') {
                // PHP 8.3's built-in server does not run auto_prepend_file before a router script (8.4 does),
                // so a small router of our own loads the file and hands over to Grav's router.
                $router = 'rm-router.php';
                file_put_contents(
                    $this->dir . '/' . $router,
                    "<?php\nrequire " . var_export($value, true) . ";\n\nreturn require __DIR__ . '/system/router.php';\n",
                );
                continue;
            }
            array_push($flags, '-d', $key . '=' . $value);
        }
        $command = self::phpCommand(...$flags, ...['-S', '127.0.0.1:' . $this->port, $router]);
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
