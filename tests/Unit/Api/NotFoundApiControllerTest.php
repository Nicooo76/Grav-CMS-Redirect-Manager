<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Api\NotFoundApiController;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(NotFoundApiController::class)]
#[CoversClass(BaseController::class)]
#[Group('api')]
final class NotFoundApiControllerTest extends ApiTestCase
{
    private NotFoundApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->controller(NotFoundApiController::class);
    }

    private function hit(string $path, string $when = '-1 hour'): void
    {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify($when),
            $path,
            '',
            '',
            'UA/1.0',
            UserAgentClass::Browser,
            null,
            'de',
            'example.org',
            'GET',
        ));
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

    public function testIndexPaginatesTheGroupedPaths(): void
    {
        $this->hit('/a');
        $this->hit('/a', '-2 hours');
        $this->hit('/b');
        $this->hit('/c');

        $body = self::decode($this->api->index(new FakeServerRequest(['per_page' => '2', 'q' => ''])));

        self::assertCount(2, $body['data']);
        self::assertSame('/a', $body['data'][0]['path']);
        self::assertSame(3, $body['meta']['pagination']['total']);
        self::assertSame(2, $body['meta']['pagination']['total_pages']);
        self::assertSame(4, $body['meta']['totals']['hits']);
        self::assertStringStartsWith('/api/v1/redirects/404?page=1&per_page=2', $body['links']['self']);
        self::assertArrayHasKey('next', $body['links']);
    }

    public function testTrendReturnsTheHitsPerDay(): void
    {
        $this->hit('/a', '-1 hour');

        $data = self::data($this->api->trend(new FakeServerRequest(['days' => '7'])));

        self::assertArrayHasKey('days', $data);
        self::assertSame(1, array_sum($data['days']));
    }

    public function testEntriesListsTheHitsOfOnePath(): void
    {
        $this->hit('/a');
        $this->hit('/a', '-2 hours');
        $this->hit('/b');

        $data = self::data($this->api->entries(new FakeServerRequest(['path' => '/a'])));

        self::assertCount(2, $data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingPaths(): iterable
    {
        yield 'no query' => [[]];
        yield 'empty' => [['path' => '']];
        yield 'array' => [['path' => ['/a']]];
    }

    /**
     * @param array<string, mixed> $query
     *
     */
    #[DataProvider('missingPaths')]
    public function testEntriesNeedsAPath(array $query): void
    {
        $e = self::thrownBy(fn () => $this->api->entries(new FakeServerRequest($query)));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame([['field' => 'path', 'code' => 'required', 'message' => '"path" is required.', 'severity' => 'error']], $e->getValidationErrors());
    }

    public function testIgnoreAddsThePatternAndPurgesOnRequest(): void
    {
        $this->hit('/wp-login.php');
        $this->hit('/keep');

        $data = self::data($this->api->ignore(new FakeServerRequest(body: ['pattern' => '/wp-login.php', 'purge' => 'yes'])));

        self::assertSame('/wp-login.php', $data['pattern']);
        self::assertContains('/wp-login.php', $data['patterns']);
        self::assertSame(1, $data['purged']);
        self::assertSame(1, $data['purged_paths']);
    }

    public function testIgnoreWithoutPurgeKeepsTheLoggedHits(): void
    {
        $this->hit('/wp-login.php');

        $data = self::data($this->api->ignore(new FakeServerRequest(body: ['pattern' => '/wp-login.php'])));

        self::assertSame(0, $data['purged']);
        self::assertSame(0, $data['purged_paths']);
    }

    public function testIgnoreRejectsANonStringPattern(): void
    {
        $e = self::thrownBy(fn () => $this->api->ignore(new FakeServerRequest(body: ['pattern' => 5])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('invalid_type', $e->getValidationErrors()[0]['code']);
    }

    public function testIgnoreWithoutAPatternIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->ignore(new FakeServerRequest(body: [])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('pattern', $e->getValidationErrors()[0]['field']);
    }

    public function testResolveMarksThePathsResolvedByDefaultAndReopensOnRequest(): void
    {
        $this->hit('/a');
        $this->hit('/b');

        $resolved = self::data($this->api->resolve(new FakeServerRequest(body: ['paths' => ['/a', '/b']])));
        $reopened = self::data($this->api->resolve(new FakeServerRequest(body: ['paths' => ['/a'], 'resolved' => false])));

        self::assertSame(['resolved' => 2], $resolved);
        self::assertSame(['resolved' => 1], $reopened);
    }

    public function testResolveWithoutAListOfPathsIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['paths' => '/a'])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('paths', $e->getValidationErrors()[0]['field']);
    }

    public function testDeleteRemovesOnePath(): void
    {
        $this->hit('/a');
        $this->hit('/b');

        $data = self::data($this->api->delete(new FakeServerRequest(['path' => '/a'])));

        self::assertSame(1, $data['deleted']);
        self::assertSame(['/b'], array_column(self::decode($this->api->index(new FakeServerRequest()))['data'], 'path'));
    }

    public function testDeleteAllTakesTheFlagFromTheBodyBeforeTheQuery(): void
    {
        $this->hit('/a');
        $this->hit('/b');

        $data = self::data($this->api->delete(new FakeServerRequest(['all' => 'false'], ['all' => true])));

        self::assertSame(2, $data['deleted']);
    }

    public function testDeleteAllTakesTheFlagFromTheQuery(): void
    {
        $this->hit('/a');

        self::assertSame(1, self::data($this->api->delete(new FakeServerRequest(['all' => '1'])))['deleted']);
    }

    public function testDeleteWithoutPathOrAllIs422(): void
    {
        $this->hit('/a');

        $e = self::thrownBy(fn () => $this->api->delete(new FakeServerRequest(['path' => ''])));

        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(422, $e->getStatusCode());
    }

    public function testWritesNeedTheManagePermissionAndReadsTheReadPermission(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        self::assertSame(200, $this->api->index(new FakeServerRequest())->getStatusCode());
        foreach ([
            fn () => $this->api->ignore(new FakeServerRequest(body: ['pattern' => '/x'])),
            fn () => $this->api->resolve(new FakeServerRequest(body: ['paths' => ['/x']])),
            fn () => $this->api->delete(new FakeServerRequest(['all' => '1'])),
        ] as $write) {
            self::assertInstanceOf(ForbiddenException::class, self::thrownBy($write));
        }

        AbstractApiController::$granted = [BaseController::MANAGE];
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->trend(new FakeServerRequest())));
    }
}
