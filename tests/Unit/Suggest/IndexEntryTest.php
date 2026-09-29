<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\IndexEntry;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Suggest\ParsedPath;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * IndexEntry and ParsedPath are internal carriers; these tests check what PageIndex and Suggester put into them.
 */
#[CoversClass(IndexEntry::class)]
#[CoversClass(ParsedPath::class)]
#[CoversClass(PageIndex::class)]
#[CoversClass(Suggester::class)]
#[Group('suggest')]
final class IndexEntryTest extends TestCase
{
    public function testIndexEntryHoldsTheNormalizedFormsOfAPage(): void
    {
        $page = new PageInfo(
            '/Blog/Über-Uns/',
            slug: 'Über Uns',
            title: 'Über uns und das Team',
            language: 'de',
            taxonomy: ['tag' => ['Team', 'Firma Geschichte'], 'category' => ['']],
        );

        $entry = (new PageIndex([$page]))->entry(0);

        self::assertInstanceOf(IndexEntry::class, $entry);
        self::assertSame($page, $entry->page);
        self::assertSame('/blog/ueber-uns', $entry->normRoute);
        self::assertSame('ueber-uns', $entry->normSlug);
        self::assertSame('ueber-uns', $entry->lastSegment);
        self::assertContains('team', $entry->titleTokens);
        self::assertSame(count($entry->titleTokens), count(array_unique($entry->titleTokens)), 'title tokens are unique');
        self::assertSame(['Team', 'Firma Geschichte'], $entry->taxonomyLabels, 'values without tokens are dropped');
        self::assertCount(2, $entry->taxonomyValues);
        self::assertSame(['team'], $entry->taxonomyValues[0]);
        self::assertSame(['firma', 'geschichte'], $entry->taxonomyValues[1]);
    }

    public function testIndexEntryFallsBackToTheRouteForTheSlugAndHandlesTheRoot(): void
    {
        $index = new PageIndex([new PageInfo('/docs/install'), new PageInfo('/')]);

        $docs = $index->entry(0);
        self::assertSame('install', $docs->normSlug, 'no slug given: the last route segment');
        self::assertSame('install', $docs->lastSegment);
        self::assertSame([], $docs->titleTokens);
        self::assertSame([], $docs->taxonomyValues);

        $root = $index->entry(1);
        self::assertSame('/', $root->normRoute);
        self::assertSame('', $root->lastSegment);
    }

    public function testIndexEntriesSurviveASerializationRoundTrip(): void
    {
        $index = new PageIndex([new PageInfo('/a/b', title: 'Hello World', language: 'en', taxonomy: ['tag' => ['Php Code']])]);

        $restored = PageIndex::fromArray($index->toArray())->entry(0);
        $original = $index->entry(0);

        self::assertSame($original->normRoute, $restored->normRoute);
        self::assertSame($original->normSlug, $restored->normSlug);
        self::assertSame($original->lastSegment, $restored->lastSegment);
        self::assertSame($original->titleTokens, $restored->titleTokens);
        self::assertSame($original->taxonomyValues, $restored->taxonomyValues);
        self::assertSame($original->taxonomyLabels, $restored->taxonomyLabels);
        self::assertSame($original->page->toArray(), $restored->page->toArray());
    }

    private function parse(string $path, ?string $language = null): ParsedPath
    {
        $index = new PageIndex([
            new PageInfo('/blog/post', language: 'en'),
            new PageInfo('/blog/post', language: 'de'),
            new PageInfo('/de/pfad', language: 'en'),
        ]);
        $method = new ReflectionMethod(Suggester::class, 'parse');

        $parsed = $method->invoke(new Suggester($index), $path, $language);
        self::assertInstanceOf(ParsedPath::class, $parsed);

        return $parsed;
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>, string, string, ?string}>
     */
    public static function parseProvider(): iterable
    {
        yield 'plain' => ['/blog/Über-Uns', null, ['blog', 'ueber-uns'], '/blog/ueber-uns', 'ueber-uns', null];
        yield 'query and fragment dropped' => ['/blog/post?utm=1#top', null, ['blog', 'post'], '/blog/post', 'post', null];
        yield 'percent decoded' => ['/blog/caf%C3%A9', null, ['blog', 'cafe'], '/blog/cafe', 'cafe', null];
        yield 'extension removed' => ['/blog/old-page.html', null, ['blog', 'old-page'], '/blog/old-page', 'old-page', null];
        yield 'index removed' => ['/blog/index.php', null, ['blog'], '/blog', 'blog', null];
        yield 'root' => ['/', null, [], '/', '', null];
        yield 'empty' => ['', null, [], '/', '', null];
        yield 'duplicate slashes' => ['//blog///post//', null, ['blog', 'post'], '/blog/post', 'post', null];
        yield 'language prefix' => ['/de/blog/post', null, ['blog', 'post'], '/blog/post', 'post', 'de'];
        yield 'language prefix is case insensitive' => ['/DE/blog/post', null, ['blog', 'post'], '/blog/post', 'post', 'de'];
        yield 'language from the caller' => ['/blog/post', 'EN', ['blog', 'post'], '/blog/post', 'post', 'en'];
        yield 'prefix wins over the caller' => ['/de/blog/post', 'en', ['blog', 'post'], '/blog/post', 'post', 'de'];
        yield 'a route that starts with a language code stays' => ['/de/pfad', null, ['de', 'pfad'], '/de/pfad', 'pfad', null];
        yield 'unknown language of the caller' => ['/blog/post', 'xx', ['blog', 'post'], '/blog/post', 'post', null];
        yield 'only a language prefix' => ['/de', null, [], '/', '', 'de'];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('parseProvider')]
    public function testParsedPath(string $path, ?string $language, array $segments, string $route, string $slug, ?string $resolved): void
    {
        $parsed = $this->parse($path, $language);

        self::assertSame($segments, $parsed->segments);
        self::assertSame($route, $parsed->route);
        self::assertSame($slug, $parsed->slug);
        self::assertSame($resolved, $parsed->language);
    }

    public function testParsedPathContentTokensComeFromTheLastSegmentWithoutStopWords(): void
    {
        $parsed = $this->parse('/blog/the-history-of-php-2024');

        self::assertContains('history', $parsed->contentTokens);
        self::assertContains('2024', $parsed->contentTokens);
        self::assertNotContains('the', $parsed->contentTokens);
        self::assertNotContains('of', $parsed->contentTokens);
        self::assertSame([], $this->parse('/')->contentTokens);
    }
}
