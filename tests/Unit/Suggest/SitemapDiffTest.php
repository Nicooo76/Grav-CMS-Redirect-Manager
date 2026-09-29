<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Suggest\SitemapDiff;
use Grav\Plugin\RedirectManager\Suggest\SitemapDiffResult;
use Grav\Plugin\RedirectManager\Suggest\SitemapDocument;
use Grav\Plugin\RedirectManager\Suggest\SitemapException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SitemapDiff::class)]
#[CoversClass(SitemapDiffResult::class)]
#[CoversClass(SitemapDocument::class)]
#[CoversClass(SitemapException::class)]
final class SitemapDiffTest extends TestCase
{
    private const URLSET = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
                xmlns:xhtml="http://www.w3.org/1999/xhtml">
          <url>
            <loc>https://example.com/</loc>
            <lastmod>2024-01-01</lastmod>
          </url>
          <url>
            <loc>
              https://example.com/blog/my-post?utm_source=x#top
            </loc>
            <xhtml:link rel="alternate" hreflang="de" href="https://example.com/de/blog/mein-beitrag"/>
            <image:image><image:loc>https://cdn.example.com/img/a.jpg</image:loc></image:image>
          </url>
          <url><loc>https://example.com/%C3%9Cber-uns</loc></url>
          <url><loc><![CDATA[https://example.com/a&b/]]></loc></url>
          <url><loc>https://example.com/blog/my-post</loc></url>
          <url><loc></loc></url>
          <url><loc/></url>
          <url><loc>mailto:someone@example.com</loc></url>
        </urlset>
        XML;

    private const INDEX = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <sitemap><loc>https://example.com/sitemap-pages.xml</loc><lastmod>2024-01-01</lastmod></sitemap>
          <sitemap><loc>https://example.com/sitemap-posts.xml.gz</loc></sitemap>
          <sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>
        </sitemapindex>
        XML;

    private SitemapDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new SitemapDiff();
    }

    public function testParseUrlSet(): void
    {
        self::assertSame(
            ['/', '/blog/my-post', '/Über-uns', '/a&b/'],
            $this->diff->parse(self::URLSET),
        );
    }

    public function testImageAndXhtmlLocationsAreIgnored(): void
    {
        $paths = $this->diff->parse(self::URLSET);

        self::assertNotContains('/img/a.jpg', $paths);
        self::assertNotContains('/de/blog/mein-beitrag', $paths);
    }

    public function testParseIndex(): void
    {
        self::assertSame(
            ['https://example.com/sitemap-pages.xml', 'https://example.com/sitemap-posts.xml.gz'],
            $this->diff->parseIndex(self::INDEX),
        );
        self::assertSame([], $this->diff->parse(self::INDEX));
        self::assertSame([], $this->diff->parseIndex(self::URLSET));
    }

    public function testReadTellsIndexFromUrlSet(): void
    {
        self::assertTrue($this->diff->read(self::INDEX)->isIndex);
        self::assertFalse($this->diff->read(self::URLSET)->isIndex);
        self::assertSame('https://example.com/', $this->diff->read(self::URLSET)->locations[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function namespaceVariants(): iterable
    {
        yield 'default namespace' => ['<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://e.com/x</loc></url></urlset>'];
        yield 'legacy namespace' => ['<urlset xmlns="http://www.google.com/schemas/sitemap/0.84"><url><loc>https://e.com/x</loc></url></urlset>'];
        yield 'no namespace' => ['<urlset><url><loc>https://e.com/x</loc></url></urlset>'];
        yield 'prefixed' => ['<sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"><sm:url><sm:loc>https://e.com/x</sm:loc></sm:url></sm:urlset>'];
        yield 'namespace declared on children' => ['<urlset><url xmlns="urn:x"><loc>https://e.com/x</loc></url></urlset>'];
        yield 'utf-8 bom and leading whitespace' => ["\xEF\xBB\xBF\n  <?xml version=\"1.0\"?><urlset><url><loc>https://e.com/x</loc></url></urlset>"];
    }

    #[DataProvider('namespaceVariants')]
    public function testNamespaceVariants(string $xml): void
    {
        self::assertSame(['/x'], $this->diff->parse($xml));
    }

    public function testMixedNamespacesBetweenUrlAndLocAreIgnored(): void
    {
        $xml = '<urlset xmlns="urn:a" xmlns:o="urn:other"><url><o:loc>https://e.com/no</o:loc><loc>https://e.com/yes</loc></url></urlset>';

        self::assertSame(['/yes'], $this->diff->parse($xml));
    }

    public function testEmptyUrlSetIsValid(): void
    {
        self::assertSame([], $this->diff->parse('<urlset/>'));
        self::assertSame([], $this->diff->parse('<urlset></urlset>'));
    }

    public function testGzipInput(): void
    {
        self::assertSame(
            ['/', '/blog/my-post', '/Über-uns', '/a&b/'],
            $this->diff->parse((string) gzencode(self::URLSET)),
        );
        self::assertCount(2, $this->diff->parseIndex((string) gzencode(self::INDEX)));
    }

    public function testGzipBombIsRejected(): void
    {
        $bomb = (string) gzencode('<urlset>' . str_repeat(' ', 6_000_000) . '</urlset>', 9);
        self::assertLessThan(20_000, strlen($bomb));

        $this->expectException(SitemapException::class);
        $this->expectExceptionMessage('decompressed');
        $this->diff->parse($bomb, 1_000_000);
    }

    public function testGzipBombWithinOneChunkOfInflateIsRejected(): void
    {
        $bomb = (string) gzencode(str_repeat('a', 3_000_000), 9);

        $this->expectException(SitemapException::class);
        $this->diff->parse($bomb, 100_000);
    }

    public function testGzipJustUnderTheLimitPasses(): void
    {
        $xml = '<urlset><url><loc>https://e.com/x</loc></url></urlset>';

        $gz = (string) gzencode($xml);

        self::assertSame(['/x'], $this->diff->parse($gz, max(strlen($gz), strlen($xml))));
    }

    public function testCorruptGzipIsRejected(): void
    {
        $this->expectException(SitemapException::class);
        $this->diff->parse("\x1f\x8b\x08\x00garbage-that-is-not-deflate");
    }

    public function testTruncatedGzipIsRejected(): void
    {
        $gz = (string) gzencode(self::URLSET);

        $this->expectException(SitemapException::class);
        $this->diff->parse(substr($gz, 0, (int) (strlen($gz) / 2)));
    }

    public function testOversizedInputIsRejectedBeforeParsing(): void
    {
        $this->expectException(SitemapException::class);
        $this->expectExceptionMessage('larger than 100 bytes');
        $this->diff->parse('<urlset>' . str_repeat('<url><loc>https://e.com/x</loc></url>', 20) . '</urlset>', 100);
    }

    public function testEntityExpansionAttackIsRejected(): void
    {
        $laughs = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE lolz [
              <!ENTITY lol "lol">
              <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
              <!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">
              <!ENTITY lol4 "&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;">
            ]>
            <urlset><url><loc>https://e.com/&lol4;</loc></url></urlset>
            XML;

        $this->expectException(SitemapException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->diff->parse($laughs);
    }

    public function testExternalEntityIsRejected(): void
    {
        $xxe = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <urlset><url><loc>https://e.com/&xxe;</loc></url></urlset>
            XML;

        $this->expectException(SitemapException::class);
        $this->diff->parse($xxe);
    }

    public function testDoctypeInUtf16IsRejectedByTheReader(): void
    {
        $xml = "\xFF\xFE" . mb_convert_encoding(
            '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE urlset [<!ENTITY a "b">]><urlset><url><loc>https://e.com/&a;</loc></url></urlset>',
            'UTF-16LE',
            'UTF-8',
        );

        $this->expectException(SitemapException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->diff->parse($xml);
    }

    public function testHarmlessDoctypeIsRejectedToo(): void
    {
        $this->expectException(SitemapException::class);
        $this->diff->parseIndex('<!DOCTYPE sitemapindex><sitemapindex/>');
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n\t "];
        yield 'plain text' => ['hello world'];
        yield 'unclosed element' => ['<urlset><url><loc>https://e.com/x</url></urlset>'];
        yield 'truncated' => ['<urlset><url><loc>https://e.com/x</loc>'];
        yield 'undefined entity' => ['<urlset><url><loc>https://e.com/&nope;</loc></url></urlset>'];
        yield 'not a sitemap root' => ['<html><body/></html>'];
        yield 'rss feed' => ['<rss version="2.0"><channel/></rss>'];
        yield 'comment only' => ['<!-- nothing to see -->'];
        yield 'two roots' => ['<urlset/><urlset/>'];
    }

    #[DataProvider('malformed')]
    public function testMalformedXmlThrows(string $xml): void
    {
        $this->expectException(SitemapException::class);
        $this->diff->parse($xml);
    }

    public function testLibxmlStateIsRestored(): void
    {
        libxml_use_internal_errors(false);

        try {
            $this->diff->parse('<urlset><url>');
            self::fail('Expected exception');
        } catch (SitemapException) {
        }

        self::assertFalse(libxml_use_internal_errors(false));
        self::assertSame([], libxml_get_errors());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://example.com/a/b', '/a/b'];
        yield 'query and fragment' => ['https://example.com/a/b?x=1#frag', '/a/b'];
        yield 'fragment only' => ['https://example.com/a#f', '/a'];
        yield 'host only' => ['https://example.com', '/'];
        yield 'host with query' => ['https://example.com?x=1', '/'];
        yield 'uppercase scheme' => ['HTTPS://EXAMPLE.com/Path', '/Path'];
        yield 'port and userinfo' => ['http://user@example.com:8080/x', '/x'];
        yield 'duplicate slashes' => ['https://example.com/a//b///c/', '/a/b/c/'];
        yield 'encoded space is decoded' => ['https://example.com/a%20b', '/a b'];
        yield 'percent decoded umlaut' => ['https://example.com/%C3%A4', '/ä'];
        yield 'plus stays plus' => ['https://example.com/a+b', '/a+b'];
        yield 'scheme relative' => ['//example.com/x', '/x'];
        yield 'absolute path' => ['/rel/path?x', '/rel/path'];
        yield 'relative path' => ['rel/path', '/rel/path'];
        yield 'ftp' => ['ftp://example.com/x', null];
        yield 'mailto' => ['mailto:a@b.c', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'whitespace inside' => ['https://example.com/a b', null];
        yield 'control character' => ["https://example.com/a\x01b", null];
        yield 'encoded null byte' => ['https://example.com/a%00b', null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
    }

    #[DataProvider('urls')]
    public function testToPath(string $url, ?string $expected): void
    {
        self::assertSame($expected, $this->diff->toPath($url));
    }

    public function testToPathScrubsInvalidUtf8AndRejectsHugeUrls(): void
    {
        $path = $this->diff->toPath('https://example.com/%FFabc');

        self::assertNotNull($path);
        self::assertSame(1, preg_match('//u', $path));
        self::assertNull($this->diff->toPath('https://example.com/' . str_repeat('a', 9000)));
    }

    public function testDiffCountsExistingRedirectedAndMissing(): void
    {
        $index = PageTreeFixture::index();
        $calls = [];
        $isRedirected = static function (string $path, ?string $language) use (&$calls): bool {
            $calls[] = [$path, $language];

            return in_array($path, ['/old-page', '/old-de'], true);
        };

        $result = $this->diff->diff([
            '/blog/my-post',
            '/blog/my-post/',
            '/de/ueber-uns',
            '/de/about',
            '/de',
            '/',
            '/old-page',
            '/de/old-de',
            '/gone',
            '/GONE/',
            '/blog/draft-post',
            '/about.html',
            '/blog/hidden',
            '/products?ref=1',
            '/deep/nested/missing',
        ], $index, $isRedirected);

        self::assertSame(14, $result->total);
        self::assertSame(6, $result->existing);
        self::assertSame(2, $result->redirected);
        self::assertSame(6, $result->missing);
        self::assertSame(
            ['/gone', '/GONE', '/blog/draft-post', '/about.html', '/blog/hidden', '/deep/nested/missing'],
            $result->missingPaths,
        );
        // Existing paths never reach the callback; the language prefix is passed separately.
        self::assertContains(['/old-page', null], $calls);
        self::assertContains(['/old-de', 'de'], $calls);
        self::assertNotContains(['/blog/my-post', null], $calls);
        self::assertSame($result->total, $result->existing + $result->redirected + $result->missing);
    }

    public function testDiffLanguagesParameterControlsPrefixStripping(): void
    {
        $index = PageTreeFixture::index();
        $never = static fn (string $path, ?string $language): bool => false;

        // Default: languages come from the index, so /de/about is the page /about.
        self::assertSame(1, $this->diff->diff(['/de/about'], $index, $never)->existing);
        // With an explicit list without "de" the prefix is part of the route.
        self::assertSame(1, $this->diff->diff(['/de/about'], $index, $never, ['fr'])->missing);
        self::assertSame(1, $this->diff->diff(['/fr/about'], $index, $never, ['fr'])->existing);
        self::assertSame(1, $this->diff->diff(['/FR/about'], $index, $never, ['fr'])->existing);
        self::assertSame(1, $this->diff->diff(['/de/about'], $index, $never, [])->missing);
    }

    public function testDiffKeepsARealRouteThatLooksLikeALanguagePrefix(): void
    {
        $index = new PageIndex([
            new PageInfo(route: '/de', language: 'en'),
            new PageInfo(route: '/x', language: 'de'),
        ]);

        $result = $this->diff->diff(['/de'], $index, static fn (): bool => false);

        self::assertSame(1, $result->existing);
    }

    public function testDiffOnEmptyInputAndResultArray(): void
    {
        $result = $this->diff->diff([], PageTreeFixture::index(), static fn (): bool => true);

        self::assertSame(['total' => 0, 'existing' => 0, 'redirected' => 0, 'missing' => 0, 'missing_paths' => []], $result->toArray());
    }

    public function testEndToEndParseThenDiff(): void
    {
        $paths = $this->diff->parse(self::URLSET);
        $result = $this->diff->diff($paths, PageTreeFixture::index(), static fn (string $p): bool => $p === '/a&b');

        self::assertSame(['/', '/blog/my-post'], array_slice($paths, 0, 2));
        self::assertSame(2, $result->existing);
        self::assertSame(1, $result->redirected);
        self::assertSame(['/Über-uns'], $result->missingPaths);
    }
}
