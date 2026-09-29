<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use Grav\Plugin\RedirectManager\Matching\LocationEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocationEncoder::class)]
final class LocationEncoderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'plain path' => ['/a/b', '/a/b'];
        yield 'root' => ['/', '/'];
        yield 'empty' => ['', ''];
        yield 'space' => ['/a b', '/a%20b'];
        yield 'umlaut' => ['/über', '/%C3%BCber'];
        yield 'emoji' => ['/a😀', '/a%F0%9F%98%80'];
        yield 'existing sequences stay' => ['/a%20b%2Fc', '/a%20b%2Fc'];
        yield 'lower case hex stays' => ['/a%c3%bc', '/a%c3%bc'];
        yield 'lone percent' => ['/100%', '/100%25'];
        yield 'percent with one digit' => ['/a%2', '/a%252'];
        yield 'percent with non-hex' => ['/a%zz', '/a%25zz'];
        yield 'percent at the end after a valid one' => ['/a%20%', '/a%20%25'];
        yield 'reserved characters stay' => ['/a:b@c!d$e&f\'g(h)i*j+k,l;m=n', '/a:b@c!d$e&f\'g(h)i*j+k,l;m=n'];
        yield 'unreserved characters stay' => ['/A-z_0.9~', '/A-z_0.9~'];
        yield 'query and fragment delimiters stay' => ['/a?b=c&d=e#f', '/a?b=c&d=e#f'];
        yield 'brackets stay' => ['/a?b[]=1&b[]=2', '/a?b[]=1&b[]=2'];
        yield 'quote' => ['/a"b', '/a%22b'];
        yield 'angle brackets' => ['/a<b>c', '/a%3Cb%3Ec'];
        yield 'backslash' => ['/a\\b', '/a%5Cb'];
        yield 'caret' => ['/a^b', '/a%5Eb'];
        yield 'backtick' => ['/a`b', '/a%60b'];
        yield 'braces and pipe' => ['/a{b|c}', '/a%7Bb%7Cc%7D'];
        yield 'tab and newline' => ["/a\tb\nc", '/a%09b%0Ac'];
        yield 'NUL' => ["/a\0b", '/a%00b'];
        yield 'delete character' => ["/a\x7Fb", '/a%7Fb'];
        yield 'space in the query' => ['/a?q=x y', '/a?q=x%20y'];
        yield 'umlaut in the query' => ['/a?q=café', '/a?q=caf%C3%A9'];
        yield 'space in the fragment' => ['/a#x y', '/a#x%20y'];
        yield 'absolute url' => ['https://example.com/a', 'https://example.com/a'];
        yield 'absolute url, path with space' => ['https://example.com/a b', 'https://example.com/a%20b'];
        yield 'absolute url, query with umlaut' => ['https://example.com/a?q=ü', 'https://example.com/a?q=%C3%BC'];
        yield 'absolute url with port' => ['https://example.com:8443/a', 'https://example.com:8443/a'];
        yield 'absolute url, host only' => ['https://example.com', 'https://example.com'];
        yield 'absolute url, query right after host' => ['https://example.com?x=y z', 'https://example.com?x=y%20z'];
        yield 'absolute url, scheme in upper case stays' => ['HTTPS://example.com/a', 'HTTPS://example.com/a'];
        yield 'absolute url with user info' => ['https://us er@example.com/', 'https://us%20er@example.com/'];
        yield 'absolute url with ipv6 host' => ['https://[::1]:8080/a', 'https://[::1]:8080/a'];
        yield 'absolute url with empty port' => ['https://example.com:/a', 'https://example.com:/a'];
        yield 'idn host becomes punycode' => ['https://bücher.example/straße', 'https://xn--bcher-kva.example/stra%C3%9Fe'];
        yield 'idn host with port' => ['https://bücher.example:8443/', 'https://xn--bcher-kva.example:8443/'];
        yield 'colon in relative path is not a scheme' => ['/a:b', '/a:b'];
        yield 'scheme-like relative string' => ['mailto:a b', 'mailto:a%20b'];
    }

    #[DataProvider('cases')]
    public function testEncode(string $input, string $expected): void
    {
        self::assertSame($expected, LocationEncoder::encode($input));
    }

    public function testEncodeIsIdempotent(): void
    {
        foreach (['/a b/über?x=y z#frag ment', 'https://bücher.example/a b', '/100%/a%2', "/a\0b"] as $input) {
            $once = LocationEncoder::encode($input);
            self::assertSame($once, LocationEncoder::encode($once), $input);
        }
    }

    public function testEncodedLocationHasNoCharactersThatBreakAHeader(): void
    {
        $encoded = LocationEncoder::encode("/a b\r\n\t\"<>\\^`{|}é");

        self::assertSame(0, preg_match('/[^\x21-\x7E]/', $encoded));
    }
}
