<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Jobs;

use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\Check\TargetChecker;
use Grav\Plugin\RedirectManager\Check\TargetCheckerOptions;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Jobs\CheckTargetsJob;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\Check\RouteClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CheckTargetsJob::class)]
#[Group('jobs')]
final class CheckTargetsJobTest extends JobsTestCase
{
    private RuleRepository $rules;
    private CheckResultStore $results;
    private ThresholdTracker $tracker;

    /** @var list<string> */
    private array $webhookBodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new RuleRepository($this->tmp . '/data', $this->clock);
        $this->results = new CheckResultStore($this->tmp . '/data/target-checks.json');
        $this->tracker = new ThresholdTracker($this->tmp . '/data/notify-state.json', $this->clock);
        $this->webhookBodies = [];
    }

    private function job(RouteClient $client, bool $webhook = true, bool $notifyDead = true): CheckTargetsJob
    {
        $checker = new TargetChecker(
            $client,
            $this->clock,
            new TargetCheckerOptions('https://site.test', minIntervalMs: 0),
            static fn (string $host): array => ['93.184.216.34'],
            static function (int $ms): void {
            },
        );
        $notifier = null;
        if ($webhook) {
            $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                $this->webhookBodies[] = (string) $options['body'];

                return new MockResponse('', ['http_code' => 200]);
            });
            $notifier = new WebhookNotifier($http, $this->clock, 'http://127.0.0.1:9/hook', 'secret');
        }

        return new CheckTargetsJob($this->rules, $checker, $this->results, $notifier, $this->tracker, $notifyDead);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function rule(string $id, string $target, array $fields = []): Rule
    {
        return Rule::fromArray($fields + ['id' => $id, 'source' => '/' . $id, 'target' => $target]);
    }

    public function testChecksEnabledRedirectTargetsAndStoresTheResults(): void
    {
        $this->rules->saveAll([
            $this->rule('ok', '/fine'),
            $this->rule('dead', '/missing'),
            $this->rule('off', '/off', ['enabled' => false]),
            $this->rule('gone', '', ['status' => 410]),
        ]);
        $client = new RouteClient(['HEAD https://site.test/missing' => ['code' => 404]]);

        $result = $this->job($client, webhook: false)->run();

        self::assertSame(2, $result['checked']);
        self::assertSame(1, $result['dead']);
        self::assertSame(0, $result['notified']);
        $stored = array_keys($this->results->all());
        sort($stored);
        self::assertSame(['dead', 'ok'], $stored);
        self::assertCount(1, $this->results->dead());
        self::assertSame(['HEAD https://site.test/fine', 'HEAD https://site.test/missing'], $this->sortedRequests($client));
    }

    /**
     * @return list<string>
     */
    private function sortedRequests(RouteClient $client): array
    {
        $out = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $client->requests);
        sort($out);

        return $out;
    }

    public function testDeadTargetWebhookFiresOncePerRelapse(): void
    {
        $this->rules->saveAll([$this->rule('dead', '/missing')]);
        $down = new RouteClient(['HEAD https://site.test/missing' => ['code' => 500]]);
        $up = new RouteClient(['HEAD https://site.test/missing' => ['code' => 200]]);

        self::assertSame(1, $this->job($down)->run()['notified']);
        self::assertSame(0, $this->job($down)->run()['notified'], 'still dead: reported once');
        self::assertSame(0, $this->job($up)->run()['notified']);
        self::assertSame(1, $this->job($down)->run()['notified'], 'dead again: reported again');

        self::assertCount(2, $this->webhookBodies);
        $payload = json_decode($this->webhookBodies[0], true);
        self::assertSame('dead_target', $payload['event']);
        self::assertSame('dead', $payload['data']['rule_id']);
        self::assertSame('/dead', $payload['data']['source']);
        self::assertSame('/missing', $payload['data']['target']);
        self::assertSame(500, $payload['data']['status']);
    }

    public function testNoWebhookWhenNotificationIsOff(): void
    {
        $this->rules->saveAll([$this->rule('dead', '/missing')]);
        $client = new RouteClient(['HEAD https://site.test/missing' => ['code' => 404]]);

        self::assertSame(0, $this->job($client, notifyDead: false)->run()['notified']);
        self::assertSame([], $this->webhookBodies);
    }

    public function testResultsOfDeletedRulesAreForgotten(): void
    {
        $this->rules->saveAll([$this->rule('a', '/a'), $this->rule('b', '/b')]);
        $client = new RouteClient();
        $this->job($client, webhook: false)->run();
        self::assertCount(2, $this->results->all());

        $this->rules->delete(['b']);
        $this->job($client, webhook: false)->run();

        self::assertSame(['a'], array_keys($this->results->all()));
    }

    public function testNoRulesNoRequests(): void
    {
        $client = new RouteClient();

        $result = $this->job($client, webhook: false)->run();

        self::assertSame(['checked' => 0, 'dead' => 0, 'notified' => 0], $result);
        self::assertSame([], $client->requests);
    }
}
