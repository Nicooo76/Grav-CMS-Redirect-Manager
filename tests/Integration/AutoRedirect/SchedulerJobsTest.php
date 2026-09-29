<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use PHPUnit\Framework\Attributes\Group;

/** The plugin's scheduler jobs, run by `bin/grav scheduler --run=<id>` in the temp site. */
#[Group('integration')]
#[Group('scheduler')]
final class SchedulerJobsTest extends SchedulerTestCase
{
    private const SECRET = 'a-shared-test-secret';

    private const LISTENER = <<<'PHP'
<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;

class RmJobsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onSuggestionCreated' => ['onSuggestion', 0], 'onRedirectRuleSaved' => ['onSaved', 0]];
    }

    public function onSuggestion($event): void
    {
        $s = $event['suggestion'];
        file_put_contents(GRAV_ROOT . '/logs/rm-jobs.log', 'suggestion|' . $s['path'] . '|' . $s['target'] . "\n", FILE_APPEND);
    }

    public function onSaved($event): void
    {
        file_put_contents(GRAV_ROOT . '/logs/rm-jobs.log', 'saved|' . $event['action'] . '|' . $event['rule']->source . '|' . ($event['rule']->enabled ? 'on' : 'off') . "\n", FILE_APPEND);
    }
}
PHP;

    private function installListener(): void
    {
        $site = $this->site();
        $site->writeFile('user/plugins/rm-jobs/rm-jobs.php', self::LISTENER);
        $site->writeFile('user/plugins/rm-jobs/rm-jobs.yaml', "enabled: true\n");
        $site->writeFile('user/plugins/rm-jobs/blueprints.yaml', "name: Rm Jobs\nslug: rm-jobs\ntype: plugin\nversion: 1.0.0\ndescription: Test listener\ncompatibility:\n  grav: ['2.0']\n");
        $site->writeFile('user/config/plugins/rm-jobs.yaml', "enabled: true\n");
    }

    /** Asserts a row of `bin/grav scheduler --jobs`: the job id and its cron expression. */
    private static function assertJob(string $listing, string $id, string $cron): void
    {
        self::assertMatchesRegularExpression('/' . preg_quote($id, '/') . '\s*│[^│]*│\s*' . preg_quote($cron, '/') . '\s*│/u', $listing, $id . ' with "' . $cron . '"');
    }

    public function testJobsAreRegisteredWithTheirSchedules(): void
    {
        $listing = $this->grav(['scheduler', '--jobs'])['output'];

        self::assertJob($listing, 'redirect-manager-maintenance', '5 * * * *');
        self::assertJob($listing, 'redirect-manager-check-targets', '30 3 * * 0');
        self::assertStringNotContainsString('redirect-manager-digest', $listing, 'no digest while notifications.email_digest is none');
    }

    public function testDigestScheduleFollowsTheConfig(): void
    {
        $this->site()->writePluginConfig(['notifications' => ['email_digest' => 'daily']]);
        self::assertJob($this->grav(['scheduler', '--jobs'])['output'], 'redirect-manager-digest', '0 7 * * *');

        $this->site()->writePluginConfig(['notifications' => ['email_digest' => 'weekly']]);
        self::assertJob($this->grav(['scheduler', '--jobs'])['output'], 'redirect-manager-digest', '0 7 * * 1');
    }

    public function testCheckerScheduleAndSwitch(): void
    {
        $this->site()->writePluginConfig(['checker' => ['schedule' => '15 2 * * 1']]);
        self::assertJob($this->grav(['scheduler', '--jobs'])['output'], 'redirect-manager-check-targets', '15 2 * * 1');

        $this->site()->writePluginConfig(['checker' => ['schedule' => 'nonsense']]);
        self::assertJob($this->grav(['scheduler', '--jobs'])['output'], 'redirect-manager-check-targets', '30 3 * * 0');

        $this->site()->writePluginConfig(['checker' => ['enabled' => false]]);
        self::assertStringNotContainsString('redirect-manager-check-targets', $this->grav(['scheduler', '--jobs'])['output']);
    }

    public function testMaintenanceFoldsHitLogsIntoTheStatistics(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/old', 'target' => '/typography']]);
        $this->recordRuleHits('r1', 3);
        self::assertNotSame([], glob($this->site()->dataDir() . '/hits/*.log'));

        $result = $this->runJob('redirect-manager-maintenance');

        self::assertStringContainsString('"stats":{"merged":3}', $result['output']);
        self::assertSame([], glob($this->site()->dataDir() . '/hits/*.log') ?: [], 'the hit logs were folded in');
        self::assertSame(3, $this->readJson('user/data/redirect-manager/stats.json')['rules']['r1']['total']);
        self::assertStringContainsString('Redirect Manager maintenance', $this->site()->gravLog());
    }

    public function testMaintenancePurgesOldLogsButKeepsRecentOnes(): void
    {
        $this->site()->writePluginConfig(['log' => ['retention_days' => 10]]);
        $this->hit404('/old-one', new \DateTimeImmutable('-40 days'));
        $this->hit404('/recent', new \DateTimeImmutable('-2 days'));
        $oldFile = $this->site()->dataDir() . '/404/' . gmdate('Y-m-d', strtotime('-40 days')) . '.jsonl';
        self::assertFileExists($oldFile);

        $this->runJob('redirect-manager-maintenance');

        self::assertFileDoesNotExist($oldFile);
        self::assertFileExists($this->site()->dataDir() . '/404/' . gmdate('Y-m-d', strtotime('-2 days')) . '.jsonl');
    }

    public function testMaintenanceEnforcesTheLogSizeCap(): void
    {
        $this->site()->writePluginConfig(['log' => ['max_size_mb' => 1, 'retention_days' => 365]]);
        $dir = $this->site()->dataDir() . '/404';
        mkdir($dir, 0775, true);
        $line = json_encode(['t' => strtotime('-5 days'), 'p' => '/x', 'ua' => 'UA', 'c' => 'browser']) . "\n";
        foreach ([9, 8, 7, 6] as $daysAgo) {
            file_put_contents($dir . '/' . gmdate('Y-m-d', strtotime("-$daysAgo days")) . '.jsonl', str_repeat($line, (int) (400_000 / strlen($line))));
        }

        $result = $this->runJob('redirect-manager-maintenance');

        self::assertMatchesRegularExpression('/"rotated":[1-9]/', $result['output']);
        self::assertFileDoesNotExist($dir . '/' . gmdate('Y-m-d', strtotime('-9 days')) . '.jsonl', 'the oldest day goes first');
        self::assertFileExists($dir . '/' . gmdate('Y-m-d', strtotime('-6 days')) . '.jsonl');
    }

    public function testMaintenanceStoresSuggestionsAndFiresTheEvent(): void
    {
        $this->installListener();
        $this->site()->writePage('about', 'About us');
        $this->hits404('/about-us', 4);
        $this->hits404('/rare', 2);

        $result = $this->runJob('redirect-manager-maintenance');

        self::assertStringContainsString('"suggestions":{"stored":1}', $result['output']);
        $store = $this->readJson('user/data/redirect-manager/suggestions.json');
        self::assertCount(1, $store['suggestions']);
        self::assertSame('/about-us', $store['suggestions'][0]['path']);
        self::assertSame('/about', $store['suggestions'][0]['target']);
        self::assertSame('404', $store['suggestions'][0]['source']);
        self::assertStringContainsString("suggestion|/about-us|/about\n", (string) $this->site()->readFile('logs/rm-jobs.log'));

        $this->runJob('redirect-manager-maintenance');
        self::assertSame(1, substr_count((string) $this->site()->readFile('logs/rm-jobs.log'), 'suggestion|'), 'a second run reports nothing new');
    }

    public function testMaintenanceSkipsPathsThatARuleHandles(): void
    {
        $this->site()->writePage('about', 'About us');
        $this->rules([['id' => 'h', 'source' => '/about-us', 'target' => '/about']]);
        $this->hits404('/about-us', 4);

        $this->runJob('redirect-manager-maintenance');

        self::assertSame([], $this->readJson('user/data/redirect-manager/suggestions.json')['suggestions'] ?? []);
    }

    public function testMaintenanceSendsASignedWebhookOnceWhenTheThresholdIsReached(): void
    {
        $server = $this->capture();
        $this->site()->writePluginConfig(['notifications' => ['webhook_url' => $server->url(), 'not_found_threshold' => 5]]);
        $this->hits404('/missing-page', 6);
        $this->hits404('/less', 2);
        $env = ['REDIRECT_MANAGER_WEBHOOK_SECRET' => self::SECRET];

        $this->runJob('redirect-manager-maintenance', $env);
        $this->runJob('redirect-manager-maintenance', $env);

        $requests = $server->requests();
        self::assertCount(1, $requests, 'reported once per cooldown');
        $request = $requests[0];
        self::assertSame('POST', $request['method']);
        $payload = json_decode($request['body'], true);
        self::assertSame('not_found_threshold', $payload['event']);
        self::assertSame('/missing-page', $payload['data']['path']);
        self::assertSame(6, $payload['data']['hits']);
        self::assertSame('24h', $payload['data']['window']);
        self::assertSame('not_found_threshold', $request['headers']['x-redirect-manager-event']);
        self::assertTrue(WebhookNotifier::verify(
            $request['body'],
            $request['headers']['x-redirect-manager-timestamp'],
            $request['headers']['x-redirect-manager-signature'],
            self::SECRET,
            300,
            time(),
        ), 'valid HMAC signature');
        self::assertFalse(WebhookNotifier::verify($request['body'], $request['headers']['x-redirect-manager-timestamp'], $request['headers']['x-redirect-manager-signature'], 'wrong', 300, time()));
    }

    public function testWebhookSecretFromTheConfigWhenTheEnvironmentHasNone(): void
    {
        $server = $this->capture();
        $this->site()->writePluginConfig(['notifications' => ['webhook_url' => $server->url(), 'webhook_secret' => 'config-secret', 'not_found_threshold' => 3]]);
        $this->hits404('/missing-page', 3);

        $this->runJob('redirect-manager-maintenance');

        $request = $server->requests()[0];
        self::assertTrue(WebhookNotifier::verify($request['body'], $request['headers']['x-redirect-manager-timestamp'], $request['headers']['x-redirect-manager-signature'], 'config-secret', 300, time()));
    }

    public function testFailedWebhookIsRetriedOnTheNextRun(): void
    {
        $server = $this->capture(500);
        $this->site()->writePluginConfig(['notifications' => ['webhook_url' => $server->url(), 'not_found_threshold' => 3]]);
        $this->hits404('/missing-page', 3);

        $this->runJob('redirect-manager-maintenance');
        $failed = count($server->requests());
        self::assertGreaterThanOrEqual(1, $failed, 'the webhook was tried');

        $server->respondWith(200);
        $this->runJob('redirect-manager-maintenance');
        self::assertGreaterThan($failed, count($server->requests()));

        $before = count($server->requests());
        $this->runJob('redirect-manager-maintenance');
        self::assertCount($before, $server->requests(), 'delivered, so not repeated');
    }

    public function testExpiredRulesAreDisabledOnlyWithTheSwitch(): void
    {
        $this->installListener();
        $this->rules([
            ['id' => 'exp', 'source' => '/a', 'target' => '/typography', 'expires_at' => '2020-01-01T00:00:00+00:00'],
            ['id' => 'live', 'source' => '/b', 'target' => '/typography'],
        ]);

        $this->runJob('redirect-manager-maintenance');
        self::assertTrue($this->site()->repository()->find('exp')?->enabled, 'off by default');

        $this->site()->writePluginConfig(['redirects' => ['disable_expired' => true]]);
        $this->runJob('redirect-manager-maintenance');

        self::assertFalse($this->site()->repository()->find('exp')?->enabled);
        self::assertTrue($this->site()->repository()->find('live')?->enabled);
        self::assertStringContainsString("saved|update|/a|off\n", (string) $this->site()->readFile('logs/rm-jobs.log'));
    }

    public function testCheckTargetsFindsDeadTargetsAndNotifiesOnce(): void
    {
        $server = $this->capture();
        $this->site()->writePluginConfig([
            'base_url' => $this->site()->url(''),
            'notifications' => ['webhook_url' => $server->url()],
            'checker' => ['timeout' => 5],
        ]);
        $this->rules([
            ['id' => 'fine', 'source' => '/a', 'target' => '/typography'],
            ['id' => 'dead', 'source' => '/b', 'target' => '/no-such-page'],
            ['id' => 'off', 'source' => '/c', 'target' => '/no-such-page', 'enabled' => false],
            ['id' => 'gone', 'source' => '/d', 'target' => '', 'status' => 410],
        ]);

        $result = $this->runJob('redirect-manager-check-targets', ['REDIRECT_MANAGER_WEBHOOK_SECRET' => self::SECRET]);

        self::assertStringContainsString('"checked":2,"dead":1,"notified":1', $result['output']);
        $stored = $this->readJson('user/data/redirect-manager/target-checks.json');
        $ids = array_keys($stored['results']);
        sort($ids);
        self::assertSame(['dead', 'fine'], $ids);
        self::assertSame(200, $stored['results']['fine']['status']);
        self::assertSame(404, $stored['results']['dead']['status']);
        self::assertArrayNotHasKey('off', $stored['results']);
        self::assertNotNull($stored['last_run']);

        $requests = $server->requests();
        self::assertCount(1, $requests);
        $payload = json_decode($requests[0]['body'], true);
        self::assertSame('dead_target', $payload['event']);
        self::assertSame('dead', $payload['data']['rule_id']);
        self::assertSame('/b', $payload['data']['source']);
        self::assertSame(404, $payload['data']['status']);
        self::assertTrue(WebhookNotifier::verify($requests[0]['body'], $requests[0]['headers']['x-redirect-manager-timestamp'], $requests[0]['headers']['x-redirect-manager-signature'], self::SECRET, 300, time()));

        $this->runJob('redirect-manager-check-targets', ['REDIRECT_MANAGER_WEBHOOK_SECRET' => self::SECRET]);
        self::assertCount(1, $server->requests(), 'the same dead target is not reported twice');
    }

    public function testCheckTargetsDeadNotificationCanBeSwitchedOff(): void
    {
        $server = $this->capture();
        $this->site()->writePluginConfig(['base_url' => $this->site()->url(''), 'notifications' => ['webhook_url' => $server->url(), 'dead_targets' => false]]);
        $this->rules([['id' => 'dead', 'source' => '/b', 'target' => '/no-such-page']]);

        $this->runJob('redirect-manager-check-targets');

        self::assertSame([], $server->requests());
        self::assertArrayHasKey('dead', $this->readJson('user/data/redirect-manager/target-checks.json')['results']);
    }

    public function testJobsFailCleanlyOnABrokenRulesFile(): void
    {
        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', "rules: [ not: valid: yaml\n");

        $result = $this->grav(['scheduler', '--run=redirect-manager-check-targets']);

        self::assertStringNotContainsString('Job ran successfully', $result['output']);
        self::assertStringContainsString('check-targets failed', $this->site()->gravLog());
    }
}
