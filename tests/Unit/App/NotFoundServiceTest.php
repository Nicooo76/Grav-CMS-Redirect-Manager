<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\App\NotFoundService;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[CoversClass(NotFoundService::class)]
#[Group('app')]
final class NotFoundServiceTest extends AppTestCase
{
    private function service(): NotFoundService
    {
        return $this->app->notFound();
    }

    /**
     * Logs one 404 hit at "now" plus the given modifier (e.g. "-3 days").
     */
    private function hit(
        string $path,
        string $when = '-1 hour',
        UserAgentClass $class = UserAgentClass::Browser,
        ?string $language = 'de',
        string $host = 'example.org',
        string $referer = '',
        string $query = '',
        ?string $ip = null,
    ): void {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify($when),
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

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function paths(array $query = []): array
    {
        return array_map(static fn (array $row): string => $row['path'], $this->service()->groups($query)['rows']);
    }

    // ---------------------------------------------------------------- groups

    public function testGroupsOfAnEmptyLogHaveTheDocumentedShape(): void
    {
        $result = $this->service()->groups([]);

        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(50, $result['per_page']);
        self::assertSame(0, $result['meta']['totals']['hits']);
        self::assertSame(0, $result['meta']['totals']['paths']);
        self::assertIsArray($result['meta']['totals']['by_day']);
        self::assertCount(30, $result['meta']['totals']['by_day']);
    }

    public function testRowHasAllFields(): void
    {
        $this->hit('/old-page', '-2 days', referer: 'https://a.example/x', query: 'ref=1', language: 'de', host: 'example.org');
        $this->hit('/old-page', '-1 hour', referer: 'https://a.example/x', language: 'en', host: 'example.org');
        $this->hit('/old-page', '-30 minutes', referer: 'https://b.example/y', language: 'de', host: 'other.org');

        $row = $this->service()->groups([])['rows'][0];

        self::assertSame('/old-page', $row['path']);
        self::assertSame(3, $row['hits']);
        self::assertSame('2026-09-27T10:00:00+00:00', $row['first_seen']);
        self::assertSame('2026-09-29T09:30:00+00:00', $row['last_seen']);
        self::assertSame(
            [['referer' => 'https://a.example/x', 'hits' => 2], ['referer' => 'https://b.example/y', 'hits' => 1]],
            $row['top_referers'],
        );
        self::assertSame(2, $row['daily']['2026-09-29']);
        self::assertSame(1, $row['daily']['2026-09-27']);
        self::assertSame(['browser' => 3], $row['ua']);
        self::assertSame(['de', 'en'], $row['languages']);
        self::assertSame(['example.org', 'other.org'], $row['hosts']);
        self::assertSame('ref=1', $row['sample_query']);
        self::assertFalse($row['has_rule']);
        self::assertNull($row['best_suggestion']);
        self::assertFalse($row['resolved']);
        self::assertSame(3, $this->service()->groups([])['meta']['totals']['hits']);
    }

    public function testDaysLimitTheRangeAndAreClamped(): void
    {
        $this->hit('/today');
        $this->hit('/week', '-5 days');
        $this->hit('/month', '-20 days');
        $this->hit('/year', '-200 days');

        self::assertSame(['/today'], $this->paths(['days' => '1']));
        self::assertSame(['/today'], $this->paths(['days' => '0']));
        self::assertEqualsCanonicalizing(['/today', '/week'], $this->paths(['days' => '7']));
        self::assertEqualsCanonicalizing(['/today', '/week', '/month'], $this->paths([]));
        self::assertEqualsCanonicalizing(['/today', '/week', '/month', '/year'], $this->paths(['days' => '9999']));
    }

    public function testFromAndToAcceptDatesAndDateTimes(): void
    {
        $this->hit('/a', '-3 days');          // 2026-09-26 10:00
        $this->hit('/b', '-2 days +8 hours'); // 2026-09-27 18:00
        $this->hit('/c', '-1 day');           // 2026-09-28 10:00

        // A date-only "to" includes the whole day.
        self::assertEqualsCanonicalizing(['/a', '/b'], $this->paths(['from' => '2026-09-26', 'to' => '2026-09-27']));
        self::assertSame(['/c'], $this->paths(['from' => '2026-09-28']));
        self::assertSame(['/b'], $this->paths(['from' => '2026-09-27T12:00:00Z', 'to' => '2026-09-27T20:00:00+00:00']));
        self::assertSame(['/a'], $this->paths(['to' => '2026-09-26']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'from' => [['from' => 'yesterday'], 'from'];
        yield 'to' => [['to' => '2026-13-45'], 'to'];
        yield 'from after to' => [['from' => '2026-09-29', 'to' => '2026-09-01'], 'from'];
        yield 'days' => [['days' => 'many'], 'days'];
        yield 'bots' => [['bots' => 'maybe'], 'bots'];
        yield 'class' => [['class' => 'human'], 'class'];
        yield 'sort' => [['sort' => 'random'], 'sort'];
        yield 'dir' => [['dir' => 'sideways'], 'dir'];
        yield 'page zero' => [['page' => '0'], 'page'];
        yield 'page text' => [['page' => 'x'], 'page'];
        yield 'per_page' => [['per_page' => '1.5'], 'per_page'];
        yield 'include_resolved' => [['include_resolved' => 'x'], 'include_resolved'];
        yield 'array value' => [['q' => ['a', 'b']], 'q'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryValuesAreRejectedWithTheParameterName(array $query, string $field): void
    {
        try {
            $this->service()->groups($query);
            self::fail('InvalidInputException expected');
        } catch (InvalidInputException $e) {
            self::assertSame($field, $e->field);
        }
    }

    public function testTrendRejectsInvalidDays(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->service()->trend(['days' => 'x']);
    }

    public function testBotsAreExcludedByDefault(): void
    {
        $this->hit('/human');
        $this->hit('/robot', class: UserAgentClass::Bot);
        $this->hit('/ping', class: UserAgentClass::Monitoring);

        self::assertEqualsCanonicalizing(['/human', '/ping'], $this->paths());
        self::assertEqualsCanonicalizing(['/human', '/ping'], $this->paths(['bots' => '0']));
        self::assertEqualsCanonicalizing(['/human', '/robot', '/ping'], $this->paths(['bots' => '1']));
    }

    public function testClassFilterNarrowsAndBotClassImpliesBots(): void
    {
        $this->hit('/human');
        $this->hit('/robot', class: UserAgentClass::Bot);
        $this->hit('/ping', class: UserAgentClass::Monitoring);

        self::assertSame(['/human'], $this->paths(['class' => 'browser']));
        self::assertSame(['/ping'], $this->paths(['class' => 'monitoring']));
        self::assertSame(['/robot'], $this->paths(['class' => 'bot']));
        self::assertSame([], $this->paths(['class' => 'unknown']));
    }

    public function testSearchLanguageAndHostFilters(): void
    {
        $this->hit('/Blog/Old-Post', language: 'de', host: 'example.org');
        $this->hit('/blog/new', language: 'en', host: 'example.org');
        $this->hit('/shop', language: 'en', host: 'shop.example.org');

        self::assertEqualsCanonicalizing(['/Blog/Old-Post', '/blog/new'], $this->paths(['q' => 'BLOG']));
        self::assertSame(['/Blog/Old-Post'], $this->paths(['q' => 'old-post']));
        self::assertEqualsCanonicalizing(['/blog/new', '/shop'], $this->paths(['language' => 'en']));
        self::assertSame(['/shop'], $this->paths(['host' => 'Shop.Example.org']));
        self::assertSame(['/blog/new'], $this->paths(['language' => 'en', 'q' => 'blog']));
    }

    public function testSortAndDirection(): void
    {
        $this->hit('/a', '-3 days');
        $this->hit('/a', '-2 days');
        $this->hit('/a', '-1 day');
        $this->hit('/b', '-5 days');
        $this->hit('/c', '-4 days');
        $this->hit('/c', '-30 minutes');

        self::assertSame(['/a', '/c', '/b'], $this->paths());
        self::assertSame(['/b', '/c', '/a'], $this->paths(['dir' => 'asc']));
        self::assertSame(['/c', '/a', '/b'], $this->paths(['sort' => 'last']));
        self::assertSame(['/b', '/a', '/c'], $this->paths(['sort' => 'last', 'dir' => 'asc']));
        self::assertSame(['/a', '/c', '/b'], $this->paths(['sort' => 'first']));
        self::assertSame(['/b', '/c', '/a'], $this->paths(['sort' => 'first', 'dir' => 'asc']));
        self::assertSame(['/a', '/b', '/c'], $this->paths(['sort' => 'path']), 'path sorts ascending by default');
        self::assertSame(['/c', '/b', '/a'], $this->paths(['sort' => 'path', 'dir' => 'desc']));
    }

    public function testPaging(): void
    {
        foreach (['/p1', '/p2', '/p3', '/p4', '/p5'] as $i => $path) {
            $this->hit($path, '-' . ($i + 1) . ' hours');
        }
        $query = ['sort' => 'path', 'per_page' => '2'];

        $page1 = $this->service()->groups($query + ['page' => '1']);
        $page3 = $this->service()->groups($query + ['page' => '3']);
        $beyond = $this->service()->groups($query + ['page' => '4']);

        self::assertSame(['/p1', '/p2'], array_column($page1['rows'], 'path'));
        self::assertSame(5, $page1['total']);
        self::assertSame(2, $page1['per_page']);
        self::assertSame(['/p5'], array_column($page3['rows'], 'path'));
        self::assertSame(3, $page3['page']);
        self::assertSame([], $beyond['rows']);
        self::assertSame(5, $beyond['total']);
        self::assertSame(5, $page3['meta']['totals']['paths'], 'totals cover all pages');
        self::assertSame(5, $page3['meta']['totals']['hits']);
    }

    public function testPerPageIsClamped(): void
    {
        self::assertSame(500, $this->service()->groups(['per_page' => '100000'])['per_page']);
        self::assertSame(1, $this->service()->groups(['per_page' => '0'])['per_page']);
    }

    public function testTotalsByDayAreZeroFilledOverTheRange(): void
    {
        $this->hit('/a', '-1 day');
        $this->hit('/a', '-1 day');
        $this->hit('/b', '-1 hour');

        $byDay = $this->service()->groups(['days' => '3'])['meta']['totals']['by_day'];

        self::assertSame(['2026-09-27' => 0, '2026-09-28' => 2, '2026-09-29' => 1], $byDay);
    }

    public function testHasRuleAndBestSuggestion(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/covered', 'target' => '/blog/my-post']]);
        $app = $this->makeApp([], PageTreeFixture::pages());
        $this->app = $app;
        $this->hit('/covered', language: 'en');
        $this->hit('/blog/my-post.html', language: 'en');
        $this->hit('/zzz-nothing-like-it', language: 'en');

        $rows = [];
        foreach ($this->service()->groups([])['rows'] as $row) {
            $rows[$row['path']] = $row;
        }

        self::assertTrue($rows['/covered']['has_rule']);
        self::assertNull($rows['/covered']['best_suggestion'], 'no suggestion for paths that have a rule');
        self::assertFalse($rows['/blog/my-post.html']['has_rule']);
        self::assertIsArray($rows['/blog/my-post.html']['best_suggestion']);
        self::assertSame('/blog/my-post', $rows['/blog/my-post.html']['best_suggestion']['target']);
        self::assertGreaterThan(0.8, $rows['/blog/my-post.html']['best_suggestion']['score']);
        self::assertFalse($rows['/zzz-nothing-like-it']['has_rule']);
    }

    public function testDisabledRuleDoesNotCountAsCovered(): void
    {
        // The matcher skips disabled rules, so a disabled rule does not count as "covered".
        $this->seedRules([['id' => 'r1', 'source' => '/off', 'target' => '/x', 'enabled' => false]]);
        $this->hit('/off');

        self::assertFalse($this->service()->groups([])['rows'][0]['has_rule']);
    }

    public function testResolvedPathsStayHiddenUntilANewHit(): void
    {
        $this->hit('/gone', '-2 days');
        $this->hit('/other', '-2 days');

        self::assertSame(['resolved' => 1], $this->service()->resolve(['/gone'], true));

        self::assertSame(['/other'], $this->paths());
        $shown = $this->service()->groups(['include_resolved' => '1']);
        $byPath = [];
        foreach ($shown['rows'] as $row) {
            $byPath[$row['path']] = $row['resolved'];
        }
        self::assertSame(['/gone' => true, '/other' => false], $byPath);

        // A new hit after the marking brings the path back, and it is no longer "resolved".
        $this->clock->set($this->clock->now()->modify('+1 hour'));
        $this->hit('/gone', 'now');

        $rows = $this->service()->groups([])['rows'];
        $gone = array_values(array_filter($rows, static fn (array $r): bool => $r['path'] === '/gone'));
        self::assertCount(1, $gone);
        self::assertFalse($gone[0]['resolved']);
    }

    // ---------------------------------------------------------------- trend

    public function testTrendIsZeroFilledAndHonoursBots(): void
    {
        $this->hit('/a', '-1 hour');
        $this->hit('/a', '-1 day');
        $this->hit('/bot', '-2 hours', UserAgentClass::Bot);

        $week = $this->service()->trend(['days' => '7'])['days'];
        $withBots = $this->service()->trend(['days' => '7', 'bots' => '1'])['days'];

        self::assertCount(7, $week);
        self::assertSame(['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29'], array_keys($week));
        self::assertSame(1, $week['2026-09-29']);
        self::assertSame(1, $week['2026-09-28']);
        self::assertSame(0, $week['2026-09-23']);
        self::assertSame(2, $withBots['2026-09-29']);
        self::assertCount(30, $this->service()->trend([])['days']);
        self::assertCount(366, $this->service()->trend(['days' => '5000'])['days']);
        self::assertCount(1, $this->service()->trend(['days' => '-4'])['days']);
    }

    // ---------------------------------------------------------------- entries

    public function testEntriesOfOnePathNewestFirstWithoutIpUnlessStored(): void
    {
        $this->hit('/x', '-3 hours', referer: 'https://r.example/', query: 'a=1', language: 'en', host: 'example.org');
        $this->hit('/x', '-1 hour', class: UserAgentClass::Bot, language: null, ip: '203.0.113.0');
        $this->hit('/x/child', '-2 hours');
        $this->hit('/X', '-2 hours');

        $entries = $this->service()->entries('/x');

        self::assertCount(2, $entries);
        self::assertSame('2026-09-29T09:00:00+00:00', $entries[0]['time']);
        self::assertSame('bot', $entries[0]['ua_class']);
        self::assertSame('203.0.113.0', $entries[0]['ip']);
        self::assertSame('', $entries[0]['language']);
        self::assertSame(
            [
                'time' => '2026-09-29T07:00:00+00:00',
                'path' => '/x',
                'query' => 'a=1',
                'referer' => 'https://r.example/',
                'ua' => 'UA/1.0',
                'ua_class' => 'browser',
                'language' => 'en',
                'host' => 'example.org',
                'method' => 'GET',
            ],
            $entries[1],
        );
    }

    public function testEntriesAreLimitedToTheLatest100AndTheLast90Days(): void
    {
        for ($i = 0; $i < 130; ++$i) {
            $this->hit('/busy', '-' . $i . ' minutes');
        }
        $this->hit('/busy', '-100 days');

        $entries = $this->service()->entries('/busy');

        self::assertCount(100, $entries);
        self::assertSame('2026-09-29T10:00:00+00:00', $entries[0]['time']);
        self::assertSame('2026-09-29T08:21:00+00:00', $entries[99]['time']);
        self::assertSame([], $this->service()->entries('/never-logged'));
    }

    public function testEntriesNeedAPath(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->service()->entries('');
    }

    // ---------------------------------------------------------------- ignore

    public function testIgnoreAddsThePatternOnce(): void
    {
        $this->configWriter->patterns = ['/existing/*'];

        $first = $this->service()->ignore('  /Scanner/*  ', false);
        $again = $this->service()->ignore('/scanner/*', false);

        self::assertSame('/Scanner/*', $first['pattern']);
        self::assertSame(['/existing/*', '/Scanner/*'], $first['patterns']);
        self::assertSame(0, $first['purged']);
        self::assertSame(['/existing/*', '/Scanner/*'], $again['patterns'], 'case-insensitive duplicate is not added twice');
        self::assertSame(['/existing/*', '/Scanner/*'], $this->configWriter->patterns);
    }

    public function testIgnoreWithPurgeDeletesMatchingPathsOnly(): void
    {
        $this->hit('/scan/a');
        $this->hit('/scan/a');
        $this->hit('/scan/b', '-40 days');
        $this->hit('/scan/bot', class: UserAgentClass::Bot);
        $this->hit('/keep');

        $result = $this->service()->ignore('/scan/*', true);

        self::assertSame(4, $result['purged']);
        self::assertSame(['/keep'], $this->paths(['days' => '366', 'bots' => '1']));
        self::assertSame(['/scan/*'], $result['patterns']);
    }

    public function testIgnoreWithoutPurgeKeepsTheLog(): void
    {
        $this->hit('/scan/a');

        $result = $this->service()->ignore('/scan/*', false);

        self::assertSame(0, $result['purged']);
        self::assertSame(['/scan/a'], $this->paths());
    }

    public function testPurgeWalksMoreThanOnePage(): void
    {
        $logStore = $this->app->services()->logStore();
        for ($i = 0; $i < 520; ++$i) {
            $logStore->append(new NotFoundEntry($this->clock->now()->modify('-1 hour'), '/junk/' . $i, host: 'example.org', language: 'de'));
        }
        $this->hit('/keep');

        $result = $this->service()->ignore('/junk/*', true);

        self::assertSame(520, $result['purged']);
        self::assertSame(['/keep'], $this->paths());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'star' => ['*'];
        yield 'double star' => ['**'];
        yield 'slash star' => ['/*'];
        yield 'control character' => ["/a\nb"];
        yield 'null byte' => ["/a\0b"];
        yield 'too long' => ['/' . str_repeat('a', 300)];
    }

    #[DataProvider('invalidPatterns')]
    public function testIgnoreRejectsBadPatterns(string $pattern): void
    {
        try {
            $this->service()->ignore($pattern, false);
            self::fail('InvalidInputException expected');
        } catch (InvalidInputException $e) {
            self::assertSame('pattern', $e->field);
        }
        self::assertSame([], $this->configWriter->patterns);
    }

    public function testIgnoreAcceptsTheLongestAllowedPattern(): void
    {
        $pattern = '/' . str_repeat('a', 299);

        self::assertSame([$pattern], $this->service()->ignore($pattern, false)['patterns']);
    }

    public function testIgnoreWithoutConfigWriterIsUnavailable(): void
    {
        $service = new NotFoundService($this->app->services(), $this->app->site(), $this->app->rules(), null);

        $this->expectException(UnavailableException::class);

        $service->ignore('/scan/*', true);
    }

    // ---------------------------------------------------------------- resolve

    public function testResolveMarksAndUnmarks(): void
    {
        $this->hit('/a');
        $this->hit('/b');

        self::assertSame(['resolved' => 2], $this->service()->resolve(['/a', '/b', '/a'], true), 'duplicates count once');
        self::assertSame([], $this->paths());
        self::assertSame(['/a', '/b'], array_keys($this->app->services()->resolvedPaths()->all()));

        self::assertSame(['resolved' => 1], $this->service()->resolve(['/a'], false));
        self::assertSame(['/a'], $this->paths());
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidPathLists(): iterable
    {
        yield 'empty' => [[]];
        yield 'empty string' => [['/a', '']];
        yield 'blank string' => [['  ']];
        yield 'not a string' => [['/a', 5]];
        yield 'too many' => [array_map(static fn (int $i): string => '/p' . $i, range(1, 501))];
    }

    /**
     * @param list<mixed> $paths
     */
    #[DataProvider('invalidPathLists')]
    public function testResolveValidatesThePathList(array $paths): void
    {
        try {
            $this->service()->resolve($paths, true);
            self::fail('InvalidInputException expected');
        } catch (InvalidInputException $e) {
            self::assertSame('paths', $e->field);
        }
    }

    public function testResolveAcceptsExactly500Paths(): void
    {
        $paths = array_map(static fn (int $i): string => '/p' . $i, range(1, 500));

        self::assertSame(['resolved' => 500], $this->service()->resolve($paths, true));
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteOnePath(): void
    {
        $this->hit('/a');
        $this->hit('/a', '-2 days');
        $this->hit('/b');

        self::assertSame(['deleted' => 2], $this->service()->delete('/a', false));
        self::assertSame(['/b'], $this->paths());
    }

    public function testDeleteOfAnUnknownPathIsNotFound(): void
    {
        try {
            $this->service()->delete('/missing', false);
            self::fail('ResourceNotFoundException expected');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('/missing', $e->id);
        }
    }

    public function testDeleteAllCountsEntriesIncludingBots(): void
    {
        $this->hit('/a');
        $this->hit('/a', '-40 days');
        $this->hit('/bot', class: UserAgentClass::Bot);

        self::assertSame(['deleted' => 3], $this->service()->delete(null, true));
        self::assertSame([], $this->paths(['days' => '366', 'bots' => '1']));
        self::assertSame(['deleted' => 0], $this->service()->delete(null, true));
    }

    public function testDeleteNeedsAPathOrAll(): void
    {
        foreach ([null, ''] as $path) {
            try {
                $this->service()->delete($path, false);
                self::fail('InvalidInputException expected');
            } catch (InvalidInputException $e) {
                self::assertSame('path', $e->field);
            }
        }
    }

    public function testRowsSerializeToJsonObjectsAndLists(): void
    {
        $this->hit('/a', referer: 'https://r.example/');

        $json = json_encode($this->service()->groups([]), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"ua":{"browser":1}', $json);
        self::assertStringContainsString('"languages":["de"]', $json);
        self::assertStringContainsString('"best_suggestion":null', $json);
        self::assertNotInstanceOf(stdClass::class, $this->service()->groups([])['rows'][0]['daily']);
    }
}
