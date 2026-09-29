<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * Response times of the admin API at realistic sizes: 10,000 rules, 50,000 log entries of 404s, 5,000 suggestions,
 * statistics for a third of the rules. Times are wall-clock for the whole request over HTTP (Grav boot, token check,
 * plugin, JSON), so they are an upper bound of the server time. Five warm requests per endpoint: the report shows the
 * median, the budget applies to the fastest, so that other jobs on the machine do not fail the test while an algorithmic
 * regression (which slows every request) still does. The server runs with OPcache for the CLI, as a production PHP has it.
 *
 * The first request after a change to rules.yaml also computes the chain analysis of all rules (about 0.5 s at
 * 10,000 rules, cached per revision); the tests warm it first. docs/PERFORMANCE.md has the numbers.
 */
#[Group('benchmark')]
final class ApiPerformanceTest extends ApiTestCase
{
    private const RULES = 10000;
    private const LOG_ENTRIES = 50000;
    private const LOG_PATHS = 8000;
    private const SUGGESTIONS = 5000;

    /** Budget of one rule list page (50 rows) in milliseconds. */
    private const LIST_LIMIT_MS = 200.0;
    /** Budget of the other endpoints in milliseconds. */
    private const OTHER_LIMIT_MS = 300.0;

    protected static function siteIni(): array
    {
        return ['opcache.enable' => '1', 'opcache.enable_cli' => '1', 'opcache.validate_timestamps' => '1', 'opcache.memory_consumption' => '128'];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{float, float} median and fastest of the measured requests in milliseconds
     */
    private function measure(string $path, array $query = [], int $runs = 5): array
    {
        // Two warm-up requests: OPcache, the analysis cache, the parsed-rules cache.
        for ($i = 0; $i < 2; ++$i) {
            $warm = $this->api->get($path, $query);
            self::assertSame(200, $warm->status, $path . ' ' . $warm->describe());
        }
        $samples = [];
        for ($i = 0; $i < $runs; ++$i) {
            $start = hrtime(true);
            $response = $this->api->get($path, $query);
            $samples[] = (hrtime(true) - $start) / 1e6;
            self::assertSame(200, $response->status, $path . ' ' . $response->describe());
        }
        sort($samples);

        return [$samples[intdiv(count($samples), 2)], $samples[0]];
    }

    private function report(string $label, float $median, float $fastest, float $limit): void
    {
        fwrite(STDERR, sprintf("\n[redirect-manager api benchmark] %-46s median %6.1f ms, fastest %6.1f ms (limit %.0f ms)\n", $label, $median, $fastest, $limit));
    }

    private function seedRules(): void
    {
        $rows = [];
        $exact = (int) (self::RULES * 0.85);
        $wildcard = (int) (self::RULES * 0.10);
        $regex = self::RULES - $exact - $wildcard;
        for ($i = 1; $i <= $exact; ++$i) {
            $rows[] = ['source' => '/old/section-' . intdiv($i, 50) . '/page-' . $i, 'target' => '/new/section-' . intdiv($i, 50) . '/page-' . $i, 'status' => $i % 7 === 0 ? 302 : 301, 'group' => 'bench-exact'];
        }
        for ($i = 1; $i <= $wildcard; ++$i) {
            $rows[] = ['source' => '/legacy-' . $i . '/*', 'target' => '/moved-' . $i . '/$1', 'match_type' => 'wildcard', 'group' => 'bench-wildcard'];
        }
        for ($i = 1; $i <= $regex; ++$i) {
            $rows[] = ['source' => '^/archive-' . $i . '/(\d{4})/([a-z0-9-]+)$', 'target' => '/blog-' . $i . '/$1/$2', 'match_type' => 'regex', 'group' => 'bench-regex'];
        }
        $this->rules($rows);
        self::assertCount(self::RULES, $this->site()->repository()->all());
    }

    /** stats.json: every third rule was hit on about half of the last 45 days. */
    private function seedStats(): void
    {
        mt_srand(20260929);
        $stats = [];
        foreach ($this->site()->repository()->all() as $i => $rule) {
            if ($i % 3 !== 0) {
                continue;
            }
            $daily = [];
            for ($d = 0; $d < 45; ++$d) {
                if (mt_rand(0, 1) === 1) {
                    $daily[gmdate('Y-m-d', time() - $d * 86400)] = mt_rand(1, 20);
                }
            }
            $stats[$rule->id] = ['total' => array_sum($daily), 'last_hit' => gmdate('c', time() - 3600), 'daily' => (object) $daily];
        }
        $this->site()->writeFile('user/data/redirect-manager/stats.json', json_encode(['version' => 1, 'rules' => (object) $stats, 'processed' => []], JSON_THROW_ON_ERROR));
        // The stats file must be older than the two seconds in which StatsStore does not trust its modification time.
        touch($this->site()->dataDir() . '/stats.json', time() - 60);
    }

    /**
     * 50,000 log lines over the last 30 days on up to 8,000 distinct paths, one day file per day.
     *
     * @return int number of distinct paths with at least one entry that is not from a bot (what the list shows)
     */
    private function seedNotFoundLog(): int
    {
        $visible = [];
        mt_srand(4711);
        $lines = [];
        $now = time();
        for ($i = 0; $i < self::LOG_ENTRIES; ++$i) {
            $t = $now - mt_rand(60, 29 * 86400);
            $entry = ['t' => $t, 'p' => '/missing/page-' . mt_rand(1, self::LOG_PATHS)];
            if ($i % 10 !== 0) {
                $visible[$entry['p']] = true;
            }
            if ($i % 5 === 0) {
                $entry['q'] = 'utm_source=x';
            }
            $entry['r'] = 'https://example.com/ref-' . mt_rand(1, 20);
            $entry['ua'] = 'Mozilla/5.0 (benchmark)';
            $entry['c'] = $i % 10 === 0 ? 'bot' : 'browser';
            $entry['h'] = 'localhost';
            $entry['m'] = 'GET';
            $lines[gmdate('Y-m-d', $t)][] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        ksort($lines);
        foreach ($lines as $day => $dayLines) {
            $this->site()->writeFile('user/data/redirect-manager/404/' . $day . '.jsonl', implode("\n", $dayLines) . "\n");
        }

        return count($visible);
    }

    private function seedSuggestions(): void
    {
        $records = [];
        for ($i = 0; $i < self::SUGGESTIONS; ++$i) {
            $records[] = [
                'id' => sprintf('s%011x%011x', 1000 + $i, $i),
                'path' => '/missing/page-' . $i,
                'target' => '/new/page-' . $i,
                'score' => round(0.3 + ($i % 70) / 100, 2),
                'reason' => 'similar_slug',
                'title' => 'Page ' . $i,
                'status' => ['open', 'open', 'open', 'accepted', 'rejected'][$i % 5],
                'createdAt' => gmdate('c'),
                'decidedAt' => null,
                'source' => '404',
            ];
        }
        $this->site()->writeFile('user/data/redirect-manager/suggestions.json', json_encode(['version' => 1, 'suggestions' => $records], JSON_THROW_ON_ERROR));
    }

    public function testRuleListPageOfFiftyAtTenThousandRules(): void
    {
        $this->seedRules();
        $this->seedStats();

        [$median, $fastest] = $this->measure('/redirects/rules', ['per_page' => '50']);
        $this->report('GET /redirects/rules (page of 50)', $median, $fastest, self::LIST_LIMIT_MS);
        self::assertLessThan(self::LIST_LIMIT_MS, $fastest, 'rule list, first page, default sort');

        $page = $this->api->get('/redirects/rules', ['per_page' => '50', 'page' => '3']);
        self::assertSame(200, $page->status);
        self::assertCount(50, $page->data());
        self::assertSame(self::RULES, $page->meta()['total'] ?? null);

        foreach (['sort=source&dir=asc' => 'source sorted', 'q=section-7&sort=hits' => 'search, sorted by hits', 'match_type=regex' => 'only regex rules'] as $query => $label) {
            parse_str($query, $params);
            /** @var array<string, string> $params */
            [$median, $fastest] = $this->measure('/redirects/rules', ['per_page' => '50'] + $params);
            $this->report('GET /redirects/rules ' . $label, $median, $fastest, self::LIST_LIMIT_MS);
            self::assertLessThan(self::LIST_LIMIT_MS, $fastest, $label);
        }
    }

    public function testStatsNotFoundAndSuggestionsAtRealisticSizes(): void
    {
        $this->seedRules();
        $this->seedStats();
        $visiblePaths = $this->seedNotFoundLog();
        $this->seedSuggestions();

        $cases = [
            'GET /redirects/stats' => ['/redirects/stats', []],
            'GET /redirects/404 (30 days)' => ['/redirects/404', ['days' => '30']],
            'GET /redirects/404 (30 days, bots, 100 rows)' => ['/redirects/404', ['days' => '30', 'bots' => '1', 'per_page' => '100']],
            'GET /redirects/404?q=page-12' => ['/redirects/404', ['days' => '30', 'q' => 'page-12']],
            'GET /redirects/404/trend' => ['/redirects/404/trend', ['days' => '30']],
            'GET /redirects/suggestions' => ['/redirects/suggestions', []],
            'GET /redirects/suggestions (all)' => ['/redirects/suggestions', ['status' => 'all']],
        ];
        foreach ($cases as $label => [$path, $query]) {
            [$median, $fastest] = $this->measure($path, $query);
            $this->report($label, $median, $fastest, self::OTHER_LIMIT_MS);
            self::assertLessThan(self::OTHER_LIMIT_MS, $fastest, $label);
        }

        $groups = $this->api->get('/redirects/404', ['days' => '30']);
        self::assertSame($visiblePaths, $groups->meta()['total'] ?? null, 'every path with a visitor entry is listed');
    }

    public function testImportingTenThousandRulesTwentyTimesNeverFailsOnDuplicateIds(): void
    {
        $csv = "source,target,status\n";
        for ($i = 1; $i <= 10000; ++$i) {
            $csv .= '/old/section-' . intdiv($i, 50) . '/page-' . $i . ',/new/section-' . intdiv($i, 50) . '/page-' . $i . ",301\n";
        }

        $seconds = [];
        for ($run = 1; $run <= 20; ++$run) {
            $this->rules([]);
            $start = hrtime(true);
            $commit = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv']);
            $seconds[] = (hrtime(true) - $start) / 1e9;
            self::assertSame(200, $commit->status, 'run ' . $run . ' ' . $commit->describe());
            self::assertSame(10000, $commit->data()['created'] ?? null, 'run ' . $run);

            $ids = array_map(static fn ($rule): string => $rule->id, $this->site()->repository()->all());
            self::assertCount(10000, $ids, 'run ' . $run);
            self::assertCount(10000, array_unique($ids), 'run ' . $run . ': no id twice');
        }
        fwrite(STDERR, sprintf("\n[redirect-manager api benchmark] import of 10,000 rules x 20: %.2f s median, %.2f s max\n", $seconds[10], max($seconds)));
    }
}
