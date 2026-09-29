<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Security;

use Grav\Plugin\RedirectManager\Security\RegexSafety;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RegexSafety::class)]
final class RegexSafetyTest extends TestCase
{
    public function testCompileAddsDelimiterAndFlags(): void
    {
        self::assertSame('#^/a$#ui', RegexSafety::compile('^/a$', false));
        self::assertSame('#^/a$#u', RegexSafety::compile('^/a$', true));
    }

    public function testCompileEscapesTheDelimiter(): void
    {
        self::assertSame('#^/tag/\#(\w+)$#ui', RegexSafety::compile('^/tag/#(\w+)$', false));
        self::assertSame(1, preg_match(RegexSafety::compile('^/tag/#(\w+)$', false), '/tag/#abc'));
    }

    public function testCompileKeepsAlreadyEscapedDelimiter(): void
    {
        self::assertSame('#a\#b#u', RegexSafety::compile('a\#b', true));
    }

    public function testCompileDoesNotLetATrailingBackslashEscapeTheDelimiter(): void
    {
        self::assertSame('#abc\\#ui', RegexSafety::compile('abc\\', false));
        self::assertSame(RegexSafety::ERR_INVALID, RegexSafety::syntaxError('abc\\'));
    }

    public function testCompileEscapedBackslashBeforeDelimiter(): void
    {
        $compiled = RegexSafety::compile('a\\\\#', true);

        self::assertSame(1, preg_match($compiled, 'a\\#'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function validateCases(): iterable
    {
        yield 'simple' => ['^/blog/(.*)$', null];
        yield 'numbered group' => ['^/product/(\d+)$', null];
        yield 'named group' => ['^/(?<year>\d{4})/(?<slug>[a-z-]+)$', null];
        yield 'match everything' => ['.*', null];
        yield 'lazy quantifiers' => ['^/(.*?)/(.*?)/(.*?)$', null];
        yield 'several greedy groups' => ['^/(.*)/(.*)/(.*)/(.*)$', null];
        yield 'nested path segments' => ['^(?:/[a-z0-9-]+)+/?$', null];
        yield 'alternation' => ['^/(en|de|fr)/about$', null];
        yield 'lookahead' => ['^/(?!admin)(.*)$', null];
        yield 'unicode class' => ['^/\p{L}+$', null];
        yield 'empty' => ['', RegexSafety::ERR_INVALID];
        yield 'unterminated class' => ['[', RegexSafety::ERR_INVALID];
        yield 'unterminated group' => ['^/a(', RegexSafety::ERR_INVALID];
        yield 'lone quantifier' => ['*a', RegexSafety::ERR_INVALID];
        yield 'bad named group' => ['^/a(?<', RegexSafety::ERR_INVALID];
        yield 'bad backreference' => ['^(a)\2$', RegexSafety::ERR_INVALID];
        yield 'trailing backslash' => ['abc\\', RegexSafety::ERR_INVALID];
        yield 'bad unicode property' => ['\p{Nope}', RegexSafety::ERR_INVALID];
        yield 'nested plus' => ['^/(a+)+$', RegexSafety::ERR_CATASTROPHIC];
        yield 'nested star' => ['^(a*)*$', RegexSafety::ERR_CATASTROPHIC];
        yield 'overlapping alternation' => ['^(a|aa)+$', RegexSafety::ERR_CATASTROPHIC];
        yield 'unanchored nested plus' => ['(a+)+$', RegexSafety::ERR_CATASTROPHIC];
        yield 'nested dot plus' => ['^/(.+)+$', RegexSafety::ERR_CATASTROPHIC];
        yield 'too long' => ['^/' . str_repeat('a', 999), RegexSafety::ERR_TOO_LONG];
        yield 'exactly the maximum length' => [str_repeat('a', 1000), null];
    }

    #[DataProvider('validateCases')]
    public function testValidate(string $pattern, ?string $expected): void
    {
        $started = microtime(true);
        self::assertSame($expected, RegexSafety::validate($pattern));
        self::assertLessThan(1.0, microtime(true) - $started, 'validate() must be fast even for bad patterns');
    }

    public function testValidateHonoursCaseSensitivity(): void
    {
        self::assertNull(RegexSafety::validate('^/Blog$', true));
        self::assertNull(RegexSafety::validate('(?i)^/Blog$', true));
    }

    public function testSyntaxErrorIsCheapAndDoesNotProbe(): void
    {
        self::assertNull(RegexSafety::syntaxError('^/(a+)+$'));
        self::assertSame(RegexSafety::ERR_INVALID, RegexSafety::syntaxError('['));
        self::assertSame(RegexSafety::ERR_INVALID, RegexSafety::syntaxError(''));
        self::assertSame(RegexSafety::ERR_TOO_LONG, RegexSafety::syntaxError(str_repeat('a', 1001)));
    }

    public function testInvalidPatternRaisesNoWarning(): void
    {
        // failOnWarning is on in phpunit.xml.dist: a leaked E_WARNING would fail this test.
        self::assertSame(RegexSafety::ERR_INVALID, RegexSafety::validate('(?P<n>'));
    }

    public function testWithLimitsSetsAndRestoresLimits(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');

        $inside = RegexSafety::withLimits(static fn (): array => [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')], 1234, 567);

        self::assertSame(['1234', '567'], $inside);
        self::assertSame($backtrack, ini_get('pcre.backtrack_limit'));
        self::assertSame($recursion, ini_get('pcre.recursion_limit'));
    }

    public function testWithLimitsRestoresOnException(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');

        try {
            RegexSafety::withLimits(static function (): never {
                throw new RuntimeException('boom');
            }, 10, 10);
            self::fail('Exception expected');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($backtrack, ini_get('pcre.backtrack_limit'));
    }

    public function testWithLimitsNests(): void
    {
        $result = RegexSafety::withLimits(
            static fn (): array => [
                RegexSafety::withLimits(static fn (): string|false => ini_get('pcre.backtrack_limit'), 50, 50),
                ini_get('pcre.backtrack_limit'),
            ],
            500,
            500,
        );

        self::assertSame(['50', '500'], $result);
    }

    public function testLowLimitTripsOnCatastrophicPatternWithAPcreError(): void
    {
        $compiled = RegexSafety::compile('^(a+)+$', false);
        $subject = str_repeat('a', 3000) . '!';

        $result = RegexSafety::withLimits(static fn (): int|false => preg_match($compiled, $subject), 1000, 1000);

        self::assertFalse($result);
        self::assertTrue(RegexSafety::isLimitError(preg_last_error()));
    }

    public function testIsLimitError(): void
    {
        self::assertTrue(RegexSafety::isLimitError(PREG_BACKTRACK_LIMIT_ERROR));
        self::assertTrue(RegexSafety::isLimitError(PREG_RECURSION_LIMIT_ERROR));
        self::assertTrue(RegexSafety::isLimitError(PREG_JIT_STACKLIMIT_ERROR));
        self::assertFalse(RegexSafety::isLimitError(PREG_NO_ERROR));
        self::assertFalse(RegexSafety::isLimitError(PREG_BAD_UTF8_ERROR));
    }
}
