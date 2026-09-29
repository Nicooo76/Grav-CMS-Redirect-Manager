<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\ApiController;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(ApiController::class)]
#[CoversClass(BaseController::class)]
#[Group('api')]
final class ApiControllerTest extends ApiTestCase
{
    private ApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->controller(ApiController::class);
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

    /**
     * @return list<string> ids of the stored rules
     */
    private function seed(int $count = 3): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; ++$i) {
            $rows[] = ['id' => 'r' . $i, 'source' => '/old-' . $i, 'target' => '/new-' . $i];
        }
        $this->seedRules($rows);

        return array_column($rows, 'id');
    }

    // ---------------------------------------------------------------- index

    public function testIndexPaginatesWithLinksAndTheQueryKept(): void
    {
        $this->seed(3);

        $response = $this->api->index(new FakeServerRequest(['per_page' => '2', 'sort' => 'source']));
        $body = self::decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertCount(2, $body['data']);
        self::assertSame(3, $body['meta']['pagination']['total']);
        self::assertSame(2, $body['meta']['pagination']['per_page']);
        self::assertSame(2, $body['meta']['pagination']['total_pages']);
        self::assertSame(3, $body['meta']['total']);
        self::assertSame('/api/v1/redirects/rules?page=1&per_page=2&sort=source', $body['links']['self']);
        self::assertSame('/api/v1/redirects/rules?page=2&per_page=2&sort=source', $body['links']['next']);
    }

    public function testIndexNeedsTheReadPermission(): void
    {
        AbstractApiController::$granted = [BaseController::MANAGE];

        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->index(new FakeServerRequest())));
    }

    // ---------------------------------------------------------------- create

    public function testCreateStoresTheRuleAndAnswers201WithLocationAndEtag(): void
    {
        $response = $this->api->create(new FakeServerRequest(body: ['source' => '/a', 'target' => '/b']));
        $data = self::data($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/a', $data['source']);
        self::assertSame('/api/v1/redirects/rules/' . rawurlencode($data['id']), $response->getHeaderLine('Location'));
        $stored = $this->app->rules()->find($data['id']);
        self::assertSame('"' . $this->app->rules()->etag($stored) . '"', $response->getHeaderLine('ETag'));
        self::assertCount(1, $this->app->rules()->all());
    }

    public function testCreateWithDryRunValidatesWithoutStoring(): void
    {
        $response = $this->api->create(new FakeServerRequest(['dry_run' => 'true'], ['source' => '/a', 'target' => '/b']));
        $data = self::data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['rule', 'issues', 'preview'], array_keys($data));
        self::assertSame('/a', $data['rule']['source']);
        self::assertSame([], $this->app->rules()->all());
    }

    public function testCreateWithDryRunReportsIssuesInTheirFullShape(): void
    {
        $data = self::data($this->api->create(new FakeServerRequest(['dry_run' => '1'], ['source' => '/same', 'target' => '/same'])));

        self::assertNotSame([], $data['issues']);
        foreach ($data['issues'] as $issue) {
            self::assertSame(['code', 'severity', 'field', 'message', 'params'], array_keys($issue));
        }
    }

    public function testCreateWithoutDryRunWhenTheFlagIsOff(): void
    {
        $this->api->create(new FakeServerRequest(['dry_run' => 'no'], ['source' => '/a', 'target' => '/b']));

        self::assertCount(1, $this->app->rules()->all());
    }

    public function testCreateRejectsAnInvalidRuleWith422AndFieldErrors(): void
    {
        $e = self::thrownBy(fn () => $this->api->create(new FakeServerRequest(body: ['source' => '', 'target' => '/b'])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame(422, $e->getStatusCode());
        self::assertContains('source', array_column($e->getValidationErrors(), 'field'));
        self::assertSame([], $this->app->rules()->all());
    }

    public function testCreateNeedsTheManagePermission(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->create(new FakeServerRequest(body: ['source' => '/a', 'target' => '/b']))));
        self::assertSame([], $this->app->rules()->all());
    }

    // ---------------------------------------------------------------- show

    public function testShowReturnsTheRuleWithAnEtagOfTheStoredRule(): void
    {
        $this->seed(1);

        $response = $this->api->show(new FakeServerRequest(routeParams: ['id' => 'r1']));
        $data = self::data($response);

        self::assertSame('r1', $data['id']);
        self::assertArrayHasKey('stats', $data);
        self::assertSame('"' . $this->app->rules()->etag($this->app->rules()->find('r1')) . '"', $response->getHeaderLine('ETag'));
    }

    public function testShowOfAnUnknownIdIs404(): void
    {
        $e = self::thrownBy(fn () => $this->api->show(new FakeServerRequest(routeParams: ['id' => 'nope'])));

        self::assertInstanceOf(NotFoundException::class, $e);
        self::assertSame(404, $e->getStatusCode());
    }

    // ---------------------------------------------------------------- update

    public function testUpdateChangesTheRuleAndReturnsANewEtag(): void
    {
        $this->seed(1);
        $before = $this->app->rules()->etag($this->app->rules()->find('r1'));

        $response = $this->api->update(new FakeServerRequest(body: ['target' => '/changed'], routeParams: ['id' => 'r1']));

        self::assertSame('/changed', self::data($response)['target']);
        $after = $this->app->rules()->etag($this->app->rules()->find('r1'));
        self::assertNotSame($before, $after);
        self::assertSame('"' . $after . '"', $response->getHeaderLine('ETag'));
    }

    public function testUpdateAcceptsAMatchingIfMatch(): void
    {
        $this->seed(1);
        $etag = $this->app->rules()->etag($this->app->rules()->find('r1'));

        $response = $this->api->update(new FakeServerRequest(body: ['target' => '/changed'], routeParams: ['id' => 'r1'], headers: ['If-Match' => '"' . $etag . '"']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/changed', $this->app->rules()->find('r1')->target);
    }

    public function testUpdateWithAStaleIfMatchIs409AndChangesNothing(): void
    {
        $this->seed(1);

        $e = self::thrownBy(fn () => $this->api->update(new FakeServerRequest(body: ['target' => '/changed'], routeParams: ['id' => 'r1'], headers: ['If-Match' => '"stale"'])));

        self::assertInstanceOf(ConflictException::class, $e);
        self::assertSame('/new-1', $this->app->rules()->find('r1')->target);
    }

    public function testUpdateOfAnUnknownIdIs404(): void
    {
        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->update(new FakeServerRequest(body: ['target' => '/x'], routeParams: ['id' => 'nope']))));
    }

    // ---------------------------------------------------------------- delete and restore

    public function testDeleteRemovesTheRuleAndReturnsItForUndo(): void
    {
        $this->seed(2);

        $data = self::data($this->api->delete(new FakeServerRequest(routeParams: ['id' => 'r1'])));

        self::assertSame('r1', $data['id']);
        self::assertSame(['r2'], array_map(static fn ($r): string => $r->id, $this->app->rules()->all()));
    }

    public function testDeleteWithAStaleIfMatchIs409(): void
    {
        $this->seed(1);

        $e = self::thrownBy(fn () => $this->api->delete(new FakeServerRequest(routeParams: ['id' => 'r1'], headers: ['If-Match' => 'W/"old"'])));

        self::assertInstanceOf(ConflictException::class, $e);
        self::assertCount(1, $this->app->rules()->all());
    }

    public function testDeleteOfAnUnknownIdIs404(): void
    {
        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->delete(new FakeServerRequest(routeParams: ['id' => 'nope']))));
    }

    public function testRestoreBringsBackDeletedRules(): void
    {
        $this->seed(1);
        $deleted = self::data($this->api->delete(new FakeServerRequest(routeParams: ['id' => 'r1'])));

        $data = self::data($this->api->restore(new FakeServerRequest(body: ['rules' => [$deleted]])));

        self::assertSame(1, $data['restored']);
        self::assertSame('r1', $data['rules'][0]['id']);
        self::assertCount(1, $this->app->rules()->all());
    }

    public function testRestoreWithoutARulesListIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->restore(new FakeServerRequest(body: ['rules' => 'nope'])));

        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(422, $e->getStatusCode());
        self::assertSame([], $this->app->rules()->all());
    }

    // ---------------------------------------------------------------- bulk and reorder

    public function testBulkAppliesTheActionToTheIds(): void
    {
        $this->seed(3);

        $data = self::data($this->api->bulk(new FakeServerRequest(body: ['action' => 'delete', 'ids' => ['r1', 'r3']])));

        self::assertSame(2, $data['affected']);
        self::assertSame(['r1', 'r3'], array_column($data['rules'], 'id'));
        self::assertSame([], $data['skipped']);
        self::assertSame(['r2'], array_map(static fn ($r): string => $r->id, $this->app->rules()->all()));
    }

    public function testBulkWithAnUnknownActionIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->bulk(new FakeServerRequest(body: ['action' => 'explode', 'ids' => ['r1']])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('action', $e->getValidationErrors()[0]['field']);
    }

    public function testBulkWithoutAnActionOrIdsIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->bulk(new FakeServerRequest(body: ['action' => ['x'], 'ids' => 'r1'])));

        self::assertInstanceOf(ValidationException::class, $e);
    }

    public function testReorderReturnsTheRulesThatMoved(): void
    {
        $this->seed(3);

        $data = self::data($this->api->reorder(new FakeServerRequest(body: ['ids' => ['r3', 'r2', 'r1']])));

        self::assertSame(count($data['rules']), $data['affected']);
        self::assertGreaterThan(0, $data['affected']);
        $priority = fn (string $id): int => $this->app->rules()->find($id)->priority;
        self::assertGreaterThan($priority('r2'), $priority('r3'));
        self::assertGreaterThan($priority('r1'), $priority('r2'));
    }

    public function testReorderWithoutIdsIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->reorder(new FakeServerRequest(body: ['ids' => 'r1'])));

        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(422, $e->getStatusCode());
    }

    // ---------------------------------------------------------------- validate, analysis, groups, test

    public function testValidateNeedsOnlyTheReadPermissionAndStoresNothing(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        $data = self::data($this->api->validate(new FakeServerRequest(body: ['source' => '/a', 'target' => '/b'])));

        self::assertSame(['issues', 'preview'], array_keys($data));
        self::assertSame([], $this->app->rules()->all());
    }

    public function testShortenChainOfARuleThatIsNoChainIs422(): void
    {
        $this->seed(1);

        $e = self::thrownBy(fn () => $this->api->shortenChain(new FakeServerRequest(routeParams: ['id' => 'r1'])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('no_chain', $e->getValidationErrors()[0]['code']);
    }

    public function testShortenChainRewritesTheStartOfAChain(): void
    {
        $this->seedRules([
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/c'],
        ]);

        $response = $this->api->shortenChain(new FakeServerRequest(routeParams: ['id' => 'a']));

        self::assertSame('/c', self::data($response)['target']);
        self::assertSame('/c', $this->app->rules()->find('a')->target);
        self::assertNotSame('', $response->getHeaderLine('ETag'));
    }

    public function testAnalysisAndGroupsWrapTheServiceResult(): void
    {
        $this->seedRules([
            ['id' => 'a', 'source' => '/a', 'target' => '/b', 'group' => 'blog'],
            ['id' => 'b', 'source' => '/b', 'target' => '/a'],
        ]);

        $analysis = self::data($this->api->analysis(new FakeServerRequest()));
        $groups = self::data($this->api->groups(new FakeServerRequest()));

        self::assertSame(['chains', 'loops', 'conflicts', 'expired', 'unused'], array_keys($analysis));
        self::assertNotSame([], $analysis['loops']);
        self::assertSame([['name' => 'blog', 'count' => 1]], $groups['groups']);
    }

    public function testTestRunsTheRuleTester(): void
    {
        $this->seedRules([['id' => 'a', 'source' => '/old', 'target' => '/new']]);

        $data = self::data($this->api->test(new FakeServerRequest(body: ['url' => '/old'])));

        self::assertIsArray($data);
        self::assertStringContainsString('/new', (string) json_encode($data));
    }

    public function testTestWithoutAUrlIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->test(new FakeServerRequest(body: [])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('url', $e->getValidationErrors()[0]['field']);
    }
}
