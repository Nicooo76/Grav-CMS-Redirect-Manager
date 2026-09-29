<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Domain;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\Conditions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Condition::class)]
#[CoversClass(Conditions::class)]
#[Group('domain')]
final class ConditionsTest extends TestCase
{
    public function testConditionDefaultsFromEmptyData(): void
    {
        $c = Condition::fromArray([]);

        self::assertSame(ConditionKind::Header, $c->kind);
        self::assertSame('', $c->name);
        self::assertSame(ConditionOperator::Exists, $c->operator);
        self::assertSame('', $c->value);
        self::assertFalse($c->negate);
    }

    public function testConditionConstructorDefaults(): void
    {
        $c = new Condition(ConditionKind::Cookie, 'session', ConditionOperator::Exists);

        self::assertSame('', $c->value);
        self::assertFalse($c->negate);
    }

    public function testConditionRoundTrip(): void
    {
        $data = ['kind' => 'cookie', 'name' => 'lang', 'operator' => 'starts_with', 'value' => 'de-', 'negate' => true];

        $c = Condition::fromArray($data);

        self::assertSame(ConditionKind::Cookie, $c->kind);
        self::assertSame(ConditionOperator::StartsWith, $c->operator);
        self::assertSame($data, $c->toArray());
        self::assertSame($data, Condition::fromArray($c->toArray())->toArray());
    }

    public function testConditionCleansUpOddInput(): void
    {
        $c = Condition::fromArray(['kind' => 'body', 'name' => "  User-Agent \n", 'operator' => 'like', 'value' => ['x'], 'negate' => 'yes']);

        self::assertSame(ConditionKind::Header, $c->kind, 'unknown kind falls back to header');
        self::assertSame('User-Agent', $c->name);
        self::assertSame(ConditionOperator::Exists, $c->operator, 'unknown operator falls back to exists');
        self::assertSame('', $c->value, 'non-scalar value is dropped');
        self::assertTrue($c->negate);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function negateProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'one' => [1, true];
        yield 'zero' => [0, false];
        yield 'empty string' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('negateProvider')]
    public function testConditionNegateIsCastToBool(mixed $input, bool $expected): void
    {
        self::assertSame($expected, Condition::fromArray(['negate' => $input])->negate);
    }

    public function testConditionValueScalarsAreStringified(): void
    {
        self::assertSame('5', Condition::fromArray(['value' => 5])->value);
        self::assertSame('1', Condition::fromArray(['value' => true])->value);
    }

    public function testConditionsAreEmptyByDefault(): void
    {
        $c = new Conditions();

        self::assertTrue($c->isEmpty());
        self::assertSame(['hosts' => [], 'languages' => [], 'schemes' => [], 'rules' => []], $c->toArray());
        self::assertTrue(Conditions::fromArray([])->isEmpty());
    }

    /**
     * @return iterable<string, array{Conditions}>
     */
    public static function nonEmptyProvider(): iterable
    {
        yield 'hosts' => [new Conditions(hosts: ['a.com'])];
        yield 'languages' => [new Conditions(languages: ['de'])];
        yield 'schemes' => [new Conditions(schemes: ['http'])];
        yield 'rules' => [new Conditions(rules: [new Condition(ConditionKind::Header, 'X', ConditionOperator::Exists)])];
    }

    #[DataProvider('nonEmptyProvider')]
    public function testIsEmptyIsFalseWhenAnyGroupIsSet(Conditions $conditions): void
    {
        self::assertFalse($conditions->isEmpty());
    }

    public function testConditionsRoundTrip(): void
    {
        $data = [
            'hosts' => ['*.example.com', 'example.com'],
            'languages' => ['de', 'en'],
            'schemes' => ['https'],
            'rules' => [
                ['kind' => 'header', 'name' => 'Referer', 'operator' => 'regex', 'value' => '^https://x', 'negate' => false],
                ['kind' => 'cookie', 'name' => 'a', 'operator' => 'exists', 'value' => '', 'negate' => true],
            ],
        ];

        $c = Conditions::fromArray($data);

        self::assertCount(2, $c->rules);
        self::assertInstanceOf(Condition::class, $c->rules[1]);
        self::assertSame($data, $c->toArray());
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function listProvider(): iterable
    {
        yield 'comma string lowercased' => ['Example.COM, WWW.example.com', ['example.com', 'www.example.com']];
        yield 'whitespace separated' => ["de en\tfr\nes", ['de', 'en', 'fr', 'es']];
        yield 'mixed separators' => ['a,b c ,, d', ['a', 'b', 'c', 'd']];
        yield 'array trimmed lowercased' => [[' DE ', 'En'], ['de', 'en']];
        yield 'duplicates removed keeping the first' => [['de', 'DE', 'en', 'de'], ['de', 'en']];
        yield 'blank items dropped' => [['', '  ', 'x'], ['x']];
        yield 'non scalars dropped' => [['a', ['b'], null, new \stdClass()], ['a']];
        yield 'numbers stringified' => [[80, 8080], ['80', '8080']];
        yield 'empty string' => ['', []];
        yield 'not a list' => [5, []];
        yield 'null' => [null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listProvider')]
    public function testHostsLanguagesAndSchemesAreNormalizedLists(mixed $input, array $expected): void
    {
        $c = Conditions::fromArray(['hosts' => $input, 'languages' => $input, 'schemes' => $input]);

        self::assertSame($expected, $c->hosts);
        self::assertSame($expected, $c->languages);
        self::assertSame($expected, $c->schemes);
    }

    public function testRulesThatAreNotArraysAreSkipped(): void
    {
        $c = Conditions::fromArray(['rules' => ['string', 5, null, ['name' => 'X-Test', 'operator' => 'equals', 'value' => '1']]]);

        self::assertCount(1, $c->rules);
        self::assertSame('X-Test', $c->rules[0]->name);
    }

    public function testRulesThatAreNotAListAreIgnored(): void
    {
        self::assertSame([], Conditions::fromArray(['rules' => 'nope'])->rules);
        self::assertSame([], Conditions::fromArray(['rules' => null])->rules);
    }
}
