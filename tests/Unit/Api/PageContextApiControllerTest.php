<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Api\PageContextApiController;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(PageContextApiController::class)]
#[Group('api')]
final class PageContextApiControllerTest extends ApiTestCase
{
    private PageContextApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->controller(PageContextApiController::class);
        $this->seedRules([
            ['id' => 'a', 'source' => '/blog', 'target' => '/news', 'target_type' => 'page', 'origin' => 'auto', 'created_at' => '2026-09-29T09:30:00+00:00'],
            ['id' => 'b', 'source' => '/x', 'target' => '/other', 'target_type' => 'page', 'origin' => 'auto', 'created_at' => '2026-09-29T09:30:00+00:00'],
        ]);
        $this->app->services()->autoState()->addUnseen(['a', 'b']);
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

    public function testTheContextOfAPageComesWithWhatTheCallerMayDo(): void
    {
        $body = self::decode($this->api->show(new FakeServerRequest(['route' => '/news', 'lang' => 'de'])));

        self::assertSame('/news', $body['data']['route']);
        self::assertSame('de', $body['data']['language']);
        self::assertSame(['a'], array_column($body['data']['incoming'], 'id'));
        self::assertSame(1, $body['data']['unseen']);
        self::assertSame(['read' => true, 'manage' => true], $body['meta']['permissions']);
    }

    public function testTheLanguageAlsoComesAsLanguage(): void
    {
        $body = self::decode($this->api->show(new FakeServerRequest(['route' => '/news', 'language' => 'en'])));

        self::assertSame('en', $body['data']['language']);
    }

    public function testAReadOnlyCallerSeesThePageButIsNotAllowedToManage(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        $body = self::decode($this->api->show(new FakeServerRequest(['route' => '/news'])));

        self::assertSame(['read' => true, 'manage' => false], $body['meta']['permissions']);
        self::assertSame(200, $this->api->badge(new FakeServerRequest(['route' => '/news']))->getStatusCode());
    }

    public function testTheBadgeCountsTheUnseenRulesOfThePageAndIsNullForNone(): void
    {
        self::assertSame(['count' => 1], self::data($this->api->badge(new FakeServerRequest(['route' => '/news', 'lang' => 'en', 'type' => 'pages']))));
        self::assertSame(['count' => null], self::data($this->api->badge(new FakeServerRequest(['route' => '/nothing-here']))));
    }

    public function testMarkingSeenNeedsManageAndClearsOnlyThatPage(): void
    {
        AbstractApiController::$granted = [BaseController::READ];
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->seen(new FakeServerRequest(body: ['route' => '/news']))));
        self::assertSame(['a', 'b'], $this->app->services()->autoState()->unseen());

        AbstractApiController::$granted = [BaseController::MANAGE];
        $data = self::data($this->api->seen(new FakeServerRequest(body: ['route' => '/news', 'lang' => 'en'])));

        self::assertSame(['cleared' => 1, 'count' => null, 'sidebar' => 1], $data);
        self::assertSame(['b'], $this->app->services()->autoState()->unseen());
    }

    public function testEveryRouteNeedsAPermission(): void
    {
        AbstractApiController::$granted = [];

        foreach ([
            fn () => $this->api->show(new FakeServerRequest(['route' => '/news'])),
            fn () => $this->api->badge(new FakeServerRequest(['route' => '/news'])),
            fn () => $this->api->seen(new FakeServerRequest(body: ['route' => '/news'])),
        ] as $call) {
            self::assertInstanceOf(ForbiddenException::class, self::thrownBy($call));
        }
        AbstractApiController::$granted = [BaseController::MANAGE];
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->show(new FakeServerRequest(['route' => '/news']))), 'manage does not include read');
    }

    public function testAMissingOrMalformedRouteIsA422(): void
    {
        foreach ([[], ['route' => 'news'], ['route' => ['/news']], ['route' => '/a?b']] as $query) {
            $e = self::thrownBy(fn () => $this->api->show(new FakeServerRequest($query)));
            self::assertInstanceOf(ValidationException::class, $e, json_encode($query) ?: '');
            self::assertInstanceOf(ValidationException::class, self::thrownBy(fn () => $this->api->badge(new FakeServerRequest($query))));
        }
        self::assertInstanceOf(ValidationException::class, self::thrownBy(fn () => $this->api->seen(new FakeServerRequest(body: []))));
    }
}
