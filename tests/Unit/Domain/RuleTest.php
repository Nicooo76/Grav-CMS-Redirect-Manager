<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Domain;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\Conditions;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rule::class)]
#[Group('domain')]
final class RuleTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $rule = new Rule('a', '/old');

        self::assertSame('', $rule->target);
        self::assertSame(MatchType::Exact, $rule->matchType);
        self::assertSame(StatusCode::MovedPermanently, $rule->status);
        self::assertTrue($rule->enabled);
        self::assertSame(0, $rule->priority);
        self::assertSame(TargetType::Route, $rule->targetType);
        self::assertFalse($rule->caseSensitive);
        self::assertTrue($rule->ignoreTrailingSlash);
        self::assertSame(QueryMode::Ignore, $rule->queryMode);
        self::assertSame([], $rule->queryParams);
        self::assertSame([], $rule->queryIgnore);
        self::assertFalse($rule->continueMatching);
        self::assertFalse($rule->onlyIfNotFound);
        self::assertNull($rule->activeFrom);
        self::assertNull($rule->expiresAt);
        self::assertSame(RuleSource::Manual, $rule->origin);
        self::assertTrue($rule->conditions->isEmpty());
        self::assertNull($rule->createdAt);
        self::assertNull($rule->updatedAt);
    }

    public function testFromArrayOfEmptyDataUsesDefaultsAndGeneratesAnId(): void
    {
        $rule = Rule::fromArray([]);

        self::assertMatchesRegularExpression('/^r[0-9a-f]{22}$/', $rule->id);
        self::assertSame('', $rule->source);
        self::assertSame('', $rule->target);
        self::assertSame(MatchType::Exact, $rule->matchType);
        self::assertSame(StatusCode::MovedPermanently, $rule->status);
        self::assertTrue($rule->enabled);
        self::assertTrue($rule->ignoreTrailingSlash);
        self::assertFalse($rule->caseSensitive);
        self::assertSame(QueryMode::Ignore, $rule->queryMode);
        self::assertSame(RuleSource::Manual, $rule->origin);
        self::assertNull($rule->createdAt);
    }

    public function testGeneratedIdsDiffer(): void
    {
        self::assertNotSame(Rule::fromArray([])->id, Rule::fromArray([])->id);
    }

    public function testFullRoundTrip(): void
    {
        $data = [
            'id' => 'rule-1',
            'source' => '/blog/*',
            'target' => 'https://example.com/$1',
            'match_type' => 'wildcard',
            'status' => 308,
            'enabled' => false,
            'priority' => -5,
            'target_type' => 'url',
            'case_sensitive' => true,
            'ignore_trailing_slash' => false,
            'query_mode' => 'params',
            'query_params' => ['page' => '2', 'any' => null],
            'query_ignore' => ['utm_*', 'fbclid'],
            'continue' => true,
            'only_if_not_found' => true,
            'active_from' => '2026-01-01T00:00:00+00:00',
            'expires_at' => '2026-12-31T23:59:59+02:00',
            'note' => 'Blog moved',
            'group' => 'blog',
            'tags' => ['seo', 'move'],
            'origin' => 'import',
            'conditions' => [
                'hosts' => ['example.com'],
                'languages' => ['de'],
                'schemes' => ['https'],
                'rules' => [['kind' => 'cookie', 'name' => 'a', 'operator' => 'equals', 'value' => 'b', 'negate' => true]],
            ],
            'created_at' => '2026-01-02T03:04:05+00:00',
            'updated_at' => '2026-02-03T04:05:06+01:00',
        ];

        $rule = Rule::fromArray($data);

        self::assertSame($data, $rule->toArray());
        self::assertSame($data, Rule::fromArray($rule->toArray())->toArray());
        self::assertSame(StatusCode::PermanentRedirect, $rule->status);
        self::assertSame(MatchType::Wildcard, $rule->matchType);
        self::assertSame(TargetType::Url, $rule->targetType);
        self::assertSame(QueryMode::Params, $rule->queryMode);
        self::assertSame(RuleSource::Import, $rule->origin);
        self::assertTrue($rule->isExternalTarget());
        self::assertSame(ConditionKind::Cookie, $rule->conditions->rules[0]->kind);
    }

    public function testToArrayKeysAreSnakeCaseAndStable(): void
    {
        self::assertSame(
            [
                'id', 'source', 'target', 'match_type', 'status', 'enabled', 'priority', 'target_type', 'case_sensitive',
                'ignore_trailing_slash', 'query_mode', 'query_params', 'query_ignore', 'continue', 'only_if_not_found',
                'active_from', 'expires_at', 'note', 'group', 'tags', 'origin', 'conditions', 'created_at', 'updated_at',
            ],
            array_keys((new Rule('a', '/x'))->toArray()),
        );
        $array = (new Rule('a', '/x'))->toArray();
        self::assertNull($array['active_from']);
        self::assertNull($array['expires_at']);
        self::assertNull($array['created_at']);
        self::assertNull($array['updated_at']);
        self::assertSame(301, $array['status']);
        self::assertSame('exact', $array['match_type']);
    }

    /**
     * @return iterable<string, array{mixed, StatusCode}>
     */
    public static function statusProvider(): iterable
    {
        yield 'valid int' => [410, StatusCode::Gone];
        yield 'numeric string' => ['302', StatusCode::Found];
        yield 'float' => [307.0, StatusCode::TemporaryRedirect];
        yield 'pass through' => [200, StatusCode::PassThrough];
        yield 'unknown code' => [404, StatusCode::MovedPermanently];
        yield 'zero' => [0, StatusCode::MovedPermanently];
        yield 'non numeric' => ['gone', StatusCode::MovedPermanently];
        yield 'array' => [[410], StatusCode::MovedPermanently];
        yield 'null' => [null, StatusCode::MovedPermanently];
    }

    #[DataProvider('statusProvider')]
    public function testStatusIsCoercedOrFallsBackTo301(mixed $input, StatusCode $expected): void
    {
        self::assertSame($expected, Rule::fromArray(['status' => $input])->status);
    }

    public function testUnknownEnumValuesFallBackToDefaults(): void
    {
        $rule = Rule::fromArray([
            'match_type' => 'fuzzy',
            'target_type' => 'ftp',
            'query_mode' => 'sometimes',
            'origin' => 'robot',
        ]);

        self::assertSame(MatchType::Exact, $rule->matchType);
        self::assertSame(TargetType::Route, $rule->targetType);
        self::assertSame(QueryMode::Ignore, $rule->queryMode);
        self::assertSame(RuleSource::Manual, $rule->origin);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function boolProvider(): iterable
    {
        foreach (['1', 'true', 'TRUE', ' yes ', 'On'] as $truthy) {
            yield "string '$truthy'" => [$truthy, true];
        }
        foreach (['0', 'false', 'no', 'off', '', 'maybe'] as $falsy) {
            yield "string '$falsy'" => [$falsy, false];
        }
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'non empty array' => [['x'], true];
        yield 'empty array' => [[], false];
    }

    #[DataProvider('boolProvider')]
    public function testBooleansAreParsedLeniently(mixed $input, bool $expected): void
    {
        self::assertSame($expected, Rule::fromArray(['enabled' => $input])->enabled);
        self::assertSame($expected, Rule::fromArray(['continue' => $input])->continueMatching);
    }

    public function testMissingBooleansKeepTheirDefaults(): void
    {
        $rule = Rule::fromArray([]);

        self::assertTrue($rule->enabled);
        self::assertTrue($rule->ignoreTrailingSlash);
        self::assertFalse($rule->caseSensitive);
        self::assertFalse($rule->continueMatching);
        self::assertFalse($rule->onlyIfNotFound);
    }

    public function testScalarsAreStringifiedAndNonScalarsBecomeEmpty(): void
    {
        $rule = Rule::fromArray(['id' => 42, 'source' => 7.5, 'target' => ['x'], 'note' => new \stdClass()]);

        self::assertSame('42', $rule->id);
        self::assertSame('7.5', $rule->source);
        self::assertSame('', $rule->target);
        self::assertSame('', $rule->note);
    }

    public function testIdThatIsNotAScalarIsReplaced(): void
    {
        self::assertMatchesRegularExpression('/^r[0-9a-f]{22}$/', Rule::fromArray(['id' => ['x']])->id);
    }

    public function testPriorityIsCastToInt(): void
    {
        self::assertSame(12, Rule::fromArray(['priority' => '12'])->priority);
        self::assertSame(3, Rule::fromArray(['priority' => 3.9])->priority);
        self::assertSame(0, Rule::fromArray(['priority' => 'high'])->priority);
        self::assertSame(-2, Rule::fromArray(['priority' => '-2'])->priority);
    }

    public function testGroupIsTrimmed(): void
    {
        self::assertSame('blog', Rule::fromArray(['group' => "  blog\t"])->group);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function listProvider(): iterable
    {
        yield 'comma separated string' => ['a, b ,c', ['a', 'b', 'c']];
        yield 'string with blanks only' => ['  ', []];
        yield 'single word' => ['solo', ['solo']];
        yield 'array trimmed and deduped' => [[' a ', 'b', 'a', '', '  '], ['a', 'b']];
        yield 'non scalars dropped' => [['a', ['x'], null, 5], ['a', '5']];
        yield 'reindexed' => [[3 => 'x', 9 => 'y'], ['x', 'y']];
        yield 'not a list' => [12, []];
        yield 'null' => [null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listProvider')]
    public function testTagsAndQueryIgnoreAreNormalizedToStringLists(mixed $input, array $expected): void
    {
        self::assertSame($expected, Rule::fromArray(['tags' => $input])->tags);
        self::assertSame($expected, Rule::fromArray(['query_ignore' => $input])->queryIgnore);
    }

    /**
     * @return iterable<string, array{mixed, array<string, string|null>}>
     */
    public static function queryParamsProvider(): iterable
    {
        yield 'array' => [['a' => '1', 'b' => null], ['a' => '1', 'b' => null]];
        yield 'empty value becomes null' => [['a' => ''], ['a' => null]];
        yield 'int value is stringified' => [['n' => 5], ['n' => '5']];
        yield 'array value becomes null' => [['a' => ['x']], ['a' => null]];
        yield 'blank names skipped and names trimmed' => [['  ' => '1', ' k ' => 'v'], ['k' => 'v']];
        yield 'query string' => ['a=1&b=&c', ['a' => '1', 'b' => null, 'c' => null]];
        yield 'query string with question mark' => ['?page=2', ['page' => '2']];
        yield 'empty string' => ['', []];
        yield 'scalar' => [5, []];
        yield 'null' => [null, []];
    }

    /**
     * @param array<string, string|null> $expected
     */
    #[DataProvider('queryParamsProvider')]
    public function testQueryParams(mixed $input, array $expected): void
    {
        self::assertSame($expected, Rule::fromArray(['query_params' => $input])->queryParams);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function dateProvider(): iterable
    {
        yield 'atom' => ['2026-03-04T05:06:07+00:00', '2026-03-04T05:06:07+00:00'];
        yield 'offset is kept' => ['2026-03-04T05:06:07+02:00', '2026-03-04T05:06:07+02:00'];
        yield 'padded' => ['  2026-03-04T05:06:07+00:00  ', '2026-03-04T05:06:07+00:00'];
        yield 'timestamp is UTC' => [1_700_000_000, '2023-11-14T22:13:20+00:00'];
        yield 'immutable' => [new DateTimeImmutable('2026-01-01T00:00:00+01:00'), '2026-01-01T00:00:00+01:00'];
        yield 'garbage' => ['not a date at all', null];
        yield 'blank' => ['   ', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'array' => [['2026-01-01'], null];
        yield 'float' => [1.5, null];
    }

    #[DataProvider('dateProvider')]
    public function testDates(mixed $input, ?string $expected): void
    {
        $rule = Rule::fromArray(['active_from' => $input, 'expires_at' => $input, 'created_at' => $input, 'updated_at' => $input]);

        foreach ([$rule->activeFrom, $rule->expiresAt, $rule->createdAt, $rule->updatedAt] as $date) {
            self::assertSame($expected, $date?->format(Rule::DATE_FORMAT));
        }
    }

    public function testConditionsFromNonArrayAreEmpty(): void
    {
        self::assertTrue(Rule::fromArray(['conditions' => 'de'])->conditions->isEmpty());
        self::assertTrue(Rule::fromArray(['conditions' => null])->conditions->isEmpty());
    }

    public function testConditionsWithIntegerKeysAreCoercedToStringKeys(): void
    {
        $rule = Rule::fromArray(['conditions' => ['hosts' => 'Example.COM', 5 => 'ignored']]);

        self::assertSame(['example.com'], $rule->conditions->hosts);
    }

    public function testWithChangesOnlyTheGivenFields(): void
    {
        $original = new Rule(
            'a',
            '/old',
            '/new',
            priority: 4,
            note: 'keep',
            tags: ['t'],
            conditions: new Conditions(languages: ['de'], rules: [new Condition(ConditionKind::Header, 'X', ConditionOperator::Exists)]),
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $changed = $original->with(['target' => '/newer', 'status' => 302, 'match_type' => 'regex']);

        self::assertNotSame($original, $changed);
        self::assertSame('/new', $original->target, 'the original is immutable');
        self::assertSame('/newer', $changed->target);
        self::assertSame(StatusCode::Found, $changed->status);
        self::assertSame(MatchType::Regex, $changed->matchType);
        self::assertSame('a', $changed->id);
        self::assertSame('/old', $changed->source);
        self::assertSame(4, $changed->priority);
        self::assertSame('keep', $changed->note);
        self::assertSame(['t'], $changed->tags);
        self::assertSame($original->conditions->toArray(), $changed->conditions->toArray());
        self::assertSame('2026-01-01T00:00:00+00:00', $changed->createdAt?->format(Rule::DATE_FORMAT));
    }

    public function testWithNormalizesTheChangesLikeFromArray(): void
    {
        $changed = (new Rule('a', '/old'))->with(['enabled' => 'off', 'tags' => 'x, y', 'expires_at' => null, 'status' => 999]);

        self::assertFalse($changed->enabled);
        self::assertSame(['x', 'y'], $changed->tags);
        self::assertNull($changed->expiresAt);
        self::assertSame(StatusCode::MovedPermanently, $changed->status);
    }

    public function testWithCanClearADate(): void
    {
        $rule = new Rule('a', '/old', expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

        self::assertNull($rule->with(['expires_at' => null])->expiresAt);
    }

    public function testIsExternalTargetOnlyForUrlTargets(): void
    {
        self::assertFalse((new Rule('a', '/x', '/y'))->isExternalTarget());
        self::assertFalse((new Rule('a', '/x', '/y', targetType: TargetType::Page))->isExternalTarget());
        self::assertTrue((new Rule('a', '/x', 'https://e.com', targetType: TargetType::Url))->isExternalTarget());
    }

    public function testExpiryBoundary(): void
    {
        $now = new DateTimeImmutable('2026-06-01T12:00:00+00:00');

        self::assertFalse((new Rule('a', '/x'))->isExpired($now), 'no expiry never expires');
        self::assertFalse((new Rule('a', '/x', expiresAt: $now->modify('+1 second')))->isExpired($now));
        self::assertTrue((new Rule('a', '/x', expiresAt: $now))->isExpired($now), 'expires at the given instant');
        self::assertTrue((new Rule('a', '/x', expiresAt: $now->modify('-1 second')))->isExpired($now));
    }

    public function testScheduleBoundary(): void
    {
        $now = new DateTimeImmutable('2026-06-01T12:00:00+00:00');

        self::assertFalse((new Rule('a', '/x'))->isScheduled($now), 'no start is active from the beginning');
        self::assertTrue((new Rule('a', '/x', activeFrom: $now->modify('+1 second')))->isScheduled($now));
        self::assertFalse((new Rule('a', '/x', activeFrom: $now))->isScheduled($now), 'starts at the given instant');
        self::assertFalse((new Rule('a', '/x', activeFrom: $now->modify('-1 day')))->isScheduled($now));
    }

    public function testIsActiveCombinesEnabledScheduleAndExpiry(): void
    {
        $now = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $past = $now->modify('-1 day');
        $future = $now->modify('+1 day');

        self::assertTrue((new Rule('a', '/x'))->isActive($now));
        self::assertFalse((new Rule('a', '/x', enabled: false))->isActive($now));
        self::assertFalse((new Rule('a', '/x', activeFrom: $future))->isActive($now));
        self::assertFalse((new Rule('a', '/x', expiresAt: $past))->isActive($now));
        self::assertTrue((new Rule('a', '/x', activeFrom: $past, expiresAt: $future))->isActive($now));
        self::assertFalse((new Rule('a', '/x', enabled: false, activeFrom: $past, expiresAt: $future))->isActive($now));
        self::assertFalse((new Rule('a', '/x', activeFrom: $future, expiresAt: $past))->isActive($now), 'inverted window is never active');
    }
}
