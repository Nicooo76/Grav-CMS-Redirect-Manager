<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use Grav\Plugin\RedirectManager\NotFound\IgnoreList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IgnoreList::class)]
#[Group('notfound')]
final class IgnoreListTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function defaultCases(): iterable
    {
        foreach ([
            '/wp-admin', '/wp-admin/install.php', '/wp-login.php', '/wp-content/uploads/a.jpg', '/wp-includes/js/x.js',
            '/wp-json/wp/v2/users', '/xmlrpc.php', '/index.php', '/shell.php', '/a/b/c.PHP', '/x.asp', '/x.aspx', '/x.jsp', '/test.cgi',
            '/cgi-bin/luci', '/actuator/health', '/_ignition/execute-solution', '/_profiler/latest', '/autodiscover/autodiscover.xml',
            '/owa/auth/logon.aspx', '/ecp/x', '/boaform/admin/formLogin', '/HNAP1/', '/phpmyadmin', '/phpMyAdmin-5.2/index', '/pma', '/pma/index',
            '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', '/server-status', '/.env', '/.env.local', '/backup/.env', '/.git/config',
            '/.gitignore', '/sub/.git/HEAD', '/.svn/entries', '/.hg/store', '/.aws/credentials', '/.ssh/id_rsa', '/.idea/workspace.xml',
            '/.vscode/settings.json', '/.htaccess', '/.htpasswd', '/composer.json', '/composer.lock', '/package.json', '/package-lock.json',
            '/docker-compose.yml', '/docker-compose.yaml', '/sftp-config.json', '/.DS_Store', '/favicon.ico', '/apple-touch-icon.png',
            '/apple-touch-icon-precomposed.png', '/browserconfig.xml', '/.well-known/traffic-advice', '/.well-known/apple-app-site-association',
            '/apple-app-site-association', '/.well-known/assetlinks.json',
        ] as $path) {
            yield 'ignored ' . $path => [$path, true];
        }
        foreach ([
            '/', '/about', '/blog/post', '/pma-training', '/pmalink', '/ads.txt', '/robots.txt', '/sitemap.xml', '/old-page.html', '/contact/',
            '/blog/php-tutorial', '/downloads/file.pdf', '/.well-known/security.txt', '/wp-page', '/favicon.png', '/env', '/git/config',
            '/de/leistungen', '/impressum',
        ] as $path) {
            yield 'kept ' . $path => [$path, false];
        }
    }

    #[DataProvider('defaultCases')]
    public function testDefaultPatterns(string $path, bool $ignored): void
    {
        self::assertSame($ignored, (new IgnoreList())->matches($path), $path);
    }

    public function testAdsTxtIsDeliberatelyNotIgnored(): void
    {
        self::assertNotContains('/ads.txt', IgnoreList::defaultPatterns());
        self::assertFalse((new IgnoreList())->matches('/ads.txt'));
    }

    public function testDefaultPatternsAreAListOfNonEmptyStrings(): void
    {
        $patterns = IgnoreList::defaultPatterns();

        self::assertTrue(array_is_list($patterns));
        self::assertSame($patterns, array_values(array_unique($patterns)));
        foreach ($patterns as $pattern) {
            self::assertNotSame('', $pattern);
            self::assertSame('/', $pattern[0] === '*' ? '/' : $pattern[0], $pattern);
        }
    }

    public function testStarMatchesAnyRunIncludingSlashes(): void
    {
        $list = new IgnoreList(['/a/*/z', '*.bak']);

        self::assertTrue($list->matches('/a/b/z'));
        self::assertTrue($list->matches('/a/b/c/d/z'));
        self::assertTrue($list->matches('/a//z'));
        self::assertFalse($list->matches('/a/z'));
        self::assertTrue($list->matches('/deep/dir/file.bak'));
        self::assertFalse($list->matches('/file.bak.txt'));
    }

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        $list = new IgnoreList(['/file?.txt']);

        self::assertTrue($list->matches('/file1.txt'));
        self::assertTrue($list->matches('/fileä.txt'));
        self::assertFalse($list->matches('/file.txt'));
        self::assertFalse($list->matches('/file12.txt'));
    }

    public function testMatchingIsCaseInsensitiveIncludingUnicode(): void
    {
        $list = new IgnoreList(['/Admin/*', '/ÜBER*']);

        self::assertTrue($list->matches('/admin/login'));
        self::assertTrue($list->matches('/ADMIN/x'));
        self::assertTrue($list->matches('/über-uns'));
    }

    public function testPatternsAreAnchoredAtBothEnds(): void
    {
        $list = new IgnoreList(['/exact']);

        self::assertTrue($list->matches('/exact'));
        self::assertFalse($list->matches('/exact/more'));
        self::assertFalse($list->matches('/prefix/exact'));
        self::assertFalse($list->matches('/EXACTLY'));
    }

    public function testRegexMetacharactersAreLiteral(): void
    {
        $list = new IgnoreList(['/a.b', '/c+d', '/e(f)', '/g[h]', '/i|j', '/k^l$', '/m\\n', '/o~p']);

        self::assertTrue($list->matches('/a.b'));
        self::assertFalse($list->matches('/axb'));
        self::assertTrue($list->matches('/c+d'));
        self::assertFalse($list->matches('/ccd'));
        self::assertTrue($list->matches('/e(f)'));
        self::assertTrue($list->matches('/g[h]'));
        self::assertFalse($list->matches('/gh'));
        self::assertTrue($list->matches('/i|j'));
        self::assertFalse($list->matches('/i'));
        self::assertTrue($list->matches('/k^l$'));
        self::assertTrue($list->matches('/m\\n'));
        self::assertTrue($list->matches('/o~p'));
    }

    public function testPatternsWithQuestionMarkAreAlsoMatchedAgainstPathAndQuery(): void
    {
        $list = new IgnoreList(['/search?q=*', '/plain']);

        self::assertTrue($list->matches('/search', 'q=hello'));
        self::assertFalse($list->matches('/search', 'other=1'));
        self::assertFalse($list->matches('/search'));
        self::assertTrue($list->matches('/plain', 'x=1'), 'path-only patterns match on the path regardless of the query');
        self::assertFalse($list->matches('/other', 'q=hello'));
    }

    public function testPatternsWithoutQuestionMarkDoNotSeeTheQuery(): void
    {
        $list = new IgnoreList(['*.php']);

        self::assertFalse($list->matches('/page', 'file=x.php'));
        self::assertTrue($list->matches('/x.php', 'a=b'));
    }

    public function testCustomPatternsReplaceTheDefaults(): void
    {
        $list = (new IgnoreList())->withPatterns(['/private/*']);

        self::assertTrue($list->matches('/private/x'));
        self::assertFalse($list->matches('/wp-login.php'));
        self::assertSame(['/private/*'], $list->patterns());
    }

    public function testDefaultsCanBeExtendedAndPartiallyRemoved(): void
    {
        $withoutPhp = array_values(array_filter(IgnoreList::defaultPatterns(), static fn (string $p): bool => $p !== '*.php'));
        $list = new IgnoreList([...$withoutPhp, '/custom/*']);

        self::assertFalse($list->matches('/legacy/page.php'));
        self::assertTrue($list->matches('/custom/x'));
        self::assertTrue($list->matches('/wp-admin/x'));
    }

    public function testEmptyAndDuplicateEntriesAreDropped(): void
    {
        $list = new IgnoreList(['', '  ', '/a', '/a', ' /b ']);

        self::assertSame(['/a', '/b'], $list->patterns());
    }

    public function testEmptyListMatchesNothing(): void
    {
        $list = new IgnoreList([]);

        self::assertFalse($list->matches('/anything'));
        self::assertFalse($list->matches('/anything', 'a=b'));
    }

    public function testInvalidUtf8InThePathDoesNotBreakMatching(): void
    {
        $list = new IgnoreList(['*.php']);

        self::assertFalse($list->matches("/bad-\xFF"));
        self::assertTrue($list->matches("/bad-\xFF.php"));
    }

    public function testManyWildcardsDoNotHangOnLongInput(): void
    {
        $list = new IgnoreList(['*a*a*a*a*a*b']);
        $start = microtime(true);

        self::assertFalse($list->matches('/' . str_repeat('a', 2000)));
        self::assertLessThan(2.0, microtime(true) - $start);
    }
}
