<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageIndex::class)]
#[CoversClass(PageInfo::class)]
final class PageIndexTest extends TestCase
{
    public function testOnlyRoutablePublishedPagesAreTargets(): void
    {
        $index = PageTreeFixture::index();

        self::assertNull($index->byRoute('/blog/draft-post'));
        self::assertNull($index->byRoute('/blog/hidden'));
        self::assertNotNull($index->byRoute('/blog/my-post'));
        self::assertSame(count($index->all()), $index->count());
        self::assertSame(42, $index->count());
        self::assertNotContains('/blog/draft-post', $index->routes());
    }

    public function testLanguagesAreSorted(): void
    {
        self::assertSame(['de', 'en'], PageTreeFixture::index()->languages());
    }

    public function testByRouteExactAndLoose(): void
    {
        $index = PageTreeFixture::index();

        self::assertSame('Über uns', $index->byRoute('/ueber-uns/')?->title);
        self::assertSame('Über uns', $index->byRoute('ueber-uns', 'de')?->title);
        self::assertNull($index->byRoute('/Ueber-uns'));
        self::assertSame('Über uns', $index->byRoute('/Über-Uns', null, true)?->title);
        self::assertNull($index->byRoute('/ueber-uns', 'en'));
        self::assertNull($index->byRoute('/nothing-here'));
        self::assertSame('Startseite', $index->byRoute('/', 'de')?->title);
        self::assertSame('Home', $index->byRoute('')?->title);
    }

    public function testByRouteAllLanguages(): void
    {
        $pages = PageTreeFixture::index()->byRouteAllLanguages('/blog');

        self::assertSame(['en', 'de'], array_map(static fn (PageInfo $p): ?string => $p->language, $pages));
        self::assertSame([], PageTreeFixture::index()->byRouteAllLanguages('/nope'));
        self::assertCount(2, PageTreeFixture::index()->byRouteAllLanguages('/BLOG', true));
    }

    public function testRoutesCanBeLimitedToOneLanguage(): void
    {
        $index = PageTreeFixture::index();

        self::assertContains('/ueber-uns', $index->routes());
        self::assertContains('/ueber-uns', $index->routes('de'));
        self::assertNotContains('/about', $index->routes('de'));
        self::assertSame(array_unique($index->routes()), $index->routes());
    }

    public function testBySlugIsNormalizedAndLanguageAware(): void
    {
        $index = PageTreeFixture::index();

        self::assertSame(['/products/faq', '/docs/faq'], array_map(static fn (PageInfo $p): string => $p->route, $index->bySlug('FAQ')));
        self::assertSame(['/ueber-uns'], array_map(static fn (PageInfo $p): string => $p->route, $index->bySlug('Über-uns')));
        self::assertSame([], $index->bySlug('ueber-uns', 'en'));
        self::assertSame([], $index->bySlug('---'));
        self::assertSame([], $index->bySlug('unknown'));
    }

    public function testTranslationsFollowLinksInBothDirections(): void
    {
        $index = PageTreeFixture::index();

        $about = $index->byRoute('/about', 'en');
        $ueberUns = $index->byRoute('/ueber-uns', 'de');
        self::assertNotNull($about);
        self::assertNotNull($ueberUns);

        self::assertSame('/ueber-uns', $index->translation($about, 'de')?->route);
        // Only the English page declares the link; the reverse direction is derived.
        self::assertSame('/about', $index->translation($ueberUns, 'en')?->route);
        self::assertNull($index->translation($about, 'fr'));

        $gallery = $index->byRoute('/gallery');
        self::assertNotNull($gallery);
        self::assertNull($index->translation($gallery, 'de'));
    }

    public function testTranslationEntryForTheOwnLanguageIsIgnored(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/a', language: 'en', translations: ['en' => '/a', 'de' => '/b', '' => '/c']),
            new PageInfo(route: '/b', language: 'de'),
        ]);
        $page = $index->byRoute('/a');
        self::assertNotNull($page);

        self::assertSame('/b', $index->translation($page, 'de')?->route);
        self::assertNull($index->translation($page, 'en'));
    }

    public function testTranslationToMissingTargetIsIgnored(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/a', language: 'en', translations: ['de' => '/gone']),
        ]);
        $page = $index->byRoute('/a');
        self::assertNotNull($page);

        self::assertNull($index->translation($page, 'de'));
    }

    public function testLanguagelessPagesMatchEveryLanguage(): void
    {
        $index = new PageIndex([new PageInfo(route: '/x', title: 'X')]);

        self::assertNotNull($index->byRoute('/x', 'de'));
        self::assertCount(1, $index->bySlug('x', 'fr'));
        self::assertSame([], $index->languages());
        self::assertSame(['/x'], $index->routes('de'));
    }

    public function testDuplicateRouteAndLanguageKeepsTheFirstPage(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/a', title: 'first'),
            new PageInfo(route: '/a/', title: 'second'),
        ]);

        self::assertSame(1, $index->count());
        self::assertSame('first', $index->byRoute('/a')?->title);
    }

    public function testSlugFieldDiffersFromRoute(): void
    {
        $index = new PageIndex([new PageInfo(route: '/folder/x', slug: 'custom-slug')]);

        self::assertCount(1, $index->bySlug('custom-slug'));
        self::assertCount(1, $index->bySlug('x'));
    }

    public function testRoundTripThroughArrayPreservesBehavior(): void
    {
        $original = PageTreeFixture::index();
        $json = json_encode($original->toArray(), JSON_THROW_ON_ERROR);
        $restored = PageIndex::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        self::assertSame($original->count(), $restored->count());
        self::assertSame($original->languages(), $restored->languages());
        self::assertSame($original->routes(), $restored->routes());
        self::assertEquals($original->byRoute('/blog/my-post'), $restored->byRoute('/blog/my-post'));
        self::assertSame(
            $original->candidatesByTrigrams(
                $original->text()->trigramSet('/blgo/my-psot'),
                $original->text()->trigramSet('my-psot'),
                null,
                10,
            ),
            $restored->candidatesByTrigrams(
                $restored->text()->trigramSet('/blgo/my-psot'),
                $restored->text()->trigramSet('my-psot'),
                null,
                10,
            ),
        );
        $about = $restored->byRoute('/about');
        self::assertNotNull($about);
        self::assertSame('/ueber-uns', $restored->translation($about, 'de')?->route);
    }

    public function testFromArrayRejectsUnknownVersionAndMalformedEntries(): void
    {
        try {
            PageIndex::fromArray(['version' => 99, 'entries' => []]);
            self::fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unsupported', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        PageIndex::fromArray(['version' => 1, 'entries' => [['page' => []]]]);
    }

    public function testPageInfoRoundTripAndTolerance(): void
    {
        $page = new PageInfo(
            route: '/a',
            rawRoute: '/01.a',
            slug: 'a',
            title: 'A',
            language: 'de',
            parentRoute: '/',
            routable: true,
            published: false,
            taxonomy: ['tag' => ['x', 'y']],
            translations: ['en' => '/b'],
            modified: 5,
        );

        self::assertEquals($page, PageInfo::fromArray($page->toArray()));
        self::assertFalse($page->isTarget());

        $tolerant = PageInfo::fromArray([
            'route' => '/z',
            'taxonomy' => ['tag' => ['ok', 5, [], null], 'bad' => 'x'],
            'translations' => ['en' => '/e', 'de' => 5],
            'routable' => false,
            'modified' => 'yesterday',
        ]);
        self::assertSame(['tag' => ['ok', '5']], $tolerant->taxonomy);
        self::assertSame(['en' => '/e'], $tolerant->translations);
        self::assertFalse($tolerant->routable);
        self::assertNull($tolerant->modified);
        self::assertSame('/', PageInfo::fromArray([])->route);
    }
}
