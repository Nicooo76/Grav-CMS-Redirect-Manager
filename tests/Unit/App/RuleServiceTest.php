<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\RuleQuery;
use Grav\Plugin\RedirectManager\App\RuleService;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[CoversClass(RuleService::class)]
#[Group('app')]
final class RuleServiceTest extends AppTestCase
{
    private const OLD = '2026-09-01T00:00:00+00:00';

    private function rules(): RuleService
    {
        return $this->app->rules();
    }

    /**
     * Seeds rules with fixed timestamps (a month before the fixed clock) unless a row brings its own.
     *
     * @param array<string, mixed> ...$rows
     */
    private function seed(array ...$rows): void
    {
        $this->seedRules(array_map(static fn (array $row): array => $row + ['created_at' => self::OLD, 'updated_at' => self::OLD], $rows));
    }

    /**
     * A new app on the same storage: fresh in-memory state (stats are aggregated once per instance).
     *
     * @param array<string, mixed> $config
     */
    private function freshApp(array $config = [], ?SiteContext $site = null): void
    {
        $this->app = $this->makeApp($config, null, $site);
    }

    private function hit(string $ruleId, string $when = 'now', int $times = 1): void
    {
        $original = $this->clock->now();
        $this->clock->set($original->modify($when));
        for ($i = 0; $i < $times; ++$i) {
            $this->app->services()->hitRecorder()->record($ruleId);
        }
        $this->clock->set($original);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string> rule ids of the listed rows
     */
    private function ids(array $query = []): array
    {
        return array_map(static fn (array $row): string => $row['id'], $this->rules()->list(RuleQuery::fromArray($query))['rows']);
    }

    /**
     * @return list<string>
     */
    private function storedIds(): array
    {
        return array_map(static fn (Rule $r): string => $r->id, $this->app->services()->repository()->all());
    }

    private function stored(string $id): Rule
    {
        $rule = $this->app->services()->repository()->find($id);
        self::assertInstanceOf(Rule::class, $rule);

        return $rule;
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_map(static fn (ValidationIssue $i): string => $i->code, $issues);
    }

    /**
     * @return list<string>
     */
    private function analysisFiles(): array
    {
        $files = glob($this->app->services()->cacheDir() . '/analysis-*.php') ?: [];
        sort($files);

        return $files;
    }

    // ================================================================ list: filters

    /**
     * a: exact, shop, manual   b: wildcard, Marketing, import   c: regex   d: disabled 410   e: expired   f: scheduled
     */
    private function seedMixed(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/old-a', 'target' => '/new-a', 'group' => 'shop', 'tags' => ['seo', 'x'], 'note' => 'Legacy campaign', 'priority' => 3],
            ['id' => 'b', 'source' => '/promo/*', 'target' => '/sale/$1', 'match_type' => 'wildcard', 'status' => 302, 'group' => 'Marketing', 'tags' => ['seo'], 'origin' => 'import', 'priority' => 2],
            ['id' => 'c', 'source' => '^/blog/(\d+)$', 'target' => '/articles/$1', 'match_type' => 'regex', 'status' => 308, 'priority' => 1],
            ['id' => 'd', 'source' => '/gone', 'status' => 410, 'enabled' => false, 'priority' => 0],
            ['id' => 'e', 'source' => '/expired', 'target' => '/e-target', 'expires_at' => '2026-09-01T00:00:00+00:00', 'priority' => -1],
            ['id' => 'f', 'source' => '/future', 'target' => '/f-target', 'active_from' => '2026-10-15T00:00:00+00:00', 'priority' => -2],
        );
    }

    public function testListWithoutFiltersReturnsEveryRuleInNaturalOrder(): void
    {
        $this->seedMixed();

        $result = $this->rules()->list(RuleQuery::fromArray([]));

        self::assertSame(6, $result['total']);
        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f'], array_column($result['rows'], 'id'));
        self::assertSame(1, $result['page']);
        self::assertSame(50, $result['per_page']);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'q in source' => [['q' => 'old-a'], ['a']];
        yield 'q in target' => [['q' => '/sale'], ['b']];
        yield 'q in note, case-insensitive' => [['q' => 'LEGACY'], ['a']];
        yield 'q in group' => [['q' => 'marketing'], ['b']];
        yield 'q in tags' => [['q' => 'seo'], ['a', 'b']];
        yield 'q without match' => [['q' => 'nothing-like-this'], []];
        yield 'match_type wildcard' => [['match_type' => 'wildcard'], ['b']];
        yield 'match_type regex' => [['match_type' => 'regex'], ['c']];
        yield 'match_type exact' => [['match_type' => 'exact'], ['a', 'd', 'e', 'f']];
        yield 'status 410' => [['status' => '410'], ['d']];
        yield 'status 301' => [['status' => '301'], ['a', 'e', 'f']];
        yield 'state active' => [['state' => 'active'], ['a', 'b', 'c']];
        yield 'state disabled' => [['state' => 'disabled'], ['d']];
        yield 'state expired' => [['state' => 'expired'], ['e']];
        yield 'state scheduled' => [['state' => 'scheduled'], ['f']];
        yield 'badge disabled' => [['badge' => 'disabled'], ['d']];
        yield 'badge expired' => [['badge' => 'expired'], ['e']];
        yield 'badge scheduled' => [['badge' => 'scheduled'], ['f']];
        yield 'group is exact' => [['group' => 'shop'], ['a']];
        yield 'group is case-sensitive' => [['group' => 'Shop'], []];
        yield 'origin import' => [['origin' => 'import'], ['b']];
        yield 'origin manual' => [['origin' => 'manual'], ['a', 'c', 'd', 'e', 'f']];
        yield 'tag' => [['tag' => 'x'], ['a']];
        yield 'tag is exact' => [['tag' => 'se'], []];
        yield 'combined filters' => [['q' => 'seo', 'match_type' => 'wildcard', 'origin' => 'import'], ['b']];
    }

    /**
     * @param array<string, string> $query
     * @param list<string>          $expected
     */
    #[DataProvider('filters')]
    public function testFilters(array $query, array $expected): void
    {
        $this->seedMixed();

        self::assertSame($expected, $this->ids($query));
    }

    public function testUnusedDaysFilterListsActiveRulesWithoutRecentHits(): void
    {
        $this->seed(
            ['id' => 'used', 'source' => '/used', 'target' => '/u', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'idle', 'source' => '/idle', 'target' => '/i', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'old-hit', 'source' => '/old-hit', 'target' => '/oh', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'young', 'source' => '/young', 'target' => '/y', 'created_at' => '2026-09-25T00:00:00+00:00'],
            ['id' => 'off', 'source' => '/off', 'target' => '/o', 'created_at' => '2026-01-01T00:00:00+00:00', 'enabled' => false],
        );
        $this->hit('used', '-2 days');
        $this->hit('old-hit', '-100 days');
        $this->freshApp();

        self::assertEqualsCanonicalizing(['idle', 'old-hit'], $this->ids(['unused_days' => '30']));
        // 120 days back only the rule that was hit 100 days ago counts as used.
        self::assertEqualsCanonicalizing(['idle'], $this->ids(['unused_days' => '120']));
        // a young rule that never got a hit is unused as soon as it is older than the window
        self::assertEqualsCanonicalizing(['idle', 'old-hit', 'used', 'young'], $this->ids(['unused_days' => '1']));
    }

    // ================================================================ list: sorting

    private function seedForSorting(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/b-src', 'target' => '/z-tgt', 'status' => 302, 'priority' => 5, 'created_at' => '2026-01-03T00:00:00+00:00', 'updated_at' => '2026-03-01T00:00:00+00:00'],
            ['id' => 'r2', 'source' => '/a-src', 'target' => '/y-tgt', 'status' => 301, 'priority' => 10, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-05-01T00:00:00+00:00'],
            ['id' => 'r3', 'source' => '/C-src', 'target' => '/x-tgt', 'status' => 307, 'priority' => 1, 'created_at' => '2026-01-02T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00'],
        );
        $this->hit('r1', '-9 days', 1);
        $this->hit('r3', '-4 days', 3);
        $this->freshApp();
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function sortOrders(): iterable
    {
        yield 'priority (default direction)' => ['priority', '', ['r2', 'r1', 'r3']];
        yield 'priority desc' => ['priority', 'desc', ['r2', 'r1', 'r3']];
        yield 'priority asc' => ['priority', 'asc', ['r3', 'r1', 'r2']];
        yield 'source (default direction)' => ['source', '', ['r2', 'r1', 'r3']];
        yield 'source desc' => ['source', 'desc', ['r3', 'r1', 'r2']];
        yield 'target asc' => ['target', 'asc', ['r3', 'r2', 'r1']];
        yield 'target desc' => ['target', 'desc', ['r1', 'r2', 'r3']];
        yield 'status asc' => ['status', 'asc', ['r2', 'r1', 'r3']];
        yield 'status desc' => ['status', 'desc', ['r3', 'r1', 'r2']];
        yield 'hits (default direction)' => ['hits', '', ['r3', 'r1', 'r2']];
        yield 'hits asc' => ['hits', 'asc', ['r2', 'r1', 'r3']];
        yield 'last_hit (default direction)' => ['last_hit', '', ['r3', 'r1', 'r2']];
        yield 'last_hit asc' => ['last_hit', 'asc', ['r2', 'r1', 'r3']];
        yield 'created_at (default direction)' => ['created_at', '', ['r1', 'r3', 'r2']];
        yield 'created_at asc' => ['created_at', 'asc', ['r2', 'r3', 'r1']];
        yield 'updated_at (default direction)' => ['updated_at', '', ['r2', 'r3', 'r1']];
        yield 'updated_at asc' => ['updated_at', 'asc', ['r1', 'r3', 'r2']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sortOrders')]
    public function testSorting(string $sort, string $direction, array $expected): void
    {
        $this->seedForSorting();
        $query = ['sort' => $sort] + ($direction === '' ? [] : ['dir' => $direction]);

        self::assertSame($expected, $this->ids($query));
    }

    public function testSourceSortIgnoresCase(): void
    {
        $this->seedForSorting(); // r3 is "/C-src": after /b-src although "C" sorts before "b" bytewise

        self::assertSame(['r2', 'r1', 'r3'], $this->ids(['sort' => 'source', 'dir' => 'asc']));
    }

    private function seedForNaturalOrder(): void
    {
        $this->seed(
            ['id' => 'rx', 'source' => '^/r/(\d+)$', 'target' => '/t2/$1', 'match_type' => 'regex', 'created_at' => '2025-01-01T00:00:00+00:00'],
            ['id' => 'w', 'source' => '/w/*', 'target' => '/t1/$1', 'match_type' => 'wildcard', 'created_at' => '2025-01-01T00:00:00+00:00'],
            ['id' => 'e-new', 'source' => '/e-new', 'target' => '/n', 'created_at' => '2026-02-01T00:00:00+00:00'],
            ['id' => 'e-b', 'source' => '/e-b', 'target' => '/b', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'e-a', 'source' => '/e-a', 'target' => '/a', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'hi', 'source' => '^/hi/(\d+)$', 'target' => '/h/$1', 'match_type' => 'regex', 'priority' => 5],
        );
    }

    public function testNaturalOrderIsThePriorityThenTypeThenAgeThenId(): void
    {
        $this->seedForNaturalOrder();

        // priority desc; then exact before wildcard before regex; older first; id as the last tie breaker
        self::assertSame(['hi', 'e-a', 'e-b', 'e-new', 'w', 'rx'], $this->ids());
    }

    public function testAscendingPriorityIsTheExactReverseOfTheNaturalOrder(): void
    {
        $this->seedForNaturalOrder();

        self::assertSame(['rx', 'w', 'e-new', 'e-b', 'e-a', 'hi'], $this->ids(['sort' => 'priority', 'dir' => 'asc']));
    }

    public function testEqualValuesOfAnotherSortFallBackToTheNaturalOrderInBothDirections(): void
    {
        $this->seedForNaturalOrder(); // all 301

        $natural = ['hi', 'e-a', 'e-b', 'e-new', 'w', 'rx'];
        self::assertSame($natural, $this->ids(['sort' => 'status', 'dir' => 'asc']));
        self::assertSame($natural, $this->ids(['sort' => 'status', 'dir' => 'desc']));
        self::assertSame($natural, $this->ids(['sort' => 'hits']));
    }

    // ================================================================ list: paging and meta

    public function testPagingSlicesAndReportsTheTotal(): void
    {
        $this->seed(
            ['id' => 'p1', 'source' => '/p1', 'target' => '/t1', 'priority' => 5],
            ['id' => 'p2', 'source' => '/p2', 'target' => '/t2', 'priority' => 4],
            ['id' => 'p3', 'source' => '/p3', 'target' => '/t3', 'priority' => 3],
            ['id' => 'p4', 'source' => '/p4', 'target' => '/t4', 'priority' => 2],
            ['id' => 'p5', 'source' => '/p5', 'target' => '/t5', 'priority' => 1],
        );

        $second = $this->rules()->list(RuleQuery::fromArray(['per_page' => '2', 'page' => '2']));
        $last = $this->rules()->list(RuleQuery::fromArray(['per_page' => '2', 'page' => '3']));
        $beyond = $this->rules()->list(RuleQuery::fromArray(['per_page' => '2', 'page' => '4']));

        self::assertSame(['p3', 'p4'], array_column($second['rows'], 'id'));
        self::assertSame(5, $second['total']);
        self::assertSame(2, $second['page']);
        self::assertSame(2, $second['per_page']);
        self::assertSame(['p5'], array_column($last['rows'], 'id'));
        self::assertSame([], $beyond['rows']);
        self::assertSame(5, $beyond['total']);
    }

    public function testWithoutPagingReturnsEverythingAndTheTotalAsPageSize(): void
    {
        $this->seedMixed();

        $result = $this->rules()->list(RuleQuery::fromArray(['state' => 'active'])->withoutPaging());

        self::assertCount(3, $result['rows']);
        self::assertSame(3, $result['total']);
        self::assertSame(3, $result['per_page']);
        self::assertSame(1, $result['page']);
    }

    public function testMetaListsAllGroupsSortedNaturallyRegardlessOfFilters(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/ta', 'group' => 'beta'],
            ['id' => 'b', 'source' => '/b', 'target' => '/tb', 'group' => 'Alpha'],
            ['id' => 'c', 'source' => '/c', 'target' => '/tc', 'group' => 'group 10'],
            ['id' => 'd', 'source' => '/d', 'target' => '/td', 'group' => 'group 2'],
            ['id' => 'e', 'source' => '/e', 'target' => '/te'],
        );

        $meta = $this->rules()->list(RuleQuery::fromArray(['group' => 'beta']))['meta'];

        self::assertSame(['Alpha', 'beta', 'group 2', 'group 10'], $meta['groups']);
    }

    public function testBadgeCountsIgnoreTheBadgeFilterButNotTheOtherFilters(): void
    {
        $this->seedMixed();

        $all = $this->rules()->list(RuleQuery::fromArray([]))['meta']['counts'];
        $filtered = $this->rules()->list(RuleQuery::fromArray(['badge' => 'disabled']));
        $shop = $this->rules()->list(RuleQuery::fromArray(['group' => 'shop', 'badge' => 'expired']));

        self::assertSame(['active' => 3, 'disabled' => 1, 'expired' => 1, 'scheduled' => 1], array_filter($all));
        self::assertSame(array_filter($all), array_filter($filtered['meta']['counts']));
        self::assertSame(1, $filtered['total']);
        // group filter applies to the counts, the badge filter does not
        self::assertSame(['active' => 1], array_filter($shop['meta']['counts']));
        self::assertSame(0, $shop['total']);
        self::assertSame(array_fill_keys(RuleQuery::BADGES, 0), $this->rules()->list(RuleQuery::fromArray(['q' => 'zzz']))['meta']['counts']);
    }

    // ================================================================ enrichment

    public function testRowsCarryTheRuleFieldsStatsBadgesAndIssues(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b', 'note' => 'n']);
        $this->hit('a', '-2 days', 2);
        $this->hit('a', 'now', 1);
        $this->freshApp();

        $row = $this->rules()->list(RuleQuery::fromArray([]))['rows'][0];

        self::assertSame('/a', $row['source']);
        self::assertSame('n', $row['note']);
        self::assertSame(['total' => 3, 'last_hit' => '2026-09-29T10:00:00+00:00', 'daily' => ['2026-09-27' => 2, '2026-09-29' => 1]], $row['stats']);
        self::assertSame(['active'], $row['badges']);
        self::assertSame([], $row['issues']);
        self::assertInstanceOf(stdClass::class, $row['query_params']);
    }

    public function testARuleWithoutHitsHasEmptyStats(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);

        $stats = $this->rules()->get('a')['stats'];

        self::assertSame(0, $stats['total']);
        self::assertNull($stats['last_hit']);
        self::assertInstanceOf(stdClass::class, $stats['daily']);
    }

    public function testChainLoopAndConflictBadges(): void
    {
        $this->seed(
            ['id' => 'c1', 'source' => '/c1', 'target' => '/c2'],
            ['id' => 'c2', 'source' => '/c2', 'target' => '/c3'],
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2'],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l1'],
            ['id' => 'x1', 'source' => '/x', 'target' => '/x-one'],
            ['id' => 'x2', 'source' => '/x', 'target' => '/x-two'],
        );

        $rows = [];
        foreach ($this->rules()->list(RuleQuery::fromArray([]))['rows'] as $row) {
            $rows[$row['id']] = $row;
        }

        self::assertSame(['active', 'chain'], $rows['c1']['badges']);
        self::assertSame(['active'], $rows['c2']['badges']);
        self::assertSame(['active', 'loop'], $rows['l1']['badges']);
        self::assertSame(['active', 'loop'], $rows['l2']['badges']);
        self::assertSame(['active', 'conflict'], $rows['x1']['badges']);
        self::assertSame(['active', 'conflict'], $rows['x2']['badges']);
        self::assertContains('chain', array_column($rows['c1']['issues'], 'code'));
        self::assertContains('loop', array_column($rows['l1']['issues'], 'code'));
        self::assertEqualsCanonicalizing(['c1'], $this->ids(['badge' => 'chain']));
        self::assertEqualsCanonicalizing(['l1', 'l2'], $this->ids(['badge' => 'loop']));
        self::assertEqualsCanonicalizing(['x1', 'x2'], $this->ids(['badge' => 'conflict']));
    }

    public function testIdenticalRulesCountAsConflictBadge(): void
    {
        $this->seed(
            ['id' => 'd1', 'source' => '/dup', 'target' => '/same'],
            ['id' => 'd2', 'source' => '/dup', 'target' => '/same'],
        );

        $row = $this->rules()->get('d2');

        self::assertContains('conflict', $row['badges']);
        self::assertContains('duplicate', array_column($row['issues'], 'code'));
    }

    public function testExpiredRulesShowTheExpiredBadgeAndIssue(): void
    {
        $this->seedMixed();

        $row = $this->rules()->get('e');

        self::assertSame(['expired'], $row['badges']);
        self::assertContains('expired', array_column($row['issues'], 'code'));
        self::assertSame(['disabled'], $this->rules()->get('d')['badges']);
        self::assertSame(['scheduled'], $this->rules()->get('f')['badges']);
    }

    private function saveCheck(string $ruleId, ?int $status, ?string $error = null, string $checkedAt = '2026-09-29T09:00:00+00:00'): void
    {
        $this->app->services()->checkResultStore()->save([
            new TargetCheckResult($ruleId, 'http://localhost:8080/x', $status, $status !== null && $status < 400 && $error === null, $error, null, 0, 12, new DateTimeImmutable($checkedAt)),
        ]);
    }

    public function testADeadTargetResultAddsTheBadge(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b'], ['id' => 'b', 'source' => '/c', 'target' => '/d']);
        $this->saveCheck('a', 404);

        self::assertSame(['active', 'dead_target'], $this->rules()->get('a')['badges']);
        self::assertSame(['active'], $this->rules()->get('b')['badges']);
        self::assertSame(['a'], $this->ids(['badge' => 'dead_target']));
    }

    public function testUnreachableTargetsAreDeadButSkippedChecksAreNot(): void
    {
        $this->seed(
            ['id' => 'timeout', 'source' => '/a', 'target' => 'https://ext.example/a', 'target_type' => 'url'],
            ['id' => 'skipped', 'source' => '/b', 'target' => 'https://ext.example/b', 'target_type' => 'url'],
            ['id' => 'fine', 'source' => '/c', 'target' => '/c-target'],
        );
        $this->saveCheck('timeout', null, TargetCheckResult::ERROR_TIMEOUT);
        $this->saveCheck('skipped', null, TargetCheckResult::ERROR_SKIPPED_EXTERNAL);
        $this->saveCheck('fine', 200);

        self::assertSame(['timeout'], $this->ids(['badge' => 'dead_target']));
    }

    public function testADeadResultOlderThanTheLastEditIsIgnored(): void
    {
        $this->seed(['id' => 'edited', 'source' => '/a', 'target' => '/b', 'updated_at' => '2026-09-29T09:30:00+00:00']);
        $this->saveCheck('edited', 404, checkedAt: '2026-09-29T09:00:00+00:00');

        self::assertSame(['active'], $this->rules()->get('edited')['badges']);
    }

    public function testDisabledRulesAndUnknownIdsNeverCountAsDead(): void
    {
        $this->seed(['id' => 'off', 'source' => '/a', 'target' => '/b', 'enabled' => false]);
        $this->saveCheck('off', 404);
        $this->saveCheck('ghost', 404);

        self::assertSame(['disabled'], $this->rules()->get('off')['badges']);
        self::assertSame([], $this->ids(['badge' => 'dead_target']));
    }

    public function testUnusedBadgeFollowsTheConfiguredThreshold(): void
    {
        $this->seed(
            ['id' => 'idle', 'source' => '/idle', 'target' => '/i', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'young', 'source' => '/young', 'target' => '/y', 'created_at' => '2026-09-10T00:00:00+00:00'],
            ['id' => 'hit', 'source' => '/hit', 'target' => '/h', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'off', 'source' => '/off', 'target' => '/o', 'created_at' => '2026-01-01T00:00:00+00:00', 'enabled' => false],
        );
        $this->hit('hit', '-3 days');
        $this->freshApp();

        // default: 180 days
        self::assertSame(['idle'], $this->ids(['badge' => 'unused']));
        self::assertSame(['active', 'unused'], $this->rules()->get('idle')['badges']);

        $this->freshApp(['stats' => ['unused_days' => 10]]);
        self::assertEqualsCanonicalizing(['idle', 'young'], $this->ids(['badge' => 'unused']));
    }

    // ================================================================ get, etag, groups

    public function testGetReturnsTheEnrichedRule(): void
    {
        $this->seedMixed();

        $row = $this->rules()->get('b');

        self::assertSame('/promo/*', $row['source']);
        self::assertSame('Marketing', $row['group']);
        self::assertArrayHasKey('stats', $row);
        self::assertArrayHasKey('badges', $row);
        self::assertArrayHasKey('issues', $row);
    }

    public function testGetAndFindOfAnUnknownIdAreNotFound(): void
    {
        foreach (['get', 'find'] as $method) {
            try {
                $this->rules()->{$method}('missing');
                self::fail('Expected ResourceNotFoundException from ' . $method);
            } catch (ResourceNotFoundException $e) {
                self::assertSame('rule', $e->kind);
                self::assertSame('missing', $e->id);
                self::assertSame('Rule "missing" was not found.', $e->getMessage());
            }
        }
    }

    public function testEtagIsStableUntilTheRuleChanges(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $before = $this->rules()->find('a');

        self::assertSame($this->rules()->etag($before), $this->rules()->etag($this->rules()->find('a')));

        $this->rules()->update('a', ['note' => 'changed']);

        self::assertNotSame($this->rules()->etag($before), $this->rules()->etag($this->rules()->find('a')));
    }

    public function testEtagIncludesTheUpdateTimestamp(): void
    {
        $rule = Rule::fromArray(['id' => 'a', 'source' => '/a', 'target' => '/b', 'updated_at' => self::OLD]);

        self::assertNotSame(
            $this->rules()->etag($rule),
            $this->rules()->etag($rule->with(['updated_at' => '2026-09-02T00:00:00+00:00'])),
        );
    }

    public function testGroupsAndTagsAreCountedAndSorted(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/ta', 'group' => 'shop', 'tags' => ['seo', 'x']],
            ['id' => 'b', 'source' => '/b', 'target' => '/tb', 'group' => 'shop', 'tags' => ['seo']],
            ['id' => 'c', 'source' => '/c', 'target' => '/tc', 'group' => 'blog', 'tags' => ['Alpha']],
            ['id' => 'd', 'source' => '/d', 'target' => '/td'],
        );

        $result = $this->rules()->groups();

        self::assertSame([['name' => 'shop', 'count' => 2], ['name' => 'blog', 'count' => 1]], $result['groups']);
        self::assertSame([['name' => 'seo', 'count' => 2], ['name' => 'Alpha', 'count' => 1], ['name' => 'x', 'count' => 1]], $result['tags']);
    }

    public function testGroupsOfNoRulesAreEmpty(): void
    {
        self::assertSame(['groups' => [], 'tags' => []], $this->rules()->groups());
    }

    // ================================================================ create

    public function testCreateAppliesTheDocumentedDefaults(): void
    {
        $result = $this->rules()->create(['source' => '/old', 'target' => '/new']);
        $rule = $result['rule'];

        self::assertSame(301, $rule->status->value);
        self::assertSame('manual', $rule->origin->value);
        self::assertTrue($rule->enabled);
        self::assertSame(0, $rule->priority);
        self::assertNotSame('', $rule->id);
        self::assertSame('2026-09-29T10:00:00+00:00', $rule->createdAt?->format(Rule::DATE_FORMAT));
        self::assertSame('2026-09-29T10:00:00+00:00', $rule->updatedAt?->format(Rule::DATE_FORMAT));
        self::assertSame([], $result['issues']);
        self::assertEquals($rule, $this->stored($rule->id));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, int}>
     */
    public static function defaultStatuses(): iterable
    {
        yield 'no config, site default 301' => [[], 301, 301];
        yield 'site default is used' => [[], 307, 307];
        yield 'config wins over the site' => [['redirects' => ['default_status' => 302]], 307, 302];
        yield 'config 308' => [['redirects' => ['default_status' => 308]], 301, 308];
        yield 'invalid configured status falls back to 301' => [['redirects' => ['default_status' => 999]], 302, 301];
        yield 'invalid site default falls back to 301' => [[], 404, 301];
        yield 'zero in config means not configured' => [['redirects' => ['default_status' => 0]], 302, 302];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('defaultStatuses')]
    public function testCreateDefaultStatus(array $config, int $siteCode, int $expected): void
    {
        $this->freshApp($config, new SiteContext(redirectDefaultCode: $siteCode));

        $rule = $this->rules()->create(['source' => '/old', 'target' => '/new'])['rule'];

        self::assertSame($expected, $rule->status->value);
        self::assertSame($expected, $this->rules()->defaultStatus()->value);
    }

    public function testAnExplicitStatusBeatsTheDefault(): void
    {
        $this->freshApp(['redirects' => ['default_status' => 302]]);

        self::assertSame(308, $this->rules()->create(['source' => '/a', 'target' => '/b', 'status' => 308])['rule']->status->value);
    }

    public function testCreateFiresTheSavedEvent(): void
    {
        $rule = $this->rules()->create(['source' => '/old', 'target' => '/new'])['rule'];

        self::assertSame(['onRedirectRuleSaved'], $this->eventNames());
        $payload = $this->events[0]['payload'];
        self::assertSame('create', $payload['action']);
        self::assertNull($payload['previous']);
        self::assertInstanceOf(Rule::class, $payload['rule']);
        self::assertSame($rule->id, $payload['rule']->id);
    }

    public function testCreateInvalidatesTheCompiledCacheSoTheMatcherSeesTheRule(): void
    {
        $cache = $this->app->services()->compiledCache();
        self::assertSame(0, $cache->load()->count()); // warm (and cached) with no rules

        $this->rules()->create(['source' => '/old', 'target' => '/new']);

        self::assertSame(1, $cache->load()->count());
        $this->freshApp();
        $result = $this->app->services()->matcher()->match(new RequestContext(path: '/old'), MatchPhase::Early);
        self::assertNotNull($result);
        self::assertSame(301, $result->status->value);
        self::assertSame('/new', $result->location);
    }

    public function testAFailingListenerDoesNotUndoTheWrite(): void
    {
        $app = $this->makeApp();
        $throwing = new RuleService(
            $app->services(),
            $app->site(),
            $app->statsAccess(),
            new RuleEvents(static function (string $name, array $payload): array {
                throw new \RuntimeException('listener failed');
            }),
        );

        $rule = $throwing->create(['source' => '/a', 'target' => '/b'])['rule'];

        self::assertSame($rule->id, $this->stored($rule->id)->id);
    }

    public function testCreateWithErrorsStoresNothingAndCarriesTheIssues(): void
    {
        try {
            $this->rules()->create(['source' => '', 'target' => '/b']);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertSame(['source_empty'], self::codes($e->errors()));
            self::assertContainsOnlyInstancesOf(ValidationIssue::class, $e->issues);
        }

        self::assertSame([], $this->storedIds());
        self::assertSame([], $this->events);
    }

    public function testCreateRejectsInputThatIsNotARule(): void
    {
        try {
            $this->rules()->create(['source' => '/a', 'target' => '/b', 'status' => 999, 'enabled' => 'maybe']);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertSame(['status', 'enabled'], array_map(static fn (ValidationIssue $i): string => $i->field, $e->issues));
        }

        self::assertSame([], $this->storedIds());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'no target for a redirect' => [['source' => '/a'], 'target_required'];
        yield 'source is a full URL' => [['source' => 'https://example.org/a', 'target' => '/b'], 'source_invalid'];
        yield 'catastrophic regex' => [['source' => '^(a+)+$', 'match_type' => 'regex', 'target' => '/b'], 'regex_catastrophic'];
        yield 'broken regex' => [['source' => '^(a', 'match_type' => 'regex', 'target' => '/b'], 'regex_invalid'];
        yield 'protocol relative target' => [['source' => '/a', 'target' => '//evil.example/x'], 'target_protocol_relative'];
        yield 'dates inverted' => [['source' => '/a', 'target' => '/b', 'active_from' => '2026-12-01T00:00:00Z', 'expires_at' => '2026-11-01T00:00:00Z'], 'dates_inverted'];
        yield 'pass-through to an external url' => [['source' => '/a', 'target' => 'https://x.example/', 'status' => 200], 'passthrough_external'];
        yield 'self redirect' => [['source' => '/a', 'target' => '/a'], 'self_redirect'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidRules')]
    public function testCreateRefusesInvalidRules(array $body, string $code): void
    {
        try {
            $this->rules()->create($body);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertContains($code, self::codes($e->errors()));
        }

        self::assertSame([], $this->storedIds());
    }

    public function testCreateRefusesALoopAgainstStoredRules(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);

        try {
            $this->rules()->create(['source' => '/b', 'target' => '/a']);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertContains('loop', self::codes($e->errors()));
        }

        self::assertSame(['a'], $this->storedIds());
    }

    public function testCreateReturnsWarningsAndStillSaves(): void
    {
        $this->seed(['id' => 'next', 'source' => '/y', 'target' => '/z']);

        $result = $this->rules()->create(['source' => '/x', 'target' => '/y']);

        self::assertContains('chain', self::codes($result['issues']));
        foreach ($result['issues'] as $issue) {
            self::assertFalse($issue->isError());
        }
        self::assertContains($result['rule']->id, $this->storedIds());
    }

    public function testCreateWarnsAboutAConflictWithoutRefusing(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/one']);

        $result = $this->rules()->create(['source' => '/a', 'target' => '/two']);

        self::assertContains('conflict', self::codes($result['issues']));
        self::assertCount(2, $this->storedIds());
    }

    public function testCreateStoresTheGivenOptionalFields(): void
    {
        $rule = $this->rules()->create([
            'source' => '/promo/*',
            'target' => '/sale/$1',
            'match_type' => 'wildcard',
            'status' => '302',
            'priority' => '7',
            'group' => 'Campaign',
            'tags' => 'a, b',
            'note' => 'n',
            'expires_at' => '2026-12-31T00:00:00Z',
            'conditions' => ['languages' => ['de']],
        ])['rule'];

        self::assertSame('wildcard', $rule->matchType->value);
        self::assertSame(302, $rule->status->value);
        self::assertSame(7, $rule->priority);
        self::assertSame(['a', 'b'], $rule->tags);
        self::assertSame('2026-12-31T00:00:00+00:00', $rule->expiresAt?->format(Rule::DATE_FORMAT));
        self::assertSame(['de'], $rule->conditions->languages);
    }

    public function testBuildNewDoesNotStoreAnything(): void
    {
        $rule = $this->rules()->buildNew(['source' => '/a', 'target' => '/b']);

        self::assertSame('/a', $rule->source);
        self::assertSame([], $this->storedIds());
        self::assertSame([], $this->events);
    }

    // ================================================================ validate (dry run)

    public function testValidateReportsIssuesWithoutStoring(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);

        $result = $this->rules()->validate(['source' => '/b', 'target' => '/a']);

        self::assertContains('loop', self::codes($result['issues']));
        self::assertSame('/b', $result['rule']['source']);
        self::assertNull($result['preview']);
        self::assertSame(['a'], $this->storedIds());
        self::assertSame([], $this->events);
    }

    public function testValidateReturnsTheCandidateWithDefaults(): void
    {
        $result = $this->rules()->validate(['source' => '/a', 'target' => '/b']);

        self::assertSame([], $result['issues']);
        self::assertSame(301, $result['rule']['status']);
        self::assertSame('manual', $result['rule']['origin']);
    }

    public function testValidateReportsInputTypeErrorsAsIssues(): void
    {
        $result = $this->rules()->validate(['source' => '/a', 'target' => '/b', 'status' => 999, 'priority' => 'high', 'sample' => '/a']);

        self::assertSame(['invalid_value', 'invalid_type'], self::codes($result['issues']));
        self::assertSame(['status', 'priority'], array_map(static fn (ValidationIssue $i): string => $i->field, $result['issues']));
        // the unusable values fall back to defaults in the candidate; no preview for unreadable input
        self::assertSame(301, $result['rule']['status']);
        self::assertNull($result['preview']);
    }

    public function testValidateKeepsRuleIssuesNextToInputIssues(): void
    {
        $result = $this->rules()->validate(['source' => '/a', 'status' => 999]);

        // the rule itself is not checked while the input is unreadable
        self::assertSame(['invalid_value'], self::codes($result['issues']));
    }

    public function testValidatePreviewMapsTheSampleToTheRuleResult(): void
    {
        $result = $this->rules()->validate(['source' => '/promo/*', 'match_type' => 'wildcard', 'target' => '/sale/$1', 'sample' => '/promo/shoes']);

        self::assertSame('/promo/shoes', $result['preview']['sample']);
        $outcome = $result['preview']['result'];
        self::assertTrue($outcome['matched']);
        self::assertSame('matched', $outcome['reason']);
        self::assertSame(301, $outcome['status']);
        self::assertSame('/sale/shoes', $outcome['location']);
        self::assertSame(['1' => 'shoes'], (array) $outcome['captures']);
    }

    public function testValidatePreviewIsNullResultWhenNothingMatches(): void
    {
        $result = $this->rules()->validate(['source' => '/a', 'target' => '/b', 'sample' => '/somewhere-else']);

        self::assertSame(['sample' => '/somewhere-else', 'result' => null], $result['preview']);
    }

    public function testValidatePreviewShowsWhenAnotherRuleWins(): void
    {
        $this->seed(['id' => 'winner', 'source' => '/a', 'target' => '/first', 'priority' => 10]);

        $result = $this->rules()->validate(['source' => '/a', 'target' => '/second', 'sample' => '/a']);

        $outcome = $result['preview']['result'];
        self::assertFalse($outcome['matched']);
        self::assertSame('other_rule', $outcome['reason']);
        self::assertSame('/first', $outcome['location']);
        self::assertSame('winner', $outcome['rule_id']);
    }

    public function testValidatePreviewTakesTheLanguageFromTheConditionsOrTheBody(): void
    {
        $body = ['source' => '/alt', 'target' => '/neu', 'conditions' => ['languages' => ['de']], 'sample' => '/alt'];

        self::assertTrue($this->rules()->validate($body)['preview']['result']['matched']);
        // the body's language overrides the condition, so the rule no longer applies
        self::assertNull($this->rules()->validate($body + ['language' => 'en'])['preview']['result']);
    }

    public function testValidateWithAnExistingIdPatchesThatRule(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/b', 'note' => 'keep', 'priority' => 4],
            ['id' => 'r2', 'source' => '/b', 'target' => '/c'],
        );

        $result = $this->rules()->validate(['id' => 'r1', 'target' => '/other']);

        self::assertSame('r1', $result['rule']['id']);
        self::assertSame('/a', $result['rule']['source']);
        self::assertSame('/other', $result['rule']['target']);
        self::assertSame('keep', $result['rule']['note']);
        self::assertSame(4, $result['rule']['priority']);
        self::assertSame('/b', $this->stored('r1')->target);
    }

    public function testValidateTakesTheIdAsAnArgumentToo(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b'], ['id' => 'r2', 'source' => '/b', 'target' => '/c']);

        // pointing r2 back at /a would close the loop a -> b -> a
        $result = $this->rules()->validate(['target' => '/a'], 'r2');

        self::assertContains('loop', self::codes($result['issues']));
        self::assertSame('/b', $result['rule']['source']);
    }

    public function testValidateWithAnUnknownIdTreatsTheBodyAsANewRule(): void
    {
        $result = $this->rules()->validate(['id' => 'ghost', 'source' => '/a', 'target' => '/b']);

        self::assertSame('ghost', $result['rule']['id']);
        self::assertSame([], $result['issues']);
    }

    public function testValidateDoesNotReportTheRuleAgainstItself(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        self::assertSame([], $this->rules()->validate(['id' => 'r1', 'note' => 'x'])['issues']);
    }

    // ================================================================ update

    public function testUpdateChangesOnlyTheGivenFields(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b', 'note' => 'n', 'group' => 'g', 'tags' => ['t'], 'priority' => 3, 'status' => 302]);
        $before = $this->stored('r1')->toArray();

        $result = $this->rules()->update('r1', ['note' => 'new note', 'priority' => '9']);

        $after = $result['rule']->toArray();
        self::assertSame('new note', $after['note']);
        self::assertSame(9, $after['priority']);
        $changed = array_keys(array_filter($after, static fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
        sort($changed);
        self::assertSame(['note', 'priority', 'updated_at'], $changed);
        self::assertSame('2026-09-29T10:00:00+00:00', $after['updated_at']);
        self::assertSame(self::OLD, $after['created_at']);
        self::assertSame($after, $this->stored('r1')->toArray());
    }

    public function testUpdateFiresTheEventWithThePreviousRule(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        $this->rules()->update('r1', ['target' => '/c']);

        self::assertSame(['onRedirectRuleSaved'], $this->eventNames());
        $payload = $this->events[0]['payload'];
        self::assertSame('update', $payload['action']);
        self::assertSame('/b', $payload['previous']->target);
        self::assertSame('/c', $payload['rule']->target);
    }

    public function testUpdateWithoutFieldsChangesNothingAndFiresNothing(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        $result = $this->rules()->update('r1', []);

        self::assertSame(self::OLD, $result['rule']->updatedAt?->format(Rule::DATE_FORMAT));
        self::assertSame([], $this->events);
    }

    public function testUpdateWithTheSameValuesKeepsTheTimestamp(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        $result = $this->rules()->update('r1', ['target' => '/b']);

        self::assertSame(self::OLD, $result['rule']->updatedAt?->format(Rule::DATE_FORMAT));
        self::assertSame(self::OLD, $this->stored('r1')->updatedAt?->format(Rule::DATE_FORMAT));
    }

    public function testUpdateReturnsWarnings(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b'], ['id' => 'r2', 'source' => '/b', 'target' => '/c']);

        $result = $this->rules()->update('r1', ['note' => 'x']);

        self::assertContains('chain', self::codes($result['issues']));
    }

    public function testUpdateOfAnUnknownIdIsNotFound(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        try {
            $this->rules()->update('ghost', ['note' => 'x']);
            self::fail('Expected ResourceNotFoundException.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('ghost', $e->id);
        }

        self::assertSame([], $this->events);
    }

    public function testUpdateValidationErrorRollsBack(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b'], ['id' => 'r2', 'source' => '/b', 'target' => '/c']);
        $before = $this->stored('r2')->toArray();

        try {
            $this->rules()->update('r2', ['target' => '/a']); // loop
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertContains('loop', self::codes($e->errors()));
        }
        try {
            $this->rules()->update('r2', ['target' => '']);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertContains('target_required', self::codes($e->errors()));
        }

        self::assertSame($before, $this->stored('r2')->toArray());
        self::assertSame([], $this->events);
    }

    public function testUpdateWithInvalidInputTypesIsRefusedBeforeAnythingIsRead(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        try {
            $this->rules()->update('r1', ['status' => 999]);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertSame(['invalid_value'], self::codes($e->issues));
        }

        self::assertSame(301, $this->stored('r1')->status->value);
    }

    public function testUpdateIgnoresReadOnlyKeys(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        $rule = $this->rules()->update('r1', ['id' => 'hijack', 'created_at' => '2000-01-01T00:00:00Z', 'stats' => [], 'note' => 'x'])['rule'];

        self::assertSame('r1', $rule->id);
        self::assertSame(self::OLD, $rule->createdAt?->format(Rule::DATE_FORMAT));
    }

    public function testUpdateInvalidatesTheCompiledCache(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);
        self::assertSame(1, $this->app->services()->compiledCache()->load()->count());

        $this->rules()->update('r1', ['enabled' => false]);

        $this->freshApp();
        self::assertNull($this->app->services()->matcher()->match(new RequestContext(path: '/a'), MatchPhase::Early));
    }

    public function testUpdateCanClearADate(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b', 'expires_at' => '2027-01-01T00:00:00+00:00']);

        $rule = $this->rules()->update('r1', ['expires_at' => null])['rule'];

        self::assertNull($rule->expiresAt);
    }

    // ================================================================ delete, restore

    public function testDeleteReturnsTheRuleAndRemovesIt(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b'], ['id' => 'r2', 'source' => '/c', 'target' => '/d']);

        $deleted = $this->rules()->delete('r1');

        self::assertSame('r1', $deleted->id);
        self::assertSame('/a', $deleted->source);
        self::assertSame(['r2'], $this->storedIds());
        self::assertSame(['onRedirectRuleSaved'], $this->eventNames());
        self::assertSame('delete', $this->events[0]['payload']['action']);
        self::assertSame('r1', $this->events[0]['payload']['previous']->id);
    }

    public function testDeleteOfAnUnknownIdIsNotFoundAndFiresNothing(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/b']);

        $this->expectException(ResourceNotFoundException::class);
        try {
            $this->rules()->delete('ghost');
        } finally {
            self::assertSame(['r1'], $this->storedIds());
            self::assertSame([], $this->events);
        }
    }

    public function testDeleteThenRestoreIsAnUndoCycleThatKeepsIdsAndTimestamps(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/b', 'note' => 'n', 'tags' => ['t'], 'created_at' => '2026-03-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00'],
            ['id' => 'r2', 'source' => '/c', 'target' => '/d'],
        );
        $original = $this->stored('r1')->toArray();

        $deleted = $this->rules()->delete('r1');
        $this->clock->set(new DateTimeImmutable('2026-10-05T08:00:00+00:00'));
        $restored = $this->rules()->restore([$deleted->toArray()]);

        self::assertCount(1, $restored);
        self::assertSame($original, $this->stored('r1')->toArray());
        self::assertSame($original, $restored[0]->toArray());
        self::assertEqualsCanonicalizing(['r1', 'r2'], $this->storedIds());
        self::assertSame(['onRedirectRuleSaved', 'onRedirectRuleSaved'], $this->eventNames());
        self::assertSame('create', $this->events[1]['payload']['action']);
    }

    public function testRestoreReplacesARuleWithTheSameId(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/new']);

        $this->rules()->restore([['id' => 'r1', 'source' => '/a', 'target' => '/old-version', 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z']]);

        self::assertSame(['r1'], $this->storedIds());
        self::assertSame('/old-version', $this->stored('r1')->target);
    }

    public function testRestoreDoesNotValidateAgain(): void
    {
        $this->rules()->restore([['id' => 'odd', 'source' => '/a', 'target' => '']]);

        self::assertSame(['odd'], $this->storedIds());
    }

    public function testRestoreFillsMissingTimestamps(): void
    {
        $this->rules()->restore([['id' => 'r1', 'source' => '/a', 'target' => '/b']]);

        self::assertSame('2026-09-29T10:00:00+00:00', $this->stored('r1')->createdAt?->format(Rule::DATE_FORMAT));
    }

    public function testRestoreWithADuplicateIdInTheInputIsInvalid(): void
    {
        try {
            $this->rules()->restore([
                ['id' => 'same', 'source' => '/a', 'target' => '/b'],
                ['id' => 'same', 'source' => '/c', 'target' => '/d'],
            ]);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('duplicate_id', $e->errorCode);
            self::assertSame('rules', $e->field);
        }

        self::assertSame([], $this->storedIds());
    }

    public function testRestoreOfNothingOrTooMuchIsInvalid(): void
    {
        try {
            $this->rules()->restore([]);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('required', $e->errorCode);
        }

        $rows = array_map(static fn (int $i): array => ['id' => 'r' . $i, 'source' => '/a' . $i, 'target' => '/b'], range(1, RuleService::MAX_BULK + 1));
        try {
            $this->rules()->restore($rows);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('too_many', $e->errorCode);
        }
        self::assertSame([], $this->storedIds());
    }

    public function testRestoreNamesTheRowsThatCannotBeRestored(): void
    {
        try {
            $this->rules()->restore([
                ['id' => 'ok', 'source' => '/a', 'target' => '/b'],
                ['source' => '/no-id', 'target' => '/b'],
                ['id' => 'bad-status', 'source' => '/c', 'target' => '/d', 'status' => 999],
                'not-an-object',
            ]);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame(['rules.1', 'rules.2.status', 'rules.3'], array_map(static fn (ValidationIssue $i): string => $i->field, $e->issues));
        }

        // nothing is restored when any row is wrong
        self::assertSame([], $this->storedIds());
        self::assertSame([], $this->events);
    }

    // ================================================================ bulk

    private function seedThree(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'tags' => ['keep', 'old']],
            ['id' => 'r3', 'source' => '/c', 'target' => '/tc', 'enabled' => false, 'group' => 'g1'],
        );
    }

    public function testBulkRefusesAnUnknownAction(): void
    {
        try {
            $this->rules()->bulk('explode', ['r1']);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('action', $e->field);
        }
    }

    public function testBulkNeedsIdsAndCapsTheirNumber(): void
    {
        try {
            $this->rules()->bulk('enable', []);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('required', $e->errorCode);
            self::assertSame('ids', $e->field);
        }

        $ids = array_map(static fn (int $i): string => 'id' . $i, range(1, RuleService::MAX_BULK + 1));
        try {
            $this->rules()->bulk('enable', $ids);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('too_many', $e->errorCode);
        }

        // exactly the limit is fine
        $result = $this->rules()->bulk('enable', array_slice($ids, 0, RuleService::MAX_BULK));
        self::assertSame(0, $result['affected']);
        self::assertCount(RuleService::MAX_BULK, $result['skipped']);
    }

    public function testBulkIgnoresBlankAndNonStringIds(): void
    {
        $this->seedThree();

        try {
            $this->rules()->bulk('enable', ['', 5, null]); // @phpstan-ignore argument.type
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('required', $e->errorCode);
        }
    }

    public function testBulkDisableAndEnable(): void
    {
        $this->seedThree();

        $disabled = $this->rules()->bulk('disable', ['r1', 'r2']);

        self::assertSame(2, $disabled['affected']);
        self::assertSame(['r1', 'r2'], array_map(static fn (Rule $r): string => $r->id, $disabled['rules']));
        self::assertSame([], $disabled['skipped']);
        self::assertFalse($this->stored('r1')->enabled);
        self::assertFalse($this->stored('r2')->enabled);
        self::assertSame(['onRedirectRuleSaved', 'onRedirectRuleSaved'], $this->eventNames());
        self::assertSame('update', $this->events[0]['payload']['action']);
        self::assertTrue($this->events[0]['payload']['previous']->enabled);

        $enabled = $this->rules()->bulk('enable', ['r1', 'r3']);

        self::assertSame(2, $enabled['affected']);
        self::assertTrue($this->stored('r1')->enabled);
        self::assertTrue($this->stored('r3')->enabled);
        self::assertSame('2026-09-29T10:00:00+00:00', $this->stored('r3')->updatedAt?->format(Rule::DATE_FORMAT));
    }

    public function testBulkSkipsRulesThatAlreadyHaveTheState(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('enable', ['r1', 'r2']);

        self::assertSame(0, $result['affected']);
        self::assertSame([], $result['rules']);
        self::assertSame([], $result['skipped']);
        self::assertSame([], $this->events);
        self::assertSame(self::OLD, $this->stored('r1')->updatedAt?->format(Rule::DATE_FORMAT));
    }

    public function testBulkCountsEveryIdOnlyOnce(): void
    {
        $this->seedThree();

        self::assertSame(1, $this->rules()->bulk('disable', ['r1', 'r1', 'r1'])['affected']);
    }

    public function testBulkReportsUnknownIdsAsNotFound(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('disable', ['r1', 'ghost']);

        self::assertSame(1, $result['affected']);
        self::assertSame([['id' => 'ghost', 'reason' => 'not_found', 'issues' => []]], $result['skipped']);
    }

    public function testBulkSetStatus(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('set_status', ['r1', 'r2'], 302);

        self::assertSame(2, $result['affected']);
        self::assertSame(302, $this->stored('r1')->status->value);
        self::assertSame('/ta', $this->stored('r1')->target);
        // a numeric string is accepted too
        $this->rules()->bulk('set_status', ['r1'], '308');
        self::assertSame(308, $this->stored('r1')->status->value);
    }

    public function testBulkSetStatusGoneClearsTheTarget(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('set_status', ['r1'], 410);

        self::assertSame(1, $result['affected']);
        self::assertSame(410, $this->stored('r1')->status->value);
        self::assertSame('', $this->stored('r1')->target);
        self::assertSame(451, $this->rules()->bulk('set_status', ['r2'], '451')['rules'][0]->status->value);
        self::assertSame('', $this->stored('r2')->target);
    }

    public function testBulkSetStatusBackToARedirectWithoutTargetIsSkippedWithIssues(): void
    {
        $this->seed(['id' => 'gone', 'source' => '/gone', 'status' => 410], ['id' => 'ok', 'source' => '/a', 'target' => '/b']);

        $result = $this->rules()->bulk('set_status', ['gone', 'ok'], 301);

        self::assertSame(0, $result['affected']); // "ok" is a 301 already
        self::assertCount(1, $result['skipped']);
        self::assertSame('gone', $result['skipped'][0]['id']);
        self::assertSame('invalid', $result['skipped'][0]['reason']);
        self::assertSame(['target_required'], self::codes($result['skipped'][0]['issues']));
        self::assertSame(410, $this->stored('gone')->status->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badStatusValues(): iterable
    {
        yield 'unknown code' => [999];
        yield 'null' => [null];
        yield 'text' => ['gone'];
        yield 'array' => [[301]];
        yield 'float' => [301.0];
    }

    #[DataProvider('badStatusValues')]
    public function testBulkSetStatusNeedsAStatusCode(mixed $value): void
    {
        $this->seedThree();

        try {
            $this->rules()->bulk('set_status', ['r1'], $value);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('value', $e->field);
        }

        self::assertSame(301, $this->stored('r1')->status->value);
    }

    public function testBulkEnableThatWouldCreateALoopIsSkippedWithIssues(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/b'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/a', 'enabled' => false],
            ['id' => 'r3', 'source' => '/c', 'target' => '/d', 'enabled' => false],
        );

        $result = $this->rules()->bulk('enable', ['r2', 'r3']);

        self::assertSame(1, $result['affected']);
        self::assertSame('r3', $result['rules'][0]->id);
        self::assertCount(1, $result['skipped']);
        self::assertSame('r2', $result['skipped'][0]['id']);
        self::assertSame('invalid', $result['skipped'][0]['reason']);
        self::assertContains('loop', self::codes($result['skipped'][0]['issues']));
        self::assertFalse($this->stored('r2')->enabled);
        self::assertTrue($this->stored('r3')->enabled);
        self::assertCount(1, $this->events);
    }

    public function testBulkSetGroup(): void
    {
        $this->seedThree();

        $this->rules()->bulk('set_group', ['r1', 'r2'], '  Shop ');
        self::assertSame('Shop', $this->stored('r1')->group);
        self::assertSame('Shop', $this->stored('r2')->group);

        // an empty value or null removes the group
        $this->rules()->bulk('set_group', ['r1'], '');
        $this->rules()->bulk('set_group', ['r3'], null);
        self::assertSame('', $this->stored('r1')->group);
        self::assertSame('', $this->stored('r3')->group);
    }

    public function testBulkSetGroupNeedsAString(): void
    {
        $this->seedThree();

        $this->expectException(InvalidInputException::class);
        $this->rules()->bulk('set_group', ['r1'], ['x']);
    }

    public function testBulkAddAndRemoveTag(): void
    {
        $this->seedThree();

        $added = $this->rules()->bulk('add_tag', ['r1', 'r2'], ' seo ');

        // r2 does not have the tag yet either; only a rule that already has it stays out
        self::assertSame(2, $added['affected']);
        self::assertSame(['seo'], $this->stored('r1')->tags);
        self::assertSame(['keep', 'old', 'seo'], $this->stored('r2')->tags);
        self::assertSame(0, $this->rules()->bulk('add_tag', ['r1'], 'seo')['affected']);

        $removed = $this->rules()->bulk('remove_tag', ['r2', 'r3'], 'old');

        self::assertSame(1, $removed['affected']);
        self::assertSame(['keep', 'seo'], $this->stored('r2')->tags);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function badTagValues(): iterable
    {
        yield 'add null' => ['add_tag', null];
        yield 'add blank' => ['add_tag', '   '];
        yield 'add array' => ['add_tag', ['a']];
        yield 'remove null' => ['remove_tag', null];
        yield 'remove int' => ['remove_tag', 5];
    }

    #[DataProvider('badTagValues')]
    public function testBulkTagActionsNeedATag(string $action, mixed $value): void
    {
        $this->seedThree();

        try {
            $this->rules()->bulk($action, ['r1'], $value);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('value', $e->field);
        }
    }

    public function testBulkDeleteReturnsTheDeletedRules(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('delete', ['r1', 'r3', 'ghost']);

        self::assertSame(2, $result['affected']);
        self::assertEqualsCanonicalizing(['r1', 'r3'], array_map(static fn (Rule $r): string => $r->id, $result['rules']));
        self::assertSame([['id' => 'ghost', 'reason' => 'not_found', 'issues' => []]], $result['skipped']);
        self::assertSame(['r2'], $this->storedIds());
        self::assertSame(['onRedirectRuleSaved', 'onRedirectRuleSaved'], $this->eventNames());
        self::assertSame(['delete', 'delete'], array_map(static fn (array $e): string => $e['payload']['action'], $this->events));
    }

    public function testBulkDeleteOfUnknownIdsChangesNothing(): void
    {
        $this->seedThree();

        $result = $this->rules()->bulk('delete', ['nope']);

        self::assertSame(0, $result['affected']);
        self::assertCount(1, $result['skipped']);
        self::assertSame([], $this->events);
        self::assertCount(3, $this->storedIds());
    }

    public function testBulkDeleteCanBeUndoneWithRestore(): void
    {
        $this->seedThree();
        $before = $this->stored('r2')->toArray();

        $deleted = $this->rules()->bulk('delete', ['r2'])['rules'];
        $this->rules()->restore(array_map(static fn (Rule $r): array => $r->toArray(), $deleted));

        self::assertSame($before, $this->stored('r2')->toArray());
    }

    // ================================================================ reorder

    /**
     * @param list<Rule> $rules
     *
     * @return array<string, int>
     */
    private static function priorities(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->id] = $rule->priority;
        }

        return $out;
    }

    public function testReorderHandsTheOwnPriorityValuesBackInTheNewOrder(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta', 'priority' => 5],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'priority' => 10],
            ['id' => 'r3', 'source' => '/c', 'target' => '/tc', 'priority' => 1],
        );

        $changed = $this->rules()->reorder(['r1', 'r2', 'r3']);

        // r1 moves to the front: it takes the highest value (10), r2 the next (5), r3 keeps 1
        self::assertSame(['r1' => 10, 'r2' => 5], self::priorities($changed));
        self::assertSame(['r1' => 10, 'r2' => 5, 'r3' => 1], self::priorities($this->app->services()->repository()->all()));
    }

    public function testReorderLeavesRulesOutsideTheListAlone(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta', 'priority' => 5],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'priority' => 10],
            ['id' => 'r3', 'source' => '/c', 'target' => '/tc', 'priority' => 1],
            ['id' => 'other', 'source' => '/o', 'target' => '/to', 'priority' => 7],
        );

        $this->rules()->reorder(['r1', 'r2', 'r3']);

        $stored = self::priorities($this->app->services()->repository()->all());
        self::assertSame(7, $stored['other']);
        self::assertSame(self::OLD, $this->stored('other')->updatedAt?->format(Rule::DATE_FORMAT));
        // the listed rules keep their place relative to the unlisted one: r1 (10) > other (7) > r2 (5) > r3 (1)
        self::assertGreaterThan($stored['other'], $stored['r1']);
        self::assertLessThan($stored['other'], $stored['r2']);
    }

    public function testReorderBreaksTiesByGoingOneBelowThePredecessor(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/ta'],
            ['id' => 'b', 'source' => '/b', 'target' => '/tb'],
            ['id' => 'c', 'source' => '/c', 'target' => '/tc'],
        );

        $changed = $this->rules()->reorder(['c', 'a', 'b']);

        self::assertSame(['a' => -1, 'b' => -2], self::priorities($changed));
        $stored = self::priorities($this->app->services()->repository()->all());
        self::assertSame(['a' => -1, 'b' => -2, 'c' => 0], $stored);
        self::assertSame(['c', 'a', 'b'], $this->ids());
    }

    public function testReorderWithPartialTies(): void
    {
        $this->seed(
            ['id' => 'x', 'source' => '/x', 'target' => '/tx', 'priority' => 5],
            ['id' => 'y', 'source' => '/y', 'target' => '/ty', 'priority' => 5],
            ['id' => 'z', 'source' => '/z', 'target' => '/tz', 'priority' => 1],
        );

        $this->rules()->reorder(['x', 'y', 'z']);

        self::assertSame(['x' => 5, 'y' => 4, 'z' => 1], self::priorities($this->app->services()->repository()->all()));
    }

    public function testReorderWithTheCurrentOrderChangesNothing(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta', 'priority' => 9],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'priority' => 3],
        );

        self::assertSame([], $this->rules()->reorder(['r1', 'r2']));
        self::assertSame([], $this->events);
    }

    public function testReorderFiresUpdateEventsWithThePreviousRule(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta', 'priority' => 1],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'priority' => 2],
        );

        $this->rules()->reorder(['r1', 'r2']);

        self::assertCount(2, $this->events);
        foreach ($this->events as $event) {
            self::assertSame('update', $event['payload']['action']);
            self::assertNotSame($event['payload']['previous']->priority, $event['payload']['rule']->priority);
        }
    }

    public function testReorderWithAnUnknownIdIsNotFoundAndChangesNothing(): void
    {
        $this->seed(
            ['id' => 'r1', 'source' => '/a', 'target' => '/ta', 'priority' => 1],
            ['id' => 'r2', 'source' => '/b', 'target' => '/tb', 'priority' => 2],
        );

        try {
            $this->rules()->reorder(['r1', 'ghost', 'r2']);
            self::fail('Expected ResourceNotFoundException.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('ghost', $e->id);
        }

        self::assertSame(['r1' => 1, 'r2' => 2], self::priorities($this->app->services()->repository()->all()));
    }

    public function testReorderNeedsAtLeastTwoDistinctIds(): void
    {
        $this->seed(['id' => 'r1', 'source' => '/a', 'target' => '/ta']);

        foreach ([[], ['r1'], ['r1', 'r1'], ['r1', '']] as $ids) {
            try {
                $this->rules()->reorder($ids);
                self::fail('Expected InvalidInputException.');
            } catch (InvalidInputException $e) {
                self::assertSame('ids', $e->field);
                self::assertSame('required', $e->errorCode);
            }
        }
    }

    public function testReorderCapsTheNumberOfIds(): void
    {
        $ids = array_map(static fn (int $i): string => 'id' . $i, range(1, RuleService::MAX_BULK + 1));

        try {
            $this->rules()->reorder($ids);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('too_many', $e->errorCode);
        }
    }

    // ================================================================ shorten chain

    public function testShortenChainPointsTheRuleAtTheEndOfItsChain(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/c'],
        );

        $result = $this->rules()->shortenChain('a');

        self::assertSame('/c', $result['rule']->target);
        self::assertSame('/c', $this->stored('a')->target);
        self::assertSame('/c', $this->stored('b')->target);
        self::assertSame('/b', $this->stored('b')->source);
        self::assertNotContains('chain', self::codes($result['issues']));
        self::assertSame('update', $this->events[0]['payload']['action']);
        self::assertSame('/b', $this->events[0]['payload']['previous']->target);
    }

    public function testShortenChainFollowsLongChainsToTheEnd(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/c'],
            ['id' => 'c', 'source' => '/c', 'target' => '/d'],
        );

        self::assertSame('/d', $this->rules()->shortenChain('a')['rule']->target);
        // b is the start of a (shorter) chain of its own, and still is one until it is shortened as well
        self::assertSame('/d', $this->rules()->shortenChain('b')['rule']->target);
    }

    public function testShortenChainTurnsAChainEndingInGoneIntoAGoneRule(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'status' => 410],
        );

        $rule = $this->rules()->shortenChain('a')['rule'];

        self::assertSame(410, $rule->status->value);
        self::assertSame('', $rule->target);
    }

    public function testShortenChainToAnExternalTargetSwitchesTheTargetType(): void
    {
        $this->freshApp(['security' => ['allowed_hosts' => ['ext.example']]]);
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => 'https://ext.example/landing', 'target_type' => 'url'],
        );

        $rule = $this->rules()->shortenChain('a')['rule'];

        self::assertSame('https://ext.example/landing', $rule->target);
        self::assertSame('url', $rule->targetType->value);
    }

    public function testShortenChainOfARuleThatIsNoChainIsInvalid(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/c'],
        );

        try {
            $this->rules()->shortenChain('b');
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('no_chain', $e->errorCode);
            self::assertSame('id', $e->field);
        }
        self::assertSame('/c', $this->stored('b')->target);
        self::assertSame([], $this->events);
    }

    public function testShortenChainOfAnUnknownIdIsNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->rules()->shortenChain('ghost');
    }

    // ================================================================ analysis, unused

    public function testAnalysisListsChainsLoopsConflictsExpiredAndUnused(): void
    {
        $this->seed(
            ['id' => 'c1', 'source' => '/c1', 'target' => '/c2'],
            ['id' => 'c2', 'source' => '/c2', 'target' => '/c3'],
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2'],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l1'],
            ['id' => 'x1', 'source' => '/x', 'target' => '/one'],
            ['id' => 'x2', 'source' => '/x', 'target' => '/two'],
            ['id' => 'ex', 'source' => '/ex', 'target' => '/exit', 'expires_at' => '2026-09-10T00:00:00+00:00'],
            ['id' => 'idle', 'source' => '/idle', 'target' => '/i', 'created_at' => '2026-01-01T00:00:00+00:00'],
        );

        $analysis = $this->rules()->analysis();

        self::assertSame(['chains', 'loops', 'conflicts', 'expired', 'unused'], array_keys($analysis));
        self::assertSame(['c1', 'c2'], $analysis['chains'][0]['rule_ids']);
        self::assertSame(['/c1', '/c2', '/c3'], $analysis['chains'][0]['paths']);
        self::assertSame('/c3', $analysis['chains'][0]['shortcut']);
        self::assertCount(1, $analysis['loops']);
        self::assertEqualsCanonicalizing(['l1', 'l2'], $analysis['loops'][0]['rule_ids']);
        self::assertCount(1, $analysis['conflicts']);
        self::assertEqualsCanonicalizing(['x1', 'x2'], $analysis['conflicts'][0]);
        self::assertSame(['ex'], $analysis['expired']);
        self::assertContains('idle', $analysis['unused']);
        self::assertNotContains('ex', $analysis['unused']); // an expired rule is not active, so it is not "unused"
    }

    public function testAnalysisOfAnEmptyRuleSet(): void
    {
        self::assertSame(['chains' => [], 'loops' => [], 'conflicts' => [], 'expired' => [], 'unused' => []], $this->rules()->analysis());
    }

    public function testAnalysisUsesTheConfiguredUnusedThreshold(): void
    {
        $this->seed(['id' => 'mid', 'source' => '/mid', 'target' => '/m', 'created_at' => '2026-09-10T00:00:00+00:00']);

        self::assertSame([], $this->rules()->analysis()['unused']);

        $this->freshApp(['stats' => ['unused_days' => 5]]);
        self::assertSame(['mid'], $this->rules()->analysis()['unused']);
    }

    public function testUnusedListsActiveRulesWithoutHitsInTheWindow(): void
    {
        $this->seed(
            ['id' => 'idle', 'source' => '/idle', 'target' => '/i', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'hit', 'source' => '/hit', 'target' => '/h', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'stale', 'source' => '/stale', 'target' => '/s', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'young', 'source' => '/young', 'target' => '/y', 'created_at' => '2026-09-20T00:00:00+00:00'],
            ['id' => 'off', 'source' => '/off', 'target' => '/o', 'created_at' => '2026-01-01T00:00:00+00:00', 'enabled' => false],
            ['id' => 'expired', 'source' => '/exp', 'target' => '/e', 'created_at' => '2026-01-01T00:00:00+00:00', 'expires_at' => '2026-09-01T00:00:00+00:00'],
        );
        $this->hit('hit', '-5 days');
        $this->hit('stale', '-50 days');
        $this->freshApp();

        $unused = $this->rules()->unused(30);

        self::assertContainsOnlyInstancesOf(Rule::class, $unused);
        self::assertEqualsCanonicalizing(['idle', 'stale'], array_map(static fn (Rule $r): string => $r->id, $unused));
        self::assertEqualsCanonicalizing(['idle', 'stale', 'hit', 'young'], array_map(static fn (Rule $r): string => $r->id, $this->rules()->unused(1)));
    }

    // ================================================================ createMany, checkBatch

    private static function newRule(string $source, string $target, string $id = ''): Rule
    {
        return Rule::fromArray(['id' => $id, 'source' => $source, 'target' => $target]);
    }

    public function testCreateManyAssignsIdsAndTimestampsAndFiresTheAction(): void
    {
        $result = $this->rules()->createMany([
            self::newRule('/a', '/ta', 'given-1'),
            self::newRule('/b', '/tb', 'given-1'),
        ]);

        self::assertSame([], $result['rejected']);
        self::assertCount(2, $result['created']);
        $ids = array_map(static fn (Rule $r): string => $r->id, $result['created']);
        self::assertNotContains('given-1', $ids);
        self::assertCount(2, array_unique($ids));
        self::assertEqualsCanonicalizing($ids, $this->storedIds());
        self::assertSame('2026-09-29T10:00:00+00:00', $this->stored($ids[0])->createdAt?->format(Rule::DATE_FORMAT));
        self::assertSame(['import', 'import'], array_map(static fn (array $e): string => $e['payload']['action'], $this->events));
    }

    public function testCreateManyUsesTheGivenEventAction(): void
    {
        $this->rules()->createMany([self::newRule('/a', '/b')], RuleEvents::ACTION_CREATE);

        self::assertSame('create', $this->events[0]['payload']['action']);
    }

    public function testCreateManyReportsRejectedRowsByIndexAndKeepsTheRest(): void
    {
        $result = $this->rules()->createMany([
            self::newRule('/ok-1', '/t1'),
            self::newRule('', '/t2'),
            self::newRule('/ok-2', '/t3'),
            Rule::fromArray(['source' => '/no-target']),
        ]);

        self::assertCount(2, $result['created']);
        self::assertSame([1, 3], array_column($result['rejected'], 'index'));
        self::assertSame(['source_empty'], self::codes($result['rejected'][0]['issues']));
        self::assertSame(['target_required'], self::codes($result['rejected'][1]['issues']));
        self::assertCount(2, $this->storedIds());
        self::assertCount(2, $this->events);
    }

    public function testCreateManyChecksEachRowAgainstTheStoredRulesAndTheRowsBeforeIt(): void
    {
        $this->seed(['id' => 'stored', 'source' => '/a', 'target' => '/b']);

        $result = $this->rules()->createMany([
            self::newRule('/b', '/a'),   // loop with the stored rule
            self::newRule('/c', '/d'),
            self::newRule('/d', '/c'),   // loop with the row before it
        ]);

        self::assertSame([0, 2], array_column($result['rejected'], 'index'));
        self::assertContains('loop', self::codes($result['rejected'][0]['issues']));
        self::assertContains('loop', self::codes($result['rejected'][1]['issues']));
        self::assertCount(1, $result['created']);
        self::assertSame('/c', $result['created'][0]->source);
    }

    public function testCreateManyOfNothingWritesAndFiresNothing(): void
    {
        self::assertSame(['created' => [], 'rejected' => []], $this->rules()->createMany([]));
        self::assertSame([], $this->events);
    }

    public function testCreateManyInvalidatesTheCompiledCache(): void
    {
        self::assertSame(0, $this->app->services()->compiledCache()->load()->count());

        $this->rules()->createMany([self::newRule('/a', '/b')]);

        self::assertSame(1, $this->app->services()->compiledCache()->load()->count());
    }

    /**
     * @return list<Rule> $n rules: fillers plus a pair that loops
     */
    private static function batchWithLoopPair(int $n): array
    {
        $rules = [];
        for ($i = 0; $i < $n - 2; ++$i) {
            $rules[] = self::newRule('/filler-' . $i, '/target-' . $i);
        }
        $rules[] = self::newRule('/loop-a', '/loop-b');
        $rules[] = self::newRule('/loop-b', '/loop-a');

        return $rules;
    }

    public function testRelationsAreCheckedUpToTheLimit(): void
    {
        $batch = self::batchWithLoopPair(RuleService::RELATION_CHECK_LIMIT);

        $results = $this->rules()->checkBatch($batch, []);

        self::assertCount(RuleService::RELATION_CHECK_LIMIT, $results);
        self::assertFalse($results[RuleService::RELATION_CHECK_LIMIT - 2]->hasErrors());
        self::assertTrue($results[RuleService::RELATION_CHECK_LIMIT - 1]->hasErrors());
        self::assertContains('loop', self::codes($results[RuleService::RELATION_CHECK_LIMIT - 1]->issues));
    }

    public function testAboveTheLimitOnlyTheFieldsAreChecked(): void
    {
        $batch = self::batchWithLoopPair(RuleService::RELATION_CHECK_LIMIT + 1);

        $results = $this->rules()->checkBatch($batch, []);

        foreach ($results as $result) {
            self::assertFalse($result->hasErrors());
        }
        self::assertSame([], self::codes($results[RuleService::RELATION_CHECK_LIMIT]->issues));
    }

    public function testAboveTheLimitFieldErrorsAreStillCaught(): void
    {
        $batch = self::batchWithLoopPair(RuleService::RELATION_CHECK_LIMIT + 1);
        $batch[5] = Rule::fromArray(['id' => 'x', 'source' => '/no-target']);

        $results = $this->rules()->checkBatch($batch, []);

        self::assertSame(['target_required'], self::codes($results[5]->issues));
    }

    public function testConflictsBetweenRowsAreReportedOnlyWithinTheLimit(): void
    {
        $small = [self::newRule('/same', '/one'), self::newRule('/same', '/two')];
        $big = array_merge($small, array_map(static fn (int $i): Rule => self::newRule('/f' . $i, '/t' . $i), range(1, RuleService::RELATION_CHECK_LIMIT - 1)));
        self::assertCount(RuleService::RELATION_CHECK_LIMIT + 1, $big);

        $within = $this->rules()->checkBatch($small, []);
        $above = $this->rules()->checkBatch($big, []);

        self::assertContains('conflict', self::codes($within[1]->issues));
        self::assertSame([], self::codes($above[1]->issues));
    }

    public function testCheckBatchRelatesRowsToExistingRulesAndStoresNothing(): void
    {
        $this->seed(['id' => 'stored', 'source' => '/a', 'target' => '/b']);
        $stored = $this->app->services()->repository()->all();

        $results = $this->rules()->checkBatch([self::newRule('/b', '/a'), self::newRule('/z', '/y')], $stored);

        self::assertTrue($results[0]->hasErrors());
        self::assertFalse($results[1]->hasErrors());
        self::assertSame(['stored'], $this->storedIds());
        self::assertSame([], $this->events);
    }

    public function testCheckBatchDoesNotCountRejectedRowsAsKnown(): void
    {
        // the second row would only loop against the first, which is itself invalid (no target)
        $results = $this->rules()->checkBatch([Rule::fromArray(['source' => '/a']), self::newRule('/b', '/a')], []);

        self::assertTrue($results[0]->hasErrors());
        self::assertFalse($results[1]->hasErrors());
    }

    // ================================================================ analysis cache

    /**
     * Rewrites the cached report of the only analysis file: the marker shows up in analysis() only when the file
     * is read back instead of the rules being analysed again.
     */
    private function markCachedReport(): string
    {
        $files = $this->analysisFiles();
        self::assertCount(1, $files);
        /** @var array{fingerprint: string, valid_until: int|null, report: array<string, mixed>} $data */
        $data = include $files[0];
        $data['report']['expired'] = ['cache-marker'];
        file_put_contents($files[0], '<?php return ' . var_export($data, true) . ";\n");
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($files[0], true);
        }

        return $files[0];
    }

    public function testTheAnalysisIsWrittenToTheCacheNamedAfterTheRulesRevision(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b'], ['id' => 'b', 'source' => '/b', 'target' => '/c']);
        self::assertSame([], $this->analysisFiles());

        $this->rules()->analysis();

        $revision = $this->rules()->snapshot()['revision'];
        self::assertSame([$this->app->services()->cacheDir() . '/analysis-' . $revision . '.php'], $this->analysisFiles());
    }

    public function testListingAlsoFillsTheAnalysisCache(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);

        $this->rules()->list(RuleQuery::fromArray([]));

        self::assertCount(1, $this->analysisFiles());
    }

    public function testTheCachedAnalysisIsReusedByTheNextInstance(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $this->rules()->analysis();
        $this->markCachedReport();

        $this->freshApp();

        self::assertSame(['cache-marker'], $this->rules()->analysis()['expired']);
        self::assertCount(1, $this->analysisFiles());
    }

    public function testAnInstanceAnalysesOnlyOncePerRevision(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $service = $this->rules();
        $service->analysis();
        $file = $this->markCachedReport();

        // same instance, same rules: served from memory, not even read again
        self::assertSame([], $service->analysis()['expired']);
        self::assertFileExists($file);
    }

    public function testTheCacheIsDroppedWhenTheRulesChange(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $this->rules()->analysis();
        $first = $this->analysisFiles();

        $this->rules()->create(['source' => '/c', 'target' => '/d']);
        $this->rules()->analysis();

        $second = $this->analysisFiles();
        self::assertCount(1, $second);
        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($first[0]);
    }

    public function testAnAnalysisWrittenForOtherRulesIsNotUsed(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $this->rules()->analysis();
        $this->markCachedReport();

        // a change made outside the service (another process, hand edit) changes the revision
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b'], ['id' => 'b', 'source' => '/b', 'target' => '/c']);
        $this->freshApp();

        $analysis = $this->rules()->analysis();

        self::assertSame([], $analysis['expired']);
        self::assertCount(1, $analysis['chains']);
    }

    public function testAChangedMatcherConfigurationInvalidatesTheCache(): void
    {
        $this->seed(
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/c'],
            ['id' => 'c', 'source' => '/c', 'target' => '/d'],
        );
        self::assertSame([], $this->rules()->analysis()['expired']);
        $this->markCachedReport();

        $this->freshApp(['redirects' => ['max_chain_depth' => 1]]);
        $strict = $this->rules()->analysis();

        self::assertSame([], $strict['expired']);
        $codes = array_column($this->rules()->get('a')['issues'], 'code');
        self::assertContains('chain_too_deep', $codes);
    }

    public function testACorruptCacheFileIsIgnored(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b'], ['id' => 'b', 'source' => '/b', 'target' => '/c']);
        $this->rules()->analysis();
        $files = $this->analysisFiles();
        file_put_contents($files[0], '<?php return "not an array";');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($files[0], true);
        }

        $this->freshApp();

        self::assertCount(1, $this->rules()->analysis()['chains']);
    }

    public function testAnExpiredRuleTurnsUpInTheAnalysisOnceItsTimeHasCome(): void
    {
        $this->seed(['id' => 'soon', 'source' => '/soon', 'target' => '/s', 'expires_at' => '2026-09-29T11:00:00+00:00']);
        $service = $this->rules();

        self::assertSame([], $service->analysis()['expired']);
        self::assertSame(['active'], $service->get('soon')['badges']);
        self::assertSame([], $this->ids(['badge' => 'expired']));

        $this->clock->set(new DateTimeImmutable('2026-09-29T12:00:00+00:00'));

        // the same instance and the cached file must both notice that the rule expired meanwhile
        self::assertSame(['soon'], $service->analysis()['expired']);
        self::assertSame(['expired'], $service->get('soon')['badges']);
        self::assertContains('expired', array_column($service->get('soon')['issues'], 'code'));
        self::assertSame(['soon'], $this->ids(['badge' => 'expired']));
    }

    public function testACachedAnalysisIsNotUsedAfterARuleExpired(): void
    {
        $this->seed(['id' => 'soon', 'source' => '/soon', 'target' => '/s', 'expires_at' => '2026-09-29T11:00:00+00:00']);
        $this->rules()->analysis();
        $this->markCachedReport();

        // still valid at 10:59: the marker is served
        $this->clock->set(new DateTimeImmutable('2026-09-29T10:59:00+00:00'));
        $this->freshApp();
        self::assertSame(['cache-marker'], $this->rules()->analysis()['expired']);

        // at 11:00 the file is stale by its own valid_until and the rules are analysed again
        $this->clock->set(new DateTimeImmutable('2026-09-29T11:00:00+00:00'));
        $this->freshApp();
        self::assertSame(['soon'], $this->rules()->analysis()['expired']);
    }

    public function testAScheduledRuleBecomesActiveOnceItsTimeHasCome(): void
    {
        $this->seed(['id' => 'later', 'source' => '/later', 'target' => '/l', 'active_from' => '2026-09-29T11:00:00+00:00']);
        $service = $this->rules();
        self::assertSame(['scheduled'], $service->get('later')['badges']);
        $service->analysis();

        $this->clock->set(new DateTimeImmutable('2026-09-29T11:30:00+00:00'));

        self::assertSame(['active'], $service->get('later')['badges']);
        self::assertSame(['later'], $this->ids(['state' => 'active']));
        self::assertSame([], $this->ids(['state' => 'scheduled']));
    }

    public function testRulesWithoutDatesKeepTheCacheValidForever(): void
    {
        $this->seed(['id' => 'a', 'source' => '/a', 'target' => '/b']);
        $this->rules()->analysis();
        $this->markCachedReport();

        $this->clock->set(new DateTimeImmutable('2030-01-01T00:00:00+00:00'));
        $this->freshApp();

        self::assertSame(['cache-marker'], $this->rules()->analysis()['expired']);
    }
}
