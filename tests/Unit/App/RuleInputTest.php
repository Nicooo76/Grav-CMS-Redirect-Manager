<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\RuleInput;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(RuleInput::class)]
#[Group('app')]
final class RuleInputTest extends AppTestCase
{
    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string> "field:code"
     */
    private static function summary(array $issues): array
    {
        return array_map(static fn (ValidationIssue $i): string => $i->field . ':' . $i->code, $issues);
    }

    public function testOnlyPresentFieldsAreReturned(): void
    {
        $parsed = RuleInput::parse(['source' => '/a', 'priority' => '3']);

        self::assertSame(['source' => '/a', 'priority' => 3], $parsed['fields']);
        self::assertSame([], $parsed['issues']);
    }

    public function testAnEmptyBodyIsFine(): void
    {
        self::assertSame(['fields' => [], 'issues' => []], RuleInput::parse([]));
    }

    public function testReadOnlyAndUnknownKeysAreIgnored(): void
    {
        $parsed = RuleInput::parse([
            'id' => 'abc',
            'created_at' => '2020-01-01T00:00:00+00:00',
            'updated_at' => 'garbage',
            'stats' => ['total' => 5],
            'badges' => ['chain'],
            'issues' => [],
            'colour' => 'red',
            'source' => '/a',
        ]);

        self::assertSame(['source' => '/a'], $parsed['fields']);
        self::assertSame([], $parsed['issues']);
    }

    public function testWithMetaAcceptsIdAndTimestamps(): void
    {
        $parsed = RuleInput::parse([
            'id' => 'r-1',
            'created_at' => '2026-01-02T03:04:05Z',
            'updated_at' => '2026-02-03 04:05:06+01:00',
            'source' => '/a',
            'stats' => [],
        ], true);

        self::assertSame([], $parsed['issues']);
        self::assertSame('r-1', $parsed['fields']['id']);
        self::assertSame('2026-01-02T03:04:05+00:00', $parsed['fields']['created_at']);
        self::assertSame('2026-02-03T04:05:06+01:00', $parsed['fields']['updated_at']);
        self::assertArrayNotHasKey('stats', $parsed['fields']);
    }

    public function testWithMetaStillChecksTheMetaFields(): void
    {
        $parsed = RuleInput::parse(['id' => null, 'created_at' => 'yesterday-ish?', 'updated_at' => 5], true);

        self::assertSame(['id:invalid_type', 'created_at:invalid_value', 'updated_at:invalid_type'], self::summary($parsed['issues']));
        self::assertSame([], $parsed['fields']);
    }

    // ---------------------------------------------------------------- strings

    public function testStringFields(): void
    {
        $parsed = RuleInput::parse(['source' => '/a', 'target' => '/b', 'note' => 'n', 'group' => 'g']);

        self::assertSame(['source' => '/a', 'target' => '/b', 'note' => 'n', 'group' => 'g'], $parsed['fields']);
    }

    public function testNullStringsBecomeEmpty(): void
    {
        $parsed = RuleInput::parse(['target' => null, 'note' => null, 'group' => null]);

        self::assertSame(['target' => '', 'note' => '', 'group' => ''], $parsed['fields']);
        self::assertSame([], $parsed['issues']);
    }

    public function testAnIntegerGroupIsCastToString(): void
    {
        self::assertSame(['group' => '2026'], RuleInput::parse(['group' => 2026])['fields']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function badStrings(): iterable
    {
        yield 'source int' => ['source', 5];
        yield 'source array' => ['source', ['/a']];
        yield 'target bool' => ['target', true];
        yield 'note float' => ['note', 1.5];
        yield 'group array' => ['group', ['a']];
    }

    #[DataProvider('badStrings')]
    public function testWrongStringTypes(string $field, mixed $value): void
    {
        $parsed = RuleInput::parse([$field => $value]);

        self::assertSame([$field . ':invalid_type'], self::summary($parsed['issues']));
        self::assertSame([], $parsed['fields']);
    }

    // ---------------------------------------------------------------- booleans

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function goodBooleans(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string TRUE' => ['TRUE', true];
        yield 'string 1' => ['1', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'string false' => ['false', false];
        yield 'string 0' => ['0', false];
        yield 'string no' => ['no', false];
        yield 'string off' => ['off', false];
        yield 'empty string' => ['', false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
    }

    #[DataProvider('goodBooleans')]
    public function testBooleanCoercion(mixed $input, bool $expected): void
    {
        foreach (['enabled', 'case_sensitive', 'ignore_trailing_slash', 'continue', 'only_if_not_found'] as $field) {
            $parsed = RuleInput::parse([$field => $input]);

            self::assertSame([], $parsed['issues'], $field);
            self::assertSame($expected, $parsed['fields'][$field], $field);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badBooleans(): iterable
    {
        yield 'maybe' => ['maybe'];
        yield 'int 2' => [2];
        yield 'array' => [[true]];
    }

    #[DataProvider('badBooleans')]
    public function testBadBooleans(mixed $input): void
    {
        $parsed = RuleInput::parse(['enabled' => $input]);

        self::assertSame(['enabled:invalid_type'], self::summary($parsed['issues']));
        self::assertSame([], $parsed['fields']);
    }

    // ---------------------------------------------------------------- priority

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function goodPriorities(): iterable
    {
        yield 'int' => [10, 10];
        yield 'negative int' => [-4, -4];
        yield 'zero' => [0, 0];
        yield 'string' => ['25', 25];
        yield 'negative string' => ['-3', -3];
        yield 'padded string' => [' 7 ', 7];
    }

    #[DataProvider('goodPriorities')]
    public function testPriority(mixed $input, int $expected): void
    {
        $parsed = RuleInput::parse(['priority' => $input]);

        self::assertSame([], $parsed['issues']);
        self::assertSame($expected, $parsed['fields']['priority']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badPriorities(): iterable
    {
        yield 'text' => ['high'];
        yield 'float' => [1.5];
        yield 'float string' => ['1.5'];
        yield 'bool' => [true];
        yield 'null' => [null];
        yield 'too many digits' => ['1234567890'];
        yield 'array' => [[1]];
    }

    #[DataProvider('badPriorities')]
    public function testBadPriority(mixed $input): void
    {
        self::assertSame(['priority:invalid_type'], self::summary(RuleInput::parse(['priority' => $input])['issues']));
    }

    // ---------------------------------------------------------------- status

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function goodStatuses(): iterable
    {
        foreach ([200, 301, 302, 307, 308, 410, 451] as $code) {
            yield 'int ' . $code => [$code, $code];
            yield 'string ' . $code => [(string) $code, $code];
        }
        yield 'padded' => [' 301 ', 301];
    }

    #[DataProvider('goodStatuses')]
    public function testStatus(mixed $input, int $expected): void
    {
        $parsed = RuleInput::parse(['status' => $input]);

        self::assertSame([], $parsed['issues']);
        self::assertSame($expected, $parsed['fields']['status']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badStatuses(): iterable
    {
        yield 'unknown code' => [999];
        yield 'not a redirect code' => [404];
        yield 'text' => ['moved'];
        yield 'float' => [301.0];
        yield 'null' => [null];
        yield 'negative' => ['-301'];
        yield 'array' => [[301]];
    }

    #[DataProvider('badStatuses')]
    public function testStatusNotInTheEnum(mixed $input): void
    {
        $parsed = RuleInput::parse(['status' => $input]);

        self::assertSame(['status:invalid_value'], self::summary($parsed['issues']));
        self::assertStringContainsString('301', $parsed['issues'][0]->message);
        self::assertStringContainsString('451', $parsed['issues'][0]->message);
    }

    // ---------------------------------------------------------------- enums

    public function testEnumsAcceptTheirValues(): void
    {
        $parsed = RuleInput::parse([
            'match_type' => 'regex',
            'target_type' => 'page',
            'query_mode' => 'params',
            'origin' => 'suggestion',
        ]);

        self::assertSame([], $parsed['issues']);
        self::assertSame(['match_type' => 'regex', 'target_type' => 'page', 'query_mode' => 'params', 'origin' => 'suggestion'], $parsed['fields']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function badEnums(): iterable
    {
        yield 'match_type' => ['match_type', 'glob'];
        yield 'match_type case' => ['match_type', 'Exact'];
        yield 'target_type' => ['target_type', 'external'];
        yield 'query_mode' => ['query_mode', 'strict'];
        yield 'origin' => ['origin', 'api'];
        yield 'origin int' => ['origin', 1];
        yield 'match_type null' => ['match_type', null];
    }

    #[DataProvider('badEnums')]
    public function testEnumValuesOutsideTheSet(string $field, mixed $value): void
    {
        $parsed = RuleInput::parse([$field => $value]);

        self::assertSame([$field . ':invalid_value'], self::summary($parsed['issues']));
        self::assertStringContainsString($field, $parsed['issues'][0]->message);
        self::assertSame([], $parsed['fields']);
    }

    // ---------------------------------------------------------------- dates

    public function testDatesAreNormalizedToAtom(): void
    {
        $parsed = RuleInput::parse([
            'active_from' => '2026-10-01T12:00:00Z',
            'expires_at' => ' 2026-12-31 23:59:59+02:00 ',
        ]);

        self::assertSame([], $parsed['issues']);
        self::assertSame('2026-10-01T12:00:00+00:00', $parsed['fields']['active_from']);
        self::assertSame('2026-12-31T23:59:59+02:00', $parsed['fields']['expires_at']);
    }

    public function testEmptyDatesClearTheField(): void
    {
        $parsed = RuleInput::parse(['active_from' => null, 'expires_at' => '']);

        self::assertSame(['active_from' => null, 'expires_at' => null], $parsed['fields']);
        self::assertSame([], $parsed['issues']);
    }

    public function testInvalidDates(): void
    {
        $parsed = RuleInput::parse(['active_from' => 'the day after tomorrow-ish', 'expires_at' => '2026-13-45T99:00:00Z']);

        self::assertSame(['active_from:invalid_value', 'expires_at:invalid_value'], self::summary($parsed['issues']));
        self::assertSame([], $parsed['fields']);
    }

    public function testDatesOfTheWrongTypeAreTypeErrors(): void
    {
        $parsed = RuleInput::parse(['active_from' => 1_790_000_000, 'expires_at' => ['2026-01-01']]);

        self::assertSame(['active_from:invalid_type', 'expires_at:invalid_type'], self::summary($parsed['issues']));
    }

    // ---------------------------------------------------------------- lists and maps

    public function testListsPassStringsAndArraysThrough(): void
    {
        $parsed = RuleInput::parse(['tags' => 'a, b', 'query_ignore' => ['utm_*', 'ref']]);

        self::assertEquals(['tags' => 'a, b', 'query_ignore' => ['utm_*', 'ref']], $parsed['fields']);
        self::assertSame([], $parsed['issues']);
    }

    public function testListsRejectScalarsThatAreNotStrings(): void
    {
        $parsed = RuleInput::parse(['tags' => 5, 'query_ignore' => null]);

        self::assertSame(['query_ignore:invalid_type', 'tags:invalid_type'], self::summary($parsed['issues']));
    }

    public function testQueryParamsAcceptMapsAndTheStringForm(): void
    {
        self::assertSame(['query_params' => ['a' => '1']], RuleInput::parse(['query_params' => ['a' => '1']])['fields']);
        self::assertSame(['query_params' => 'a=1&b='], RuleInput::parse(['query_params' => 'a=1&b='])['fields']);
        self::assertSame(['query_params' => []], RuleInput::parse(['query_params' => null])['fields']);
    }

    public function testQueryParamsRejectOtherTypes(): void
    {
        self::assertSame(['query_params:invalid_type'], self::summary(RuleInput::parse(['query_params' => 5])['issues']));
        self::assertSame(['query_params:invalid_type'], self::summary(RuleInput::parse(['query_params' => true])['issues']));
    }

    // ---------------------------------------------------------------- conditions

    public function testConditionsPassWhenWellFormed(): void
    {
        $conditions = [
            'hosts' => ['example.org'],
            'languages' => 'de, en',
            'schemes' => ['https'],
            'rules' => [
                ['kind' => 'header', 'name' => 'X-Test', 'operator' => 'equals', 'value' => '1'],
                ['kind' => 'cookie', 'name' => 'session', 'operator' => 'exists'],
            ],
        ];
        $parsed = RuleInput::parse(['conditions' => $conditions]);

        self::assertSame([], $parsed['issues']);
        self::assertSame($conditions, $parsed['fields']['conditions']);
    }

    public function testNullConditionsClearThem(): void
    {
        self::assertSame(['conditions' => []], RuleInput::parse(['conditions' => null])['fields']);
        self::assertSame(['conditions' => []], RuleInput::parse(['conditions' => []])['fields']);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function badConditions(): iterable
    {
        yield 'not an object' => ['de', 'invalid_type', 'conditions'];
        yield 'hosts int' => [['hosts' => 5], 'invalid_type', 'conditions.hosts'];
        yield 'languages bool' => [['languages' => true], 'invalid_type', 'conditions.languages'];
        yield 'schemes int' => [['schemes' => 1], 'invalid_type', 'conditions.schemes'];
        yield 'rules not a list' => [['rules' => 'x'], 'invalid_type', 'conditions.rules'];
        yield 'rule not an object' => [['rules' => ['header']], 'invalid_type', 'conditions.rules.0'];
        yield 'second rule not an object' => [['rules' => [['kind' => 'header'], 5]], 'invalid_type', 'conditions.rules.1'];
        yield 'unknown kind' => [['rules' => [['kind' => 'query']]], 'invalid_value', 'conditions.rules.0.kind'];
        yield 'unknown operator' => [['rules' => [['kind' => 'header', 'operator' => 'like']]], 'invalid_value', 'conditions.rules.0.operator'];
    }

    #[DataProvider('badConditions')]
    public function testMalformedConditions(mixed $conditions, string $code, string $messagePart): void
    {
        $parsed = RuleInput::parse(['conditions' => $conditions]);

        self::assertSame(['conditions:' . $code], self::summary($parsed['issues']));
        self::assertStringContainsString($messagePart, $parsed['issues'][0]->message);
        self::assertSame([], $parsed['fields']);
    }

    // ---------------------------------------------------------------- misc

    public function testEveryFindingIsAnErrorIssue(): void
    {
        $parsed = RuleInput::parse(['status' => 999, 'enabled' => 'maybe']);

        self::assertCount(2, $parsed['issues']);
        foreach ($parsed['issues'] as $issue) {
            self::assertTrue($issue->isError());
        }
    }

    public function testFieldsAreCheckedIndependentlyOfEachOther(): void
    {
        $parsed = RuleInput::parse(['source' => '/ok', 'status' => 999, 'priority' => '5']);

        self::assertSame(['status:invalid_value'], self::summary($parsed['issues']));
        self::assertSame(['source' => '/ok', 'priority' => 5], $parsed['fields']);
    }

    public function testTheDocumentedFieldListCoversTheRuleFields(): void
    {
        self::assertContains('source', RuleInput::FIELDS);
        self::assertContains('conditions', RuleInput::FIELDS);
        self::assertNotContains('id', RuleInput::FIELDS);
        self::assertNotContains('created_at', RuleInput::FIELDS);
    }
}
