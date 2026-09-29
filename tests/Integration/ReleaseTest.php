<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\ProcessRunner;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ReleaseSite;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs the built release ZIP into a clean Grav 2.2.2 the way a user does, uses it, and removes it again.
 *
 * Not part of the normal integration run: the test skips itself unless RM_RELEASE_ZIP points to the ZIP.
 * scripts/test-release.sh is the driver (builds the ZIP, fetches the Grav ZIP, sets the environment):
 *
 *   scripts/test-release.sh [zip]
 *   RM_RELEASE_ZIP=dist/grav-plugin-redirect-manager-1.0.0.zip RM_GRAV_ZIP=.grav/grav-admin-v2.2.2.zip \
 *       vendor/bin/phpunit --testsuite integration --group release
 *
 * Environment:
 *   RM_RELEASE_ZIP      the plugin ZIP (required, else the test is skipped)
 *   RM_GRAV_ZIP         grav-admin-v2.2.2.zip (default: .grav/grav-admin-v2.2.2.zip)
 *   RM_RELEASE_INSTALL  "gpm" (default: bin/gpm direct-install <zip> -y) or "manual" (unzip into user/plugins)
 *   RM_PHP_BIN          PHP binary for the server and every bin/ script
 *
 * The steps are ordered test methods (#[Depends]); after every step the logs are checked, so a failure names the
 * step. logs/grav.log and the server log must not get a warning, notice, error or deprecation that was not
 * already there before the plugin was installed.
 */
#[Group('release')]
final class ReleaseTest extends TestCase
{
    private const SOURCE = '/release-old';
    private const TARGET = '/typography';

    private static ?ReleaseSite $site = null;
    private static string $zip = '';
    private static string $step = 'setup';
    /** @var list<string> CLI commands that were run */
    private static array $ranCommands = [];
    private static string $ruleId = '';

    public static function setUpBeforeClass(): void
    {
        $zip = getenv('RM_RELEASE_ZIP');
        if (!is_string($zip) || $zip === '') {
            self::markTestSkipped('Set RM_RELEASE_ZIP (see scripts/test-release.sh) to run the release test.');
        }
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required.');
        }
        self::assertFileExists($zip, 'RM_RELEASE_ZIP does not exist');
        self::$zip = (string) realpath($zip);

        $grav = getenv('RM_GRAV_ZIP') ?: dirname(__DIR__, 2) . '/.grav/grav-admin-v2.2.2.zip';
        self::assertFileExists($grav, 'Grav ZIP missing, run scripts/test-release.sh');
        self::$site = ReleaseSite::create((string) realpath($grav));
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->cleanup();
        self::$site = null;
    }

    public function testCleanSiteWorksWithoutThePlugin(): void
    {
        self::$step = 'clean site before install';
        $site = $this->site();
        self::assertNotContains('redirect-manager', $site->pluginFolders(), 'the fresh Grav must not ship the plugin');

        $site->createApiUser();
        $site->start();
        $this->assertPageOk('/');
        $this->assertPageOk(self::TARGET);
        self::assertSame(404, $site->request('/no-such-page-baseline')->status);
        $noise = $site->markLogBaseline();
        if ($noise !== []) {
            fwrite(STDERR, "\nNote: a clean Grav already logs " . count($noise) . " problem line(s), ignored below:\n  " . implode("\n  ", $noise) . "\n");
        }
    }

    #[Depends('testCleanSiteWorksWithoutThePlugin')]
    public function testInstall(): void
    {
        self::$step = 'install';
        $site = $this->site();
        $before = $site->pluginFolders();
        $method = getenv('RM_RELEASE_INSTALL') ?: 'gpm';

        if ($method === 'manual') {
            $unzip = ProcessRunner::run(['unzip', '-q', self::$zip, '-d', $site->dir . '/user/plugins'], $site->dir);
            self::assertSame(0, $unzip['code'], $unzip['stderr']);
            $clear = $site->bin('grav', ['clearcache']);
            self::assertSame(0, $clear['code'], $clear['stdout'] . $clear['stderr']);
        } else {
            $result = $site->bin('gpm', ['direct-install', self::$zip, '-y']);
            $output = $result['stdout'] . $result['stderr'];
            self::assertSame(0, $result['code'], "bin/gpm direct-install failed:\n" . $output);
            self::assertStringContainsString('Success', $output, $output);
            $this->assertNoPhpErrorsIn($output, 'bin/gpm direct-install');
        }

        $added = array_values(array_diff($site->pluginFolders(), $before));
        self::assertSame(
            ['redirect-manager'],
            $added,
            'The ZIP must install as user/plugins/redirect-manager, but the new plugin folder(s) are: ' . ($added === [] ? '(none)' : implode(', ', $added))
            . ". GPM (GPM::getPackageName) names a direct-installed package after the first *.yaml in the ZIP root other than blueprints.yaml and languages.yaml, so redirect-manager.yaml must be the only other one (D-024).",
        );

        $expected = [];
        foreach (explode("\n", trim((string) shell_exec('unzip -Z1 ' . escapeshellarg(self::$zip)))) as $entry) {
            if (!str_ends_with($entry, '/')) {
                $expected[] = substr($entry, strlen('redirect-manager/'));
            }
        }
        sort($expected);
        self::assertSame($expected, $site->filesBelow($site->pluginDir()), 'installed files differ from the ZIP content');

        $blueprint = Yaml::parseFile($site->pluginDir() . '/blueprints.yaml');
        self::assertIsArray($blueprint);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $blueprint['version']);

        $this->assertLogsClean();
    }

    #[Depends('testInstall')]
    public function testFrontendAfterInstall(): void
    {
        self::$step = 'frontend with the plugin';
        $site = $this->site();
        foreach (['/', self::TARGET] as $path) {
            $response = $this->assertPageOk($path);
            self::assertFalse($response->has('x-redirect-by'), $path . ' must not be redirected');
        }
        self::assertLessThan(500, $site->request('/admin')->status, '/admin (Admin 2) must not fail');
        self::assertSame(404, $site->request('/no-such-page-release')->status);

        $files = glob($site->dataDir() . '/404/*.jsonl') ?: [];
        self::assertNotSame([], $files, 'the 404 monitor wrote no log file');
        self::assertStringContainsString('/no-such-page-release', implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $files)));

        $this->assertLogsClean();
    }

    #[Depends('testFrontendAfterInstall')]
    public function testRestApi(): void
    {
        self::$step = 'REST API';
        $site = $this->site();

        self::assertSame(401, $site->request('/api/v1/redirects/rules', 'GET', ['Accept' => 'application/json'])->status, 'the rules list must need a login');

        foreach (['/redirects/rules', '/redirects/stats', '/redirects/404', '/redirects/analysis', '/redirects/groups', '/redirects/suggestions', '/redirects/import/formats'] as $path) {
            $response = $site->api('GET', $path);
            self::assertSame(200, $response->status, 'GET ' . $path . ' ' . $response->describe());
        }
        $stats = $site->api('GET', '/redirects/stats')->data();
        self::assertIsArray($stats);
        self::assertSame(0, $stats['rules_total'] ?? null);
        self::assertSame(200, $site->api('GET', '/redirects/export', null, ['format' => 'csv'])->status);

        // Permissions (config/permissions.yaml) and MCP tools (config/mcp.yaml, registered through onApiMcpTools) of the installed ZIP.
        $mcp = $site->api('GET', '/mcp/tools');
        self::assertSame(200, $mcp->status, $mcp->describe());
        $tools = array_values(array_map(static fn (array $tool): string => (string) $tool['name'], array_filter((array) $mcp->data()['tools'], static fn (array $tool): bool => ($tool['plugin'] ?? '') === 'redirect-manager')));
        self::assertSame(['redirects_list', 'redirects_create', 'redirects_test', 'redirects_top_404', 'redirects_suggest', 'redirects_import'], $tools);
        self::assertSame([], $mcp->data()['warnings'], 'the MCP tools of the ZIP must load without warnings');

        $created = $site->api('POST', '/redirects/rules', ['source' => self::SOURCE, 'target' => self::TARGET, 'match_type' => 'exact', 'status' => 301]);
        self::assertSame(201, $created->status, $created->describe());
        $rule = $created->data();
        self::assertIsArray($rule);
        self::$ruleId = (string) $rule['id'];
        self::assertNotSame('', self::$ruleId);
        self::assertSame(200, $site->api('GET', '/redirects/rules/' . self::$ruleId)->status);

        $redirect = $site->request(self::SOURCE);
        self::assertSame(301, $redirect->status, 'the rule made through the API must redirect: ' . $redirect->describe());
        self::assertSame(self::TARGET, $redirect->location());
        self::assertSame('Grav Redirect Manager', $redirect->header('x-redirect-by'));
        self::assertFileExists($site->dataDir() . '/rules.yaml');

        $this->assertLogsClean();
    }

    #[Depends('testRestApi')]
    public function testEveryCliCommand(): void
    {
        self::$step = 'CLI';
        $site = $this->site();
        $dir = $site->dir . '/tmp';
        $csv = $dir . '/release-export.csv';
        $base = $site->url('');

        $commands = $this->installedCliCommands();
        self::assertNotSame([], $commands, 'no cli/*.php command found in the installed plugin');

        $listed = $this->cli('rules', ['--json']);
        self::assertStringContainsString(self::SOURCE, $listed);
        $added = json_decode($this->cli('add', ['/cli-old', self::TARGET, '--status=301', '--json']), true);
        self::assertIsArray($added);
        $cliRuleId = (string) ($added['data']['id'] ?? '');
        self::assertNotSame('', $cliRuleId, 'add --json returned no rule id');
        $this->cli('add', ['/cli-dry', self::TARGET, '--dry-run', '--json']);
        $this->cli('test', ['/cli-old', '--expect-status=301', '--expect-location=' . self::TARGET]);
        $this->cli('disable', [$cliRuleId]);
        self::assertSame(404, $site->request('/cli-old')->status, 'a disabled rule must not redirect');
        $this->cli('enable', [$cliRuleId]);
        self::assertSame(301, $site->request('/cli-old')->status, 'an enabled rule must redirect');
        $this->cli('stats', ['--json']);
        $this->cli('export', ['--format=csv', '--output=' . $csv]);
        self::assertFileExists($csv);
        $this->cli('export', ['--format=json', '--json']);
        $this->cli('import', [$csv, '--dry-run', '--json']);
        $this->cli('suggest', ['--dry-run', '--json']);
        $this->cli('check-targets', ['--base-url=' . $base, '--json']);
        $this->cli('prune', ['--dry-run', '--json']);
        $this->cli('rebuild-cache');
        $this->cli('remove', [$cliRuleId]);
        self::assertSame(404, $site->request('/cli-old')->status, 'a removed rule must not redirect');

        $missing = array_values(array_diff($commands, self::$ranCommands));
        self::assertSame([], $missing, 'CLI commands without a check in this test (add them here): ' . implode(', ', $missing));
        self::assertSame(301, $site->request(self::SOURCE)->status, 'the API rule must survive the CLI run');

        $this->assertLogsClean();
    }

    #[Depends('testEveryCliCommand')]
    public function testSchedulerJobs(): void
    {
        self::$step = 'scheduler';
        $site = $this->site();

        $list = $site->bin('grav', ['scheduler', '--jobs']);
        self::assertSame(0, $list['code'], $list['stdout'] . $list['stderr']);
        preg_match_all('/redirect-manager-[a-z-]+/', $list['stdout'], $found);
        $jobs = array_values(array_unique($found[0]));
        foreach (['redirect-manager-maintenance', 'redirect-manager-check-targets'] as $expected) {
            self::assertContains($expected, $jobs, 'scheduler --jobs does not list ' . $expected . ":\n" . $list['stdout']);
        }
        $this->assertNoPhpErrorsIn($list['stdout'] . $list['stderr'], 'scheduler --jobs');

        foreach ($jobs as $job) {
            $run = $site->bin('grav', ['scheduler', '--run=' . $job]);
            $output = $run['stdout'] . $run['stderr'];
            self::assertSame(0, $run['code'], $job . ":\n" . $output);
            self::assertStringContainsString('ran successfully', $output, $job . ":\n" . $output);
            $this->assertNoPhpErrorsIn($output, 'scheduler --run=' . $job);
        }

        // The maintenance job folds the recorded hits into the statistics.
        $rule = $site->api('GET', '/redirects/rules/' . self::$ruleId)->data();
        self::assertIsArray($rule);
        self::assertGreaterThanOrEqual(1, (int) ($rule['stats']['total'] ?? 0), 'the hit on ' . self::SOURCE . ' is not in the rule statistics after the maintenance job');

        $this->assertLogsClean();
    }

    #[Depends('testSchedulerJobs')]
    public function testUninstallKeepsDataAndSiteKeepsWorking(): void
    {
        self::$step = 'uninstall';
        $site = $this->site();

        $result = $site->bin('gpm', ['uninstall', 'redirect-manager', '-y']);
        $output = $result['stdout'] . $result['stderr'];
        self::assertSame(0, $result['code'], "bin/gpm uninstall failed:\n" . $output);
        self::assertStringContainsString('Success', $output, $output);
        $this->assertNoPhpErrorsIn($output, 'bin/gpm uninstall');

        self::assertDirectoryDoesNotExist($site->pluginDir(), 'the plugin folder is still there');
        self::assertNotContains('redirect-manager', $site->pluginFolders());
        self::assertFileExists($site->dataDir() . '/rules.yaml', 'uninstall must keep the rules');
        self::assertStringContainsString(self::SOURCE, (string) file_get_contents($site->dataDir() . '/rules.yaml'));

        $this->assertPageOk('/');
        $this->assertPageOk(self::TARGET);
        $gone = $site->request(self::SOURCE);
        self::assertSame(404, $gone->status, 'without the plugin ' . self::SOURCE . ' is an ordinary 404: ' . $gone->describe());
        self::assertFalse($gone->has('x-redirect-by'));
        self::assertLessThan(500, $site->request('/admin')->status);
        self::assertSame(404, $site->api('GET', '/redirects/stats')->status, 'the API route must be gone');

        $scheduler = $site->bin('grav', ['scheduler', '--jobs']);
        self::assertSame(0, $scheduler['code'], $scheduler['stdout'] . $scheduler['stderr']);
        self::assertStringNotContainsString('redirect-manager', $scheduler['stdout'], 'scheduler jobs of the removed plugin are still listed');

        $cli = $site->bin('plugin', ['redirect-manager', 'stats']);
        self::assertNotSame(0, $cli['code'], 'the CLI command of a removed plugin must not succeed');
        $this->assertNoPhpErrorsIn($cli['stdout'] . $cli['stderr'], 'bin/plugin redirect-manager after uninstall');

        $this->assertLogsClean();
    }

    #[Depends('testUninstallKeepsDataAndSiteKeepsWorking')]
    public function testReinstallPicksUpTheKeptData(): void
    {
        self::$step = 'reinstall';
        $site = $this->site();

        $method = getenv('RM_RELEASE_INSTALL') ?: 'gpm';
        if ($method === 'manual') {
            $unzip = ProcessRunner::run(['unzip', '-q', self::$zip, '-d', $site->dir . '/user/plugins'], $site->dir);
            self::assertSame(0, $unzip['code'], $unzip['stderr']);
            $site->bin('grav', ['clearcache']);
        } else {
            $result = $site->bin('gpm', ['direct-install', self::$zip, '-y']);
            self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        }

        $redirect = $site->request(self::SOURCE);
        self::assertSame(301, $redirect->status, 'the kept rule must work again after a reinstall: ' . $redirect->describe());
        self::assertSame(self::TARGET, $redirect->location());
        self::assertSame(200, $site->api('GET', '/redirects/stats')->status);

        $this->assertLogsClean();
    }

    private function site(): ReleaseSite
    {
        self::assertNotNull(self::$site, 'release site is not set up');

        return self::$site;
    }

    private function assertPageOk(string $path): \Grav\Plugin\RedirectManager\Tests\Integration\Support\HttpResponse
    {
        $response = $this->site()->request($path);
        self::assertSame(200, $response->status, 'GET ' . $path . ': ' . $response->describe());
        $this->assertNoPhpErrorsIn($response->body, 'GET ' . $path);

        return $response;
    }

    /**
     * Runs `bin/plugin redirect-manager <command> ...`, expects exit code 0 and clean output, returns stdout.
     *
     * @param list<string> $args
     */
    private function cli(string $command, array $args = []): string
    {
        $result = $this->site()->bin('plugin', ['redirect-manager', $command, ...$args]);
        $output = $result['stdout'] . $result['stderr'];
        self::assertSame(0, $result['code'], sprintf('bin/plugin redirect-manager %s %s exited with %d:%s', $command, implode(' ', $args), $result['code'], "\n" . $output));
        $this->assertNoPhpErrorsIn($output, 'redirect-manager ' . $command);
        self::$ranCommands[] = $command;

        return $result['stdout'];
    }

    /**
     * Command names declared by cli/*Command.php of the installed plugin (setName('...')).
     *
     * @return list<string>
     */
    private function installedCliCommands(): array
    {
        $names = [];
        foreach (glob($this->site()->pluginDir() . '/cli/*.php') ?: [] as $file) {
            if (preg_match("/setName\\('([^']+)'\\)/", (string) file_get_contents($file), $m) === 1) {
                $names[] = $m[1];
            }
        }
        sort($names);

        return $names;
    }

    private function assertNoPhpErrorsIn(string $text, string $what): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/(^|\n)\s*(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error):|<b>(Warning|Notice|Deprecated|Fatal error)<\/b>|Stack trace:|Uncaught |Whoops/',
            $text,
            $what . ' printed a PHP error',
        );
    }

    private function assertLogsClean(): void
    {
        $problems = $this->site()->newLogProblems();
        self::assertSame([], $problems, sprintf("Step '%s' left new problem lines in the logs:\n  %s", self::$step, implode("\n  ", $problems)));
    }
}
