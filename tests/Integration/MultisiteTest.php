<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Cli\ExitCode;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\CliRunner;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\HttpResponse;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite;
use PHPUnit\Framework\Attributes\Group;

/**
 * Multisite (I-24): one Grav installation whose `setup.php` maps the Host header to `user/sites/<name>/`, with the
 * plugin linked into both sites. Rules, hit counts, the 404 log, the compiled rule cache and the plugin settings
 * are per site, so a rule of site A never applies on site B.
 */
#[Group('integration')]
final class MultisiteTest extends IntegrationTestCase
{
    private TestSite $a;
    private TestSite $b;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required for the integration tests.');
        }
        if (TestSite::baseDir() === null) {
            self::markTestSkipped('No Grav test site. Run scripts/setup-test-site.sh first.');
        }
        self::$site = TestSite::createMultisite(['site-a', 'site-b']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->site()->forSite('site-a');
        $this->b = $this->site()->forSite('site-b');
        putenv('RM_SITE');
    }

    protected function tearDown(): void
    {
        putenv('RM_SITE');
    }

    private function assertRedirected(HttpResponse $r, int $status, string $location, string $message = ''): void
    {
        self::assertSame($status, $r->status, $message . ' ' . $r->describe());
        self::assertSame($location, $r->location(), $message);
        self::assertSame('Grav Redirect Manager', $r->header('x-redirect-by'), $message);
    }

    private function assertUntouched(HttpResponse $r, string $message = ''): void
    {
        self::assertNull($r->header('x-redirect-by'), $message . ' ' . $r->describe());
        self::assertSame(404, $r->status, $message . ' ' . $r->describe());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function logged(TestSite $site, string $path): array
    {
        return array_values(array_filter($site->notFoundEntries(), static fn (array $e): bool => ($e['p'] ?? null) === $path));
    }

    public function testTheSetupIsReallyTwoSites(): void
    {
        $dir = $this->site()->dir;
        self::assertFileExists($dir . '/setup.php');
        self::assertDirectoryExists($dir . '/user/sites/site-a/pages');
        self::assertDirectoryExists($dir . '/user/sites/site-b/pages');

        // Two sites, two page trees: a page of A does not exist on B.
        $this->a->writePage('only-on-a', 'Only on A', 'Body A', null, '20');
        self::assertSame(200, $this->a->request('/only-on-a')->status);
        self::assertSame(404, $this->b->request('/only-on-a')->status);
        // Both sites run the plugin: the default baseline page exists on both.
        self::assertSame(200, $this->a->request('/typography')->status);
        self::assertSame(200, $this->b->request('/typography')->status);
    }

    public function testARuleOfSiteADoesNotApplyOnSiteB(): void
    {
        $this->a->writeRules([['id' => 'ra', 'source' => '/only-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb', 'source' => '/only-b', 'target' => '/typography', 'status' => 302]]);

        $this->assertRedirected($this->a->request('/only-a'), 301, '/typography');
        $this->assertUntouched($this->b->request('/only-a'), 'site B has no rule for /only-a');

        $this->assertRedirected($this->b->request('/only-b'), 302, '/typography');
        $this->assertUntouched($this->a->request('/only-b'), 'site A has no rule for /only-b');
    }

    public function testTheSameSourceCanGoToDifferentTargetsPerSite(): void
    {
        $this->a->writeRules([['id' => 'r1', 'source' => '/promo', 'target' => '/typography', 'status' => 301]]);
        $this->b->writeRules([['id' => 'r1', 'source' => '/promo', 'target' => '/home', 'status' => 307]]);

        $this->assertRedirected($this->a->request('/promo'), 301, '/typography');
        $this->assertRedirected($this->b->request('/promo'), 307, '/home');
        // Alternating requests keep the answers apart (a shared cache would mix them up).
        for ($i = 0; $i < 3; ++$i) {
            $this->assertRedirected($this->b->request('/promo'), 307, '/home');
            $this->assertRedirected($this->a->request('/promo'), 301, '/typography');
        }
    }

    public function testRulesAreStoredInTheDataFolderOfTheirOwnSite(): void
    {
        $this->a->writeRules([['id' => 'ra', 'source' => '/only-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb', 'source' => '/only-b', 'target' => '/typography']]);

        $dir = $this->site()->dir;
        $fileA = (string) file_get_contents($dir . '/user/sites/site-a/data/redirect-manager/rules.yaml');
        $fileB = (string) file_get_contents($dir . '/user/sites/site-b/data/redirect-manager/rules.yaml');
        self::assertStringContainsString('/only-a', $fileA);
        self::assertStringNotContainsString('/only-b', $fileA);
        self::assertStringContainsString('/only-b', $fileB);
        self::assertStringNotContainsString('/only-a', $fileB);
        self::assertFileDoesNotExist($dir . '/user/data/redirect-manager/rules.yaml', 'nothing in a shared user/data');
    }

    public function testNotFoundLogsAndHitCountsAreKeptPerSite(): void
    {
        $this->a->writeRules([['id' => 'ra', 'source' => '/hit-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb', 'source' => '/hit-b', 'target' => '/typography']]);

        $this->a->request('/lost-a');
        $this->b->request('/lost-b');
        $this->a->request('/lost-both');
        $this->b->request('/lost-both');
        $this->b->request('/lost-both');
        $this->a->request('/hit-a');
        $this->b->request('/hit-b');
        $this->b->request('/hit-b');

        self::assertCount(1, self::logged($this->a, '/lost-a'));
        self::assertSame([], self::logged($this->b, '/lost-a'), 'site B never saw /lost-a');
        self::assertCount(1, self::logged($this->b, '/lost-b'));
        self::assertSame([], self::logged($this->a, '/lost-b'));
        self::assertCount(1, self::logged($this->a, '/lost-both'));
        self::assertCount(2, self::logged($this->b, '/lost-both'), 'each site counts its own visits');
        self::assertSame('site-a.test', self::logged($this->a, '/lost-a')[0]['h'], 'the host of the visit');
        self::assertSame('site-b.test', self::logged($this->b, '/lost-b')[0]['h']);

        self::assertSame(['ra'], $this->a->recordedHits());
        self::assertSame(['rb', 'rb'], $this->b->recordedHits());
    }

    public function testEachSiteHasItsOwnCompiledRuleCache(): void
    {
        $this->a->writeRules([['id' => 'ra', 'source' => '/only-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb', 'source' => '/only-b', 'target' => '/typography']]);
        $this->a->request('/only-a');
        $this->b->request('/only-b');

        $dir = $this->site()->dir;
        $cacheA = glob($dir . '/cache/site-a/redirect-manager/rules-*.php') ?: [];
        $cacheB = glob($dir . '/cache/site-b/redirect-manager/rules-*.php') ?: [];
        self::assertCount(1, $cacheA);
        self::assertCount(1, $cacheB);
        self::assertStringContainsString('/only-a', (string) file_get_contents($cacheA[0]));
        self::assertStringNotContainsString('/only-b', (string) file_get_contents($cacheA[0]));
        self::assertStringContainsString('/only-b', (string) file_get_contents($cacheB[0]));
        self::assertNotSame(basename($cacheA[0]), basename($cacheB[0]), 'the cache file is keyed by the rules file of the site');
        self::assertSame([], glob($dir . '/cache/redirect-manager/*') ?: [], 'no shared cache folder');
    }

    public function testChangingTheRulesOfOneSiteLeavesTheOtherAlone(): void
    {
        $this->a->writeRules([['id' => 'r', 'source' => '/x', 'target' => '/one']]);
        $this->b->writeRules([['id' => 'r', 'source' => '/x', 'target' => '/one']]);
        $this->assertRedirected($this->a->request('/x'), 301, '/one');
        $this->assertRedirected($this->b->request('/x'), 301, '/one');

        $this->a->writeRules([['id' => 'r', 'source' => '/x', 'target' => '/two']]);
        $this->assertRedirected($this->a->request('/x'), 301, '/two');
        $this->assertRedirected($this->b->request('/x'), 301, '/one', 'B still has its own rule');

        $this->a->writeRules([]);
        $this->assertUntouched($this->a->request('/x'));
        $this->assertRedirected($this->b->request('/x'), 301, '/one');
    }

    public function testPluginSettingsAreConfiguredPerSite(): void
    {
        $this->a->writeRules([['id' => 'r', 'source' => '/x', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'r', 'source' => '/x', 'target' => '/typography']]);
        $this->b->writePluginConfig(['redirects' => ['cache_control_permanent' => 'public, max-age=60']]);

        self::assertSame('public, max-age=3600', $this->a->request('/x')->header('cache-control'));
        self::assertSame('public, max-age=60', $this->b->request('/x')->header('cache-control'));

        // Switch the plugin off on A only.
        $this->a->writePluginConfig(['enabled' => false]);
        $this->assertUntouched($this->a->request('/x'));
        $this->assertRedirected($this->b->request('/x'), 301, '/typography');
        self::assertSame([], $this->a->notFoundEntries(), 'a disabled plugin logs nothing');
    }

    public function testAHostThatIsNoSiteFallsBackToTheFirstSiteAndDoesNotMixRules(): void
    {
        // The Host header picks the site; an unknown name maps to the first site in this setup.php.
        $this->a->writeRules([['id' => 'ra', 'source' => '/only-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb', 'source' => '/only-b', 'target' => '/typography']]);

        $unknown = $this->a->request('/only-b', ['headers' => ['Host' => 'other.test:' . $this->site()->port]]);
        $this->assertUntouched($unknown, 'site B\'s rule does not leak to an unknown host');
        $this->assertRedirected($this->a->request('/only-a', ['headers' => ['Host' => 'other.test:' . $this->site()->port]]), 301, '/typography');
    }

    public function testTheCommandLineWorksPerSite(): void
    {
        $this->a->writeRules([['id' => 'ra', 'source' => '/only-a', 'target' => '/typography']]);
        $this->b->writeRules([['id' => 'rb1', 'source' => '/only-b', 'target' => '/typography'], ['id' => 'rb2', 'source' => '/also-b', 'target' => '/home']]);
        $cli = new CliRunner($this->site());

        putenv('RM_SITE=site-a');
        $listA = $cli->run(['rules', '--json']);
        self::assertSame(ExitCode::OK, $listA['code'], $listA['stderr']);
        self::assertSame(['ra'], array_column((array) (json_decode($listA['stdout'], true)['data'] ?? []), 'id'));

        putenv('RM_SITE=site-b');
        $listB = $cli->run(['rules', '--json']);
        self::assertSame(ExitCode::OK, $listB['code'], $listB['stderr']);
        self::assertEqualsCanonicalizing(['rb1', 'rb2'], array_column((array) (json_decode($listB['stdout'], true)['data'] ?? []), 'id'));

        $add = $cli->run(['add', '/added-on-b', '/typography']);
        self::assertSame(ExitCode::OK, $add['code'], $add['stderr']);
        putenv('RM_SITE');
        $this->assertRedirected($this->b->request('/added-on-b'), 302, '/typography', 'created by the CLI on site B (302 is Grav\'s default code)');
        $this->assertUntouched($this->a->request('/added-on-b'));
        self::assertCount(1, $this->a->repository()->all());
        self::assertCount(3, $this->b->repository()->all());
    }
}
