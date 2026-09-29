<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use DateTimeImmutable;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Pages;
use Grav\Common\Plugins;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\AutoRedirectController;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakePage;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use Grav\Plugin\RedirectManagerPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

#[CoversClass(AutoRedirectController::class)]
#[Group('api')]
final class AutoRedirectControllerTest extends GravTestCase
{
    use TempDirTrait;

    private const ANY = PageSnapshot::ANY;

    private Pages $pages;
    private ServiceFactory $services;
    private AutoRedirectController $api;

    /** @var array<string, object> */
    private array $registry = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->registry = Plugins::$registry;
        AbstractApiController::$granted = ['*'];

        $this->boot();
    }

    /**
     * @param array<string, mixed> $config plugin config
     */
    private function boot(array $config = []): void
    {
        $grav = new Grav();
        $this->pages = new Pages();
        $grav['pages'] = $this->pages;
        $grav['language'] = new Language();
        $grav['log'] = new NullLogger();
        $this->services = new ServiceFactory($config, [], $this->tmp . '/data', $this->tmp . '/cache', null, new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00')));
        $events = new RuleEvents(static fn (string $name, array $payload): array => $payload);
        $listener = new AutoRedirectListener($grav, $this->services, $events);
        Plugins::$registry['redirect-manager'] = new RedirectManagerPlugin($this->services, $events, $listener);

        $this->api = new AutoRedirectController($grav, new Config());
    }

    protected function tearDown(): void
    {
        Plugins::$registry = $this->registry;
        AbstractApiController::$granted = ['*'];
        $this->removeTempDir();
    }

    /**
     * @param list<string> $children
     */
    private function pend(string $title = 'Old post', string $route = '/blog/old', array $children = [], string $parent = '/blog'): string
    {
        $descendants = [];
        foreach ($children as $i => $child) {
            $descendants[] = new PageNode('c' . $i, [self::ANY => $child]);
        }
        $snapshot = new PageSnapshot($title, $route, [self::ANY => $route], $descendants, $parent === '' ? [] : [self::ANY => [$parent]]);

        return $this->services->autoState()->addPending($snapshot)->id;
    }

    private function livePage(string $route): void
    {
        $this->pages->byRoute[$route] = new FakePage('/site/pages' . $route, ucfirst(trim($route, '/')), $route);
    }

    /**
     * @param callable(): ResponseInterface $call
     */
    private static function thrownBy(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }
        self::fail('Nothing was thrown.');
    }

    /**
     * @return list<string> "source -> target" of the stored rules
     */
    private function stored(): array
    {
        return array_map(static fn ($r): string => $r->source . ' -> ' . $r->target . ' [' . $r->status->value . ']', $this->services->repository()->all());
    }

    // ---------------------------------------------------------------- pending

    public function testPendingIsEmptyWithoutDeletedPages(): void
    {
        $body = self::decode($this->api->pending(new FakeServerRequest()));

        self::assertSame([], $body['data']);
        self::assertSame(['total' => 0], $body['meta']);
    }

    public function testPendingListsTheDeletedPagesWithTheirSuggestedParent(): void
    {
        $this->livePage('/blog');
        $id = $this->pend('Old post', '/blog/old', ['/blog/old/a', '/blog/old/b']);

        $body = self::decode($this->api->pending(new FakeServerRequest()));

        self::assertSame(1, $body['meta']['total']);
        $row = $body['data'][0];
        self::assertSame($id, $row['id']);
        self::assertSame('Old post', $row['title']);
        self::assertSame('/blog/old', $row['route']);
        self::assertSame([self::ANY => '/blog/old'], $row['routes']);
        self::assertSame([], $row['languages'], 'the single-language marker is not a language');
        self::assertSame(['/blog/old/a', '/blog/old/b'], $row['children']);
        self::assertSame(2, $row['children_count']);
        self::assertSame('2026-09-29T10:00:00+00:00', $row['deleted_at']);
        self::assertSame('/blog', $row['suggested_parent']);
    }

    public function testPendingHasNoSuggestedParentWhenTheAncestorIsGone(): void
    {
        $this->pend();

        self::assertNull(self::decode($this->api->pending(new FakeServerRequest()))['data'][0]['suggested_parent']);
    }

    public function testPendingCapsTheChildrenListButCountsAll(): void
    {
        $children = array_map(static fn (int $i): string => '/blog/old/c' . $i, range(1, 60));
        $this->pend('Old post', '/blog/old', $children);

        $row = self::decode($this->api->pending(new FakeServerRequest()))['data'][0];

        self::assertCount(50, $row['children']);
        self::assertSame(60, $row['children_count']);
    }

    // ---------------------------------------------------------------- resolve

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidActions(): iterable
    {
        yield 'missing' => [null];
        yield 'unknown' => ['archive'];
        yield 'not a string' => [3];
        yield 'blank' => ['  '];
    }

    #[DataProvider('invalidActions')]
    public function testResolveWithoutAValidActionIs422(mixed $action): void
    {
        $id = $this->pend();

        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => $action], routeParams: ['id' => $id])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('action_invalid', $e->getValidationErrors()[0]['code']);
        self::assertSame(1, $this->services->autoState()->pendingCount());
    }

    public function testResolveOfAnUnknownDecisionIs404(): void
    {
        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => 'nope'])));

        self::assertInstanceOf(NotFoundException::class, $e);
        self::assertSame('No pending decision with this id.', $e->getMessage());
    }

    public function testDismissDropsTheDecisionWithoutCreatingRules(): void
    {
        $id = $this->pend();

        $data = self::data($this->api->resolve(new FakeServerRequest(body: ['action' => ' Dismiss '], routeParams: ['id' => $id])));

        self::assertSame(['id' => $id, 'action' => 'dismiss', 'created' => [], 'updated' => [], 'deleted' => [], 'notes' => []], $data);
        self::assertSame(0, $this->services->autoState()->pendingCount());
        self::assertSame([], $this->stored());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'none' => [null];
        yield 'blank' => ['   '];
        yield 'relative' => ['new-page'];
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'other scheme' => ['javascript:alert(1)'];
        yield 'scheme without host' => ['https://'];
    }

    #[DataProvider('invalidTargets')]
    public function testRedirectNeedsARouteOrAnHttpUrlAsTarget(?string $target): void
    {
        $id = $this->pend();

        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'redirect', 'target' => $target], routeParams: ['id' => $id])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('target_required', $e->getValidationErrors()[0]['code']);
        self::assertSame(1, $this->services->autoState()->pendingCount(), 'the decision stays open');
        self::assertSame([], $this->stored());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validTargets(): iterable
    {
        yield 'route' => ['/new-page', '/new-page'];
        yield 'trimmed' => ['  /new-page  ', '/new-page'];
    }

    #[DataProvider('validTargets')]
    public function testRedirectCreatesRulesToTheChosenTarget(string $input, string $expected): void
    {
        $id = $this->pend('Old post', '/blog/old', ['/blog/old/a']);

        $data = self::data($this->api->resolve(new FakeServerRequest(body: ['action' => 'redirect', 'target' => $input], routeParams: ['id' => $id])));

        self::assertSame($id, $data['id']);
        self::assertSame('redirect', $data['action']);
        self::assertSame(['/blog/old', '/blog/old/*'], array_column($data['created'], 'source'));
        self::assertSame([$expected, $expected], array_column($data['created'], 'target'));
        self::assertSame($this->services->autoState()->pendingCount(), 0);
        self::assertSame(['/blog/old -> ' . $expected . ' [301]', '/blog/old/* -> ' . $expected . ' [301]'], $this->stored());
    }

    public function testGoneCreatesA410RuleForThePage(): void
    {
        $id = $this->pend('Old post', '/blog/old');

        $data = self::data($this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => $id])));

        self::assertSame(['/blog/old'], array_column($data['created'], 'source'));
        self::assertSame(410, $data['created'][0]['status']);
        self::assertSame([], $data['deleted']);
        self::assertSame(0, $this->services->autoState()->pendingCount());
    }

    public function testParentRedirectsToTheNearestExistingAncestor(): void
    {
        $this->livePage('/blog');
        $id = $this->pend('Old post', '/blog/old');

        $data = self::data($this->api->resolve(new FakeServerRequest(body: ['action' => 'parent'], routeParams: ['id' => $id])));

        self::assertSame(['/blog/old'], array_column($data['created'], 'source'));
        self::assertSame(['/blog'], array_column($data['created'], 'target'));
    }

    public function testResolvedDecisionsCannotBeResolvedAgain(): void
    {
        $id = $this->pend();
        $this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => $id]));

        self::assertInstanceOf(NotFoundException::class, self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => $id]))));
        self::assertCount(1, $this->stored());
    }

    public function testAnExternalTargetThatTheGuardRejectsIs422WithTheRulesSourceAndNothingIsStored(): void
    {
        $id = $this->pend('Old post', '/blog/old', ['/blog/old/a']);

        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'redirect', 'target' => 'https://elsewhere.example/new'], routeParams: ['id' => $id])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('The redirect is not valid.', $e->getMessage());
        $errors = $e->getValidationErrors();
        self::assertNotSame([], $errors);
        self::assertSame(['/blog/old', '/blog/old/*'], array_values(array_unique(array_column($errors, 'source'))));
        foreach ($errors as $error) {
            self::assertSame('target', $error['field']);
            self::assertSame('error', $error['severity']);
        }
        self::assertSame(1, $this->services->autoState()->pendingCount(), 'the decision stays open');
        self::assertSame([], $this->stored());
    }

    public function testAnExternalTargetOnAnAllowedHostIsAccepted(): void
    {
        $this->boot(['security' => ['allowed_hosts' => ['example.org']]]);
        $id = $this->pend('Old post', '/blog/old');

        $data = self::data($this->api->resolve(new FakeServerRequest(body: ['action' => 'redirect', 'target' => 'https://example.org/new'], routeParams: ['id' => $id])));

        self::assertSame(['https://example.org/new'], array_column($data['created'], 'target'));
        self::assertSame('url', $data['created'][0]['target_type']);
        self::assertSame(['/blog/old -> https://example.org/new [301]'], $this->stored());
    }

    public function testAnUnreadableRulesFileIs422(): void
    {
        $id = $this->pend();
        file_put_contents($this->services->repository()->file(), "rules: [unclosed\n  - : :");

        $e = self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => $id])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertStringStartsWith('rules.yaml cannot be read: ', $e->getMessage());
        self::assertSame(1, $this->services->autoState()->pendingCount());
    }

    // ---------------------------------------------------------------- badge

    public function testBadgeIsNullWithoutNews(): void
    {
        $data = self::data($this->api->badge(new FakeServerRequest()));

        self::assertSame(['count' => null, 'unseen' => 0, 'pending' => 0], $data);
    }

    public function testBadgeCountsUnseenRulesAndPendingDecisions(): void
    {
        $this->services->repository()->saveAll([
            Rule::fromArray(['id' => 'a1', 'source' => '/a', 'target' => '/b']),
            Rule::fromArray(['id' => 'a2', 'source' => '/c', 'target' => '/d']),
        ]);
        $this->services->autoState()->addUnseen(['a1', 'a2', 'deleted-meanwhile']);
        $this->pend();

        $data = self::data($this->api->badge(new FakeServerRequest()));

        self::assertSame(['count' => 3, 'unseen' => 2, 'pending' => 1], $data);
    }

    public function testBadgeWithAnUnreadableRulesFileCountsAllUnseenIds(): void
    {
        $this->services->autoState()->addUnseen(['a1']);
        file_put_contents($this->services->repository()->file(), "rules: [unclosed\n  - : :");

        $data = self::data($this->api->badge(new FakeServerRequest()));

        self::assertSame(['count' => 1, 'unseen' => 1, 'pending' => 0], $data);
    }

    public function testBadgeSeenClearsTheUnseenRulesButKeepsPendingDecisions(): void
    {
        $this->services->autoState()->addUnseen(['a1', 'a2']);
        $this->pend();

        self::assertSame(['count' => 1], self::data($this->api->badgeSeen(new FakeServerRequest())));
        self::assertSame([], $this->services->autoState()->unseen());
    }

    public function testBadgeSeenWithNothingPendingReportsNull(): void
    {
        $this->services->autoState()->addUnseen(['a1']);

        self::assertSame(['count' => null], self::data($this->api->badgeSeen(new FakeServerRequest())));
    }

    // ---------------------------------------------------------------- permissions and plugin

    public function testPermissions(): void
    {
        $id = $this->pend();

        AbstractApiController::$granted = [BaseController::READ];
        self::assertSame(200, $this->api->pending(new FakeServerRequest())->getStatusCode());
        self::assertSame(200, $this->api->badge(new FakeServerRequest())->getStatusCode());
        self::assertSame(200, $this->api->badgeSeen(new FakeServerRequest())->getStatusCode());
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->resolve(new FakeServerRequest(body: ['action' => 'gone'], routeParams: ['id' => $id]))));

        AbstractApiController::$granted = [BaseController::MANAGE];
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->pending(new FakeServerRequest())));
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->badge(new FakeServerRequest())));
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->badgeSeen(new FakeServerRequest())));
        self::assertSame(1, $this->services->autoState()->pendingCount());
    }

    public function testWithoutTheLoadedPluginTheControllerFailsClearly(): void
    {
        Plugins::$registry = [];

        $e = self::thrownBy(fn () => $this->api->pending(new FakeServerRequest()));

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('The Redirect Manager plugin is not loaded.', $e->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function data(ResponseInterface $response): mixed
    {
        return self::decode($response)['data'] ?? null;
    }
}
