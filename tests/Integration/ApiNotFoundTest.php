<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/** REST API, 404 monitor: list, trend, entries, ignore, resolve, delete. The 404s come from real frontend requests. */
#[Group('integration')]
final class ApiNotFoundTest extends ApiTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    /**
     * A frontend 404 as a browser causes it (the curl user agent would be classified as a bot).
     *
     * @param array<string, string> $headers
     */
    private function miss(string $path, int $times = 1, array $headers = []): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $response = $this->get($path, ['headers' => $headers + ['User-Agent' => self::BROWSER]]);
            self::assertSame(404, $response->status, $path);
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $query = []): array
    {
        $response = $this->api->get('/redirects/404', $query);
        self::assertSame(200, $response->status, $response->describe());

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->data();

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function paths(array $query = []): array
    {
        return array_values(array_map('strval', array_column($this->rows($query), 'path')));
    }

    private function seed(): void
    {
        $this->miss('/typograpy', 3, ['Referer' => 'https://ref.example/a']);
        $this->miss('/typograpy', 1, ['Referer' => 'https://other.example/b']);
        $this->miss('/missing/page', 2);
        $this->miss('/zzz');
        self::assertSame(404, $this->get('/botpath')->status, 'a request with the curl user agent is a bot');
    }

    public function testListGroupsPathsWithHitsAndDetails(): void
    {
        $this->seed();
        $response = $this->api->get('/redirects/404');
        self::assertSame(200, $response->status, $response->describe());
        $rows = $response->data();

        self::assertSame(['/typograpy', '/missing/page', '/zzz'], array_column($rows, 'path'), 'busiest first, bots hidden by default');
        self::assertSame([4, 2, 1], array_column($rows, 'hits'));

        $row = $rows[0];
        foreach (['path', 'hits', 'first_seen', 'last_seen', 'top_referers', 'daily', 'ua', 'languages', 'hosts', 'has_rule', 'best_suggestion', 'resolved'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame([['referer' => 'https://ref.example/a', 'hits' => 3], ['referer' => 'https://other.example/b', 'hits' => 1]], $row['top_referers']);
        self::assertSame(['browser' => 4], $row['ua']);
        self::assertSame(['127.0.0.1'], $row['hosts']);
        self::assertSame([], $row['languages']);
        self::assertSame(4, $row['daily'][date('Y-m-d')]);
        self::assertCount(30, $row['daily'], 'the default range is 30 days, missing days are 0');
        self::assertFalse($row['has_rule']);
        self::assertFalse($row['resolved']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $row['first_seen']);

        $meta = $response->meta();
        self::assertSame(['hits' => 7, 'paths' => 3], array_intersect_key($meta['totals'], ['hits' => 1, 'paths' => 1]));
        self::assertSame(7, $meta['totals']['by_day'][date('Y-m-d')]);
        self::assertSame([3, 1, 50], [$meta['total'], $meta['page'], $meta['per_page']]);
    }

    public function testBestSuggestionForAPathThatLooksLikeAnExistingPage(): void
    {
        $this->miss('/typograpy');
        $row = $this->rows()[0];
        self::assertSame('/typograpy', $row['path']);
        self::assertSame('/typography', $row['best_suggestion']['target']);
        self::assertSame('similar_route', $row['best_suggestion']['reason']);
        self::assertSame('Typography', $row['best_suggestion']['page_title']);
        self::assertGreaterThan(0.5, $row['best_suggestion']['score']);
        self::assertLessThanOrEqual(1.0, $row['best_suggestion']['score']);
    }

    public function testHasRuleIsTrueWhenARuleCoversThePath(): void
    {
        $this->miss('/typograpy');
        $this->miss('/other');
        $this->rules([['id' => 'fix', 'source' => '/typograpy', 'target' => '/typography', 'only_if_not_found' => true]]);
        $byPath = array_column($this->rows(), null, 'path');
        self::assertTrue($byPath['/typograpy']['has_rule']);
        self::assertNull($byPath['/typograpy']['best_suggestion'], 'no suggestion where a rule exists');
        self::assertFalse($byPath['/other']['has_rule']);
    }

    public function testBotsAndUserAgentClassFilter(): void
    {
        $this->seed();
        self::assertNotContains('/botpath', $this->paths());
        self::assertContains('/botpath', $this->paths(['bots' => 1]));
        self::assertSame(['/botpath'], $this->paths(['class' => 'bot']), 'class=bot includes bots without bots=1');
        self::assertNotContains('/botpath', $this->paths(['class' => 'browser', 'bots' => 1]));
        self::assertSame(['bot' => 1], $this->rows(['class' => 'bot'])[0]['ua']);
        self::assertSame(8, $this->api->get('/redirects/404', ['bots' => 1])->meta()['totals']['hits']);
    }

    public function testSearchSortAndPaging(): void
    {
        $this->seed();
        self::assertSame(['/missing/page'], $this->paths(['q' => 'missing']));
        self::assertSame(['/typograpy'], $this->paths(['q' => 'TYPOGRAP']), 'q is case-insensitive');
        self::assertSame([], $this->paths(['q' => 'nothing-like-this']));

        self::assertSame(['/missing/page', '/typograpy', '/zzz'], $this->paths(['sort' => 'path']), 'path sorts ascending by default');
        self::assertSame(['/zzz', '/typograpy', '/missing/page'], $this->paths(['sort' => 'path', 'dir' => 'desc']));
        self::assertSame(['/zzz', '/missing/page', '/typograpy'], $this->paths(['sort' => 'hits', 'dir' => 'asc']));
        self::assertCount(3, $this->paths(['sort' => 'last']));
        self::assertCount(3, $this->paths(['sort' => 'first', 'dir' => 'asc']));

        $page = $this->api->get('/redirects/404', ['per_page' => 2, 'page' => 2]);
        self::assertSame(['/zzz'], array_column($page->data(), 'path'));
        self::assertSame([3, 2, 2], [$page->meta()['total'], $page->meta()['page'], $page->meta()['per_page']]);
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 3, 'total_pages' => 2], $page->meta()['pagination']);
        self::assertSame(7, $page->meta()['totals']['hits'], 'totals cover all pages');
    }

    public function testHostAndDateRangeFilters(): void
    {
        $this->seed();
        self::assertCount(3, $this->paths(['host' => '127.0.0.1']));
        self::assertSame([], $this->paths(['host' => 'shop.example.com']));
        self::assertCount(3, $this->paths(['days' => 7]));
        self::assertCount(3, $this->paths(['days' => 90]));
        $today = date('Y-m-d');
        self::assertCount(3, $this->paths(['from' => $today, 'to' => $today]));
        self::assertSame([], $this->paths(['from' => '2020-01-01', 'to' => '2020-01-31']), 'no 404s that old');
    }

    public function testLanguageFilterAndLanguageFreePaths(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->miss('/de/gibt-es-nicht', 2);
        $this->miss('/en/gibt-es-nicht');
        $this->miss('/de/nur-deutsch');

        $all = array_column($this->rows(), null, 'path');
        self::assertSame(3, $all['/gibt-es-nicht']['hits'], 'the log stores language-free paths');
        self::assertEqualsCanonicalizing(['de', 'en'], $all['/gibt-es-nicht']['languages']);
        self::assertSame(['/gibt-es-nicht', '/nur-deutsch'], $this->paths(['language' => 'de', 'sort' => 'path']));
        self::assertSame(['/gibt-es-nicht'], $this->paths(['language' => 'en']));
        self::assertSame(2, $this->rows(['language' => 'de'])[0]['hits']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badListQueries(): array
    {
        return [
            'class' => [['class' => 'human'], 'class'],
            'sort' => [['sort' => 'nope'], 'sort'],
            'dir' => [['dir' => 'sideways'], 'dir'],
            'page' => [['page' => 0], 'page'],
            'page not a number' => [['page' => 'x'], 'page'],
            'bots flag' => [['bots' => 'maybe'], 'bots'],
            'from is not a date' => [['from' => 'yesterday'], 'from'],
            'from after to' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'from'],
            'days not a number' => [['days' => 'many'], 'days'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('badListQueries')]
    public function testBadQueriesGive422(array $query, string $field): void
    {
        $response = $this->api->get('/redirects/404', $query);
        $this->assertProblem($response, 422);
        self::assertSame($field, $response->errors()[0]['field']);
        $this->assertProblem($this->api->get('/redirects/404/trend', ['days' => 'many']), 422);
    }

    public function testTrendCountsPerDay(): void
    {
        $this->seed();
        foreach ([7, 30, 90] as $days) {
            $trend = $this->api->get('/redirects/404/trend', ['days' => $days]);
            self::assertSame(200, $trend->status, $trend->describe());
            $map = $trend->data()['days'];
            self::assertCount($days, $map);
            self::assertSame(7, $map[date('Y-m-d')], 'browsers only: 4 + 2 + 1');
            self::assertSame(7, array_sum($map));
            self::assertSame(date('Y-m-d'), array_key_last($map));
        }
        self::assertSame(8, $this->api->get('/redirects/404/trend', ['days' => 7, 'bots' => 1])->data()['days'][date('Y-m-d')]);
        self::assertCount(30, $this->api->get('/redirects/404/trend')->data()['days'], '30 days by default');
    }

    public function testEntriesOfOnePath(): void
    {
        $this->seed();
        $response = $this->api->get('/redirects/404/entries', ['path' => '/typograpy']);
        self::assertSame(200, $response->status, $response->describe());
        $entries = $response->data();
        self::assertCount(4, $entries);
        $entry = $entries[0];
        self::assertSame(['time', 'path', 'query', 'referer', 'ua', 'ua_class', 'language', 'host', 'method'], array_slice(array_keys($entry), 0, 9));
        self::assertSame(['/typograpy', 'browser', 'GET', '127.0.0.1', self::BROWSER], [$entry['path'], $entry['ua_class'], $entry['method'], $entry['host'], $entry['ua']]);
        self::assertEqualsCanonicalizing(['https://ref.example/a', 'https://ref.example/a', 'https://ref.example/a', 'https://other.example/b'], array_column($entries, 'referer'));
        self::assertSame('127.0.0.0', $entry['ip'], 'IPs are anonymized by default');
        self::assertSame([], $this->api->get('/redirects/404/entries', ['path' => '/never-requested'])->data());
        self::assertCount(1, $this->api->get('/redirects/404/entries', ['path' => '/zzz'])->data(), 'other paths do not leak in');
    }

    public function testEntriesNeedAPath(): void
    {
        $response = $this->api->get('/redirects/404/entries');
        $this->assertProblem($response, 422);
        self::assertSame(['path', 'required'], [$response->errors()[0]['field'], $response->errors()[0]['code']]);
    }

    public function testResolveHidesARowAndUnresolveBringsItBack(): void
    {
        $this->seed();
        $resolve = $this->api->post('/redirects/404/resolve', ['paths' => ['/zzz', '/missing/page']]);
        self::assertSame(200, $resolve->status, $resolve->describe());
        self::assertSame(['resolved' => 2], $resolve->data());

        self::assertSame(['/typograpy'], $this->paths(), 'resolved rows are hidden');
        self::assertSame(1, $this->api->get('/redirects/404')->meta()['total']);
        $all = $this->rows(['include_resolved' => 1]);
        self::assertSame(['/typograpy', '/missing/page', '/zzz'], array_column($all, 'path'));
        self::assertSame([false, true, true], array_column($all, 'resolved'));

        $undo = $this->api->post('/redirects/404/resolve', ['paths' => ['/zzz'], 'resolved' => false]);
        self::assertSame(['resolved' => 1], $undo->data());
        self::assertSame(['/typograpy', '/zzz'], $this->paths());
        self::assertFalse($this->rows()[1]['resolved']);
    }

    public function testAResolvedPathReturnsWhenItIsHitAgain(): void
    {
        $this->miss('/zzz');
        $this->api->post('/redirects/404/resolve', ['paths' => ['/zzz']]);
        self::assertSame([], $this->paths());
        sleep(1);
        $this->miss('/zzz');
        self::assertSame(['/zzz'], $this->paths(), 'a new hit after the resolution shows the path again');
    }

    public function testResolvedPathsDoNotFeedSuggestionGeneration(): void
    {
        $this->miss('/typograpy');
        $this->api->post('/redirects/404/resolve', ['paths' => ['/typograpy']]);
        $generated = $this->api->post('/redirects/suggestions/generate');
        self::assertSame(200, $generated->status, $generated->describe());
        self::assertSame(0, $generated->data()['paths']);
    }

    public function testResolveRefusesBadInput(): void
    {
        $empty = $this->api->post('/redirects/404/resolve', ['paths' => []]);
        $this->assertProblem($empty, 422);
        self::assertSame('paths', $empty->errors()[0]['field']);
        $this->assertProblem($this->api->post('/redirects/404/resolve', []), 422);
        $this->assertProblem($this->api->post('/redirects/404/resolve', ['paths' => ['/a', '']]), 422);
        $this->assertProblem($this->api->post('/redirects/404/resolve', ['paths' => [1]]), 422);
        $this->assertProblem($this->api->post('/redirects/404/resolve', ['paths' => array_map(static fn (int $i): string => '/p' . $i, range(1, 501))]), 422);
    }

    public function testIgnoreStoresThePatternAndKeepsOtherConfig(): void
    {
        $this->site()->writePluginConfig(['log' => ['retention_days' => 12, 'ignore_patterns' => ['/already/*']], 'redirects' => ['max_chain_depth' => 7]]);
        $this->miss('/missing/one');
        $this->miss('/keep/me');

        $response = $this->api->post('/redirects/404/ignore', ['pattern' => '/missing/*']);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['pattern' => '/missing/*', 'patterns' => ['/already/*', '/missing/*'], 'purged' => 0, 'purged_paths' => 0], $response->data());
        self::assertContains('/missing/one', $this->paths(), 'without purge the logged entries stay');

        $yaml = Yaml::parse((string) $this->site()->readFile('user/config/plugins/redirect-manager.yaml'));
        self::assertSame(['/already/*', '/missing/*'], $yaml['log']['ignore_patterns']);
        self::assertSame(12, $yaml['log']['retention_days'], 'other keys of the file are kept');
        self::assertSame(7, $yaml['redirects']['max_chain_depth']);

        // From now on the frontend does not log matching 404s any more. Grav's compiled config is keyed by the mtime
        // of the yaml files (one second resolution), so it is dropped like a real edit a second later would.
        exec('rm -rf ' . escapeshellarg($this->site()->dir . '/cache/compiled'));
        $before = count($this->site()->notFoundEntries());
        $this->miss('/missing/two');
        self::assertCount($before, $this->site()->notFoundEntries());
        $this->miss('/keep/me');
        self::assertCount($before + 1, $this->site()->notFoundEntries());

        // The same pattern twice is stored once.
        $again = $this->api->post('/redirects/404/ignore', ['pattern' => '/MISSING/*']);
        self::assertSame(['/already/*', '/missing/*'], $again->data()['patterns']);
    }

    public function testIgnoreWithPurgeRemovesMatchingEntries(): void
    {
        $this->miss('/missing/one', 2);
        $this->miss('/missing/two');
        $this->miss('/keep/me');
        self::assertCount(4, $this->site()->notFoundEntries());

        $response = $this->api->post('/redirects/404/ignore', ['pattern' => '/missing/*', 'purge' => true]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(3, $response->data()['purged'], 'log entries (two hits of one path, one of the other)');
        self::assertSame(2, $response->data()['purged_paths'], 'paths, what the monitor lists');
        self::assertSame(['/keep/me'], $this->paths());
        self::assertSame(['/keep/me'], array_column($this->site()->notFoundEntries(), 'p'));
        $yaml = Yaml::parse((string) $this->site()->readFile('user/config/plugins/redirect-manager.yaml'));
        self::assertSame(['/missing/*'], $yaml['log']['ignore_patterns']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badPatterns(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'everything' => ['/*'],
            'star' => ['*'],
            'too long' => [str_repeat('a', 301)],
            'control character' => ["/a\x01b"],
            'not a string' => [['x']],
        ];
    }

    #[DataProvider('badPatterns')]
    public function testIgnoreRefusesBadPatterns(mixed $pattern): void
    {
        $response = $this->api->post('/redirects/404/ignore', ['pattern' => $pattern]);
        $this->assertProblem($response, 422);
        self::assertSame('pattern', $response->errors()[0]['field']);
        self::assertNull($this->site()->readFile('user/config/plugins/redirect-manager.yaml'), 'nothing was written');
    }

    public function testDeleteOnePath(): void
    {
        $this->seed();
        $response = $this->api->delete('/redirects/404', ['path' => '/typograpy']);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['deleted' => 4], $response->data());
        self::assertSame(['/missing/page', '/zzz'], $this->paths());
        $this->assertProblem($this->api->delete('/redirects/404', ['path' => '/typograpy']), 404);
    }

    public function testDeleteAllClearsTheLog(): void
    {
        $this->seed();
        $response = $this->api->delete('/redirects/404', [], ['all' => true]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['deleted' => 8], $response->data(), 'counts every entry, bots included');
        self::assertSame([], $this->rows(['bots' => 1]));
        self::assertSame([], $this->site()->notFoundEntries());
        self::assertSame(0, $this->api->get('/redirects/404')->meta()['totals']['hits']);
    }

    public function testDeleteNeedsAPathOrAll(): void
    {
        $this->seed();
        $response = $this->api->delete('/redirects/404');
        $this->assertProblem($response, 422);
        self::assertSame('path', $response->errors()[0]['field']);
        self::assertCount(3, $this->paths(), 'nothing was deleted');
    }
}
