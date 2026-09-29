<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Api\SystemApiController;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Check\RouteClient;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(SystemApiController::class)]
#[CoversClass(BaseController::class)]
#[Group('api')]
final class SystemApiControllerTest extends ApiTestCase
{
    private const BASE = 'http://localhost:8080';

    private SystemApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApp();
    }

    private function useApp(?RouteClient $client = null): void
    {
        $this->app = $this->makeApp(
            [],
            PageTreeFixture::pages(),
            new SiteContext(baseUrl: self::BASE, languages: ['en', 'de'], defaultLanguage: 'en'),
            $client,
        );
        $this->api = $this->controller(SystemApiController::class);
    }

    /**
     * @param callable(): mixed $callable
     */
    private static function thrownBy(callable $callable): Throwable
    {
        try {
            $callable();
        } catch (Throwable $e) {
            return $e;
        }
        self::fail('Nothing was thrown.');
    }

    public function testStatsReturnTheDashboardAndWhatTheCallerMayDo(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/b']]);

        $body = self::decode($this->api->stats(new FakeServerRequest()));

        self::assertSame(1, $body['data']['rules_total']);
        self::assertSame(['read' => true, 'manage' => true], $body['meta']['permissions']);
    }

    public function testStatsOfAReadOnlyCallerSaysSo(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        $body = self::decode($this->api->stats(new FakeServerRequest()));

        self::assertSame(['read' => true, 'manage' => false], $body['meta']['permissions']);
    }

    public function testStatsNeedTheReadPermission(): void
    {
        AbstractApiController::$granted = [BaseController::MANAGE];

        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->stats(new FakeServerRequest())));
    }

    public function testChecksListsTheStoredResults(): void
    {
        $data = self::data($this->api->checks(new FakeServerRequest()));

        self::assertSame(['last_run' => null, 'results' => []], $data);
    }

    public function testRunChecksChecksAllTargetsAndStoresTheResult(): void
    {
        $this->useApp(new RouteClient([self::BASE . '/ok' => ['code' => 200], self::BASE . '/missing' => ['code' => 404]]));
        $this->seedRules([
            ['id' => 'r-ok', 'source' => '/a', 'target' => '/ok'],
            ['id' => 'r-dead', 'source' => '/b', 'target' => '/missing'],
        ]);

        $data = self::data($this->api->runChecks(new FakeServerRequest()));

        self::assertSame(2, $data['checked']);
        self::assertSame(1, $data['dead']);
        self::assertCount(2, self::data($this->api->checks(new FakeServerRequest()))['results']);
    }

    public function testRunChecksHonoursTheIdsAndEnforcesTheIntervalForManualRuns(): void
    {
        $this->useApp(new RouteClient([self::BASE . '/ok' => ['code' => 200]]));
        $this->seedRules([
            ['id' => 'r-ok', 'source' => '/a', 'target' => '/ok'],
            ['id' => 'r-other', 'source' => '/b', 'target' => '/ok'],
        ]);

        $first = self::data($this->api->runChecks(new FakeServerRequest(body: ['ids' => ['r-ok', 4]])));
        $second = self::thrownBy(fn () => $this->api->runChecks(new FakeServerRequest()));

        self::assertSame(1, $first['checked']);
        self::assertInstanceOf(ApiException::class, $second);
        self::assertSame(429, $second->getStatusCode());
        self::assertArrayHasKey('Retry-After', $second->getHeaders());
    }

    public function testRunChecksOfAnUnknownRuleIs404(): void
    {
        $this->useApp(new RouteClient([]));

        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->runChecks(new FakeServerRequest(body: ['ids' => ['nope']]))));
    }

    public function testRunChecksWithoutRulesChecksNothing(): void
    {
        $data = self::data($this->api->runChecks(new FakeServerRequest(body: ['ids' => 'all'])));

        self::assertSame(0, $data['checked']);
        self::assertSame([], $data['results']);
    }

    public function testRunChecksNeedsTheManagePermission(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->runChecks(new FakeServerRequest())));
    }

    public function testPagesSearchesTheIndex(): void
    {
        $data = self::data($this->api->pages(new FakeServerRequest(['q' => 'about', 'limit' => '5'])));

        self::assertNotSame([], $data);
        self::assertSame('/about', $data[0]['route']);
        self::assertSame(['route', 'title', 'language', 'translations'], array_keys($data[0]));
    }

    public function testPagesWithoutQueryListsPagesUpToTheLimit(): void
    {
        self::assertCount(3, self::data($this->api->pages(new FakeServerRequest(['limit' => '3']))));
        self::assertCount(1, self::data($this->api->pages(new FakeServerRequest(['limit' => '0']))));
    }

    public function testPagesFilterByLanguageAndIgnoreABlankOne(): void
    {
        $de = self::data($this->api->pages(new FakeServerRequest(['language' => 'de', 'limit' => '100'])));
        $all = self::data($this->api->pages(new FakeServerRequest(['language' => '', 'limit' => '100'])));

        self::assertNotSame([], $de);
        self::assertSame(['de'], array_values(array_unique(array_column($de, 'language'))));
        self::assertGreaterThan(count($de), count($all));
    }
}
