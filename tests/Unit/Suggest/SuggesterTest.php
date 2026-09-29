<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Suggester::class)]
#[CoversClass(SuggestionReason::class)]
final class SuggesterTest extends TestCase
{
    private Suggester $suggester;

    protected function setUp(): void
    {
        $this->suggester = new Suggester(PageTreeFixture::index());
    }

    /** @return iterable<string, array{string, ?string, string, SuggestionReason, float, float}> */
    public static function knownResults(): iterable
    {
        yield 'moved folder, same slug' => ['/old-folder/my-post', null, '/blog/my-post', SuggestionReason::SameSlug, 0.95, 0.95];
        yield 'moved to root, same slug' => ['/my-post', null, '/blog/my-post', SuggestionReason::SameSlug, 0.95, 0.97];
        yield 'index.html and query' => ['/legacy/hello-world/index.html?utm=1', null, '/blog/hello-world', SuggestionReason::SameSlug, 0.95, 0.95];
        yield 'percent encoded umlaut' => ['/%C3%9Cber-uns.html', null, '/ueber-uns', SuggestionReason::SimilarRoute, 0.9, 1.0];
        yield 'umlaut, case, extension' => ['/Über-uns.html', null, '/ueber-uns', SuggestionReason::SimilarRoute, 0.97, 0.97];
        yield 'camel case and php extension' => ['/Blog/MyPost.php', null, '/blog/my-post', SuggestionReason::SimilarRoute, 0.97, 0.97];
        yield 'umlaut in slug' => ['/people/jan-müller', null, '/team/jan-mueller', SuggestionReason::SameSlug, 0.95, 0.95];
        yield 'language prefix, translated slug' => ['/de/about', null, '/ueber-uns', SuggestionReason::OtherLanguage, 0.9, 0.9];
        yield 'language from argument' => ['/about', 'de', '/ueber-uns', SuggestionReason::OtherLanguage, 0.9, 0.9];
        yield 'reverse translation' => ['/en/ueber-uns', null, '/about', SuggestionReason::OtherLanguage, 0.9, 0.9];
        yield 'uppercase language prefix' => ['/DE/contact', null, '/kontakt', SuggestionReason::OtherLanguage, 0.9, 0.9];
        yield 'untranslated page' => ['/de/gallery', null, '/gallery', SuggestionReason::OtherLanguage, 0.75, 0.75];
        yield 'typo, transposed letters' => ['/blgo/my-psot', null, '/blog/my-post', SuggestionReason::SimilarRoute, 0.3, 0.85];
        yield 'typo in single segment' => ['/contcat', null, '/contact', SuggestionReason::SimilarRoute, 0.3, 0.85];
        yield 'typo in folder, slug intact' => ['/produts/widget-pro', null, '/products/widget-pro', SuggestionReason::SameSlug, 0.95, 0.95];
        yield 'title match, reordered words' => ['/greetings/world-hello', null, '/blog/hello-world', SuggestionReason::TitleMatch, 0.75, 0.8];
        yield 'title match, different slug' => ['/leistungen/consulting-seo', null, '/services/seo-consulting', SuggestionReason::TitleMatch, 0.75, 0.8];
        yield 'title match, extra token' => ['/blog/my-post-2', null, '/blog/my-post', SuggestionReason::TitleMatch, 0.75, 0.8];
        yield 'taxonomy match' => ['/topics/kubernetes', null, '/docs/cluster-setup', SuggestionReason::TaxonomyMatch, 0.6, 0.6];
        yield 'direct parent fallback' => ['/blog/deleted-post', null, '/blog', SuggestionReason::ParentFallback, 0.5, 0.5];
        yield 'distant ancestor fallback' => ['/docs/old/deep/page', null, '/docs', SuggestionReason::ParentFallback, 0.4, 0.4];
        yield 'home fallback' => ['/completely-unknown-xyz', null, '/', SuggestionReason::HomeFallback, 0.1, 0.1];
        yield 'language argument unknown to the index is ignored' => ['/old-folder/my-post', 'fr', '/blog/my-post', SuggestionReason::SameSlug, 0.95, 0.95];
        yield 'language prefix and extension' => ['/de/ueber-uns.html', null, '/ueber-uns', SuggestionReason::SimilarRoute, 0.97, 0.97];
    }

    #[DataProvider('knownResults')]
    public function testKnownResults(string $path, ?string $language, string $target, SuggestionReason $reason, float $min, float $max): void
    {
        $suggestions = $this->suggester->suggest($path, $language);

        self::assertNotSame([], $suggestions, $path);
        self::assertSame($target, $suggestions[0]->target, $path);
        self::assertSame($reason, $suggestions[0]->reason, $path);
        self::assertGreaterThanOrEqual($min, $suggestions[0]->score, $path);
        self::assertLessThanOrEqual($max, $suggestions[0]->score, $path);
    }

    public function testSameSlugWithSeveralCandidatesScoresEachLower(): void
    {
        $suggestions = $this->suggester->suggest('/old/faq');

        self::assertSame(['/docs/faq', '/products/faq'], array_map(static fn (Suggestion $s): string => $s->target, array_slice($suggestions, 0, 2)));
        self::assertSame(0.85, $suggestions[0]->score);
        self::assertSame(0.85, $suggestions[1]->score);
        self::assertSame(2, $suggestions[0]->details['candidates']);
        self::assertSame(SuggestionReason::SameSlug, $suggestions[1]->reason);
    }

    public function testExtensionOnlyPathStillFindsTheSlug(): void
    {
        $suggestions = $this->suggester->suggest('/faq.html');

        self::assertSame(SuggestionReason::SameSlug, $suggestions[0]->reason);
        self::assertSame(0.85, $suggestions[0]->score);
    }

    public function testOtherLanguageDetails(): void
    {
        $suggestion = $this->suggester->suggest('/de/about')[0];

        self::assertSame('de', $suggestion->details['language']);
        self::assertSame('translation', $suggestion->details['via']);
        self::assertSame('/about', $suggestion->details['source_route']);
        self::assertSame('Über uns', $suggestion->pageTitle);
    }

    public function testRequestLanguageRestrictsCandidates(): void
    {
        foreach ($this->suggester->suggest('/de/blgo/my-psot', null, 10) as $suggestion) {
            self::assertNotSame('/about', $suggestion->target);
        }
        // "/de/blog/mein-beitrag-alt" must find the German page, not the English one.
        $top = $this->suggester->suggest('/de/blog/mein-beitrag-alt')[0];
        self::assertSame('/blog/mein-beitrag', $top->target);

        $englishOnly = $this->suggester->suggest('/en/blog/mein-beitrag-alt', null, 10);
        self::assertNotContains('/blog/mein-beitrag', array_map(static fn (Suggestion $s): string => $s->target, $englishOnly));
    }

    public function testSuggestionsAreDeduplicatedByTargetKeepingTheBestReason(): void
    {
        $suggestions = $this->suggester->suggest('/Über-uns.html', null, 10);
        $targets = array_map(static fn (Suggestion $s): string => $s->target, $suggestions);

        self::assertSame($targets, array_values(array_unique($targets)));
        self::assertSame('/ueber-uns', $suggestions[0]->target);
        self::assertSame(SuggestionReason::SimilarRoute, $suggestions[0]->reason);
        self::assertContains('same_slug', $suggestions[0]->details['also']);
    }

    public function testResultsAreSortedAndStable(): void
    {
        foreach (['/blog/deleted-post', '/de/about', '/tag/grav', '/blgo/my-psot', '/x/faq', '/docs/getting-started-guide'] as $path) {
            $first = $this->suggester->suggest($path, null, 10);
            $scores = array_map(static fn (Suggestion $s): float => $s->score, $first);

            $sorted = $scores;
            rsort($sorted);
            self::assertSame($sorted, $scores, $path);
            self::assertEquals($first, $this->suggester->suggest($path, null, 10), $path);
            self::assertEquals($first, (new Suggester(PageTreeFixture::index()))->suggest($path, null, 10), $path);
        }
    }

    public function testScoresStayWithinBoundsForManyPaths(): void
    {
        $paths = [
            ...PageTreeFixture::index()->routes(),
            '/', '', '///', '/index.html', '/a', '/ab/cd/ef/gh/ij/kl', '/%FF%FE', '/..', '/über', '/日本語/ページ',
            '/BLOG/MY-POST/', '/blog/my-post.html', '/blog/my-post/index.php', '/de', '/en/', '/de/',
            str_repeat('/long-segment', 40),
        ];

        foreach ($paths as $path) {
            foreach ($this->suggester->suggest($path, null, 20) as $suggestion) {
                self::assertGreaterThanOrEqual(0.0, $suggestion->score, $path);
                self::assertLessThanOrEqual(1.0, $suggestion->score, $path);
                self::assertSame(round($suggestion->score, 3), $suggestion->score);
                if (!$suggestion->reason->isFallback()) {
                    self::assertGreaterThanOrEqual(0.2, $suggestion->score, $path);
                }
                self::assertNotSame('/blog/draft-post', $suggestion->target);
                self::assertNotSame('/blog/hidden', $suggestion->target);
            }
        }
    }

    public function testHomeFallbackOnlyAppearsAlone(): void
    {
        foreach ($this->suggester->suggest('/blog/deleted-post', null, 20) as $suggestion) {
            self::assertNotSame(SuggestionReason::HomeFallback, $suggestion->reason);
        }

        $alone = $this->suggester->suggest('/zzzz/qqqq');
        self::assertCount(1, $alone);
        self::assertSame(SuggestionReason::HomeFallback, $alone[0]->reason);
        self::assertSame('Home', $alone[0]->pageTitle);
    }

    public function testEmptyAndRootPathsYieldNothing(): void
    {
        self::assertSame([], $this->suggester->suggest('/'));
        self::assertSame([], $this->suggester->suggest(''));
        self::assertSame([], $this->suggester->suggest('/index.html'));
        self::assertSame([], $this->suggester->suggest('/de/'));
        self::assertSame([], $this->suggester->suggest('/...'));
    }

    public function testLimit(): void
    {
        self::assertSame([], $this->suggester->suggest('/old/faq', null, 0));
        self::assertSame([], $this->suggester->suggest('/old/faq', null, -3));
        self::assertCount(1, $this->suggester->suggest('/old/faq', null, 1));
        self::assertLessThanOrEqual(3, count($this->suggester->suggest('/tag/grav', null, 3)));
    }

    public function testSuggestMany(): void
    {
        $paths = ['/old-folder/my-post', '/de/about', '/blog/deleted-post', '/de/about'];
        $many = $this->suggester->suggestMany($paths, null, 3);

        self::assertSame(['/old-folder/my-post', '/de/about', '/blog/deleted-post'], array_keys($many));
        foreach ($many as $path => $suggestions) {
            self::assertEquals($this->suggester->suggest($path, null, 3), $suggestions);
        }
        self::assertSame([], $this->suggester->suggestMany([]));
    }

    public function testTaxonomyTiesAreOrderedByScoreThenTarget(): void
    {
        $suggestions = $this->suggester->suggest('/tag/grav');

        self::assertSame(['/blog/grav-tips-and-tricks', '/blog/my-post'], array_map(static fn (Suggestion $s): string => $s->target, $suggestions));
        self::assertSame(0.6, $suggestions[0]->score);
        self::assertSame(SuggestionReason::TaxonomyMatch, $suggestions[0]->reason);
        self::assertContains('title_match', $suggestions[0]->details['also']);
    }

    public function testMoreMatchingTaxonomyValuesScoreHigher(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/aaa', title: 'A', taxonomy: ['tag' => ['red car', 'car xy']]),
            new PageInfo(route: '/bbb', title: 'B', taxonomy: ['tag' => ['red car']]),
            new PageInfo(route: '/ccc', title: 'C', taxonomy: ['tag' => ['red car', 'green']], modified: 10),
        ]);
        $suggestions = (new Suggester($index))->suggest('/x/red-car-xy');

        self::assertSame(['/aaa', '/bbb', '/ccc'], array_map(static fn (Suggestion $s): string => $s->target, $suggestions));
        self::assertSame(0.51, $suggestions[0]->score);
        self::assertSame(0.48, $suggestions[1]->score);
        self::assertSame(0.48, $suggestions[2]->score);
    }

    public function testTaxonomyTieBetweenCandidatesKeepsTheNewestPages(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/old', taxonomy: ['tag' => ['topic']], modified: 1),
            new PageInfo(route: '/aaa-untouched', taxonomy: ['tag' => ['topic']]),
            new PageInfo(route: '/new', taxonomy: ['tag' => ['topic']], modified: 99),
        ]);

        $targets = array_map(static fn (Suggestion $s): string => $s->target, (new Suggester($index))->suggest('/x/topic', null, 2));

        self::assertSame(['/new', '/old'], $targets);
    }

    public function testTitleNeedsSixtyPercentCoverage(): void
    {
        $suggestions = $this->suggester->suggest('/x/second-thing', null, 20);

        foreach ($suggestions as $suggestion) {
            self::assertNotSame(SuggestionReason::TitleMatch, $suggestion->reason);
        }
    }

    public function testStopWordsAloneCarryNoTitleEvidence(): void
    {
        $suggestions = $this->suggester->suggest('/x/the-and');

        self::assertSame(SuggestionReason::HomeFallback, $suggestions[0]->reason);
    }

    public function testSiblingsThatOnlyShareTheParentAreNotSimilar(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/blog', title: 'Blog'),
            new PageInfo(route: '/blog/alpha', title: 'Alpha'),
        ]);
        $suggestions = (new Suggester($index))->suggest('/blog/zzzzzzzz');

        self::assertSame([SuggestionReason::ParentFallback], array_map(static fn (Suggestion $s): SuggestionReason => $s->reason, $suggestions));
    }

    public function testParentFallbackSkipsMissingAndUnpublishedAncestors(): void
    {
        $suggestions = $this->suggester->suggest('/blog/hidden/child/page');

        self::assertSame('/blog', $suggestions[0]->target);
        self::assertSame(0.4, $suggestions[0]->score);
        self::assertSame(3, $suggestions[0]->details['distance']);
    }

    public function testLanguagePrefixIsNotStrippedWhenItIsARealRoute(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/a', language: 'en'),
            new PageInfo(route: '/a', language: 'de'),
            new PageInfo(route: '/de/special', language: 'en', title: 'Special'),
        ]);
        $suggestions = (new Suggester($index))->suggest('/de/special.html');

        self::assertSame('/de/special', $suggestions[0]->target);
        self::assertSame(0.97, $suggestions[0]->score);
    }

    public function testSingleLanguageSiteTreatsLanguageCodesAsNormalSegments(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/about', title: 'About'),
            new PageInfo(route: '/en', title: 'English landing'),
        ]);
        $suggester = new Suggester($index);

        $suggestions = $suggester->suggest('/en/missing-page');
        self::assertSame(SuggestionReason::ParentFallback, $suggestions[0]->reason);
        self::assertSame('/en', $suggestions[0]->target);
        self::assertSame('/about', $suggester->suggest('/old/about.html')[0]->target);
    }

    public function testEmptyIndex(): void
    {
        $suggestions = (new Suggester(new PageIndex()))->suggest('/anything/here');

        self::assertCount(1, $suggestions);
        self::assertSame(SuggestionReason::HomeFallback, $suggestions[0]->reason);
        self::assertSame('', $suggestions[0]->pageTitle);
    }

    public function testWorksOnACachedIndex(): void
    {
        $restored = PageIndex::fromArray(PageTreeFixture::index()->toArray());
        $suggester = new Suggester($restored);

        foreach (['/old-folder/my-post', '/de/about', '/blgo/my-psot', '/tag/grav', '/blog/deleted-post'] as $path) {
            self::assertEquals($this->suggester->suggest($path), $suggester->suggest($path), $path);
        }
    }
}
