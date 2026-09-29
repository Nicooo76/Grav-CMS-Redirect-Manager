<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\CheckService;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Check\RouteClient;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CheckService::class)]
final class CheckServiceTest extends AppTestCase
{
    private const BASE = 'http://localhost:8080';

    private RouteClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new RouteClient([
            self::BASE . '/ok' => ['code' => 200],
            self::BASE . '/missing' => ['code' => 404],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function service(array $config = []): CheckService
    {
        $this->app = $this->makeApp($config, null, null, $this->client);

        return $this->app->checks();
    }

    private function seedTargets(): void
    {
        $this->seedRules([
            ['id' => 'r-ok', 'source' => '/a', 'target' => '/ok'],
            ['id' => 'r-dead', 'source' => '/b', 'target' => '/missing'],
            ['id' => 'r-off', 'source' => '/c', 'target' => '/ok', 'enabled' => false],
            ['id' => 'r-gone', 'source' => '/d', 'target' => '', 'status' => 410],
        ]);
    }

    public function testListIsEmptyBeforeTheFirstRun(): void
    {
        self::assertSame(['last_run' => null, 'results' => []], $this->service()->list());
    }

    public function testRunChecksEnabledRedirectTargetsOnly(): void
    {
        $this->seedTargets();
        $result = $this->service()->run();

        self::assertSame(2, $result['checked']);
        self::assertSame(1, $result['dead']);
        self::assertSame('2026-09-29T10:00:00+00:00', $result['last_run']);
        $byId = array_column($result['results'], null, 'rule_id');
        self::assertEqualsCanonicalizing(['r-ok', 'r-dead'], array_keys($byId));
        self::assertTrue($byId['r-ok']['ok']);
        self::assertSame(200, $byId['r-ok']['status']);
        self::assertFalse($byId['r-dead']['ok']);
        self::assertSame(404, $byId['r-dead']['status']);
        self::assertSame('/a', $byId['r-ok']['source']);
        self::assertSame('/ok', $byId['r-ok']['target']);
        self::assertSame(self::BASE . '/ok', $byId['r-ok']['url']);
    }

    public function testRunStoresResultsAndListShowsDeadFirst(): void
    {
        $this->seedTargets();
        $service = $this->service();
        $service->run();

        $list = $service->list();
        self::assertSame('2026-09-29T10:00:00+00:00', $list['last_run']);
        self::assertSame(['r-dead', 'r-ok'], array_column($list['results'], 'rule_id'));
        self::assertSame('/b', $list['results'][0]['source']);
        self::assertCount(2, $this->app->services()->checkResultStore()->all());
    }

    public function testListLeavesOutResultsOfDeletedRules(): void
    {
        $this->seedTargets();
        $service = $this->service();
        $service->run();
        $this->app->rules()->delete('r-dead');

        self::assertSame(['r-ok'], array_column($service->list()['results'], 'rule_id'));
    }

    public function testRunLimitedToIds(): void
    {
        $this->seedTargets();
        $result = $this->service()->run(['r-dead']);

        self::assertSame(['r-dead'], array_column($result['results'], 'rule_id'));
        self::assertSame(1, $result['dead']);
        self::assertCount(1, $this->client->requests);
    }

    public function testRunSkipsIdsThatAreNoTargets(): void
    {
        $this->seedTargets();
        $result = $this->service()->run(['r-off', 'r-gone']);

        self::assertSame([], $result['results']);
        self::assertSame(0, $result['checked']);
        self::assertSame([], $this->client->requests);
    }

    public function testUnknownIdIsNotFoundAndDoesNotStartTheInterval(): void
    {
        $this->seedTargets();
        $service = $this->service();

        try {
            $service->run(['nope']);
            self::fail('Expected an exception');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('rule', $e->kind);
            self::assertSame('nope', $e->id);
        }
        self::assertSame(2, $service->run()['checked'], 'the failed call did not use up the interval');
    }

    public function testSecondManualRunIsRateLimited(): void
    {
        $this->seedTargets();
        $service = $this->service();
        $service->run();

        try {
            $service->run();
            self::fail('Expected an exception');
        } catch (RateLimitedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
            self::assertLessThanOrEqual(300, $e->retryAfter);
        }
    }

    public function testIntervalFollowsConfig(): void
    {
        $this->seedTargets();
        $service = $this->service(['checker' => ['manual_interval' => 60]]);
        $service->run();

        try {
            $service->run();
            self::fail('Expected an exception');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
        }
    }

    public function testEnforceIntervalFalseBypassesTheLimit(): void
    {
        $this->seedTargets();
        $service = $this->service();
        $service->run();

        self::assertSame(2, $service->run(null, false)['checked']);
        self::assertSame(2, $service->run(null, false)['checked']);
    }

    public function testLockIsReleasedAfterARun(): void
    {
        $this->seedTargets();
        $service = $this->service();
        $service->run();

        $handle = fopen($this->tmp . '/data/check-run.lock', 'c+');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        fclose($handle);
    }

    public function testExternalTargetsAreSkippedWhenConfigured(): void
    {
        $this->seedRules([
            ['id' => 'r-ext', 'source' => '/e', 'target' => 'https://other.example/page', 'target_type' => 'external'],
            ['id' => 'r-ok', 'source' => '/a', 'target' => '/ok'],
        ]);
        $result = $this->service(['checker' => ['check_external' => false]])->run();

        $byId = array_column($result['results'], null, 'rule_id');
        self::assertSame(TargetCheckResult::ERROR_SKIPPED_EXTERNAL, $byId['r-ext']['error']);
        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['dead']);
        self::assertSame([self::BASE . '/ok'], array_column($this->client->requests, 'url'));
    }

    public function testPlaceholderTargetsAreSkipped(): void
    {
        $this->seedRules([
            ['id' => 'r-rx', 'source' => '^/p/(\d+)$', 'target' => '/q/$1', 'match_type' => 'regex'],
        ]);
        $result = $this->service()->run();

        self::assertSame(TargetCheckResult::ERROR_SKIPPED_DYNAMIC, $result['results'][0]['error']);
        self::assertSame(0, $result['checked']);
        self::assertSame(0, $result['dead']);
        self::assertSame([], $this->client->requests);
    }

    public function testRunWithoutTargetsReturnsEmptyResult(): void
    {
        $result = $this->service()->run();

        self::assertSame(['last_run' => null, 'checked' => 0, 'dead' => 0, 'results' => []], $result);
    }

    public function testMaxPerRunLeavesTheRestUnchecked(): void
    {
        $this->seedTargets();
        $result = $this->service(['checker' => ['max_per_run' => 1]])->run();

        self::assertSame(1, $result['checked']);
        $errors = array_column($result['results'], 'error');
        self::assertContains(TargetCheckResult::ERROR_RATE_LIMITED, $errors);
    }
}
