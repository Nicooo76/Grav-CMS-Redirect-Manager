<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Behaviour every LogStore implementation must share. Concrete classes only provide createStore(). */
#[Group('notfound')]
abstract class LogStoreContract extends TestCase
{
    use TempDirTrait;

    protected FixedClock $clock;

    protected LogStore $store;

    /** 2026-09-29 12:00:00 UTC */
    protected const NOW = 1_790_683_200;

    abstract protected function createStore(string $dir, FixedClock $clock): LogStore;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(self::at(0));
        $this->store = $this->createStore($this->makeTempDir(), $this->clock);
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->removeTempDir();
    }

    protected static function at(int $offsetSeconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . (self::NOW + $offsetSeconds)))->setTimezone(new DateTimeZone('UTC'));
    }

    protected static function day(int $days, int $seconds = 0): int
    {
        return $days * 86400 + $seconds;
    }

    protected function add(
        string $path,
        int $offset = 0,
        string $referer = '',
        UserAgentClass $class = UserAgentClass::Browser,
        ?string $language = 'de',
        string $host = 'example.org',
        string $query = '',
        ?string $ip = null,
    ): void {
        $this->store->append(new NotFoundEntry(
            self::at($offset),
            $path,
            $query,
            $referer,
            'UA/1.0',
            $class,
            $ip,
            $language,
            $host,
            'GET',
        ));
    }

    /** @param array<string, mixed> $overrides */
    protected function query(array $overrides = []): GroupQuery
    {
        $args = [
            'from' => self::at(self::day(-6)),
            'to' => self::at(self::day(0, 3600)),
            'includeBots' => true,
        ];

        return new GroupQuery(...array_merge($args, $overrides));
    }

    /**
     * @param iterable<NotFoundEntry> $entries
     *
     * @return list<NotFoundEntry>
     */
    protected static function toList(iterable $entries): array
    {
        return is_array($entries) ? array_values($entries) : iterator_to_array($entries, false);
    }

    /** @return list<string> */
    protected function pathsOf(GroupQuery $q): array
    {
        return array_map(static fn ($row): string => $row->path, $this->store->groups($q)->rows);
    }

    public function testAppendedEntryRoundTripsWithAllFields(): void
    {
        $this->store->append(new NotFoundEntry(
            self::at(5),
            '/old/über uns "quoted" \\ 日本語/😀',
            'a=1&b=%20',
            'https://ref.example/page',
            'Mozilla/5.0 (X11) AppleWebKit',
            UserAgentClass::Browser,
            '1.2.3.0',
            'de-de',
            'example.org',
            'HEAD',
        ));

        $entries = self::toList($this->store->entries(self::at(-10), self::at(10)));

        self::assertCount(1, $entries);
        $e = $entries[0];
        self::assertSame(self::NOW + 5, $e->time->getTimestamp());
        self::assertSame('UTC', $e->time->getTimezone()->getName());
        self::assertSame('/old/über uns "quoted" \\ 日本語/😀', $e->path);
        self::assertSame('a=1&b=%20', $e->query);
        self::assertSame('https://ref.example/page', $e->referer);
        self::assertSame('Mozilla/5.0 (X11) AppleWebKit', $e->userAgent);
        self::assertSame(UserAgentClass::Browser, $e->uaClass);
        self::assertSame('1.2.3.0', $e->ip);
        self::assertSame('de-de', $e->language);
        self::assertSame('example.org', $e->host);
        self::assertSame('HEAD', $e->method);
    }

    public function testEntriesAreFilteredByInclusiveRangeAndOrderedByTime(): void
    {
        $this->add('/a', self::day(-2));
        $this->add('/b', self::day(-1));
        $this->add('/c', 0);
        $this->add('/d', 60);
        $this->add('/e', self::day(1));

        $paths = array_map(
            static fn (NotFoundEntry $e): string => $e->path,
            self::toList($this->store->entries(self::at(self::day(-1)), self::at(60))),
        );

        self::assertSame(['/b', '/c', '/d'], $paths);
        self::assertSame([], self::toList($this->store->entries(self::at(self::day(10)), self::at(self::day(11)))));
    }

    public function testGroupsCountHitsAndFirstAndLastSeen(): void
    {
        $this->add('/x', self::day(-3, 10));
        $this->add('/x', self::day(-1, 30));
        $this->add('/x', self::day(-2));
        $this->add('/y', 0);

        $page = $this->store->groups($this->query());

        self::assertSame(2, $page->total);
        self::assertSame(4, $page->totals->hits);
        self::assertSame(2, $page->totals->uniquePaths);
        self::assertSame('/x', $page->rows[0]->path);
        self::assertSame(3, $page->rows[0]->hits);
        self::assertSame(self::NOW + self::day(-3, 10), $page->rows[0]->firstSeen->getTimestamp());
        self::assertSame(self::NOW + self::day(-1, 30), $page->rows[0]->lastSeen->getTimestamp());
        self::assertSame(1, $page->rows[1]->hits);
    }

    public function testGroupsOnEmptyStoreAreEmpty(): void
    {
        $page = $this->store->groups($this->query());

        self::assertSame([], $page->rows);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->totals->hits);
        self::assertCount(7, $page->totals->byDay);
    }

    public function testGroupsSortByEveryKeyAndDirection(): void
    {
        $this->add('/b', self::day(-5));
        $this->add('/b', self::day(-1));
        $this->add('/b', self::day(-1, 5));
        $this->add('/a', self::day(-4));
        $this->add('/c', self::day(-3));
        $this->add('/c', self::day(-2));

        $sorted = fn (GroupSort $s, SortDirection $d): array => $this->pathsOf(
            $this->query(['sort' => $s, 'direction' => $d]),
        );

        self::assertSame(['/b', '/c', '/a'], $sorted(GroupSort::Hits, SortDirection::Desc));
        self::assertSame(['/a', '/c', '/b'], $sorted(GroupSort::Hits, SortDirection::Asc));
        self::assertSame(['/b', '/c', '/a'], $sorted(GroupSort::Last, SortDirection::Desc));
        self::assertSame(['/a', '/c', '/b'], $sorted(GroupSort::Last, SortDirection::Asc));
        self::assertSame(['/b', '/a', '/c'], $sorted(GroupSort::First, SortDirection::Asc));
        self::assertSame(['/c', '/a', '/b'], $sorted(GroupSort::First, SortDirection::Desc));
        self::assertSame(['/a', '/b', '/c'], $sorted(GroupSort::Path, SortDirection::Asc));
        self::assertSame(['/c', '/b', '/a'], $sorted(GroupSort::Path, SortDirection::Desc));
    }

    public function testEqualSortValuesFallBackToPathAscending(): void
    {
        foreach (['/z', '/m', '/a'] as $path) {
            $this->add($path);
        }

        self::assertSame(['/a', '/m', '/z'], $this->pathsOf($this->query()));
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            for ($n = 0; $n < $i; $n++) {
                $this->add('/p' . $i, $n);
            }
        }

        $page1 = $this->store->groups($this->query(['perPage' => 2, 'page' => 1]));
        $page2 = $this->store->groups($this->query(['perPage' => 2, 'page' => 2]));
        $page3 = $this->store->groups($this->query(['perPage' => 2, 'page' => 3]));
        $page4 = $this->store->groups($this->query(['perPage' => 2, 'page' => 4]));

        self::assertSame(['/p5', '/p4'], array_map(static fn ($r): string => $r->path, $page1->rows));
        self::assertSame(['/p3', '/p2'], array_map(static fn ($r): string => $r->path, $page2->rows));
        self::assertSame(['/p1'], array_map(static fn ($r): string => $r->path, $page3->rows));
        self::assertSame([], $page4->rows);
        foreach ([$page1, $page2, $page3, $page4] as $page) {
            self::assertSame(5, $page->total);
            self::assertSame(15, $page->totals->hits);
        }
    }

    public function testPageAndPerPageAreClamped(): void
    {
        $this->add('/a');
        $this->add('/b', 1);

        $q = $this->query(['page' => -3, 'perPage' => 0]);

        self::assertSame(1, $q->pageNumber());
        self::assertSame(1, $q->pageSize());
        self::assertCount(1, $this->store->groups($q)->rows);
        self::assertSame(GroupQuery::MAX_PER_PAGE, $this->query(['perPage' => 100000])->pageSize());
    }

    public function testTopReferersAreTopThreeByCountAndIgnoreEmpty(): void
    {
        foreach ([['https://a.example/', 4], ['https://b.example/x', 3], ['https://c.example/', 2], ['https://d.example/', 1], ['', 9]] as [$ref, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $this->add('/x', $i, $ref);
            }
        }

        $row = $this->store->groups($this->query())->rows[0];

        self::assertSame(
            ['https://a.example/' => 4, 'https://b.example/x' => 3, 'https://c.example/' => 2],
            $row->topReferers,
        );
    }

    public function testTopReferersBreakTiesByName(): void
    {
        $this->add('/x', 0, 'https://z.example/');
        $this->add('/x', 1, 'https://a.example/');

        self::assertSame(
            ['https://a.example/' => 1, 'https://z.example/' => 1],
            $this->store->groups($this->query())->rows[0]->topReferers,
        );
    }

    public function testDailyBucketsCoverTheWholeRangeWithZeros(): void
    {
        $this->add('/x', self::day(-6, 60));
        $this->add('/x', self::day(-2));
        $this->add('/x', self::day(-2, 60));
        $this->add('/x', 0);

        $daily = $this->store->groups($this->query())->rows[0]->daily;

        self::assertSame(
            [
                '2026-09-23' => 1,
                '2026-09-24' => 0,
                '2026-09-25' => 0,
                '2026-09-26' => 0,
                '2026-09-27' => 2,
                '2026-09-28' => 0,
                '2026-09-29' => 1,
            ],
            $daily,
        );
    }

    public function testTotalsByDayMatchVisibleGroupsOnly(): void
    {
        $this->add('/x', self::day(-1));
        $this->add('/x', 0);
        $this->add('/y', 0);
        $this->add('/other', 0);

        $page = $this->store->groups($this->query(['search' => '/', 'hidePaths' => ['/other' => self::at(10)]]));

        self::assertSame(3, $page->totals->hits);
        self::assertSame(2, $page->totals->uniquePaths);
        self::assertSame(1, $page->totals->byDay['2026-09-28']);
        self::assertSame(2, $page->totals->byDay['2026-09-29']);
        self::assertSame(0, $page->totals->byDay['2026-09-27']);
    }

    public function testBotFilterAndUserAgentClassFilter(): void
    {
        $this->add('/human', 0, class: UserAgentClass::Browser);
        $this->add('/bot', 1, class: UserAgentClass::Bot);
        $this->add('/monitor', 2, class: UserAgentClass::Monitoring);
        $this->add('/unknown', 3, class: UserAgentClass::Unknown);

        self::assertSame(['/bot', '/human', '/monitor', '/unknown'], $this->pathsOf($this->query(['includeBots' => true, 'sort' => GroupSort::Path, 'direction' => SortDirection::Asc])));
        self::assertSame(['/human', '/monitor', '/unknown'], $this->pathsOf($this->query(['includeBots' => false, 'sort' => GroupSort::Path, 'direction' => SortDirection::Asc])));
        self::assertSame(['/monitor'], $this->pathsOf($this->query(['uaClasses' => [UserAgentClass::Monitoring]])));
        self::assertSame(['/bot', '/human'], $this->pathsOf($this->query(['uaClasses' => [UserAgentClass::Browser, UserAgentClass::Bot], 'sort' => GroupSort::Path, 'direction' => SortDirection::Asc])));
        self::assertSame([], $this->pathsOf($this->query(['includeBots' => false, 'uaClasses' => [UserAgentClass::Bot]])));
    }

    public function testSearchIsCaseInsensitiveSubstringOfThePath(): void
    {
        $this->add('/Blog/Über-uns');
        $this->add('/blog/other', 1);
        $this->add('/shop', 2);
        $this->add('/shop', 3, query: 'blog=1');

        self::assertSame(['/Blog/Über-uns', '/blog/other'], $this->pathsOf($this->query(['search' => 'BLOG', 'sort' => GroupSort::Path, 'direction' => SortDirection::Asc])));
        self::assertSame(['/Blog/Über-uns'], $this->pathsOf($this->query(['search' => 'über'])));
        self::assertSame([], $this->pathsOf($this->query(['search' => 'nothing'])));
    }

    public function testLanguageAndHostFilters(): void
    {
        $this->add('/de', 0, language: 'de', host: 'a.example');
        $this->add('/en', 1, language: 'en', host: 'a.example');
        $this->add('/none', 2, language: null, host: 'b.example');

        self::assertSame(['/de'], $this->pathsOf($this->query(['language' => 'de'])));
        self::assertSame(['/en'], $this->pathsOf($this->query(['language' => 'en'])));
        self::assertSame(['/none'], $this->pathsOf($this->query(['host' => 'b.example'])));
        self::assertSame(['/de'], $this->pathsOf($this->query(['host' => 'a.example', 'language' => 'de'])));
        self::assertSame([], $this->pathsOf($this->query(['host' => 'c.example'])));
    }

    public function testResolvedPathsAreHiddenUntilTheyGetNewHits(): void
    {
        $this->add('/done', self::day(-2));
        $this->add('/done', self::day(-1));
        $this->add('/open', self::day(-1));

        $resolved = ['/done' => self::at(self::day(-1, 100))];

        self::assertSame(['/open'], $this->pathsOf($this->query(['hidePaths' => $resolved])));
        self::assertSame(1, $this->store->groups($this->query(['hidePaths' => $resolved]))->total);

        $this->add('/done', self::day(0, -50));

        $page = $this->store->groups($this->query(['hidePaths' => $resolved]));
        self::assertSame(['/done', '/open'], array_map(static fn ($r): string => $r->path, $page->rows));
        self::assertSame(3, $page->rows[0]->hits, 'a reappearing path shows all its hits in the range');
    }

    public function testHitExactlyAtResolvedTimeStaysHidden(): void
    {
        $this->add('/done', 100);

        self::assertSame([], $this->pathsOf($this->query(['hidePaths' => ['/done' => self::at(100)]])));
        self::assertSame(['/done'], $this->pathsOf($this->query(['hidePaths' => ['/done' => self::at(99)]])));
    }

    public function testRowDetailsUaBreakdownLanguagesHostsAndSampleQuery(): void
    {
        $this->add('/x', 0, class: UserAgentClass::Browser, language: 'de', host: 'a.example', query: 'first=1');
        $this->add('/x', 10, class: UserAgentClass::Bot, language: 'de', host: 'a.example', query: 'latest=2');
        $this->add('/x', 20, class: UserAgentClass::Browser, language: 'en', host: 'b.example');
        $this->add('/x', 30, class: UserAgentClass::Browser, language: null, host: 'a.example');

        $row = $this->store->groups($this->query())->rows[0];

        self::assertSame(['browser' => 3, 'bot' => 1], $row->uaBreakdown);
        self::assertSame(['de' => 2, 'en' => 1], $row->languages);
        self::assertSame(['a.example' => 3, 'b.example' => 1], $row->hosts);
        self::assertSame('latest=2', $row->sampleQuery);
    }

    public function testSampleQueryIsEmptyWithoutAnyQuery(): void
    {
        $this->add('/x');

        self::assertSame('', $this->store->groups($this->query())->rows[0]->sampleQuery);
    }

    public function testGroupsIgnoreEntriesOutsideTheRange(): void
    {
        $this->add('/x', self::day(-30));
        $this->add('/x', self::day(5));
        $this->add('/x', 0);

        self::assertSame(1, $this->store->groups($this->query())->rows[0]->hits);
    }

    public function testCountsByDayAreZeroFilledAndCanExcludeBots(): void
    {
        $this->add('/a', self::day(-1));
        $this->add('/b', self::day(-1, 5), class: UserAgentClass::Bot);
        $this->add('/c', 0);

        $with = $this->store->countsByDay(self::at(self::day(-2)), self::at(3600), true);
        $without = $this->store->countsByDay(self::at(self::day(-2)), self::at(3600), false);

        self::assertSame(['2026-09-27' => 0, '2026-09-28' => 2, '2026-09-29' => 1], $with);
        self::assertSame(['2026-09-27' => 0, '2026-09-28' => 1, '2026-09-29' => 1], $without);
    }

    public function testPurgeDeletesOlderEntriesOnly(): void
    {
        $this->add('/old', self::day(-40));
        $this->add('/old', self::day(-31, 10));
        $this->add('/edge', self::day(-30));
        $this->add('/new', 0);

        $removed = $this->store->purge(self::at(self::day(-30)));

        self::assertSame(2, $removed);
        $paths = array_map(static fn (NotFoundEntry $e): string => $e->path, self::toList($this->store->entries(self::at(self::day(-100)), self::at(self::day(1)))));
        self::assertSame(['/edge', '/new'], $paths);
        self::assertSame(0, $this->store->purge(self::at(self::day(-30))));
    }

    public function testPurgeInsideOneDayKeepsTheRest(): void
    {
        $this->add('/a', -100);
        $this->add('/b', -50);
        $this->add('/c', 10);

        self::assertSame(2, $this->store->purge(self::at(0)));
        self::assertSame(['/c'], array_map(static fn (NotFoundEntry $e): string => $e->path, self::toList($this->store->entries(self::at(-1000), self::at(1000)))));
    }

    public function testDeletePathRemovesOnlyThatPath(): void
    {
        $this->add('/gone', self::day(-2));
        $this->add('/gone', self::day(-1));
        $this->add('/gone', 0);
        $this->add('/gone/child', 1);
        $this->add('/GONE', 2);
        $this->add('/stay', 3);

        self::assertSame(3, $this->store->deletePath('/gone'));
        self::assertSame(0, $this->store->deletePath('/gone'));
        self::assertSame(0, $this->store->deletePath('/never-seen'));

        self::assertSame(['/GONE', '/gone/child', '/stay'], $this->pathsOf($this->query(['sort' => GroupSort::Path, 'direction' => SortDirection::Asc])));
    }

    public function testSizeBytesGrowsAndClearEmptiesTheStore(): void
    {
        $this->add('/a');
        $this->add('/b', 1);
        self::assertGreaterThan(0, $this->store->sizeBytes());

        $this->store->clear();

        self::assertSame([], self::toList($this->store->entries(self::at(self::day(-10)), self::at(self::day(10)))));
        self::assertSame(0, $this->store->groups($this->query())->total);

        $this->add('/again', 2);
        self::assertSame(['/again'], $this->pathsOf($this->query()));
    }

    public function testClearOnEmptyStoreIsHarmless(): void
    {
        $this->store->clear();

        self::assertSame(0, $this->store->purge(self::at(0)));
        self::assertSame(0, $this->store->deletePath('/x'));
    }

    public function testEntryWithIpAndNullLanguageRoundTrips(): void
    {
        $this->add('/x', 0, language: null, ip: '10.0.0.0');

        $e = self::toList($this->store->entries(self::at(-1), self::at(1)))[0];

        self::assertSame('10.0.0.0', $e->ip);
        self::assertNull($e->language);
    }
}
