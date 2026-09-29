<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\TextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Transliterator;

#[CoversClass(TextNormalizer::class)]
final class TextNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function asciiCases(): iterable
    {
        yield 'umlauts' => ['Über-uns Größe Äpfel Öl', 'ueber-uns groesse aepfel oel'];
        yield 'sharp s' => ['Straße', 'strasse'];
        yield 'accents' => ['Café Crème brûlée', 'cafe creme brulee'];
        yield 'polish and czech' => ['Łódź Žižkov', 'lodz zizkov'];
        yield 'plain' => ['already-ascii', 'already-ascii'];
        yield 'empty' => ['', ''];
        yield 'decomposed umlaut' => ["U\u{0308}ber", 'ueber'];
    }

    #[DataProvider('asciiCases')]
    public function testAscii(string $input, string $expected): void
    {
        self::assertSame($expected, (new TextNormalizer())->ascii($input));
    }

    public function testAsciiUsesTransliteratorForCharactersOutsideTheTable(): void
    {
        if (!class_exists(Transliterator::class)) {
            self::markTestSkipped('ext-intl is not available.');
        }

        self::assertSame('hello', (new TextNormalizer())->ascii('Ħello'));
    }

    public function testNonLatinScriptsStayIntact(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame(['привет', 'мир'], $normalizer->tokens('Привет-Мир'));
        self::assertSame(['日本語'], $normalizer->tokens('日本語'));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame(1, preg_match('//u', $normalizer->ascii("caf\xE9-bar")));
        self::assertSame(1, preg_match('//u', $normalizer->scrub("a\xFFb")));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function tokenCases(): iterable
    {
        yield 'dashes' => ['my-post', ['my', 'post']];
        yield 'underscores dots slashes' => ['my_post.v2/final', ['my', 'post', 'v2', 'final']];
        yield 'camel case' => ['myBlogPost', ['my', 'blog', 'post']];
        yield 'camel case with digits' => ['page2Next', ['page2', 'next']];
        yield 'acronym stays together' => ['PHPUnit', ['phpunit']];
        yield 'umlaut segment' => ['Über-uns', ['ueber', 'uns']];
        yield 'only separators' => ['--__..', []];
        yield 'empty' => ['', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('tokenCases')]
    public function testTokens(string $input, array $expected): void
    {
        self::assertSame($expected, (new TextNormalizer())->tokens($input));
    }

    public function testContentTokensDropStopWordsSingleLettersAndDuplicates(): void
    {
        self::assertSame(
            ['guide', 'grav', '2024'],
            (new TextNormalizer())->contentTokens('The guide to Grav, a guide 2024 x'),
        );
        self::assertSame([], (new TextNormalizer())->contentTokens('der und die'));
    }

    public function testSlugAndRoute(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame('ueber-uns', $normalizer->slug('Über_Uns'));
        self::assertSame('/ueber-uns', $normalizer->route('/Über-uns/'));
        self::assertSame('/blog/my-post', $normalizer->route('//Blog//myPost'));
        self::assertSame('/', $normalizer->route(''));
        self::assertSame('/', $normalizer->route('/../.'));
    }

    public function testTrigramSet(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame(['^ab', 'ab$'], array_keys($normalizer->trigramSet('ab')));
        self::assertSame(['^a$' => true], $normalizer->trigramSet('a'));
        self::assertSame(['^$' => true], $normalizer->trigramSet(''));
        // Numeric trigrams become integer keys but still behave as set members.
        $set = $normalizer->trigramSet('12345');
        self::assertArrayHasKey('234', $set);
        self::assertArrayHasKey('^12', $set);
    }
}
