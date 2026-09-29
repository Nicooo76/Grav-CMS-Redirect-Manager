<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Util;

use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathNormalizer::class)]
#[CoversClass(InvalidPathException::class)]
final class PathNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function normalizeCases(): iterable
    {
        yield 'plain path' => ['/a/b', '/a/b'];
        yield 'root' => ['/', '/'];
        yield 'empty string' => ['', '/'];
        yield 'missing leading slash' => ['a/b', '/a/b'];
        yield 'trailing slash is preserved' => ['/a/b/', '/a/b/'];
        yield 'repeated slashes' => ['//a///b//', '/a/b/'];
        yield 'only slashes' => ['////', '/'];
        yield 'backslash becomes slash' => ['\\a\\b', '/a/b'];
        yield 'encoded backslash becomes slash' => ['/a%5Cb', '/a/b'];
        yield 'encoded slash is a separator' => ['/a%2Fb', '/a/b'];
        yield 'percent decoding once' => ['/a%2520b', '/a%20b'];
        yield 'space' => ['/a%20b', '/a b'];
        yield 'plus stays plus' => ['/a+b', '/a+b'];
        yield 'encoded plus stays plus' => ['/a%2Bb', '/a+b'];
        yield 'invalid percent sequence stays' => ['/100%zz', '/100%zz'];
        yield 'lone percent at the end stays' => ['/100%', '/100%'];
        yield 'umlaut' => ['/%C3%BCber', '/über'];
        yield 'NFD becomes NFC' => ["/cafe\u{301}", "/caf\u{e9}"];
        yield 'encoded NFD becomes NFC' => ['/cafe%CC%81', "/caf\u{e9}"];
        yield 'dot segment' => ['/a/./b', '/a/b'];
        yield 'dot dot segment' => ['/a/b/../c', '/a/c'];
        yield 'dot dot cannot leave the root' => ['/../../a', '/a'];
        yield 'only dot dot' => ['/..', '/'];
        yield 'trailing dot dot keeps a slash' => ['/a/b/..', '/a/'];
        yield 'trailing dot keeps a slash' => ['/a/b/.', '/a/b/'];
        yield 'encoded dot dot' => ['/a/%2e%2e/b', '/b'];
        yield 'traversal to etc/passwd' => ['/a/../../etc/passwd', '/etc/passwd'];
        yield 'dot files are not dot segments' => ['/.well-known/x', '/.well-known/x'];
        yield 'three dots are a normal name' => ['/.../x', '/.../x'];
        yield 'star and question-like characters' => ['/a*b', '/a*b'];
        yield 'tilde' => ['/~user', '/~user'];
        yield 'max length' => ['/' . str_repeat('a', 2047), '/' . str_repeat('a', 2047)];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $raw, string $expected): void
    {
        self::assertSame($expected, PathNormalizer::normalize($raw));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedCases(): iterable
    {
        yield 'NUL byte' => ["/a\0b"];
        yield 'encoded NUL' => ['/a%00b'];
        yield 'encoded NUL lower case hex' => ['/a%00'];
        yield 'newline' => ["/a\nb"];
        yield 'encoded newline' => ['/a%0Ab'];
        yield 'encoded carriage return' => ['/a%0d'];
        yield 'tab' => ["/a\tb"];
        yield 'escape' => ['/a%1Bb'];
        yield 'delete character' => ['/a%7Fb'];
        yield 'invalid UTF-8' => ["/a\xffb"];
        yield 'encoded invalid UTF-8' => ['/a%ffb'];
        yield 'truncated UTF-8 sequence' => ['/a%C3'];
        yield 'too long' => ['/' . str_repeat('a', 2048)];
        yield 'too long when encoded' => ['/' . str_repeat('%41', 4000)];
        yield 'absurdly long raw input' => [str_repeat('a', 7000)];
    }

    #[DataProvider('rejectedCases')]
    public function testNormalizeRejects(string $raw): void
    {
        $this->expectException(InvalidPathException::class);
        PathNormalizer::normalize($raw);
    }

    public function testExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathNormalizer::normalize("/\0");
    }

    public function testNormalizeIsIdempotentForPlainPaths(): void
    {
        foreach (['/a/b', '/a/b/', '/', '/über', '/a b'] as $path) {
            self::assertSame($path, PathNormalizer::normalize($path));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function stripCases(): iterable
    {
        yield 'root stays' => ['/', '/'];
        yield 'no slash' => ['/a', '/a'];
        yield 'one slash' => ['/a/', '/a'];
        yield 'several slashes' => ['/a//', '/a'];
        yield 'only slashes' => ['//', '/'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('stripCases')]
    public function testStripTrailingSlash(string $path, string $expected): void
    {
        self::assertSame($expected, PathNormalizer::stripTrailingSlash($path));
    }

    public function testLower(): void
    {
        self::assertSame('/über/abc', PathNormalizer::lower('/ÜBER/AbC'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function splitCases(): iterable
    {
        yield 'no query' => ['/a/b', '/a/b', ''];
        yield 'query' => ['/a?x=1&y=2', '/a', 'x=1&y=2'];
        yield 'empty query' => ['/a?', '/a', ''];
        yield 'second question mark stays in the query' => ['/a?x=1?y', '/a', 'x=1?y'];
        yield 'only query' => ['?x=1', '', 'x=1'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('splitCases')]
    public function testSplitSource(string $source, string $path, string $query): void
    {
        self::assertSame([$path, $query], PathNormalizer::splitSource($source));
    }
}
