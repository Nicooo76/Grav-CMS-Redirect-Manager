<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\RuleQuery;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(RuleQuery::class)]
#[Group('app')]
final class RuleQueryTest extends AppTestCase
{
    public function testDefaults(): void
    {
        $q = RuleQuery::fromArray([]);

        self::assertSame('', $q->search);
        self::assertNull($q->matchType);
        self::assertNull($q->status);
        self::assertNull($q->state);
        self::assertNull($q->badge);
        self::assertNull($q->group);
        self::assertNull($q->origin);
        self::assertNull($q->tag);
        self::assertNull($q->unusedDays);
        self::assertSame('priority', $q->sort);
        self::assertSame('desc', $q->direction);
        self::assertSame(1, $q->page);
        self::assertSame(50, $q->perPage);
    }

    public function testDefaultPerPageIsConfigurable(): void
    {
        self::assertSame(25, RuleQuery::fromArray([], 25)->perPage);
        self::assertSame(10, RuleQuery::fromArray(['per_page' => '10'], 25)->perPage);
    }

    public function testAllFiltersAreRead(): void
    {
        $q = RuleQuery::fromArray([
            'q' => '  old ',
            'match_type' => 'regex',
            'status' => '410',
            'state' => 'expired',
            'badge' => 'dead_target',
            'group' => ' Shop ',
            'origin' => 'import',
            'tag' => 'seo',
            'unused_days' => '30',
            'sort' => 'source',
            'dir' => 'DESC',
            'page' => '3',
            'per_page' => '20',
        ]);

        self::assertSame('old', $q->search);
        self::assertSame('regex', $q->matchType);
        self::assertSame(410, $q->status);
        self::assertSame('expired', $q->state);
        self::assertSame('dead_target', $q->badge);
        self::assertSame('Shop', $q->group);
        self::assertSame('import', $q->origin);
        self::assertSame('seo', $q->tag);
        self::assertSame(30, $q->unusedDays);
        self::assertSame('source', $q->sort);
        self::assertSame('desc', $q->direction);
        self::assertSame(3, $q->page);
        self::assertSame(20, $q->perPage);
    }

    public function testBlankValuesCountAsAbsent(): void
    {
        $q = RuleQuery::fromArray(['q' => '  ', 'match_type' => '', 'status' => '', 'group' => '', 'sort' => '', 'dir' => '']);

        self::assertSame('', $q->search);
        self::assertNull($q->matchType);
        self::assertNull($q->status);
        self::assertNull($q->group);
        self::assertSame('priority', $q->sort);
        self::assertSame('desc', $q->direction);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directionDefaults(): iterable
    {
        yield 'priority starts high' => ['priority', 'desc'];
        yield 'hits starts high' => ['hits', 'desc'];
        yield 'last_hit starts newest' => ['last_hit', 'desc'];
        yield 'created_at starts newest' => ['created_at', 'desc'];
        yield 'updated_at starts newest' => ['updated_at', 'desc'];
        yield 'source is alphabetical' => ['source', 'asc'];
        yield 'target is alphabetical' => ['target', 'asc'];
        yield 'status starts low' => ['status', 'asc'];
    }

    #[DataProvider('directionDefaults')]
    public function testDirectionDefaultsPerSort(string $sort, string $expected): void
    {
        self::assertSame($expected, RuleQuery::fromArray(['sort' => $sort])->direction);
    }

    public function testExplicitDirectionWinsOverTheSortDefault(): void
    {
        self::assertSame('desc', RuleQuery::fromArray(['sort' => 'source', 'dir' => 'desc'])->direction);
        self::assertSame('asc', RuleQuery::fromArray(['sort' => 'hits', 'dir' => 'asc'])->direction);
    }

    public function testEverySortKeyIsAccepted(): void
    {
        foreach (RuleQuery::SORTS as $sort) {
            self::assertSame($sort, RuleQuery::fromArray(['sort' => $sort])->sort);
        }
    }

    public function testEveryStateAndBadgeIsAccepted(): void
    {
        foreach (RuleQuery::STATES as $state) {
            self::assertSame($state, RuleQuery::fromArray(['state' => $state])->state);
        }
        foreach (RuleQuery::BADGES as $badge) {
            self::assertSame($badge, RuleQuery::fromArray(['badge' => $badge])->badge);
        }
    }

    public function testPerPageIsCappedAndFloored(): void
    {
        self::assertSame(500, RuleQuery::fromArray(['per_page' => '9999'])->perPage);
        self::assertSame(500, RuleQuery::fromArray(['per_page' => '500'])->perPage);
        self::assertSame(1, RuleQuery::fromArray(['per_page' => '0'])->perPage);
        self::assertSame(1, RuleQuery::fromArray(['per_page' => '-5'])->perPage);
    }

    public function testPageIsAtLeastOne(): void
    {
        self::assertSame(1, RuleQuery::fromArray(['page' => '0'])->page);
        self::assertSame(1, RuleQuery::fromArray(['page' => '-3'])->page);
        self::assertSame(7, RuleQuery::fromArray(['page' => '7'])->page);
    }

    public function testNumericValuesMayComeAsIntegers(): void
    {
        $q = RuleQuery::fromArray(['status' => 301, 'page' => 2, 'per_page' => 5, 'unused_days' => 10]);

        self::assertSame(301, $q->status);
        self::assertSame(2, $q->page);
        self::assertSame(5, $q->perPage);
        self::assertSame(10, $q->unusedDays);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'match_type' => [['match_type' => 'glob'], 'match_type'];
        yield 'state' => [['state' => 'weird'], 'state'];
        yield 'badge' => [['badge' => 'red'], 'badge'];
        yield 'sort' => [['sort' => 'colour'], 'sort'];
        yield 'dir' => [['dir' => 'sideways'], 'dir'];
        yield 'status not numeric' => [['status' => 'abc'], 'status'];
        yield 'status decimal' => [['status' => '30.1'], 'status'];
        yield 'page not numeric' => [['page' => 'first'], 'page'];
        yield 'per_page not numeric' => [['per_page' => 'many'], 'per_page'];
        yield 'unused_days not numeric' => [['unused_days' => '3d'], 'unused_days'];
        yield 'integer too long' => [['unused_days' => '1234567890'], 'unused_days'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidValuesNameTheField(array $query, string $field): void
    {
        try {
            RuleQuery::fromArray($query);
            self::fail('Expected an InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame($field, $e->field);
            self::assertSame('invalid_value', $e->errorCode);
            self::assertSame($field, $e->issues[0]->field);
        }
    }

    public function testArrayValuesAreIgnoredLikeMissingOnes(): void
    {
        $q = RuleQuery::fromArray(['q' => ['a'], 'status' => ['301'], 'group' => ['x']]);

        self::assertSame('', $q->search);
        self::assertNull($q->status);
        self::assertNull($q->group);
    }

    public function testWithoutPagingKeepsFiltersAndSorting(): void
    {
        $q = RuleQuery::fromArray(['q' => 'x', 'status' => '302', 'tag' => 't', 'sort' => 'hits', 'dir' => 'asc', 'page' => '4', 'per_page' => '10']);
        $all = $q->withoutPaging();

        self::assertSame('x', $all->search);
        self::assertSame(302, $all->status);
        self::assertSame('t', $all->tag);
        self::assertSame('hits', $all->sort);
        self::assertSame('asc', $all->direction);
        self::assertSame(1, $all->page);
        self::assertSame(PHP_INT_MAX, $all->perPage);
    }
}
