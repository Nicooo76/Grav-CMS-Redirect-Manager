<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite;
use PHPUnit\Framework\Attributes\Group;

/**
 * The plugin's cost per request inside a real Grav site with 10,000 rules (85 % exact, 10 % wildcard, 5 % regex).
 *
 * The plugin reports the time of its early phase (request context, compiled rule cache, match) in the header
 * X-Redirect-Manager-Time when `debug_timing` is on. The server runs with OPcache for the CLI, as a production
 * PHP would. The full benchmark with totals and p95 is scripts/benchmark-request.sh (docs/PERFORMANCE.md).
 */
#[Group('benchmark')]
final class RequestBenchmarkTest extends IntegrationTestCase
{
    private const RULES = 10000;
    private const WARMUP = 60;
    private const REQUESTS = 400;
    private const MEDIAN_LIMIT_US = 1000.0;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required for the integration tests.');
        }
        if (TestSite::baseDir() === null) {
            self::markTestSkipped('No Grav test site. Run scripts/setup-test-site.sh first.');
        }
        self::$site = TestSite::create(['opcache.enable' => '1', 'opcache.enable_cli' => '1', 'opcache.validate_timestamps' => '1', 'opcache.memory_consumption' => '128']);
    }

    public function testEarlyPhaseTakesLessThanOneMillisecondWithTenThousandRules(): void
    {
        $rows = [];
        $exact = (int) (self::RULES * 0.85);
        $wildcard = (int) (self::RULES * 0.10);
        $regex = self::RULES - $exact - $wildcard;
        for ($i = 1; $i <= $exact; $i++) {
            $rows[] = ['id' => 'e' . $i, 'source' => '/old/page-' . $i, 'target' => '/new/page-' . $i, 'status' => 301];
        }
        for ($i = 1; $i <= $wildcard; $i++) {
            $rows[] = ['id' => 'w' . $i, 'source' => '/legacy-' . $i . '/*', 'target' => '/moved-' . $i . '/$1', 'match_type' => 'wildcard', 'status' => 301];
        }
        for ($i = 1; $i <= $regex; $i++) {
            $rows[] = ['id' => 'r' . $i, 'source' => '^/archive-' . $i . '/(\d{4})/([a-z0-9-]+)$', 'target' => '/blog-' . $i . '/$1/$2', 'match_type' => 'regex', 'status' => 301];
        }
        $this->rules($rows);
        $this->site()->writePluginConfig(['debug_timing' => true]);

        mt_srand(4711);
        $requests = [];
        for ($n = 0; $n < self::WARMUP + self::REQUESTS; $n++) {
            $roll = mt_rand(1, 100);
            $requests[] = match (true) {
                $roll <= 45 => ['/old/page-' . mt_rand(1, $exact), 301, '/new/page-'],
                $roll <= 60 => ['/legacy-' . mt_rand(1, $wildcard) . '/deep/file.html', 301, '/moved-'],
                $roll <= 70 => ['/archive-' . mt_rand(1, $regex) . '/2024/post-' . mt_rand(1, 99), 301, '/blog-'],
                $roll <= 85 => [mt_rand(0, 1) === 0 ? '/' : '/typography', 200, null],
                default => ['/no-such-page-' . mt_rand(1, 99999), 404, null],
            };
        }

        $times = [];
        foreach ($requests as $n => [$path, $status, $prefix]) {
            $response = $this->get($path);
            self::assertSame($status, $response->status, $path . ' ' . $response->describe());
            if ($prefix !== null) {
                self::assertStringStartsWith($prefix, (string) $response->location(), $path);
            }
            if ($n < self::WARMUP) {
                continue;
            }
            $header = $response->header('x-redirect-manager-time');
            self::assertNotNull($header, 'debug_timing adds the header to ' . $path);
            $times[] = (float) $header;
        }

        sort($times);
        $median = $times[(int) floor((count($times) - 1) * 0.50)];
        $p95 = $times[(int) ceil((count($times) - 1) * 0.95)];
        fwrite(STDERR, sprintf("\n[redirect-manager request benchmark] %d rules, %d requests: plugin time median %.1f us, p95 %.1f us, max %.1f us\n", self::RULES, count($times), $median, $p95, max($times)));

        self::assertLessThan(self::MEDIAN_LIMIT_US, $median, 'median plugin time per request in microseconds');
    }

    public function testTheHeaderIsAbsentWithoutDebugTiming(): void
    {
        $this->rules([['id' => 'a', 'source' => '/a', 'target' => '/typography', 'status' => 301]]);

        self::assertNull($this->get('/a')->header('x-redirect-manager-time'));
        self::assertNull($this->get('/typography')->header('x-redirect-manager-time'));
    }

    public function testTheHeaderIsOnRedirectsAndPagesWithDebugTiming(): void
    {
        $this->rules([['id' => 'a', 'source' => '/a', 'target' => '/typography', 'status' => 301]]);
        $this->site()->writePluginConfig(['debug_timing' => true]);

        foreach (['/a' => 301, '/typography' => 200, '/gone-forever' => 404] as $path => $status) {
            $response = $this->get($path);
            self::assertSame($status, $response->status, $path);
            self::assertMatchesRegularExpression('/^\d+\.\d$/', (string) $response->header('x-redirect-manager-time'), $path);
        }
    }
}
