<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\ImportExport\Support\HeaderCond;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderCond::class)]
#[Group('import')]
final class HeaderCondTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, ConditionOperator, string}>
     */
    public static function fromPatternProvider(): iterable
    {
        //                                pattern            insensitive  operator                       value
        yield 'dot is any value' => ['.', false, ConditionOperator::Exists, ''];
        yield 'dot plus is any value' => ['.+', false, ConditionOperator::Exists, ''];
        yield 'anchored literal' => ['^en-US$', false, ConditionOperator::Equals, 'en-US'];
        yield 'anchored escaped literal' => ['^https\://example\.com/$', false, ConditionOperator::Equals, 'https://example.com/'];
        yield 'anchored empty' => ['^$', false, ConditionOperator::Equals, ''];
        yield 'prefix' => ['^Googlebot', false, ConditionOperator::StartsWith, 'Googlebot'];
        yield 'prefix with escapes' => ['^https\://partner\.com', false, ConditionOperator::StartsWith, 'https://partner.com'];
        yield 'plain word' => ['bot', false, ConditionOperator::Contains, 'bot'];
        yield 'escaped dot' => ['example\.com', false, ConditionOperator::Contains, 'example.com'];
        yield 'anchored with regex inside falls through to regex' => ['^a.b$', false, ConditionOperator::Regex, '^a.b$'];
        yield 'prefix with regex inside' => ['^a+', false, ConditionOperator::Regex, '^a+'];
        yield 'suffix anchor only' => ['bot$', false, ConditionOperator::Regex, 'bot$'];
        yield 'bare caret' => ['^', false, ConditionOperator::Regex, '^'];
        yield 'alternation' => ['a|b', false, ConditionOperator::Regex, 'a|b'];
        yield 'empty pattern' => ['', false, ConditionOperator::Regex, ''];
        yield 'escaped alphanumeric is a class, not a literal' => ['^\d+$', false, ConditionOperator::Regex, '^\d+$'];
        yield 'insensitive flag argument' => ['bot', true, ConditionOperator::Regex, '(?i)bot'];
        yield 'insensitive inline flag' => ['(?i)bot', false, ConditionOperator::Regex, '(?i)bot'];
        yield 'insensitive dot stays a regex' => ['.', true, ConditionOperator::Regex, '(?i).'];
        yield 'both flags do not double up' => ['(?i)^a$', true, ConditionOperator::Regex, '(?i)^a$'];
    }

    #[DataProvider('fromPatternProvider')]
    public function testFromPatternPicksTheSimplestOperator(string $pattern, bool $insensitive, ConditionOperator $operator, string $value): void
    {
        $cond = HeaderCond::fromPattern(ConditionKind::Header, 'User-Agent', $pattern, $insensitive);

        self::assertSame(ConditionKind::Header, $cond->kind);
        self::assertSame('User-Agent', $cond->name);
        self::assertSame($operator, $cond->operator);
        self::assertSame($value, $cond->value);
        self::assertFalse($cond->negate);
    }

    public function testFromPatternKeepsKindNameAndNegation(): void
    {
        foreach (['.', '^a$', '^a', 'a', '(?i)a'] as $pattern) {
            $cond = HeaderCond::fromPattern(ConditionKind::Cookie, 'session', $pattern, false, true);

            self::assertSame(ConditionKind::Cookie, $cond->kind, $pattern);
            self::assertSame('session', $cond->name, $pattern);
            self::assertTrue($cond->negate, $pattern);
        }
    }

    /**
     * @return iterable<string, array{Condition, string, bool}>
     */
    public static function toPatternProvider(): iterable
    {
        $c = static fn (ConditionOperator $op, string $value): Condition => new Condition(ConditionKind::Header, 'X', $op, $value);

        yield 'exists' => [$c(ConditionOperator::Exists, ''), '.+', false];
        yield 'exists ignores the value' => [$c(ConditionOperator::Exists, 'ignored'), '.+', false];
        yield 'equals' => [$c(ConditionOperator::Equals, 'a.b'), '^a\\.b$', false];
        yield 'equals is escaped' => [$c(ConditionOperator::Equals, 'a+b'), '^a\+b$', false];
        yield 'contains' => [$c(ConditionOperator::Contains, 'bot'), 'bot', false];
        yield 'contains is escaped' => [$c(ConditionOperator::Contains, '(x)'), '\(x\)', false];
        yield 'starts with' => [$c(ConditionOperator::StartsWith, 'Mozilla/'), '^Mozilla/', false];
        yield 'regex' => [$c(ConditionOperator::Regex, '^a.+$'), '^a.+$', false];
        yield 'regex with case flag' => [$c(ConditionOperator::Regex, '(?i)^bot'), '^bot', true];
    }

    #[DataProvider('toPatternProvider')]
    public function testToPattern(Condition $cond, string $pattern, bool $insensitive): void
    {
        self::assertSame([$pattern, $insensitive], HeaderCond::toPattern($cond));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'equals' => ['^en-US$'];
        yield 'prefix' => ['^Googlebot'];
        yield 'contains' => ['crawler'];
        yield 'exists' => ['.+'];
        yield 'regex' => ['^(a|b)+$'];
        yield 'insensitive regex' => ['(?i)bot'];
    }

    #[DataProvider('roundTripProvider')]
    public function testPatternsSurviveAConditionRoundTrip(string $pattern): void
    {
        $cond = HeaderCond::fromPattern(ConditionKind::Header, 'X', $pattern, false);
        [$back, $ci] = HeaderCond::toPattern($cond);
        $again = HeaderCond::fromPattern(ConditionKind::Header, 'X', $back, $ci);

        self::assertSame($cond->operator, $again->operator);
        self::assertSame($cond->value, $again->value);
    }
}
