<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiResponse;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\StaticServer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/** REST API, system: dashboard numbers, live target checks, page search and the MCP tool list. */
#[Group('integration')]
final class ApiSystemTest extends ApiTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    private static ?StaticServer $static = null;

    public static function tearDownAfterClass(): void
    {
        self::$static?->stop();
        self::$static = null;
        parent::tearDownAfterClass();
    }

    /**
     * Rules for the live check, plus a site whose base URL is a second tiny server (see below).
     *
     * The live check requests internal targets with the site's base URL. The test server handles one request at a
     * time and would wait for itself, so the base URL points at a second server: /ok.html is 200, the rest 404.
     * Nothing here may reach the internet: external targets are skipped (`checker.check_external`).
     *
     * @param array<string, mixed> $checker extra `checker` settings
     */
    private function checkSetup(array $checker = []): void
    {
        self::$static ??= StaticServer::start();
        $this->site()->writeSystemConfig(['custom_base_url' => self::$static->baseUrl()]);
        $this->site()->writePluginConfig(['checker' => $checker + ['check_external' => false], 'security' => ['allowed_hosts' => ['example.com']]]);
        $this->rules([
            ['id' => 'ok', 'source' => '/a', 'target' => '/ok.html', 'status' => 301],
            ['id' => 'bad', 'source' => '/b', 'target' => '/missing', 'status' => 301],
            ['id' => 'gone', 'source' => '/c', 'target' => '', 'status' => 410],
            ['id' => 'off', 'source' => '/d', 'target' => '/missing', 'status' => 301, 'enabled' => false],
            ['id' => 'ext', 'source' => '/e', 'target' => 'https://example.com/x', 'status' => 302, 'target_type' => 'url'],
        ]);
    }

    // ------------------------------------------------------------------ stats

    public function testStatsOfAnEmptySite(): void
    {
        $response = $this->api->get('/redirects/stats');
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame(
            ['not_found_today', 'not_found_7d', 'not_found_by_day', 'hits_today', 'hits_7d', 'hits_by_day', 'rules_total', 'rules_active', 'open_suggestions', 'dead_targets', 'pending_deletes'],
            array_keys($data),
        );
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], [$data['not_found_today'], $data['not_found_7d'], $data['hits_today'], $data['hits_7d'], $data['rules_total'], $data['rules_active'], $data['open_suggestions'], $data['dead_targets']]);
        self::assertSame(0, $data['pending_deletes']);
        foreach (['not_found_by_day', 'hits_by_day'] as $series) {
            self::assertCount(30, $data[$series], $series . ' covers 30 days');
            self::assertSame(date('Y-m-d'), array_key_last($data[$series]));
            self::assertSame(0, array_sum($data[$series]));
        }
    }

    public function testStatsCountHitsAnd404sAndRules(): void
    {
        $this->rules([
            ['id' => 'a', 'source' => '/a', 'target' => '/typography', 'status' => 301],
            ['id' => 'b', 'source' => '/b', 'target' => '/typography', 'status' => 302],
            ['id' => 'off', 'source' => '/off', 'target' => '/typography', 'enabled' => false],
            ['id' => 'old', 'source' => '/old', 'target' => '/typography', 'expires_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        foreach (['/a', '/a', '/a', '/b'] as $path) {
            $this->assertRedirect($this->get($path), $path === '/b' ? 302 : 301, '/typography');
        }
        foreach (['/nope1', '/nope1', '/nope2'] as $path) {
            self::assertSame(404, $this->get($path, ['headers' => ['User-Agent' => self::BROWSER]])->status);
        }
        self::assertSame(404, $this->get('/bot-path')->status, 'a bot 404 is logged but not counted');
        $this->get('/typograpy', ['headers' => ['User-Agent' => self::BROWSER]]);
        $this->api->post('/redirects/suggestions/generate');

        $data = $this->api->get('/redirects/stats')->data();
        $today = date('Y-m-d');
        self::assertSame([4, 4], [$data['hits_today'], $data['hits_7d']]);
        self::assertSame(4, $data['hits_by_day'][$today]);
        self::assertSame([4, 4], [$data['not_found_today'], $data['not_found_7d']], 'three browser 404s and /typograpy');
        self::assertSame(4, $data['not_found_by_day'][$today]);
        self::assertSame([4, 2], [$data['rules_total'], $data['rules_active']], 'a disabled and an expired rule are not active');
        self::assertSame(1, $data['open_suggestions']);
        self::assertSame([0, 0], [$data['dead_targets'], $data['pending_deletes']]);
    }

    public function testStatsReflectRuleChangesAtOnce(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        self::assertSame([1, 1], [$this->api->get('/redirects/stats')->data()['rules_total'], $this->api->get('/redirects/stats')->data()['rules_active']]);
        $this->api->patch('/redirects/rules/' . $rule['id'], ['enabled' => false]);
        self::assertSame([1, 0], [$this->api->get('/redirects/stats')->data()['rules_total'], $this->api->get('/redirects/stats')->data()['rules_active']]);
        $this->api->delete('/redirects/rules/' . $rule['id']);
        self::assertSame(0, $this->api->get('/redirects/stats')->data()['rules_total']);
    }

    // ------------------------------------------------------------------ checks

    public function testChecksAreEmptyBeforeTheFirstRun(): void
    {
        $response = $this->api->get('/redirects/checks');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['last_run' => null, 'results' => []], $response->data());
    }

    public function testRunningTheChecksReportsLiveAndDeadTargets(): void
    {
        $this->checkSetup();

        $response = $this->api->post('/redirects/checks/run');
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame(2, $data['checked'], 'a 410 rule, a disabled rule and the skipped external target are not requested');
        self::assertSame(1, $data['dead']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['last_run']);

        $rows = array_column($data['results'], null, 'rule_id');
        self::assertEqualsCanonicalizing(['ok', 'bad', 'ext'], array_keys($rows), 'no rows for the 410 and the disabled rule');
        foreach (['rule_id', 'url', 'status', 'ok', 'error', 'final_url', 'redirects', 'duration_ms', 'checked_at', 'source', 'target'] as $key) {
            self::assertArrayHasKey($key, $rows['ok']);
        }
        self::assertSame([200, true, null, '/a', '/ok.html'], [$rows['ok']['status'], $rows['ok']['ok'], $rows['ok']['error'], $rows['ok']['source'], $rows['ok']['target']]);
        self::assertSame(self::$static?->baseUrl() . '/ok.html', $rows['ok']['url'], 'internal targets are requested with the base URL of the site');
        self::assertSame([404, false], [$rows['bad']['status'], $rows['bad']['ok']]);
        self::assertFalse($rows['ext']['ok']);
        self::assertSame('skipped_external', $rows['ext']['error']);
    }

    public function testStoredCheckResultsShowUpInListBadgesAndStats(): void
    {
        $this->checkSetup();
        self::assertSame(0, $this->api->get('/redirects/stats')->data()['dead_targets']);
        $this->api->post('/redirects/checks/run');

        $checks = $this->api->get('/redirects/checks')->data();
        self::assertNotNull($checks['last_run']);
        self::assertSame(['bad', 'ext', 'ok'], array_column($checks['results'], 'rule_id'), 'dead targets first');

        $rules = array_column($this->api->get('/redirects/rules')->data(), null, 'id');
        self::assertContains('dead_target', $rules['bad']['badges']);
        self::assertNotContains('dead_target', $rules['ok']['badges']);
        self::assertNotContains('dead_target', $rules['off']['badges']);
        self::assertSame(['bad'], array_column($this->api->get('/redirects/rules', ['badge' => 'dead_target'])->data(), 'id'));
        self::assertSame(1, $this->api->get('/redirects/rules')->meta()['counts']['dead_target']);
        self::assertSame(1, $this->api->get('/redirects/stats')->data()['dead_targets']);
        self::assertContains('dead_target', $this->api->get('/redirects/rules/bad')->data()['badges']);
    }

    public function testEditingARuleDropsItsDeadBadge(): void
    {
        $this->checkSetup();
        $this->api->post('/redirects/checks/run');
        self::assertSame(['bad'], array_column($this->api->get('/redirects/rules', ['badge' => 'dead_target'])->data(), 'id'));

        sleep(1); // timestamps have a resolution of one second: the edit must be newer than the check
        $this->api->patch('/redirects/rules/bad', ['target' => '/typography']);
        self::assertSame([], $this->api->get('/redirects/rules', ['badge' => 'dead_target'])->data(), 'a result older than the last edit says nothing about the new target');
    }

    public function testASecondRunWithinTheIntervalIs429WithRetryAfter(): void
    {
        $this->checkSetup();
        self::assertSame(200, $this->api->post('/redirects/checks/run')->status);
        $lastRun = $this->api->get('/redirects/checks')->data()['last_run'];

        $second = $this->api->post('/redirects/checks/run');
        $this->assertProblem($second, 429);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $second->header('retry-after'));
        self::assertGreaterThan(0, (int) $second->header('retry-after'));
        self::assertLessThanOrEqual(300, (int) $second->header('retry-after'));
        self::assertSame(429, $second->json['status'] ?? null);
        self::assertSame($lastRun, $this->api->get('/redirects/checks')->data()['last_run'], 'the refused run changed nothing');
    }

    public function testTheIntervalIsConfigurable(): void
    {
        $this->checkSetup(['manual_interval' => 0]);
        self::assertSame(200, $this->api->post('/redirects/checks/run')->status);
        self::assertSame(200, $this->api->post('/redirects/checks/run')->status);
    }

    public function testRunningTheChecksForSomeRulesOnly(): void
    {
        $this->checkSetup();

        $unknown = $this->api->post('/redirects/checks/run', ['ids' => ['ok', 'rghost']]);
        $this->assertProblem($unknown, 404);

        $one = $this->api->post('/redirects/checks/run', ['ids' => ['bad']]);
        self::assertSame(200, $one->status, $one->describe() . ' (a refused id does not use up the interval)');
        self::assertSame([1, 1], [$one->data()['checked'], $one->data()['dead']]);
        self::assertSame(['bad'], array_column($one->data()['results'], 'rule_id'));
    }

    public function testChecksWithNothingToCheckStillReportARun(): void
    {
        $this->rules([['id' => 'gone', 'source' => '/c', 'target' => '', 'status' => 410]]);
        $response = $this->api->post('/redirects/checks/run');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame([0, 0, []], [$response->data()['checked'], $response->data()['dead'], $response->data()['results']]);
    }

    // ------------------------------------------------------------------ pages

    private function multilanguagePages(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About us', 'x', 'en', '10');
        $this->site()->writePage('about', 'Ueber uns', 'x', 'de', '10', "slug: ueber-uns\n");
        $this->site()->writePage('blog', 'Blog', 'x', 'en', '20');
        $this->site()->writePage('blog', 'Blog DE', 'x', 'de', '20');
        $this->site()->writePage('contact', 'Contact Info', 'x', 'en', '30');
    }

    public function testPagesListAllPagesSortedByRoute(): void
    {
        $response = $this->api->get('/redirects/pages');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(
            [['route' => '/', 'title' => 'Home', 'language' => '', 'translations' => []], ['route' => '/typography', 'title' => 'Typography', 'language' => '', 'translations' => []]],
            $response->data(),
            'a single-language site has no language',
        );
    }

    public function testPagesSearchByRouteOrTitle(): void
    {
        $this->site()->writePage('blog-post', 'Ein Beitrag', 'x', null, '20');
        $routes = static fn (ApiResponse $r): array => array_column((array) $r->data(), 'route');

        self::assertSame(['/typography'], $routes($this->api->get('/redirects/pages', ['q' => 'typo'])));
        self::assertSame(['/typography'], $routes($this->api->get('/redirects/pages', ['q' => '/typo'])));
        self::assertSame(['/blog-post'], $routes($this->api->get('/redirects/pages', ['q' => 'beitrag'])), 'the title is searched too');
        self::assertSame(['/blog-post'], $routes($this->api->get('/redirects/pages', ['q' => 'BLOG'])), 'case-insensitive');
        self::assertSame([], $routes($this->api->get('/redirects/pages', ['q' => 'no-such-page'])));
        self::assertSame(['/', '/blog-post', '/typography'], $routes($this->api->get('/redirects/pages')));

        $this->site()->writePage('typo-guide', 'Guide', 'x', null, '30');
        $this->site()->writePage('my-typography', 'Notes', 'x', null, '40');
        self::assertSame(['/typo-guide', '/typography', '/my-typography'], $routes($this->api->get('/redirects/pages', ['q' => 'typo'])), 'route prefix first (shorter first), then contains');
    }

    public function testPagesLimit(): void
    {
        $this->site()->writePage('a-page', 'A', 'x', null, '20');
        $this->site()->writePage('b-page', 'B', 'x', null, '30');
        self::assertCount(2, $this->api->get('/redirects/pages', ['limit' => 2])->data());
        self::assertCount(1, $this->api->get('/redirects/pages', ['limit' => 1])->data());
        self::assertCount(1, $this->api->get('/redirects/pages', ['limit' => 0])->data(), 'at least one');
        self::assertCount(4, $this->api->get('/redirects/pages')->data(), 'the default limit is 20');
    }

    public function testPagesCarryLanguageAndTranslationsOnAMultilanguageSite(): void
    {
        $this->multilanguagePages();
        $rows = $this->api->get('/redirects/pages')->data();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['language'] . ' ' . $row['route']] = $row;
        }
        self::assertSame(['About us', ['de']], [$byKey['en /about']['title'], $byKey['en /about']['translations']]);
        self::assertSame(['Ueber uns', ['en']], [$byKey['de /ueber-uns']['title'], $byKey['de /ueber-uns']['translations']]);
        self::assertSame(['Blog DE', ['en']], [$byKey['de /blog']['title'], $byKey['de /blog']['translations']]);
        self::assertSame([], $byKey['en /contact']['translations']);

        $de = $this->api->get('/redirects/pages', ['language' => 'de'])->data();
        self::assertSame(['/blog', '/ueber-uns'], array_column($de, 'route'));
        self::assertSame(['de', 'de'], array_column($de, 'language'));
        $en = $this->api->get('/redirects/pages', ['language' => 'EN', 'limit' => 2])->data();
        self::assertSame(['/', '/about'], array_column($en, 'route'), 'the language is case-insensitive');
        self::assertSame(['/ueber-uns'], array_column($this->api->get('/redirects/pages', ['q' => 'ueber'])->data(), 'route'));
        self::assertSame([], $this->api->get('/redirects/pages', ['q' => 'ueber', 'language' => 'en'])->data());
    }

    // ------------------------------------------------------------------ MCP

    /**
     * @return array<string, array<string, mixed>> redirect tools by name
     */
    private function redirectTools(ApiResponse $response): array
    {
        self::assertSame(200, $response->status, $response->describe());
        $tools = [];
        foreach ((array) $response->data()['tools'] as $tool) {
            if (($tool['plugin'] ?? '') === 'redirect-manager') {
                $tools[$tool['name']] = $tool;
            }
        }

        return $tools;
    }

    public function testMcpListsTheRedirectToolsWithoutWarnings(): void
    {
        $response = $this->api->get('/mcp/tools');
        $tools = $this->redirectTools($response);
        self::assertSame(['redirects_list', 'redirects_create', 'redirects_test', 'redirects_top_404', 'redirects_suggest', 'redirects_import'], array_keys($tools));
        self::assertSame([], $response->data()['warnings'], 'mcp.yaml is valid: no warnings');
        $plugin = array_values(array_filter($response->data()['plugins'], static fn (array $p): bool => $p['slug'] === 'redirect-manager'))[0];
        self::assertSame(['Redirect Manager', 6], [$plugin['name'], $plugin['tools']]);

        $yaml = Yaml::parseFile(__DIR__ . '/../../mcp.yaml');
        foreach ($yaml['tools'] as $definition) {
            $tool = $tools[$yaml['prefix'] . '_' . $definition['name']];
            self::assertSame([$definition['method'], $definition['path'], $definition['permission']], [$tool['method'], $tool['path'], $tool['permission']], $tool['name']);
            self::assertSame(array_keys($definition['input']['properties']), array_keys($tool['input_schema']['properties']), $tool['name']);
            self::assertSame($definition['input']['required'] ?? null, $tool['input_schema']['required'] ?? null, $tool['name']);
            self::assertNotSame('', trim((string) $tool['description']));
        }
        self::assertTrue($tools['redirects_list']['annotations']['readOnly']);
        self::assertFalse($tools['redirects_create']['annotations']['readOnly']);
        self::assertSame(['dry_run'], $tools['redirects_create']['query']);
    }

    public function testMcpToolsFollowThePermissionsOfTheCaller(): void
    {
        $reader = $this->redirectTools($this->reader->get('/mcp/tools'));
        self::assertSame(['redirects_list', 'redirects_test', 'redirects_top_404', 'redirects_suggest'], array_keys($reader), 'read tools only');
        foreach ($reader as $tool) {
            self::assertSame('api.redirects.read', $tool['permission']);
        }
        self::assertSame([], $this->redirectTools($this->basic->get('/mcp/tools')), 'api.access alone sees none of them');
        self::assertSame(401, $this->api->withoutAuth()->get('/mcp/tools')->status);
    }

    public function testEveryMcpToolPointsAtAWorkingRoute(): void
    {
        $this->site()->writePage('blog-post', 'Blog Post', 'x', null, '20');
        $tools = $this->redirectTools($this->api->get('/mcp/tools'));
        $arguments = [
            'redirects_list' => [[], []],
            'redirects_create' => [['dry_run' => 'true'], ['source' => '/old', 'target' => '/typography']],
            'redirects_test' => [[], ['url' => '/old']],
            'redirects_top_404' => [['days' => 7], []],
            'redirects_suggest' => [['path' => '/blog-pots'], []],
            'redirects_import' => [[], ['content' => "source,target,status\n/imported,/typography,301\n", 'format' => 'csv']],
        ];
        foreach ($tools as $name => $tool) {
            [$query, $body] = $arguments[$name];
            self::assertStringStartsWith('/redirects/', $tool['path']);
            $response = $this->api->request($tool['method'], $tool['path'], $tool['method'] === 'GET' ? null : $body, $query);
            self::assertSame(200, $response->status, $name . ' ' . $response->describe());
            self::assertNotNull($response->json, $name);
        }
        self::assertSame(['/imported'], array_column($this->api->get('/redirects/rules')->data(), 'source'), 'only the import saved something, the dry run did not');
    }
}
