<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use Grav\Plugin\RedirectManager\NotFound\FieldSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldSanitizer::class)]
#[Group('notfound')]
final class FieldSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function injections(): iterable
    {
        yield 'crlf' => ["/a\r\nHTTP/1.1 200 OK\r\nX: y", '/aHTTP/1.1 200 OKX: y'];
        yield 'lf only' => ["/a\nfake log line", '/afake log line'];
        yield 'cr only' => ["/a\rb", '/ab'];
        yield 'nul' => ["/a\0b", '/ab'];
        yield 'tab and vertical tab' => ["/a\tb\x0Bc", '/abc'];
        yield 'del' => ["/a\x7Fb", '/ab'];
        yield 'c1 controls' => ["/a\u{0085}b\u{009B}c", '/abc'];
        yield 'ansi color' => ["/a\x1B[31mred\x1B[0m", '/ared'];
        yield 'ansi cursor and clear' => ["/a\x1B[2J\x1B[1;1Hb", '/ab'];
        yield 'ansi osc title' => ["/a\x1B]0;evil title\x07b", '/ab'];
        yield 'ansi osc st' => ["/a\x1B]8;;http://x\x1B\\link\x1B]8;;\x1B\\", '/alink'];
        yield 'lone escape' => ["/a\x1Bb", '/ab'];
        yield 'line separators' => ["/a\u{2028}b\u{2029}c", '/abc'];
        yield 'bidi override' => ["/a\u{202E}gpj.exe", '/agpj.exe'];
        yield 'bidi isolate' => ["/a\u{2066}b\u{2069}", '/ab'];
        yield 'zero width' => ["/a\u{200B}b\u{FEFF}", '/ab'];
        yield 'clean input untouched' => ['/über/日本語/😀', '/über/日本語/😀'];
    }

    #[DataProvider('injections')]
    public function testTextRemovesLogInjectionVectors(string $input, string $expected): void
    {
        self::assertSame($expected, FieldSanitizer::text($input, 2048));
    }

    public function testTextMakesInvalidUtf8Valid(): void
    {
        $out = FieldSanitizer::text("/a\xFF\xFEb\xC3", 2048);

        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertStringStartsWith('/a', $out);
        self::assertNotFalse(json_encode($out));
    }

    public function testTextTruncatesByBytesWithoutSplittingCharacters(): void
    {
        $out = FieldSanitizer::text(str_repeat('ü', 100), 11);

        self::assertSame(str_repeat('ü', 5), $out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertLessThanOrEqual(11, strlen($out));
    }

    public function testHugeInputIsCappedQuicklyAndSafely(): void
    {
        $huge = str_repeat("/a\x1B[31m" . "\xFF", 2_000_000);
        $start = microtime(true);

        $out = FieldSanitizer::text($huge, 2048);

        self::assertLessThanOrEqual(2048, strlen($out));
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testTextOfZeroLengthLimit(): void
    {
        self::assertSame('', FieldSanitizer::text('/abc', 0));
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function referers(): iterable
    {
        yield 'null' => [null, ''];
        yield 'empty' => ['', ''];
        yield 'query dropped' => ['https://example.com/page?email=a@b.de&token=1', 'https://example.com/page'];
        yield 'fragment dropped' => ['https://example.com/page#section', 'https://example.com/page'];
        yield 'userinfo dropped' => ['https://user:secret@example.com/page', 'https://example.com/page'];
        yield 'host lowercased' => ['HTTPS://Example.COM/Page', 'https://example.com/Page'];
        yield 'port kept' => ['http://example.com:8080/x', 'http://example.com:8080/x'];
        yield 'no path' => ['https://example.com', 'https://example.com'];
        yield 'javascript scheme' => ['javascript:alert(1)', ''];
        yield 'data scheme' => ['data:text/html,<script>', ''];
        yield 'ftp scheme' => ['ftp://example.com/x', ''];
        yield 'relative' => ['/just/a/path', ''];
        yield 'no host' => ['https:///path', ''];
        yield 'garbage' => ['http://', ''];
        yield 'crlf in referer' => ["https://example.com/a\r\nInjected: 1", 'https://example.com/aInjected: 1'];
        yield 'android app' => ['android-app://com.google.android.gm', ''];
    }

    #[DataProvider('referers')]
    public function testRefererKeepsSchemeHostAndPathOnly(?string $referer, string $expected): void
    {
        self::assertSame($expected, FieldSanitizer::referer($referer, 1024));
    }

    public function testRefererIsTruncated(): void
    {
        $out = FieldSanitizer::referer('https://example.com/' . str_repeat('a', 5000), 100);

        self::assertSame(100, strlen($out));
    }

    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'leading question mark' => ['?a=1', 'a=1'];
        yield 'plain params kept' => ['page=2&sort=asc', 'page=2&sort=asc'];
        yield 'email' => ['email=john@example.com&page=1', 'email=***&page=1'];
        yield 'e-mail' => ['e-mail=x&p=1', 'e-mail=***&p=1'];
        yield 'user_email' => ['user_email=x', 'user_email=***'];
        yield 'mail' => ['mail=x', 'mail=***'];
        yield 'token' => ['token=abc123', 'token=***'];
        yield 'access_token' => ['access_token=abc', 'access_token=***'];
        yield 'csrfToken camelCase' => ['csrfToken=abc', 'csrfToken=***'];
        yield 'password' => ['password=hunter2', 'password=***'];
        yield 'passwd and pwd' => ['passwd=x&pwd=y', 'passwd=***&pwd=***'];
        yield 'pass' => ['pass=x', 'pass=***'];
        yield 'key' => ['key=abc', 'key=***'];
        yield 'api_key' => ['api_key=abc', 'api_key=***'];
        yield 'api-key' => ['api-key=abc', 'api-key=***'];
        yield 'apiKey' => ['apiKey=abc', 'apiKey=***'];
        yield 'secret' => ['client_secret=abc', 'client_secret=***'];
        yield 'session' => ['session=abc', 'session=***'];
        yield 'sessionid' => ['sessionid=abc', 'sessionid=***'];
        yield 'PHPSESSID' => ['PHPSESSID=abc', 'PHPSESSID=***'];
        yield 'sid' => ['sid=abc', 'sid=***'];
        yield 'auth' => ['auth=abc&authorization=Bearer x', 'auth=***&authorization=***'];
        yield 'signature' => ['sig=abc&signature=def', 'sig=***&signature=***'];
        yield 'uppercase name' => ['TOKEN=abc', 'TOKEN=***'];
        yield 'encoded name' => ['to%6Ben=abc', 'to%6Ben=***'];
        yield 'plus in name' => ['user+email=abc', 'user+email=***'];
        yield 'glued suffixes' => ['accesstoken=1&userpassword=2&myapikey=3&Xsessionid=4&loginemail=5', 'accesstoken=***&userpassword=***&myapikey=***&Xsessionid=***&loginemail=***'];
        yield 'keyword is not sensitive' => ['keyword=shoes&keys=1', 'keyword=shoes&keys=1'];
        yield 'inside is not sid' => ['inside=1&consider=2', 'inside=1&consider=2'];
        yield 'monkey is not key' => ['monkey=1', 'monkey=1'];
        yield 'email-looking value in harmless param' => ['ref=john@example.com', 'ref=***'];
        yield 'encoded email value' => ['ref=john%40example.com', 'ref=***'];
        yield 'at sign without domain is kept' => ['handle=@john', 'handle=@john'];
        yield 'empty sensitive value stays empty' => ['token=&a=1', 'token=&a=1'];
        yield 'sensitive without equals' => ['token&a=1', 'token&a=1'];
        yield 'duplicate names' => ['token=a&token=b', 'token=***&token=***'];
        yield 'control chars stripped' => ["a=1\r\nb=2", 'a=1b=2'];
    }

    #[DataProvider('queries')]
    public function testQueryRedactsSensitiveParameters(string $query, string $expected): void
    {
        self::assertSame($expected, FieldSanitizer::query($query, 1024));
    }

    public function testQueryIsTruncatedAfterRedaction(): void
    {
        $out = FieldSanitizer::query('token=' . str_repeat('s', 30) . '&x=' . str_repeat('y', 100), 20);

        self::assertSame('token=***&x=' . str_repeat('y', 8), $out);
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function languages(): iterable
    {
        yield 'null' => [null, null];
        yield 'two letters' => ['de', 'de'];
        yield 'uppercase' => ['DE', 'de'];
        yield 'region' => ['de-DE', 'de-de'];
        yield 'underscore region' => ['pt_BR', 'pt_br'];
        yield 'three letters' => ['fil', 'fil'];
        yield 'script and region' => ['zh-Hant-TW', 'zh-hant-tw'];
        yield 'padded' => [' en ', 'en'];
        yield 'empty' => ['', null];
        yield 'one letter' => ['d', null];
        yield 'digits' => ['12', null];
        yield 'injection' => ["de\r\nX: y", null];
        yield 'sql' => ["de'; --", null];
        yield 'too long' => [str_repeat('a', 40), null];
        yield 'wildcard' => ['*', null];
    }

    #[DataProvider('languages')]
    public function testLanguage(?string $input, ?string $expected): void
    {
        self::assertSame($expected, FieldSanitizer::language($input));
    }

    public function testHostIsLowercasedTrimmedCleanedAndCapped(): void
    {
        self::assertSame('example.org', FieldSanitizer::host(' Example.ORG '));
        self::assertSame('example.org:8080', FieldSanitizer::host('example.org:8080'));
        self::assertSame('a.exampleb', FieldSanitizer::host("a.example\r\nb"));
        self::assertSame(255, strlen(FieldSanitizer::host(str_repeat('a', 1000))));
        self::assertSame('', FieldSanitizer::host(''));
    }
}
