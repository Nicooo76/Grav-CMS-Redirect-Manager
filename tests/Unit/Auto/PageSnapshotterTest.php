<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PageSnapshotter;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakePage;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PageSnapshotter::class)]
#[Group('auto')]
final class PageSnapshotterTest extends GravTestCase
{
    private const ANY = PageSnapshot::ANY;

    private Grav $grav;
    private Pages $pages;
    private Language $language;
    private PageSnapshotter $snapshotter;

    protected function setUp(): void
    {
        $this->grav = new Grav();
        $this->pages = new Pages();
        $this->language = new Language();
        $this->grav['pages'] = $this->pages;
        $this->grav['language'] = $this->language;
        $this->snapshotter = new PageSnapshotter($this->grav);
    }

    private function multilingual(string $active = 'en', string $default = 'en'): void
    {
        $this->language->supported = ['en', 'de'];
        $this->language->default = $default;
        $this->language->active = $active;
    }

    private static function page(string $route, string $dir, bool $routable = true, bool $published = true): FakePage
    {
        return new FakePage('/site/pages/' . $dir, ucfirst(basename($route)), $route, $route, $routable, $published);
    }

    public function testSnapshotOfASingleLanguagePageWithSubtree(): void
    {
        $blog = self::page('/blog', '01.blog');
        $blog->home = false;
        $a = self::page('/blog/a', '01.blog/01.a');
        $b = self::page('/blog/b', '01.blog/02.b');
        $deep = self::page('/blog/b/deep', '01.blog/02.b/01.deep');
        $blog->withChild($a)->withChild($b);
        $b->withChild($deep);
        FakePage::rootPage()->withChild($blog);

        $snapshot = $this->snapshotter->snapshot($blog);

        self::assertNotNull($snapshot);
        self::assertSame('Blog', $snapshot->title);
        self::assertSame('/blog', $snapshot->rawRoute);
        self::assertSame([self::ANY => '/blog'], $snapshot->routes);
        self::assertFalse($snapshot->home);
        self::assertEquals(
            [
                new PageNode('01.a', [self::ANY => '/blog/a']),
                new PageNode('02.b', [self::ANY => '/blog/b']),
                new PageNode('02.b/01.deep', [self::ANY => '/blog/b/deep']),
            ],
            $snapshot->descendants,
        );
        self::assertSame([self::ANY => []], $snapshot->ancestors, 'the root page is not an ancestor');
    }

    public function testHomePageIsFlagged(): void
    {
        $home = self::page('/home', '01.home');
        $home->home = true;

        self::assertTrue($this->snapshotter->snapshot($home)?->home);
    }

    /**
     * @param callable(FakePage): void $configure
     */
    #[DataProvider('pagesWithoutUrl')]
    public function testPagesThatServeNoUrlHaveNoRoutesAndNoSnapshot(callable $configure): void
    {
        $page = self::page('/x', '01.x');
        $configure($page);

        self::assertSame([], $this->snapshotter->routesOf($page));
        self::assertNull($this->snapshotter->snapshot($page));
    }

    /**
     * @return iterable<string, array{callable(FakePage): void}>
     */
    public static function pagesWithoutUrl(): iterable
    {
        yield 'not routable' => [static function (FakePage $p): void {
            $p->routable = false;
        }];
        yield 'unpublished' => [static function (FakePage $p): void {
            $p->published = false;
        }];
        yield 'modular' => [static function (FakePage $p): void {
            $p->modular = true;
        }];
        yield 'root' => [static function (FakePage $p): void {
            $p->root = true;
        }];
    }

    public function testDescendantsSkipUnservedPagesButRecurseIntoThem(): void
    {
        $blog = self::page('/blog', '01.blog');
        $hidden = self::page('/blog/hidden', '01.blog/01.hidden', routable: false);
        $inner = self::page('/blog/hidden/inner', '01.blog/01.hidden/01.inner');
        $draft = self::page('/blog/draft', '01.blog/02.draft', published: false);
        $blog->withChild($hidden)->withChild($draft);
        $hidden->withChild($inner);
        $blog->children[] = new \stdClass(); // not a page: ignored

        $snapshot = $this->snapshotter->snapshot($blog);

        self::assertNotNull($snapshot);
        self::assertEquals([new PageNode('01.hidden/01.inner', [self::ANY => '/blog/hidden/inner'])], $snapshot->descendants);
    }

    public function testDescendantsAreCappedAtMaxDescendants(): void
    {
        $blog = self::page('/blog', '01.blog');
        for ($i = 0; $i < PageSnapshotter::MAX_DESCENDANTS + 1; ++$i) {
            $blog->children[] = new FakePage('/site/pages/01.blog/p' . $i, 'P', '/blog/p' . $i);
        }

        $snapshot = $this->snapshotter->snapshot($blog);

        self::assertNotNull($snapshot);
        self::assertCount(PageSnapshotter::MAX_DESCENDANTS, $snapshot->descendants);
        self::assertSame('p4999', $snapshot->descendants[4999]->key);
    }

    public function testAncestorsAreCollectedNearestFirstAndSkipUnservedOnes(): void
    {
        $root = FakePage::rootPage();
        $top = self::page('/top', '01.top');
        $draft = self::page('/top/draft', '01.top/01.draft', published: false);
        $folder = self::page('/top/draft/folder', '01.top/01.draft/01.folder', routable: false);
        $mid = self::page('/top/mid', '01.top/02.mid');
        $leaf = self::page('/top/mid/leaf', '01.top/02.mid/01.leaf');
        $root->withChild($top);
        $top->withChild($mid);
        $mid->withChild($leaf);
        $top->withChild($draft);
        $draft->withChild($folder);
        $folder->withChild(self::page('/top/draft/folder/x', '01.top/01.draft/01.folder/01.x'));

        self::assertSame([self::ANY => ['/top/mid', '/top']], $this->snapshotter->snapshot($leaf)?->ancestors);
        $x = $folder->children[0];
        self::assertInstanceOf(FakePage::class, $x);
        self::assertSame([self::ANY => ['/top']], $this->snapshotter->snapshot($x)?->ancestors, 'unpublished and unroutable parents are skipped');
    }

    public function testAncestorWithRootRouteOrEmptyRouteIsLeftOut(): void
    {
        $parent = new FakePage('/site/pages/01.p', 'P', '/');
        $child = self::page('/c', '01.p/01.c');
        $parent->withChild($child);

        self::assertSame([self::ANY => []], $this->snapshotter->snapshot($child)?->ancestors);
    }

    public function testMultilingualSnapshotResolvesRoutesAndAncestorsPerLanguage(): void
    {
        $this->multilingual();
        $parent = self::page('/parent', '01.parent');
        $parent->languages = ['en' => '/parent', 'de' => '/eltern'];
        $child = self::page('/parent/kid', '01.parent/01.kid');
        $child->languages = ['en' => '/parent/kid', 'de' => '/eltern/kind'];
        $parent->withChild($child);
        $grand = self::page('/parent/kid/g', '01.parent/01.kid/01.g');
        $grand->languages = ['en' => '/parent/kid/g'];
        $child->withChild($grand);

        $snapshot = $this->snapshotter->snapshot($child);

        self::assertNotNull($snapshot);
        self::assertSame(['en' => '/parent/kid', 'de' => '/eltern/kind'], $snapshot->routes);
        self::assertSame(['en' => ['/parent'], 'de' => ['/eltern']], $snapshot->ancestors);
        self::assertEquals([new PageNode('01.g', ['en' => '/parent/kid/g'])], $snapshot->descendants);
    }

    public function testAncestorFallsBackToItsOwnRouteWhenItLacksTheLanguage(): void
    {
        $this->multilingual();
        $parent = self::page('/parent', '01.parent');
        $parent->languages = ['en' => '/parent'];
        $child = self::page('/parent/kid', '01.parent/01.kid');
        $child->languages = ['en' => '/parent/kid', 'de' => '/parent/kind'];
        $parent->withChild($child);

        self::assertSame(['en' => ['/parent'], 'de' => ['/parent']], $this->snapshotter->snapshot($child)?->ancestors);
    }

    public function testRouteMapSingleLanguageUsesAnyKeyAndRootSlash(): void
    {
        self::assertSame([self::ANY => '/blog'], $this->snapshotter->routeMap(self::page('/blog', '01.blog')));
        self::assertSame([self::ANY => '/'], $this->snapshotter->routeMap(new FakePage('/p', 'Home', null)));
        self::assertSame([self::ANY => '/'], $this->snapshotter->routeMap(new FakePage('/p', 'Home', '')));
    }

    public function testRouteMapMultilingualDropsEmptyAndUnpublishedTranslations(): void
    {
        $this->multilingual();
        $page = self::page('/blog', '01.blog');
        $page->languages = ['en' => '/blog', 'de' => '', 'fr' => false];

        self::assertSame(['en' => '/blog'], $this->snapshotter->routeMap($page));
    }

    /**
     * @param array<string, string|false> $languages
     */
    #[DataProvider('routeMapFallbacks')]
    public function testRouteMapFallsBackToTheActiveLanguageRoute(array $languages, bool $throws, string $active, string $default, string $expectedKey): void
    {
        $this->multilingual($active, $default);
        $page = self::page('/blog', '01.blog');
        $page->languages = $languages;
        $page->languagesThrow = $throws;

        self::assertSame([$expectedKey => '/blog'], $this->snapshotter->routeMap($page));
    }

    /**
     * @return iterable<string, array{array<string, string|false>, bool, string, string, string}>
     */
    public static function routeMapFallbacks(): iterable
    {
        yield 'no translations, active de' => [[], false, 'de', 'en', 'de'];
        yield 'translatedLanguages throws' => [[], true, 'en', 'en', 'en'];
        yield 'all translations unusable' => [['de' => false, 'en' => ''], false, 'de', 'en', 'de'];
        yield 'no active language: default' => [[], false, '', 'en', 'en'];
    }

    public function testRouteMapFallsBackToAnyWhenNoLanguageIsKnown(): void
    {
        $this->language->supported = ['en', 'de'];
        $page = self::page('/blog', '01.blog');

        self::assertSame([self::ANY => '/blog'], $this->snapshotter->routeMap($page));
        self::assertNull($this->snapshotter->activeLanguage());
    }

    public function testActiveLanguageAndMultilingualFlag(): void
    {
        self::assertFalse($this->snapshotter->multilingual());
        self::assertNull($this->snapshotter->activeLanguage());

        $this->multilingual('de', 'en');
        self::assertTrue($this->snapshotter->multilingual());
        self::assertSame('de', $this->snapshotter->activeLanguage());

        $this->language->active = '';
        self::assertSame('en', $this->snapshotter->activeLanguage(), 'the default language when none is active');
    }

    public function testIsLiveNeedsAServingPageInTheActiveLanguage(): void
    {
        $this->multilingual('en');
        $live = self::page('/news', '01.news');
        $this->pages->byRoute['/news'] = $live;

        self::assertTrue($this->snapshotter->isLive('/news', 'en'));
        self::assertFalse($this->snapshotter->isLive('/news', 'de'), 'other languages are unknown and reported as no');
        self::assertFalse($this->snapshotter->isLive('/missing', 'en'));
    }

    public function testIsLiveOnASingleLanguageSiteUsesAny(): void
    {
        $this->pages->byRoute['/news'] = self::page('/news', '01.news');

        self::assertFalse($this->pages->enabled);
        self::assertTrue($this->snapshotter->isLive('/news', self::ANY));
        self::assertTrue($this->pages->enabled, 'the page tree is enabled before it is read');
        self::assertFalse($this->snapshotter->isLive('/news', 'en'), 'without languages the active language is null, not "en"');
    }

    /**
     * @param callable(FakePage): void $configure
     */
    #[DataProvider('notLive')]
    public function testIsLiveRejectsPagesThatDoNotServeTheRoute(callable $configure): void
    {
        $page = self::page('/news', '01.news');
        $configure($page);
        $this->pages->byRoute['/blog'] = $page;

        self::assertFalse($this->snapshotter->isLive('/blog', self::ANY));
    }

    /**
     * @return iterable<string, array{callable(FakePage): void}>
     */
    public static function notLive(): iterable
    {
        yield 'root page' => [static function (FakePage $p): void {
            $p->root = true;
        }];
        yield 'not routable' => [static function (FakePage $p): void {
            $p->routable = false;
        }];
        yield 'unpublished' => [static function (FakePage $p): void {
            $p->published = false;
        }];
        yield 'found by structural route only (slug override)' => [static function (FakePage $p): void {
            $p->route = '/news';
        }];
    }

    public function testRouteMatchingIgnoresCaseAndTrailingSlash(): void
    {
        $this->pages->byRoute['/News/'] = self::page('/news', '01.news');

        self::assertTrue($this->snapshotter->isLive('/News/', self::ANY));
        self::assertTrue($this->snapshotter->routeExists('/News/', self::ANY));
    }

    public function testRouteExistsDoesNotRequirePublishedOrRoutable(): void
    {
        $this->pages->byRoute['/folder'] = self::page('/folder', '01.folder', routable: false, published: false);
        $this->pages->byRoute['/root'] = FakePage::rootPage();
        $this->pages->byRoute['/moved'] = self::page('/elsewhere', '01.elsewhere');

        self::assertTrue($this->snapshotter->routeExists('/folder', self::ANY));
        self::assertFalse($this->snapshotter->routeExists('/root', self::ANY));
        self::assertFalse($this->snapshotter->routeExists('/moved', self::ANY), 'the page has a different public route');
        self::assertFalse($this->snapshotter->routeExists('/none', self::ANY));
    }

    public function testRouteExistsAssumesOtherLanguagesExist(): void
    {
        $this->multilingual('en');

        self::assertTrue($this->snapshotter->routeExists('/none', 'de'));
        self::assertFalse($this->snapshotter->routeExists('/none', 'en'));
    }

    public function testReloadResetsAndEnablesThePageTree(): void
    {
        $this->snapshotter->reload();

        self::assertSame(1, $this->pages->resets);
        self::assertTrue($this->pages->enabled);
    }

    public function testByPath(): void
    {
        $page = self::page('/blog', '01.blog');
        $this->pages->byPath['/site/pages/01.blog'] = $page;

        self::assertSame($page, $this->snapshotter->byPath('/site/pages/01.blog'));
        self::assertNull($this->snapshotter->byPath('/site/pages/nope'));
    }

    public function testByRouteFindsPublicRouteAndFallsBackToRawRoute(): void
    {
        $public = self::page('/news', '01.blog');
        $this->pages->byRoute['/news'] = $public;
        $structural = self::page('/custom', '02.folder');
        $structural->rawRoute = '/folder';
        $this->pages->byPath['/site/pages/02.folder'] = $structural;
        $this->pages->byPath['/site/pages/junk'] = new \stdClass(); // instances() may hold anything

        self::assertSame($public, $this->snapshotter->byRoute('news/'));
        self::assertSame($structural, $this->snapshotter->byRoute('/folder'), 'the raw route is what Admin 2 sends');
        self::assertNull($this->snapshotter->byRoute('/absent'));
    }
}
