<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Api\SuggestionApiController;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(SuggestionApiController::class)]
#[CoversClass(BaseController::class)]
#[Group('api')]
final class SuggestionApiControllerTest extends ApiTestCase
{
    private SuggestionApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->makeApp(
            [],
            PageTreeFixture::pages(),
            new SiteContext(baseUrl: 'http://localhost:8080', languages: ['en', 'de'], defaultLanguage: 'en'),
        );
        $this->api = $this->controller(SuggestionApiController::class);
    }

    private function record(string $path, string $target, float $score): string
    {
        $record = $this->app->services()->suggestionStore()->upsertOpen($path, new Suggestion($target, $score, SuggestionReason::SimilarRoute, 'Title of ' . $target), SuggestionStore::SOURCE_NOT_FOUND);
        self::assertNotNull($record);

        return $record['id'];
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

    // ---------------------------------------------------------------- suggest

    public function testSuggestReturnsLiveSuggestionsForThePathInTheQuery(): void
    {
        $data = self::data($this->api->suggest(new FakeServerRequest(['path' => '/blog/grav-tips-and-trick'])));

        self::assertSame('/blog/grav-tips-and-tricks', $data[0]['target']);
    }

    public function testSuggestPassesLimitAndLanguageOn(): void
    {
        self::assertCount(2, self::data($this->api->suggest(new FakeServerRequest(['path' => '/old/faq', 'limit' => '2']))));
        // a non-numeric limit falls back to the default of 5
        self::assertSame(
            self::data($this->api->suggest(new FakeServerRequest(['path' => '/old/faq']))),
            self::data($this->api->suggest(new FakeServerRequest(['path' => '/old/faq', 'limit' => 'many']))),
        );
    }

    public function testSuggestWithoutAPathIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->suggest(new FakeServerRequest(['path' => ['x']])));

        self::assertInstanceOf(ValidationException::class, $e);
    }

    // ---------------------------------------------------------------- index

    public function testIndexListsTheOpenSuggestionsWithCounts(): void
    {
        $this->record('/old-a', '/blog', 0.9);
        $this->record('/old-b', '/about', 0.6);
        $rejected = $this->record('/old-c', '/contact', 0.7);
        $this->app->services()->suggestionStore()->reject($rejected);

        $body = self::decode($this->api->index(new FakeServerRequest()));

        self::assertSame(['/old-a', '/old-b'], array_column($body['data'], 'path'));
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(['open' => 2, 'accepted' => 0, 'rejected' => 1], $body['meta']['counts']);
    }

    public function testIndexFiltersByMinimumScore(): void
    {
        $this->record('/old-a', '/blog', 0.9);
        $this->record('/old-b', '/about', 0.6);

        $body = self::decode($this->api->index(new FakeServerRequest(['min_score' => '0.8'])));

        self::assertSame(['/old-a'], array_column($body['data'], 'path'));
        self::assertSame(1, $body['meta']['total']);
    }

    public function testIndexWithAnUnknownStatusIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->index(new FakeServerRequest(['status' => 'weird'])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('status', $e->getValidationErrors()[0]['field']);
    }

    // ---------------------------------------------------------------- generate

    public function testGenerateStoresSuggestionsForOpen404Paths(): void
    {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify('-1 hour'),
            '/blog/grav-tips-and-trick',
            '',
            '',
            'UA/1.0',
            UserAgentClass::Browser,
            null,
            null,
            'example.org',
            'GET',
        ));

        $data = self::data($this->api->generate(new FakeServerRequest()));

        self::assertSame(1, $data['created']);
        self::assertCount(1, $this->app->services()->suggestionStore()->open());
    }

    // ---------------------------------------------------------------- accept and reject

    public function testAcceptCreatesTheRuleAnswers201AndPointsToIt(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $response = $this->api->accept(new FakeServerRequest(routeParams: ['id' => $id]));
        $data = self::data($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/old-a', $data['rule']['source']);
        self::assertSame('/api/v1/redirects/rules/' . rawurlencode($data['rule']['id']), $response->getHeaderLine('Location'));
        self::assertSame('accepted', $data['suggestion']['status']);
        self::assertCount(1, $this->app->rules()->all());
    }

    public function testAcceptTakesOverridesFromTheBody(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $data = self::data($this->api->accept(new FakeServerRequest(body: ['target' => '/about'], routeParams: ['id' => $id])));

        self::assertSame('/about', $data['rule']['target']);
    }

    public function testAcceptOfAnUnknownSuggestionIs404(): void
    {
        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->accept(new FakeServerRequest(routeParams: ['id' => 'nope']))));
    }

    public function testAcceptingTwiceIs409(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);
        $this->api->accept(new FakeServerRequest(routeParams: ['id' => $id]));

        self::assertInstanceOf(ConflictException::class, self::thrownBy(fn () => $this->api->accept(new FakeServerRequest(routeParams: ['id' => $id]))));
        self::assertCount(1, $this->app->rules()->all());
    }

    public function testRejectMarksTheSuggestionRejected(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $data = self::data($this->api->reject(new FakeServerRequest(routeParams: ['id' => $id])));

        self::assertSame('rejected', $data['status']);
        self::assertSame([], $this->app->services()->suggestionStore()->open());
    }

    public function testRejectOfAnUnknownSuggestionIs404(): void
    {
        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->reject(new FakeServerRequest(routeParams: ['id' => 'nope']))));
    }

    // ---------------------------------------------------------------- bulk accept

    public function testBulkAcceptDryRunPreviewsWithoutCreatingRules(): void
    {
        $this->record('/old-a', '/blog', 0.95);
        $this->record('/old-b', '/about', 0.5);

        $data = self::data($this->api->bulkAccept(new FakeServerRequest(body: ['min_score' => '0.9', 'dry_run' => true])));

        self::assertSame(0.9, $data['min_score']);
        self::assertSame(1, $data['count']);
        self::assertSame([], $this->app->rules()->all());
    }

    public function testBulkAcceptCreatesRulesForTheSuggestionsAboveTheScore(): void
    {
        $this->record('/old-a', '/blog', 0.95);
        $this->record('/old-b', '/about', 0.5);

        $data = self::data($this->api->bulkAccept(new FakeServerRequest(body: ['min_score' => 0.9])));

        self::assertArrayHasKey('rules', $data);
        self::assertSame(['/old-a'], array_map(static fn ($r): string => $r->source, $this->app->rules()->all()));
    }

    public function testBulkAcceptCanBeLimitedToIds(): void
    {
        $a = $this->record('/old-a', '/blog', 0.95);
        $this->record('/old-c', '/about', 0.96);

        $data = self::data($this->api->bulkAccept(new FakeServerRequest(body: ['ids' => [$a, 7], 'dry_run' => '1'])));

        self::assertSame(1, $data['count']);
        self::assertSame(['/old-a'], array_column($data['rows'], 'path'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBulkBodies(): iterable
    {
        yield 'score is not a number' => [['min_score' => 'high'], 'invalid_type'];
        yield 'score above 1' => [['min_score' => 3], 'invalid_value'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBulkBodies')]
    public function testBulkAcceptRejectsAnInvalidScore(array $body, string $code): void
    {
        $e = self::thrownBy(fn () => $this->api->bulkAccept(new FakeServerRequest(body: $body)));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('min_score', $e->getValidationErrors()[0]['field']);
        self::assertSame($code, $e->getValidationErrors()[0]['code']);
    }

    public function testWritesNeedTheManagePermission(): void
    {
        AbstractApiController::$granted = [BaseController::READ];
        $id = $this->record('/old-a', '/blog', 0.9);

        self::assertSame(200, $this->api->index(new FakeServerRequest())->getStatusCode());
        foreach ([
            fn () => $this->api->generate(new FakeServerRequest()),
            fn () => $this->api->accept(new FakeServerRequest(routeParams: ['id' => $id])),
            fn () => $this->api->reject(new FakeServerRequest(routeParams: ['id' => $id])),
            fn () => $this->api->bulkAccept(new FakeServerRequest()),
        ] as $write) {
            self::assertInstanceOf(ForbiddenException::class, self::thrownBy($write));
        }
        self::assertCount(1, $this->app->services()->suggestionStore()->open());
    }
}
