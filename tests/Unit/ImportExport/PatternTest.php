<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pattern::class)]
final class PatternTest extends TestCase
{
    public function testEscapeKeepsPathsReadable(): void
    {
        self::assertSame('/a\.html', Pattern::escape('/a.html'));
        self::assertSame('/old-page_1', Pattern::escape('/old-page_1'));
        self::assertSame('/x\?y\=z'[0] . 'x\?y=z', Pattern::escape('/x?y=z'));
        self::assertSame('/ü\(1\)', Pattern::escape('/ü(1)'));
    }

    public function testWildcardToRegex(): void
    {
        self::assertSame('^/blog/(.*)$', Pattern::wildcardToRegex('/blog/*'));
        self::assertSame('^/a/(.*)/b\.html/(.*)$', Pattern::wildcardToRegex('/a/*/b.html/*'));
        self::assertSame('^/plain$', Pattern::wildcardToRegex('/plain'));
    }

    public function testIsValid(): void
    {
        self::assertTrue(Pattern::isValid('^/a/(\d+)$'));
        self::assertTrue(Pattern::isValid('^/(?<year>\d{4})/'));
        self::assertFalse(Pattern::isValid('^/a/(unclosed'));
        self::assertFalse(Pattern::isValid('[a-'));
        self::assertFalse(Pattern::isValid(''));
    }

    /**
     * @return iterable<string, array{string, array{path: string, trailingSlash: bool, caseInsensitive: bool}|null}>
     */
    public static function literals(): iterable
    {
        yield 'plain' => ['^/old$', ['path' => '/old', 'trailingSlash' => false, 'caseInsensitive' => false]];
        yield 'optional slash' => ['^/old/?$', ['path' => '/old', 'trailingSlash' => true, 'caseInsensitive' => false]];
        yield 'escaped dot' => ['^/a\.html$', ['path' => '/a.html', 'trailingSlash' => false, 'caseInsensitive' => false]];
        yield 'case flag' => ['(?i)^/old$', ['path' => '/old', 'trailingSlash' => false, 'caseInsensitive' => true]];
        yield 'escaped slash' => ['^\/old$', ['path' => '/old', 'trailingSlash' => false, 'caseInsensitive' => false]];
        yield 'not anchored' => ['/old', null];
        yield 'no end anchor' => ['^/old', null];
        yield 'unescaped dot' => ['^/a.html$', null];
        yield 'group' => ['^/a/(b)$', null];
        yield 'class shorthand' => ['^/a/\d$', null];
        yield 'escaped dollar at end' => ['^/a\$', null];
        yield 'dangling backslash' => ['^/a\\\\$', ['path' => '/a\\', 'trailingSlash' => false, 'caseInsensitive' => false]];
    }

    /**
     * @param array{path: string, trailingSlash: bool, caseInsensitive: bool}|null $expected
     */
    #[DataProvider('literals')]
    public function testLiteralOf(string $regex, ?array $expected): void
    {
        self::assertSame($expected, Pattern::literalOf($regex));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function wildcards(): iterable
    {
        yield 'trailing' => ['^/blog/(.*)$', '/blog/*'];
        yield 'no end anchor' => ['^/blog/(.*)', '/blog/*'];
        yield 'middle' => ['^/a/(.*)/b$', '/a/*/b'];
        yield 'two' => ['^/(.*)/x/(.*)$', '/*/x/*'];
        yield 'case flag' => ['(?i)^/blog/(.*)$', '/blog/*'];
        yield 'plus is not a wildcard' => ['^/blog/(.+)$', null];
        yield 'no star at all' => ['^/blog$', null];
        yield 'not anchored' => ['/blog/(.*)$', null];
        yield 'other regex' => ['^/blog/(\d+)/(.*)$', null];
        yield 'no end' => ['^/blog/x', null];
    }

    #[DataProvider('wildcards')]
    public function testWildcardOf(string $regex, ?string $expected): void
    {
        self::assertSame($expected, Pattern::wildcardOf($regex)['wildcard'] ?? null);
    }

    public function testWildcardOfReportsCaseFlag(): void
    {
        self::assertTrue(Pattern::wildcardOf('(?i)^/a/(.*)$')['caseInsensitive'] ?? false);
        self::assertFalse(Pattern::wildcardOf('^/a/(.*)$')['caseInsensitive'] ?? true);
    }

    public function testStripCaseFlag(): void
    {
        self::assertSame(['^/a', true], Pattern::stripCaseFlag('(?i)^/a'));
        self::assertSame(['^/a(?i)', false], Pattern::stripCaseFlag('^/a(?i)'));
    }

    public function testGroups(): void
    {
        self::assertSame([], Pattern::groups('^/plain$'));
        self::assertSame([null, null], Pattern::groups('^/(a)/(\d+)$'));
        self::assertSame(['year', null, 'slug', 'q'], Pattern::groups('^/(?<year>\d{4})/(x)(?P<slug>[^/]+)(?\'q\'y)$'));
        self::assertSame([null], Pattern::groups('^/(?:non|capturing)/(cap)$'));
        self::assertSame([null], Pattern::groups('^/\(escaped\)/(cap)$'));
        self::assertSame([null], Pattern::groups('^/[(]/[^)(]/[]()]/(cap)$'));
    }

    public function testNamedToNumbered(): void
    {
        self::assertSame('/x/$2/$1', Pattern::namedToNumbered('/x/{b}/{a}', '^/(?<a>\d+)/(?<b>\w+)$'));
        self::assertSame('/x/$1', Pattern::namedToNumbered('/x/$1', '^/(a)$'));
        self::assertNull(Pattern::namedToNumbered('/x/{missing}', '^/(?<a>x)$'));
    }

    /**
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function hostRegexes(): iterable
    {
        yield 'plain' => ['^example\.com$', ['example.com']];
        yield 'optional www' => ['^(www\.)?example\.com$', ['example.com', 'www.example.com']];
        yield 'alternatives' => ['^(a\.com|b\.org)$', ['a.com', 'b.org']];
        yield 'wildcard class' => ['^[^.]+\.example\.org$', ['*.example.org']];
        yield 'wildcard dot plus' => ['^.+\.example\.org$', ['*.example.org']];
        yield 'optional subdomain' => ['^(.+\.)?example\.org$', ['example.org', '*.example.org']];
        yield 'case flag and uppercase' => ['(?i)^Example\.com$', ['example.com']];
        yield 'nested alternatives' => ['^(example\.com|(www\.)?other\.org)$', null];
        yield 'not anchored' => ['example\.com', null];
        yield 'regexy' => ['^ex.mple\.com$', null];
        yield 'garbage host' => ['^-bad_host$', null];
    }

    /**
     * @param list<string>|null $expected
     */
    #[DataProvider('hostRegexes')]
    public function testHostsOf(string $regex, ?array $expected): void
    {
        self::assertSame($expected, Pattern::hostsOf($regex));
    }

    public function testHostsToRegexRoundtrips(): void
    {
        foreach ([['example.com'], ['a.com', 'b.org'], ['*.example.org'], ['example.com', 'www.example.com', '*.x.io']] as $hosts) {
            $regex = Pattern::hostsToRegex($hosts);
            self::assertTrue(Pattern::isValid($regex), $regex);
            self::assertSame($hosts, Pattern::hostsOf($regex), $regex);
        }
    }
}
