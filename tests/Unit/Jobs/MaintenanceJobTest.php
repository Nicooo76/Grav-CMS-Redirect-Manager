<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Jobs;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Jobs\MaintenanceJob;
use Grav\Plugin\RedirectManager\Jobs\SuggestionRefresher;
use Grav\Plugin\RedirectManager\NotFound\IgnoreList;
use Grav\Plugin\RedirectManager\NotFound\IpAnonymizer;
use Grav\Plugin\RedirectManager\NotFound\IpMode;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLogger;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLoggerOptions;
use Grav\Plugin\RedirectManager\NotFound\ResolvedPaths;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClassifier;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MaintenanceJob::class)]
#[Group('jobs')]
final class MaintenanceJobTest extends JobsTestCase
{
    /** @var list<array{method: string, url: string, body: string, headers: array<string, string>}> */
    private array $requests = [];

    private function stats(): StatsStore
    {
        return new StatsStore($this->tmp . '/stats.json', $this->tmp . '/hits', $this->clock);
    }

    private function logger(): NotFoundLogger
    {
        return new NotFoundLogger(
            $this->log,
            new IgnoreList([]),
            new IpAnonymizer(IpMode::None),
            new UserAgentClassifier(),
            $this->clock,
            new NotFoundLoggerOptions(true, true, false),
        );
    }

    private function webhook(int $status = 200, string $secret = 'topsecret'): WebhookNotifier
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$name, $value] = explode(': ', (string) $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) $options['body'], 'headers' => $headers];

            return new MockResponse('', ['http_code' => $status]);
        });

        return new WebhookNotifier($client, $this->clock, 'http://localhost:9/hook', $secret, 5, 'Example', static function (int $ms): void {
        });
    }

    private function job(array $overrides = []): MaintenanceJob
    {
        $args = $overrides + [
            'stats' => $this->stats(),
            'retention' => 30,
            'suggestions' => null,
            'webhook' => null,
            'threshold' => 0,
            'rules' => null,
            'disableExpired' => false,
            'onSuggestion' => null,
            'onRuleDisabled' => null,
        ];

        return new MaintenanceJob(
            $args['stats'],
            $this->log,
            $this->logger(),
            $args['retention'],
            $this->clock,
            $args['suggestions'],
            $args['webhook'],
            new ThresholdTracker($this->tmp . '/notify-state.json', $this->clock),
            $args['threshold'],
            $args['rules'],
            $args['disableExpired'],
            $args['onSuggestion'],
            $args['onRuleDisabled'],
        );
    }

    public function testAggregatesPendingHitLogs(): void
    {
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);
        $recorder->record('r1');
        $recorder->record('r1');
        $stats = $this->stats();

        $result = $this->job(['stats' => $stats])->run();

        self::assertSame(2, $result['stats']['merged']);
        self::assertSame(2, $stats->forRule('r1')->total);
    }

    public function testPurgesLogEntriesOlderThanTheRetention(): void
    {
        $this->hit('/old', '2026-07-01T10:00:00+00:00');
        $this->hit('/new', '2026-09-28T10:00:00+00:00');

        $result = $this->job(['retention' => 30])->run();

        self::assertSame(1, $result['log']['purged']);
        $paths = [];
        foreach ($this->log->entries(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31')) as $entry) {
            $paths[] = $entry->path;
        }
        self::assertSame(['/new'], $paths);
        self::assertFileDoesNotExist($this->tmp . '/404/2026-07-01.jsonl');
    }

    public function testRetentionZeroKeepsEverything(): void
    {
        $this->hit('/old', '2026-01-01T10:00:00+00:00');

        $this->job(['retention' => 0])->run();

        self::assertFileExists($this->tmp . '/404/2026-01-01.jsonl');
    }

    public function testSizeCapRemovesTheOldestLogFiles(): void
    {
        $log = new \Grav\Plugin\RedirectManager\NotFound\JsonlLogStore($this->tmp . '/capped', $this->clock, 400);
        for ($day = 1; $day <= 5; $day++) {
            for ($i = 0; $i < 3; $i++) {
                $log->append(new \Grav\Plugin\RedirectManager\NotFound\NotFoundEntry(new DateTimeImmutable(sprintf('2026-09-%02dT10:00:00+00:00', $day + 20)), '/p' . $i, '', '', 'UA', \Grav\Plugin\RedirectManager\NotFound\UserAgentClass::Browser));
            }
        }
        $job = new MaintenanceJob($this->stats(), $log, $this->logger(), 0, $this->clock);

        $result = $job->run();

        self::assertGreaterThan(0, $result['log']['rotated']);
        self::assertFileExists($this->tmp . '/capped/2026-09-25.jsonl', 'the newest day stays');
    }

    public function testRefreshesSuggestionsAndReportsEachToTheListener(): void
    {
        $this->hits('/about-us', 3);
        $reported = [];
        $refresher = new SuggestionRefresher(
            $this->log,
            new SuggestionStore($this->tmp . '/suggestions.json', $this->clock),
            static fn (): Suggester => new Suggester(PageTreeFixture::index()),
            static fn (string $path, ?string $language): bool => false,
            new ResolvedPaths($this->tmp . '/404-state.json'),
            $this->clock,
        );

        $result = $this->job([
            'suggestions' => $refresher,
            'onSuggestion' => static function (array $record) use (&$reported): void {
                $reported[] = $record['target'];
            },
        ])->run();

        self::assertSame(1, $result['suggestions']['stored']);
        self::assertSame(['/about'], $reported);
    }

    public function testThresholdWebhookIsSentOnceWithAValidSignature(): void
    {
        $this->hits('/missing', 30);
        $this->hits('/rare', 3);
        $job = $this->job(['webhook' => $this->webhook(), 'threshold' => 25]);

        self::assertSame(1, $job->run()['webhook']['sent']);
        self::assertSame(0, $job->run()['webhook']['sent'], 'reported once per cooldown');

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        $payload = json_decode($request['body'], true);
        self::assertSame('not_found_threshold', $payload['event']);
        self::assertSame('/missing', $payload['data']['path']);
        self::assertSame(30, $payload['data']['hits']);
        self::assertSame('24h', $payload['data']['window']);
        self::assertTrue(WebhookNotifier::verify(
            $request['body'],
            $request['headers']['x-redirect-manager-timestamp'],
            $request['headers']['x-redirect-manager-signature'],
            'topsecret',
            300,
            $this->clock->now()->getTimestamp(),
        ));
    }

    public function testFailedWebhookIsRetriedNextRun(): void
    {
        $this->hits('/missing', 30);
        $failing = $this->job(['webhook' => $this->webhook(404), 'threshold' => 25]);
        self::assertSame(0, $failing->run()['webhook']['sent']);

        $working = $this->job(['webhook' => $this->webhook(200), 'threshold' => 25]);
        self::assertSame(1, $working->run()['webhook']['sent']);
    }

    public function testHitsOlderThan24HoursDoNotTriggerTheWebhook(): void
    {
        $this->hits('/missing', 30, '2026-09-27T10:00:00+00:00');

        self::assertSame(0, $this->job(['webhook' => $this->webhook(), 'threshold' => 25])->run()['webhook']['sent']);
    }

    public function testWebhookStepIsSkippedWithoutUrlOrThreshold(): void
    {
        $this->hits('/missing', 30);

        self::assertSame(['skipped' => true], $this->job()->run()['webhook']);
        self::assertSame(['skipped' => true], $this->job(['webhook' => $this->webhook(), 'threshold' => 0])->run()['webhook']);
        self::assertSame([], $this->requests);
    }

    public function testExpiredRulesAreDisabledOnlyWhenAsked(): void
    {
        $repo = new RuleRepository($this->tmp . '/data', $this->clock);
        $repo->saveAll([
            Rule::fromArray(['id' => 'exp', 'source' => '/a', 'target' => '/b', 'expires_at' => '2026-09-01T00:00:00+00:00']),
            Rule::fromArray(['id' => 'live', 'source' => '/c', 'target' => '/d', 'expires_at' => '2027-01-01T00:00:00+00:00']),
            Rule::fromArray(['id' => 'none', 'source' => '/e', 'target' => '/f']),
        ]);

        self::assertSame(['skipped' => true], $this->job(['rules' => $repo])->run()['expired']);
        self::assertTrue($repo->find('exp')?->enabled);

        $disabled = [];
        $result = $this->job([
            'rules' => $repo,
            'disableExpired' => true,
            'onRuleDisabled' => static function (Rule $after, Rule $before) use (&$disabled): void {
                $disabled[] = [$after->id, $before->enabled, $after->enabled];
            },
        ])->run();

        self::assertSame(1, $result['expired']['disabled']);
        self::assertFalse($repo->find('exp')?->enabled);
        self::assertTrue($repo->find('live')?->enabled);
        self::assertTrue($repo->find('none')?->enabled);
        self::assertSame([['exp', true, false]], $disabled);
    }

    public function testAFailingStepDoesNotStopTheOthers(): void
    {
        $this->hits('/about-us', 3);
        $refresher = new SuggestionRefresher(
            $this->log,
            new SuggestionStore($this->tmp . '/suggestions.json', $this->clock),
            static function (): Suggester {
                throw new \RuntimeException('index broken');
            },
            static fn (string $path, ?string $language): bool => false,
            new ResolvedPaths($this->tmp . '/404-state.json'),
            $this->clock,
        );
        $recorder = new HitRecorder($this->tmp . '/hits', $this->clock);
        $recorder->record('r1');

        $result = $this->job(['suggestions' => $refresher, 'stats' => $this->stats()])->run();

        self::assertSame('index broken', $result['suggestions']['error']);
        self::assertSame(1, $result['stats']['merged']);
        self::assertArrayHasKey('purged', $result['log']);
    }
}
