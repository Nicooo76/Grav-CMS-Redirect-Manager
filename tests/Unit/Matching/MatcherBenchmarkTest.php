<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Speed guard with 10,000 rules: 8,500 exact, 1,000 wildcard, 500 regex.
 *
 * Budgets are far above what the code needs, so slow CI machines pass; they only catch a return to
 * linear scans. Numbers go to STDERR and build/benchmark.json.
 */
#[CoversNothing]
#[Group('benchmark')]
final class MatcherBenchmarkTest extends TestCase
{
    private const EXACT = 8500;
    private const WILDCARD = 1000;
    private const REGEX = 500;
    private const REQUESTS = 2000;

    /**
     * @return list<Rule>
     */
    private static function rules(): array
    {
        $rules = [];
        for ($i = 0; $i < self::EXACT; ++$i) {
            $extra = [];
            if ($i % 40 === 0) {
                $extra['conditions'] = ['languages' => ['de', 'en']];
            }
            if ($i % 50 === 1) {
                $extra['conditions'] = ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'bot', 'negate' => true]]];
            }
            if ($i % 25 === 2) {
                $extra['case_sensitive'] = true;
            }
            if ($i % 30 === 3) {
                $extra['query_mode'] = 'pass';
            }
            $rules[] = Rule::fromArray(array_merge([
                'id' => sprintf('e%05d', $i),
                'source' => sprintf('/section-%d/old-page-%d', $i % 60, $i),
                'target' => sprintf('/section-%d/new-page-%d', $i % 60, $i),
                'priority' => $i % 7,
                'created_at' => sprintf('2026-01-%02dT10:00:00+00:00', 1 + $i % 28),
            ], $extra));
        }
        for ($i = 0; $i < self::WILDCARD; ++$i) {
            $rules[] = Rule::fromArray([
                'id' => sprintf('w%05d', $i),
                'source' => sprintf('/legacy-%d/*', $i),
                'target' => sprintf('/modern-%d/$1', $i),
                'match_type' => 'wildcard',
                'case_sensitive' => $i % 10 === 0,
            ]);
        }
        // A few rules with a wildcard at the start: these are candidates for every request.
        foreach (['*.pdf' => '/files$1.pdf', '*/print' => '/p$1', '*/amp' => '/a$1'] as $source => $target) {
            $rules[] = Rule::fromArray(['id' => 'wg-' . md5($source), 'source' => $source, 'target' => $target, 'match_type' => 'wildcard', 'priority' => -1]);
        }
        for ($i = 0; $i < self::REGEX; ++$i) {
            $rules[] = Rule::fromArray([
                'id' => sprintf('x%05d', $i),
                'source' => sprintf('^/shop%d/item-%d/(\d+)$', $i % 100, $i),
                'target' => sprintf('/store%d/item-%d/$1', $i % 100, $i),
                'match_type' => 'regex',
            ]);
        }
        foreach (['\.html?$' => '/html', '^/(?<lang>en|de)/legacy/(.*)$' => '/{lang}/$2'] as $pattern => $target) {
            $rules[] = Rule::fromArray(['id' => 'xg-' . md5($pattern), 'source' => $pattern, 'target' => $target, 'match_type' => 'regex', 'priority' => -1]);
        }

        return $rules;
    }

    /**
     * @return list<RequestContext>
     */
    private static function requests(): array
    {
        mt_srand(20260929);
        $requests = [];
        for ($n = 0; $n < self::REQUESTS; ++$n) {
            $kind = $n % 10;
            $i = mt_rand(0, self::EXACT - 1);
            $path = match (true) {
                $kind < 4 => sprintf('/section-%d/old-page-%d', $i % 60, $i),
                $kind < 6 => sprintf('/legacy-%d/some/deep/path-%d', mt_rand(0, self::WILDCARD - 1), $n),
                $kind < 7 => sprintf('/shop%d/item-%d/%d', ($i % 500) % 100, $i % 500, $n),
                default => sprintf('/unknown/page-%d/%d', $n, mt_rand(0, 99999)),
            };
            $ctx = MatcherHarness::context(['path' => $path, 'query' => ['q' => (string) $n], 'lang' => 'de', 'host' => 'example.com', 'headers' => ['user-agent' => 'Mozilla/5.0']]);
            self::assertNotNull($ctx);
            $requests[] = $ctx;
        }

        return $requests;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public function testTenThousandRules(): void
    {
        $rules = self::rules();
        self::assertGreaterThanOrEqual(10000, count($rules));
        $compiler = new RuleCompiler();

        // Compile: warm up once, then take the median of three runs.
        $compiler->compile($rules);
        $compileTimes = [];
        $set = null;
        for ($run = 0; $run < 3; ++$run) {
            $t = hrtime(true);
            $set = $compiler->compile($rules);
            $compileTimes[] = (hrtime(true) - $t) / 1e6;
        }
        self::assertNotNull($set);
        $compileMs = self::median($compileTimes);
        self::assertSame(count($rules), $set->count());
        self::assertLessThan(500.0, $compileMs, 'compile() of 10,000 rules');

        // Export the way the cache does: PHP file, include, fromArray.
        $file = sys_get_temp_dir() . '/redirect-manager-bench-' . getmypid() . '.php';
        $export = var_export($set->toArray(), true);
        file_put_contents($file, "<?php\n\nreturn " . $export . ";\n");
        try {
            $includeTimes = [];
            $fromArrayTimes = [];
            $restored = null;
            for ($run = 0; $run < 3; ++$run) {
                $restored = null; // free the previous run first so its destructor is not timed
                unset($data);
                $t = hrtime(true);
                /** @var array<string, mixed> $data */
                $data = include $file;
                $mid = hrtime(true);
                $restored = CompiledRuleSet::fromArray($data);
                $end = hrtime(true);
                $includeTimes[] = ($mid - $t) / 1e6;
                $fromArrayTimes[] = ($end - $mid) / 1e6;
            }
        } finally {
            unlink($file);
        }
        self::assertNotNull($restored);
        $fromArrayMs = self::median($fromArrayTimes);
        $includeMs = self::median($includeTimes);
        self::assertLessThan(50.0, $fromArrayMs, 'fromArray() must not do per-rule work');

        // Matching: warm up, then five rounds; the median round decides.
        $matcher = MatcherHarness::matcher($restored);
        $requests = self::requests();
        $hits = 0;
        foreach (array_slice($requests, 0, 300) as $ctx) {
            $matcher->match($ctx, MatchPhase::Early);
        }
        $rounds = [];
        for ($round = 0; $round < 5; ++$round) {
            $hits = 0;
            $t = hrtime(true);
            foreach ($requests as $ctx) {
                if ($matcher->match($ctx, MatchPhase::Early) !== null) {
                    ++$hits;
                }
            }
            $rounds[] = (hrtime(true) - $t) / 1e3 / count($requests); // microseconds per request
        }
        $avgMicros = self::median($rounds);

        // Sanity: the mix produces hits and misses, and a known request resolves to the right rule.
        self::assertGreaterThan(count($requests) * 0.4, $hits);
        self::assertLessThan(count($requests), $hits);
        $known = $matcher->match(MatcherHarness::context('/section-5/old-page-65') ?? new RequestContext('/'), MatchPhase::Early);
        self::assertSame('/section-5/new-page-65', $known?->location);
        $wild = $matcher->match(MatcherHarness::context('/legacy-77/a/b') ?? new RequestContext('/'), MatchPhase::Early);
        self::assertSame('/modern-77/a/b', $wild?->location);
        $regex = $matcher->match(MatcherHarness::context('/shop7/item-207/42') ?? new RequestContext('/'), MatchPhase::Early);
        self::assertSame('/store7/item-207/42', $regex?->location);

        self::assertLessThan(1000.0, $avgMicros, 'average match time must stay below 1 ms');

        $numbers = [
            'rules' => count($rules),
            'compile_ms' => round($compileMs, 2),
            'export_file_kb' => (int) round(strlen($export) / 1024),
            'include_ms' => round($includeMs, 2),
            'from_array_ms' => round($fromArrayMs, 4),
            'requests' => count($requests),
            'hits' => $hits,
            'avg_match_us' => round($avgMicros, 2),
            'php' => PHP_VERSION,
        ];
        $json = json_encode($numbers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            fwrite(STDERR, "\n[redirect-manager benchmark] " . str_replace("\n", ' ', $json) . "\n");
            $dir = dirname(__DIR__, 3) . '/build';
            if (is_dir($dir) && is_writable($dir)) {
                file_put_contents($dir . '/benchmark.json', $json . "\n");
            }
        }
    }
}
