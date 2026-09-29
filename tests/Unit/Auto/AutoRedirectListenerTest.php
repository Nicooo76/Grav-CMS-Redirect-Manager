<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use DateTimeImmutable;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectApplier;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PageSnapshotter;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakePage;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RocketTheme\Toolbox\Event\Event;

#[CoversClass(AutoRedirectListener::class)]
#[CoversClass(PageSnapshotter::class)]
#[Group('auto')]
final class AutoRedirectListenerTest extends GravTestCase
{
    use TempDirTrait;

    private const ANY = PageSnapshot::ANY;

    private Grav $grav;
    private Pages $pages;
    private Language $language;
    private FixedClock $clock;
    private FakeLogger $logger;

    /** @var list<array{string, array<string, mixed>}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->grav = new Grav();
        $this->pages = new Pages();
        $this->language = new Language();
        $this->logger = new FakeLogger();
        $this->grav['pages'] = $this->pages;
        $this->grav['language'] = $this->language;
        $this->grav['log'] = $this->logger;
        $this->dispatched = [];
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function services(array $config = [], ?string $dataDir = null): ServiceFactory
    {
        return new ServiceFactory($config, [], $dataDir ?? $this->tmp . '/data', $this->tmp . '/cache', null, $this->clock);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function listener(array $config = [], ?ServiceFactory $services = null): AutoRedirectListener
    {
        $services ??= $this->services($config);
        $events = new RuleEvents(function (string $name, array $payload): array {
            $this->dispatched[] = [$name, $payload];

            return $payload;
        });

        return new AutoRedirectListener($this->grav, $services, $events);
    }

    /**
     * @return list<Rule>
     */
    private function rules(?ServiceFactory $services = null): array
    {
        return ($services ?? $this->services())->repository()->all();
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private static function pairs(array $rules): array
    {
        $out = array_map(static fn (Rule $r): string => $r->source . ' => ' . $r->target . ' [' . $r->status->value . ']', $rules);
        sort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string> the pending deletes as "route"
     */
    private function pending(array $config = []): array
    {
        return array_map(
            static fn ($p): string => $p->snapshot->primaryRoute(),
            $this->services($config)->autoState()->pending(),
        );
    }

    private function moveEvent(string $old, string $new, ?FakePage $page = null, ?string $method = null): Event
    {
        return new Event(['old_route' => $old, 'new_route' => $new, 'page' => $page, 'method' => $method]);
    }

    /** Blog page with two children, root parent, registered in the fake page tree. */
    private function blog(string $route = '/blog'): FakePage
    {
        $blog = new FakePage('/site/pages/01.blog', 'Blog', $route);
        $blog->header = ['title' => 'Blog'];
        $blog->withChild(new FakePage('/site/pages/01.blog/01.a', 'A', $route . '/a'));
        $blog->withChild(new FakePage('/site/pages/01.blog/02.b', 'B', $route . '/b'));
        FakePage::rootPage()->withChild($blog);
        $this->pages->byPath[$blog->path] = $blog;

        return $blog;
    }

    /** Changes the page's public route the way an update of "slug" does, including its children. */
    private static function renameRoute(FakePage $page, string $from, string $to): void
    {
        $page->route = $to;
        foreach ($page->children as $child) {
            \assert($child instanceof FakePage);
            $child->route = $to . substr((string) $child->route, strlen($from));
        }
    }

    // ---- update -----------------------------------------------------------------------------------------------

    public function testSlugChangeCreatesRulesForPageAndChildren(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));

        $rules = $this->rules();
        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($rules));
        foreach ($rules as $rule) {
            self::assertSame(RuleSource::Auto, $rule->origin);
        }
        self::assertSame(1, $this->pages->resets, 'the tree is reloaded to read the new routes');
        self::assertCount(2, array_filter($this->dispatched, static fn (array $e): bool => $e[0] === 'onRedirectRuleSaved' && $e[1]['action'] === 'auto'));
        self::assertCount(2, $this->services()->autoState()->unseen());
        self::assertSame([], $this->logger->errors());
    }

    public function testRoutesChangeInHeaderAlsoCountsAndAnAliasKeepsRulesEmptyWhenRouteIsUnchanged(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->header = ['routes' => ['aliases' => ['/x']]];

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['routes' => ['aliases' => ['/x', '/y']]]]]));
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame([], $this->rules(), 'the public route did not change: no rule');
        self::assertSame(1, $this->pages->resets, 'but the snapshot was taken and the tree reloaded');
    }

    public function testUpdateWithoutRouteKeysCapturesNothing(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['title' => 'New title']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame([], $this->rules());
        self::assertSame(0, $this->pages->resets, 'no before snapshot: the after event returns early');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}> payload, whether a real page is added
     */
    public static function unusableUpdateEvents(): iterable
    {
        yield 'no page' => [['data' => ['header' => ['slug' => 'x']]], false];
        yield 'page is not a page' => [['page' => 'blog', 'data' => ['header' => ['slug' => 'x']]], false];
        yield 'no data' => [[], true];
        yield 'data is not an array' => [['data' => 'slug'], true];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unusableUpdateEvents')]
    public function testUnusableUpdateEventsAreIgnored(array $payload, bool $withPage): void
    {
        $listener = $this->listener();
        $page = $withPage ? $this->blog() : null;
        if ($page !== null) {
            $payload['page'] = $page;
        }

        $listener->onBeforePageUpdate(new Event($payload));
        $listener->onPageUpdated(new Event(['page' => $page]));

        self::assertSame([], $this->rules());
        self::assertSame(0, $this->pages->resets);
        self::assertSame([], $this->logger->lines);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function inactiveConfigs(): iterable
    {
        yield 'plugin disabled' => [['enabled' => false]];
        yield 'auto redirect disabled' => [['enabled' => true, 'auto_redirect' => ['enabled' => false]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('inactiveConfigs')]
    public function testInactiveListenerDoesNothingOnAnyEvent(array $config): void
    {
        $listener = $this->listener($config);
        $blog = $this->blog();
        $other = $this->blog('/other');
        $other->path = '/site/pages/02.other';

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));
        $listener->onPageMoved($this->moveEvent('/a', '/b', $blog));
        $listener->onBeforePagesReorganize(new Event(['operations' => [['actuallyMoves' => true, 'page' => $blog, 'oldPath' => $blog->path]]]));
        $listener->onPagesReorganized(new Event(['operations' => [['oldPath' => $blog->path, 'finalPath' => $blog->path, 'route' => '/blog']]]));
        $listener->onBeforePageDelete(new Event(['page' => $other]));
        $listener->onPageDeleted(new Event(['route' => '/other']));

        self::assertSame([], $this->rules($this->services($config)));
        self::assertSame([], $this->pending($config));
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->pages->resets);
    }

    public function testBatchUpdatesTrackEachPageByItsFolder(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $docs = new FakePage('/site/pages/02.docs', 'Docs', '/docs');
        $docs->header = [];
        $this->pages->byPath[$docs->path] = $docs;

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'method' => 'batch', 'data' => ['header' => ['slug' => 'news']]]));
        $listener->onBeforePageUpdate(new Event(['page' => $docs, 'method' => 'batch', 'data' => ['header' => ['slug' => 'manual']]]));
        self::renameRoute($blog, '/blog', '/news');
        self::renameRoute($docs, '/docs', '/manual');
        $listener->onPageUpdated(new Event(['page' => $docs]));
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame(
            ['/blog => /news [301]', '/blog/* => /news/$1 [301]', '/docs => /manual [301]'],
            self::pairs($this->rules()),
        );
    }

    public function testUpdatedTwiceOnlyActsOnce(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame(1, $this->pages->resets);
        self::assertCount(2, $this->rules());
    }

    public function testUpdatedWithoutPageOrWithMissingFreshPageDoesNothing(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));

        $listener->onPageUpdated(new Event([]));
        self::assertSame(0, $this->pages->resets);

        unset($this->pages->byPath[$blog->path]);
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame(1, $this->pages->resets, 'reloaded, but the page vanished from the tree');
        self::assertSame([], $this->rules());
    }

    public function testUpdatedPageThatNoLongerServesAUrlCreatesNoRule(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        $blog->published = false;

        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame([], $this->rules());
    }

    public function testUpdateOfAnUnpublishedPageIsNotCaptured(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->published = false;

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        $blog->published = true;
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertSame([], $this->rules());
    }

    public function testHeaderObjectIsReadThroughJson(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->header = (object) ['slug' => 'blog'];

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertCount(2, $this->rules());
    }

    public function testHeaderThatIsNotAnArrayCountsAsEmpty(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->header = 'scalar';

        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertCount(2, $this->rules());
    }

    // ---- move -------------------------------------------------------------------------------------------------

    public function testMoveFromTheRootDerivesTheOldRoutes(): void
    {
        $listener = $this->listener();
        $blog = $this->blog('/news'); // already at the new route

        $listener->onPageMoved($this->moveEvent('/blog', '/news', $blog));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));
        self::assertSame([], $this->logger->errors());
    }

    public function testMoveFindsThePageByRouteWhenTheEventCarriesNone(): void
    {
        $listener = $this->listener();
        $blog = $this->blog('/news');
        $this->pages->byRoute['/news'] = $blog;

        $listener->onPageMoved($this->moveEvent('/blog', '/news'));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));
    }

    public function testMoveFallsBackToTheRawRouteLookup(): void
    {
        $listener = $this->listener();
        $this->blog('/news'); // in the tree by folder, but Pages::find() does not know the route

        $listener->onPageMoved($this->moveEvent('/blog', '/news'));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));
    }

    public function testMoveOfAnUnknownPageDoesNothing(): void
    {
        $this->listener()->onPageMoved($this->moveEvent('/blog', '/news'));

        self::assertSame([], $this->rules());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteMoveEvents(): iterable
    {
        yield 'no routes' => [[]];
        yield 'no old route' => [['new_route' => '/news']];
        yield 'empty new route' => [['old_route' => '/blog', 'new_route' => '']];
        yield 'routes are not strings' => [['old_route' => 5, 'new_route' => ['x']]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('incompleteMoveEvents')]
    public function testIncompleteMoveEventsAreIgnored(array $payload): void
    {
        $blog = $this->blog('/news');
        $this->pages->byRoute['/news'] = $blog;

        $this->listener()->onPageMoved(new Event($payload));

        self::assertSame([], $this->rules());
        self::assertSame([], $this->logger->lines);
    }

    public function testMoveIntoANestedParentUsesTheParentsOldRoute(): void
    {
        $listener = $this->listener();
        $parent = new FakePage('/site/pages/01.parent', 'Parent', '/parent');
        $this->pages->byRoute['/parent'] = $parent;
        $moved = new FakePage('/site/pages/02.other/01.blog', 'Blog', '/other/blog');
        $moved->withChild(new FakePage('/site/pages/02.other/01.blog/01.a', 'A', '/other/blog/a'));

        $listener->onPageMoved($this->moveEvent('/parent/blog', '/other/blog', $moved));

        self::assertSame(['/parent/blog => /other/blog [301]', '/parent/blog/* => /other/blog/$1 [301]'], self::pairs($this->rules()));
    }

    public function testMoveWithAnUnknownOldParentAssumesTheParentRoute(): void
    {
        $moved = new FakePage('/site/pages/02.other/01.blog', 'Blog', '/other/blog');

        $this->listener()->onPageMoved($this->moveEvent('/gone/blog', '/other/blog', $moved));

        self::assertSame(['/gone/blog => /other/blog [301]'], self::pairs($this->rules()));
    }

    public function testMoveUnderTheRootRouteOfTheOldParentMapsToTheEmptyParent(): void
    {
        $parent = new FakePage('/site/pages/01.home', 'Home', '/');
        $this->pages->byRoute['/home'] = $parent;
        $moved = new FakePage('/site/pages/02.other/01.blog', 'Blog', '/other/blog');

        $this->listener()->onPageMoved($this->moveEvent('/home/blog', '/other/blog', $moved));

        self::assertSame(['/blog => /other/blog [301]'], self::pairs($this->rules()));
    }

    public function testMultilingualMoveResolvesTheOldParentPerLanguage(): void
    {
        $this->language->supported = ['en', 'de'];
        $this->language->default = 'en';
        $this->language->active = 'en';
        $parent = new FakePage('/site/pages/01.parent', 'Parent', '/parent');
        $parent->languages = ['en' => '/parent', 'de' => '/eltern'];
        $this->pages->byRoute['/parent'] = $parent;
        $moved = new FakePage('/site/pages/02.other/01.blog', 'Blog', '/other/blog');
        $moved->languages = ['en' => '/other/blog', 'de' => '/anders/blog'];

        $this->listener()->onPageMoved($this->moveEvent('/parent/blog', '/other/blog', $moved));

        $rules = $this->rules();
        self::assertSame(['/eltern/blog => /anders/blog [301]', '/parent/blog => /other/blog [301]'], self::pairs($rules));
        $languages = [];
        foreach ($rules as $rule) {
            $languages[$rule->source] = $rule->conditions->languages;
        }
        self::assertSame(['de'], $languages['/eltern/blog']);
        self::assertSame(['en'], $languages['/parent/blog']);
    }

    public function testOldParentWithoutTheMovedPagesLanguageUsesTheFirstRoute(): void
    {
        $this->language->supported = ['en', 'de'];
        $this->language->default = 'en';
        $this->language->active = 'en';
        $parent = new FakePage('/site/pages/01.parent', 'Parent', '/parent');
        $parent->languages = ['en' => '/parent'];
        $this->pages->byRoute['/parent'] = $parent;
        $moved = new FakePage('/site/pages/02.other/01.blog', 'Blog', '/other/blog');
        $moved->languages = ['de' => '/anders/blog'];

        $this->listener()->onPageMoved($this->moveEvent('/parent/blog', '/other/blog', $moved));

        self::assertSame(['/parent/blog => /anders/blog [301]'], self::pairs($this->rules()));
    }

    // ---- reorganize -------------------------------------------------------------------------------------------

    /**
     * @return array{FakePage, list<array<string, mixed>>}
     */
    private function reorganizeSetup(AutoRedirectListener $listener): array
    {
        $blog = $this->blog();
        $ops = [['actuallyMoves' => true, 'page' => $blog, 'oldPath' => $blog->path, 'finalPath' => $blog->path, 'route' => '/blog']];
        $listener->onBeforePagesReorganize(new Event(['operations' => $ops]));
        self::renameRoute($blog, '/blog', '/news');

        return [$blog, $ops];
    }

    public function testReorganizeCreatesRulesAndSkipsThePerPageMoveEventsOfTheSameRequest(): void
    {
        $listener = $this->listener();
        [$blog, $ops] = $this->reorganizeSetup($listener);

        $listener->onPagesReorganized(new Event(['operations' => $ops]));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));

        // The per-page move event of the same request is skipped, even when it would produce something else.
        $services = $this->services();
        $services->repository()->transaction(static fn (array $rules): array => []);
        $listener->onPageMoved($this->moveEvent('/Blog/', '/news', $blog, 'reorganize'));
        self::assertSame([], $this->rules(), 'handled by onPagesReorganized');

        // A move event of another method is not deduplicated.
        $listener->onPageMoved($this->moveEvent('/blog', '/news', $blog, 'move'));
        self::assertCount(2, $this->rules());
    }

    public function testReorganizeWithoutCaptureDoesNotSkipMoveEvents(): void
    {
        $listener = $this->listener();
        $blog = $this->blog('/news');

        $listener->onPagesReorganized(new Event(['operations' => [['oldPath' => 'x', 'finalPath' => 'y', 'route' => '/blog']]]));
        $listener->onPageMoved($this->moveEvent('/blog', '/news', $blog, 'reorganize'));

        self::assertCount(2, $this->rules());
    }

    public function testReorganizeCaptureIgnoresOperationsThatDoNotMove(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();

        $listener->onBeforePagesReorganize(new Event(['operations' => [
            'junk',
            ['actuallyMoves' => false, 'page' => $blog, 'oldPath' => $blog->path],
            ['actuallyMoves' => true, 'page' => 'not a page'],
            ['actuallyMoves' => true],
        ]]));
        $listener->onBeforePagesReorganize(new Event([]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPagesReorganized(new Event(['operations' => [['oldPath' => $blog->path, 'finalPath' => $blog->path, 'route' => '/blog']]]));

        self::assertSame([], $this->rules());
        self::assertSame(0, $this->pages->resets);
    }

    public function testReorganizeCaptureKeysByPagePathWithoutOldPathAndSkipsUnservedPages(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $draft = new FakePage('/site/pages/05.draft', 'Draft', '/draft', published: false);
        $this->pages->byPath[$draft->path] = $draft;

        $listener->onBeforePagesReorganize(new Event(['operations' => [
            ['actuallyMoves' => true, 'page' => $blog],
            ['actuallyMoves' => true, 'page' => $draft],
        ]]));
        self::renameRoute($blog, '/blog', '/news');
        $listener->onPagesReorganized(new Event(['operations' => [
            ['oldPath' => $blog->path, 'finalPath' => $blog->path, 'route' => '/blog'],
            ['oldPath' => $draft->path, 'finalPath' => $draft->path, 'route' => '/draft'],
        ]]));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));
    }

    public function testReorganizedSkipsIncompleteOrUnresolvableOperations(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $docs = new FakePage('/site/pages/02.docs', 'Docs', '/docs');
        $gone = new FakePage('/site/pages/03.gone', 'Gone', '/gone');
        $hidden = new FakePage('/site/pages/04.hidden', 'Hidden', '/hidden');
        $this->pages->byPath[$docs->path] = $docs;
        $this->pages->byPath[$hidden->path] = $hidden;
        $listener->onBeforePagesReorganize(new Event(['operations' => [
            ['actuallyMoves' => true, 'page' => $blog, 'oldPath' => $blog->path],
            ['actuallyMoves' => true, 'page' => $docs, 'oldPath' => $docs->path],
            ['actuallyMoves' => true, 'page' => $gone, 'oldPath' => $gone->path],
            ['actuallyMoves' => true, 'page' => $hidden, 'oldPath' => $hidden->path],
        ]]));
        self::renameRoute($blog, '/blog', '/news');
        $hidden->published = false;

        $listener->onPagesReorganized(new Event(['operations' => [
            'junk',
            ['oldPath' => '/site/pages/unknown', 'finalPath' => '/site/pages/unknown', 'route' => '/unknown'],
            ['oldPath' => $docs->path, 'finalPath' => '', 'route' => '/docs'],
            ['oldPath' => $gone->path, 'finalPath' => $gone->path, 'route' => '/gone'],
            ['oldPath' => $hidden->path, 'finalPath' => $hidden->path, 'route' => '/hidden'],
            ['oldPath' => $blog->path, 'finalPath' => $blog->path],
        ]]));

        self::assertSame(['/blog => /news [301]', '/blog/* => /news/$1 [301]'], self::pairs($this->rules()));
    }

    public function testReorganizedWithoutOperationsClearsTheCapture(): void
    {
        $listener = $this->listener();
        [$blog, $ops] = $this->reorganizeSetup($listener);

        $listener->onPagesReorganized(new Event([]));
        $listener->onPagesReorganized(new Event(['operations' => $ops]));

        self::assertSame([], $this->rules(), 'the first call consumed the capture');
    }

    public function testFailingOperationIsLoggedAndTheNextOnesStillRun(): void
    {
        // The repository cannot write: its data directory sits below a regular file.
        file_put_contents($this->tmp . '/file', 'x');
        $services = $this->services([], $this->tmp . '/file/data');
        $listener = $this->listener([], $services);
        [$blog, $ops] = $this->reorganizeSetup($listener);

        $listener->onPagesReorganized(new Event(['operations' => $ops]));

        self::assertCount(1, $this->logger->errors());
        self::assertStringContainsString('auto-redirect (reorganize op) failed', $this->logger->errors()[0]);
    }

    // ---- delete -----------------------------------------------------------------------------------------------

    public function testDeleteWithPolicyAskRecordsAPendingDecision(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame(['/blog'], $this->pending());
        self::assertSame([], $this->rules());
        $pending = $this->services()->autoState()->pending();
        self::assertCount(2, $pending[0]->snapshot->descendants);
    }

    public function testDeleteWithPolicyGoneCreatesRules(): void
    {
        $listener = $this->listener(['auto_redirect' => ['on_delete' => 'gone']]);
        $blog = $this->blog();

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        $rules = $this->rules();
        self::assertSame(['/blog =>  [410]', '/blog/* =>  [410]'], self::pairs($rules));
        self::assertSame([], $this->pending(['auto_redirect' => ['on_delete' => 'gone']]));
    }

    public function testDeleteWithPolicyParentRedirectsToTheNearestExistingParent(): void
    {
        $listener = $this->listener(['auto_redirect' => ['on_delete' => 'parent']]);
        $section = new FakePage('/site/pages/01.section', 'Section', '/section');
        $page = new FakePage('/site/pages/01.section/01.page', 'Page', '/section/page');
        $section->withChild($page);
        $this->pages->byRoute['/section'] = $section;

        $listener->onBeforePageDelete(new Event(['page' => $page]));
        $listener->onPageDeleted(new Event(['route' => '/section/page']));

        self::assertSame(['/section/page => /section [301]'], self::pairs($this->rules()));
    }

    public function testDeleteMatchesByRawRouteToo(): void
    {
        $listener = $this->listener();
        $blog = $this->blog('/custom');
        $blog->rawRoute = '/blog';

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onPageDeleted(new Event(['route' => '/Blog/']));

        self::assertSame(['/custom'], $this->pending());
    }

    public function testDeleteOfOneLanguageOfSeveralTranslationsDoesNothing(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->languages = ['en' => '/blog', 'de' => '/blog-de'];

        $listener->onBeforePageDelete(new Event(['page' => $blog, 'lang' => 'de']));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame([], $this->pending());
        self::assertSame([], $this->rules());
    }

    public function testDeleteOfTheLastLanguageIsHandledLikeAPageDelete(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->languages = ['en' => '/blog'];

        $listener->onBeforePageDelete(new Event(['page' => $blog, 'lang' => 'en']));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame(['/blog'], $this->pending());
    }

    public function testDeleteWithLangButFailingTranslationLookupCountsAsSingleTranslation(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->languagesThrow = true;

        $listener->onBeforePageDelete(new Event(['page' => $blog, 'lang' => 'de']));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame(['/blog'], $this->pending());
    }

    public function testDeleteOfPageThatServesNoUrlLeavesNothingPending(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $blog->published = false;

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame([], $this->pending());
    }

    public function testDeleteEventsPairUpByRouteAndOnlyConsumeTheirOwnEntry(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $docs = new FakePage('/site/pages/02.docs', 'Docs', '/docs');

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onBeforePageDelete(new Event(['page' => $docs]));
        $listener->onPageDeleted(new Event(['route' => '/docs']));
        self::assertSame(['/docs'], $this->pending());

        $listener->onPageDeleted(new Event(['route' => '/docs'])); // already consumed
        $listener->onPageDeleted(new Event(['route' => '/unrelated']));
        $listener->onPageDeleted(new Event([]));
        self::assertSame(['/docs'], $this->pending());

        $listener->onPageDeleted(new Event(['route' => '/blog']));
        self::assertEqualsCanonicalizing(['/docs', '/blog'], $this->pending());
    }

    public function testDeleteCaptureIgnoresEventsWithoutAPage(): void
    {
        $listener = $this->listener();

        $listener->onBeforePageDelete(new Event([]));
        $listener->onBeforePageDelete(new Event(['page' => 'blog']));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertSame([], $this->pending());
        self::assertSame([], $this->logger->lines);
    }

    // ---- guard ------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function throwingHandlers(): iterable
    {
        yield 'before update' => ['onBeforePageUpdate', 'update capture'];
        yield 'moved' => ['onPageMoved', 'move'];
        yield 'before delete' => ['onBeforePageDelete', 'delete capture'];
        yield 'before reorganize' => ['onBeforePagesReorganize', 'reorganize capture'];
    }

    #[DataProvider('throwingHandlers')]
    public function testHandlersLogInsteadOfThrowing(string $handler, string $what): void
    {
        $listener = $this->listener();
        $page = new FakePage('/site/pages/01.blog', 'Blog', '/blog');
        $page->routeThrows = true;
        $page->header = [];
        $event = match ($handler) {
            'onBeforePageUpdate' => new Event(['page' => $page, 'data' => ['header' => ['slug' => 'x']]]),
            'onPageMoved' => $this->moveEvent('/a', '/b', $page),
            'onBeforePagesReorganize' => new Event(['operations' => [['actuallyMoves' => true, 'page' => $page, 'oldPath' => 'x']]]),
            default => new Event(['page' => $page]),
        };

        $listener->{$handler}($event);

        self::assertCount(1, $this->logger->errors());
        self::assertStringContainsString('Redirect Manager: auto-redirect (' . $what . ') failed: route() failed', $this->logger->errors()[0]);
        self::assertStringContainsString('FakePage.php:', $this->logger->errors()[0]);
        self::assertSame([], $this->rules());
    }

    public function testFailingAfterHandlersAreLoggedToo(): void
    {
        $listener = $this->listener();
        $blog = $this->blog();
        $listener->onBeforePageUpdate(new Event(['page' => $blog, 'data' => ['header' => ['slug' => 'news']]]));
        $blog->routeThrows = true;

        $listener->onPageUpdated(new Event(['page' => $blog]));

        self::assertStringContainsString('(update) failed: route() failed', $this->logger->errors()[0]);
    }

    public function testFailingDeleteAppliedIsLogged(): void
    {
        file_put_contents($this->tmp . '/file', 'x');
        $config = ['auto_redirect' => ['on_delete' => 'gone']];
        $listener = $this->listener($config, $this->services($config, $this->tmp . '/file/data'));
        $blog = $this->blog();

        $listener->onBeforePageDelete(new Event(['page' => $blog]));
        $listener->onPageDeleted(new Event(['route' => '/blog']));

        self::assertCount(1, $this->logger->errors());
        self::assertStringContainsString('auto-redirect (delete) failed', $this->logger->errors()[0]);
    }

    public function testGuardWorksWithoutALoggerAndSurvivesALoggerThatThrows(): void
    {
        $page = new FakePage('/site/pages/01.blog', 'Blog', '/blog');
        $page->routeThrows = true;
        $event = new Event(['page' => $page]);

        unset($this->grav['log']); // Grav without a logger
        $this->listener()->onBeforePageDelete($event);

        $this->grav['log'] = new class () extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log failed');
            }
        };
        $this->listener()->onBeforePageDelete($event);

        $this->grav['log'] = 'not a logger';
        $this->listener()->onBeforePageDelete($event);

        self::assertSame([], $this->logger->lines);
        self::assertSame([], $this->rules());
    }

    // ---- accessors --------------------------------------------------------------------------------------------

    public function testApplierAndSnapshotterAreCreatedOnce(): void
    {
        $listener = $this->listener();

        self::assertInstanceOf(AutoRedirectApplier::class, $listener->applier());
        self::assertSame($listener->applier(), $listener->applier());
        self::assertInstanceOf(PageSnapshotter::class, $listener->snapshotter());
        self::assertSame($listener->snapshotter(), $listener->snapshotter());
    }

    public function testApplierIsWiredToConfigEventsAndLogger(): void
    {
        $listener = $this->listener(['auto_redirect' => ['status' => 302, 'children' => 'each']]);
        $before = new PageSnapshot('Blog', '/blog', [self::ANY => '/blog'], [new PageNode('a', [self::ANY => '/blog/a'])]);
        $after = new PageSnapshot('Blog', '/news', [self::ANY => '/news'], [new PageNode('a', [self::ANY => '/news/a'])]);

        $listener->applier()->move($before, $after);

        $rules = $this->rules();
        self::assertSame(['/blog => /news [302]', '/blog/a => /news/a [302]'], self::pairs($rules), 'status and children mode come from auto_redirect.*');
        self::assertSame(StatusCode::Found, $rules[0]->status);
        self::assertSame(MatchType::Exact, $rules[0]->matchType);
        self::assertSame('onRedirectRuleSaved', $this->dispatched[0][0]);
        self::assertSame('auto', $this->dispatched[0][1]['action']);
        self::assertNotEmpty($this->logger->lines, 'the applier logs its summary to grav[log]');
    }

    public function testApplierWorksWithoutALogger(): void
    {
        unset($this->grav['log']);
        $listener = $this->listener();

        $listener->applier()->move(
            new PageSnapshot('Blog', '/blog', [self::ANY => '/blog']),
            new PageSnapshot('Blog', '/news', [self::ANY => '/news']),
        );

        self::assertSame(['/blog => /news [301]'], self::pairs($this->rules()));
    }
}

/** PSR-3 logger that keeps its lines as "level: message". */
final class FakeLogger extends AbstractLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = $level . ': ' . $message;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->lines, static fn (string $l): bool => str_starts_with($l, 'error: ')));
    }
}
