<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** REST API, import and export: formats, preview, commit, crawler files, sitemap diff, export, site.yaml. */
#[Group('integration')]
final class ApiImportExportTest extends ApiTestCase
{
    private const FIXTURES = __DIR__ . '/../Unit/ImportExport/fixtures/';

    private const WORDPRESS_JSON = [
        'plugin' => ['version' => '5.4.2'],
        'groups' => [['id' => 1, 'name' => 'Relaunch', 'module_id' => 1, 'enabled' => true]],
        'redirects' => [
            ['id' => 1, 'url' => '/kontakt-alt', 'match_url' => '/kontakt-alt', 'match_data' => ['source' => ['flag_query' => 'exact', 'flag_case' => false, 'flag_trailing' => true, 'flag_regex' => false]], 'action_code' => 301, 'action_type' => 'url', 'action_data' => ['url' => '/typography'], 'match_type' => 'url', 'title' => 'Kontakt', 'hits' => 5, 'regex' => false, 'group_id' => 1, 'position' => 0, 'enabled' => true, 'status' => 'enabled'],
            ['id' => 2, 'url' => '^/blog/(\d+)$', 'match_url' => '^/blog/(\d+)$', 'match_data' => ['source' => ['flag_regex' => true]], 'action_code' => 302, 'action_type' => 'url', 'action_data' => ['url' => '/typography'], 'match_type' => 'url', 'regex' => true, 'group_id' => 1, 'position' => 1, 'enabled' => false, 'status' => 'disabled'],
            ['id' => 3, 'url' => '/weg', 'match_url' => '/weg', 'action_code' => 410, 'action_type' => 'error', 'action_data' => null, 'match_type' => 'url', 'regex' => false, 'group_id' => 1, 'position' => 2, 'enabled' => true, 'status' => 'enabled'],
        ],
    ];

    /**
     * Small inline samples per import format: [format, content, expected rules as [source, target, match_type, status]].
     *
     * @return array<string, array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string, 3: int}>}>
     */
    public static function samples(): array
    {
        return [
            'csv' => ['csv', "source,target,status,match_type\n/a,/typography,301,exact\n/b/*,/typography,302,wildcard\n", [
                ['/a', '/typography', 'exact', 301], ['/b/*', '/typography', 'wildcard', 302],
            ]],
            'json' => ['json', json_encode(['version' => 1, 'rules' => [['source' => '/a', 'target' => '/typography', 'status' => 301], ['source' => '/b/*', 'target' => '/typography', 'match_type' => 'wildcard']]], JSON_THROW_ON_ERROR), [
                ['/a', '/typography', 'exact', 301], ['/b/*', '/typography', 'wildcard', 302],
            ]],
            'yaml' => ['yaml', "version: 1\nrules:\n  - source: /a\n    target: /typography\n    status: 301\n  - source: /b\n    target: /typography\n", [
                ['/a', '/typography', 'exact', 301], ['/b', '/typography', 'exact', 302],
            ]],
            'htaccess' => ['htaccess', "Redirect 301 /alt/seite.html /neu/seite\nRedirect gone /entfernt\nRedirectMatch 301 ^/produkte/(\\d+)/?\$ /shop/artikel-\$1\n", [
                ['/alt/seite.html', '/neu/seite', 'exact', 301], ['/entfernt', '', 'exact', 410], ['^/produkte/(\d+)/?$', '/shop/artikel-$1', 'regex', 301],
            ]],
            'nginx' => ['nginx', "server {\n location = /alt { return 301 /neu; }\n location = /entfernt { return 410; }\n location ~ ^/produkte/(\\d+)\$ { return 301 /shop/artikel-\$1; }\n}\n", [
                ['/alt', '/neu', 'exact', 301], ['/entfernt', '', 'exact', 410], ['^/produkte/(\d+)$', '/shop/artikel-$1', 'regex', 301],
            ]],
            'netlify' => ['netlify', "/home / 301\n/blog/* /news/:splat 301\n/gone /404.html 410\n", [
                ['/home', '/', 'exact', 301], ['/blog/*', '/news/$1', 'wildcard', 301], ['/gone', '', 'exact', 410],
            ]],
            'grav_site' => ['grav_site', "redirects:\n    '/alte-seite': '/neue-seite'\n    '/impressum.html': '/impressum [301]'\n    '/blog/(.*)': '/magazin/\$1 [301]'\nroutes:\n    '/alias': '/typography'\n", [
                ['/alte-seite', '/neue-seite', 'exact', 302], ['/impressum.html', '/impressum', 'exact', 301], ['/blog/*', '/magazin/$1', 'wildcard', 301], ['/alias', '/typography', 'exact', 200],
            ]],
            'wordpress_json' => ['wordpress_json', json_encode(self::WORDPRESS_JSON, JSON_THROW_ON_ERROR), [
                ['/kontakt-alt', '/typography', 'exact', 301], ['^/blog/(\d+)$', '/typography', 'regex', 302], ['/weg', '', 'exact', 410],
            ]],
            'wordpress_csv' => ['wordpress_csv', "source,target,regex,code,type,hits,title,status\n/kontakt-alt,/typography,0,301,url,154,Kontakt,enabled\n\"/blog/(\\d{4})/(.*)\",\"/magazin/\$1/\$2\",1,301,url,2210,,enabled\n/shop/alt,,0,410,error,3,Weg,enabled\n/kampagne,/,0,302,url,0,Vorueber,disabled\n", [
                ['/kontakt-alt', '/typography', 'exact', 301], ['/blog/(\d{4})/(.*)', '/magazin/$1/$2', 'regex', 301], ['/shop/alt', '', 'exact', 410], ['/kampagne', '/', 'exact', 302],
            ]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return array_map(static fn ($rule): array => $rule->toArray(), $this->site()->repository()->all());
    }

    /**
     * @param list<array<string, mixed>> $rules
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private static function brief(array $rules): array
    {
        return array_map(static fn (array $r): array => [$r['source'], $r['target'], $r['match_type'], $r['status']], $rules);
    }

    // ------------------------------------------------------------------ formats

    public function testFormatsListNamesEveryFormatWithItsCapabilities(): void
    {
        $response = $this->api->get('/redirects/import/formats');
        self::assertSame(200, $response->status, $response->describe());
        $formats = array_column($response->data(), null, 'id');

        self::assertSame(
            ['csv', 'json', 'yaml', 'grav_site', 'htaccess', 'nginx', 'wordpress_json', 'wordpress_csv', 'crawler_csv', 'cloudflare_csv', 'netlify'],
            array_keys($formats),
        );
        foreach ($formats as $format) {
            self::assertSame(['id', 'label', 'import', 'export', 'extension', 'mime'], array_keys($format));
            self::assertNotSame('', $format['label']);
            self::assertIsBool($format['import']);
            self::assertIsBool($format['export']);
        }
        self::assertSame([true, false], [$formats['crawler_csv']['import'], $formats['crawler_csv']['export']], 'crawler files are import only');
        self::assertSame([false, true], [$formats['cloudflare_csv']['import'], $formats['cloudflare_csv']['export']], 'Cloudflare is export only');
        self::assertSame(['text/csv', 'csv'], [$formats['csv']['mime'], $formats['csv']['extension']]);
        self::assertSame(['application/json', 'json'], [$formats['json']['mime'], $formats['json']['extension']]);
    }

    // ------------------------------------------------------------------ preview and commit

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int}> $expected
     */
    #[DataProvider('samples')]
    public function testPreviewThenCommitForEveryImportFormat(string $format, string $content, array $expected): void
    {
        $preview = $this->api->post('/redirects/import/preview', ['content' => $content, 'format' => $format]);
        self::assertSame(200, $preview->status, $preview->describe());
        $data = $preview->data();
        self::assertSame($format, $data['format']);
        self::assertSame([], $data['errors']);
        self::assertSame(count($expected), $data['counts']['total']);
        self::assertSame(count($expected), $data['counts']['valid']);
        self::assertSame([0, 0], [$data['counts']['errors'], $data['counts']['duplicates']]);
        self::assertTrue($data['relations_checked']);
        self::assertSame($expected, self::brief(array_column($data['rows'], 'rule')));
        foreach ($data['rows'] as $row) {
            self::assertSame(['line', 'raw', 'rule', 'errors', 'warnings', 'duplicate_of', 'duplicate_in_file'], array_keys($row));
            self::assertSame([], $row['errors']);
            self::assertSame('import', $row['rule']['origin']);
        }
        self::assertSame([], $this->stored(), 'a preview stores nothing');

        $commit = $this->api->post('/redirects/import/commit', ['content' => $content, 'format' => $format]);
        self::assertSame(200, $commit->status, $commit->describe());
        self::assertSame($format, $commit->data()['format']);
        self::assertSame([count($expected), 0, false, 0], [$commit->data()['created'], $commit->data()['skipped'], $commit->data()['rules_truncated'], $commit->data()['suggestions']]);
        self::assertSame($expected, self::brief($commit->data()['rules']));
        self::assertSame($expected, self::brief($this->stored()));
        self::assertSame(['import'], array_unique(array_column($this->stored(), 'origin')));
    }

    public function testImportedRulesWorkOnTheFrontend(): void
    {
        $this->api->post('/redirects/import/commit', ['format' => 'netlify', 'content' => "/home /typography 301\n/blog/* /typography 302\n/gone /404.html 410\n"]);
        $this->assertRedirect($this->get('/home'), 301, '/typography');
        $this->assertRedirect($this->get('/blog/anything'), 302, '/typography');
        self::assertSame(410, $this->get('/gone')->status);
    }

    public function testTheFormatIsDetectedFromContentAndFilename(): void
    {
        $json = $this->api->post('/redirects/import/preview', ['content' => (string) json_encode(['rules' => [['source' => '/a', 'target' => '/typography']]])]);
        self::assertSame('json', $json->data()['format']);

        $htaccess = $this->api->post('/redirects/import/preview', ['content' => "Redirect 301 /a /typography\n", 'filename' => '.htaccess']);
        self::assertSame('htaccess', $htaccess->data()['format']);

        $csv = $this->api->post('/redirects/import/preview', ['content' => "source,target,status\n/a,/typography,301\n", 'filename' => 'export.csv']);
        self::assertSame(['csv', 1], [$csv->data()['format'], $csv->data()['counts']['valid']]);

        $unknown = $this->api->post('/redirects/import/preview', ['content' => 'just some words']);
        self::assertSame(200, $unknown->status);
        self::assertNull($unknown->data()['format']);
        self::assertSame('unknown_format', $unknown->data()['errors'][0]['code']);
    }

    public function testOptionsShapeTheImportedRules(): void
    {
        $content = "source;target\n/a;/typography\n";
        $response = $this->api->post('/redirects/import/commit', [
            'content' => $content,
            'format' => 'csv',
            'options' => ['delimiter' => ';', 'default_status' => 308, 'default_group' => 'Migration'],
        ]);
        self::assertSame(200, $response->status, $response->describe());
        $rule = $response->data()['rules'][0];
        self::assertSame([308, 'Migration'], [$rule['status'], $rule['group']]);

        $bad = $this->api->post('/redirects/import/commit', ['content' => $content, 'format' => 'csv', 'options' => ['default_status' => 999]]);
        $this->assertProblem($bad, 422);
        self::assertSame('options.default_status', $bad->errors()[0]['field']);
    }

    public function testDuplicatesAreFlaggedAndSkippedByDefault(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/old', 'target' => '/typography', 'status' => 301]]);
        $csv = "source,target,status\n/old,/typography,301\n/new1,/typography,301\n/new1,/typography,301\n";

        $preview = $this->api->post('/redirects/import/preview', ['content' => $csv, 'format' => 'csv'])->data();
        self::assertSame(2, $preview['counts']['duplicates']);
        self::assertSame(['r1', null, null], array_column($preview['rows'], 'duplicate_of'));
        self::assertSame([false, false, true], array_column($preview['rows'], 'duplicate_in_file'));
        self::assertSame('duplicate', $preview['rows'][0]['warnings'][0]['code']);

        $commit = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv']);
        self::assertSame([1, 2], [$commit->data()['created'], $commit->data()['skipped']]);
        self::assertSame(['/old', '/new1'], array_column($this->stored(), 'source'));

        $again = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv']);
        self::assertSame([0, 3], [$again->data()['created'], $again->data()['skipped']]);
    }

    public function testSkipDuplicatesFalseCreatesThemAnyway(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/old', 'target' => '/typography', 'status' => 301]]);
        $response = $this->api->post('/redirects/import/commit', ['content' => "source,target,status\n/old,/typography,301\n", 'format' => 'csv', 'skip_duplicates' => false]);
        self::assertSame(1, $response->data()['created']);
        self::assertCount(2, $this->stored());
    }

    public function testAnInvalidRegexRowIsAnErrorAndRejectsTheCommitUnlessInvalidRowsAreSkipped(): void
    {
        $csv = "source,target,status,match_type\n/good,/typography,301,exact\n/(bad,/typography,301,regex\n/(a+)+\$,/typography,301,regex\n/also-good,/typography,302,exact\n";

        $preview = $this->api->post('/redirects/import/preview', ['content' => $csv, 'format' => 'csv'])->data();
        self::assertSame(2, $preview['counts']['errors']);
        self::assertSame(2, $preview['counts']['valid']);
        self::assertNull($preview['rows'][1]['rule'], 'no rule for a row with errors');
        self::assertSame('invalid_regex', $preview['rows'][1]['errors'][0]['code']);
        self::assertSame(3, $preview['rows'][1]['line']);
        self::assertNotSame([], $preview['rows'][2]['errors']);
        self::assertNotNull($preview['rows'][0]['rule']);

        $refused = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv', 'skip_invalid' => false]);
        $this->assertProblem($refused, 422);
        self::assertSame(['rows.3', 'invalid_regex', 3], [$refused->errors()[0]['field'], $refused->errors()[0]['code'], $refused->errors()[0]['params']['line']]);
        self::assertCount(2, $refused->errors());
        self::assertSame([], $this->stored(), 'the refused import created nothing, not even the valid rows');
        $this->assertProblem($this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv']), 422, 'skip_invalid defaults to false');

        $partial = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv', 'skip_invalid' => true]);
        self::assertSame(200, $partial->status, $partial->describe());
        self::assertSame([2, 2], [$partial->data()['created'], $partial->data()['skipped']]);
        self::assertSame(['/good', '/also-good'], array_column($this->stored(), 'source'));
    }

    public function testCommitCanBeLimitedToLines(): void
    {
        $csv = "source,target,status\n/a,/typography,301\n/b,/typography,301\n/c,/typography,301\n";
        $response = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv', 'lines' => [2, 4]]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(2, $response->data()['created']);
        self::assertSame(['/a', '/c'], array_column($this->stored(), 'source'));
        $this->assertProblem($this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'csv', 'lines' => 'all']), 422);
    }

    public function testRowsThatBreakRulesAreErrorsToo(): void
    {
        $csv = "source,target,status\n/a,//evil.example,301\n/self,/self,301\n/ok,/typography,301\n";
        $preview = $this->api->post('/redirects/import/preview', ['content' => $csv, 'format' => 'csv'])->data();
        self::assertSame(2, $preview['counts']['errors']);
        self::assertSame('target_protocol_relative', $preview['rows'][0]['errors'][0]['code']);
        self::assertSame('self_redirect', $preview['rows'][1]['errors'][0]['code']);
        self::assertSame('/ok', $preview['rows'][2]['rule']['source']);
    }

    public function testInvalidRequestsAre422(): void
    {
        $empty = $this->api->post('/redirects/import/commit', ['content' => '', 'format' => 'csv']);
        $this->assertProblem($empty, 422);
        self::assertSame(['content', 'empty_file'], [$empty->errors()[0]['field'], $empty->errors()[0]['code']]);
        self::assertSame('empty_file', $this->api->post('/redirects/import/preview', ['content' => '', 'format' => 'csv'])->data()['errors'][0]['code'], 'preview reports it as data');

        $missing = $this->api->post('/redirects/import/preview', []);
        $this->assertProblem($missing, 422);
        self::assertSame(['content', 'required'], [$missing->errors()[0]['field'], $missing->errors()[0]['code']]);

        $unknown = $this->api->post('/redirects/import/commit', ['content' => 'x', 'format' => 'nope']);
        $this->assertProblem($unknown, 422);
        self::assertSame(['format', 'unknown_format'], [$unknown->errors()[0]['field'], $unknown->errors()[0]['code']]);

        $exportOnly = $this->api->post('/redirects/import/commit', ['content' => 'x', 'format' => 'cloudflare_csv']);
        $this->assertProblem($exportOnly, 422);
        self::assertSame('format_not_importable', $exportOnly->errors()[0]['code']);

        $undetected = $this->api->post('/redirects/import/commit', ['content' => 'hello world']);
        $this->assertProblem($undetected, 422);
        self::assertSame('unknown_format', $undetected->errors()[0]['code']);
        self::assertSame([], $this->stored());
    }

    // ------------------------------------------------------------------ size limit

    public function testTheRequestBodyIsLimitedByImportMaxMb(): void
    {
        $this->site()->writePluginConfig(['import' => ['max_mb' => 1]]);
        $body = ['content' => str_repeat("/a /typography 301\n", 90_000), 'format' => 'netlify']; // about 1.7 MB in the request
        foreach (['preview', 'commit', 'sitemap'] as $action) {
            $response = $this->api->post('/redirects/import/' . $action, $body);
            $this->assertProblem($response, 413, $action);
            self::assertStringContainsString('1 MB', (string) ($response->json['detail'] ?? ''));
        }
        self::assertSame([], $this->stored());
    }

    public function testAContentJustOverTheLimitIsRefusedByTheServiceToo(): void
    {
        $this->site()->writePluginConfig(['import' => ['max_mb' => 1]]);
        // 1.1 MB of text: under the header check (1 MB plus a third), over the limit of the decoded content.
        $content = str_repeat("/a /typography 301\n", 61_000);
        self::assertGreaterThan(1_048_576, strlen($content));
        $this->assertProblem($this->api->post('/redirects/import/preview', ['content' => $content, 'format' => 'netlify']), 413);
    }

    public function testASmallImportStillWorksWithTheLowLimit(): void
    {
        $this->site()->writePluginConfig(['import' => ['max_mb' => 1]]);
        self::assertSame(200, $this->api->post('/redirects/import/commit', ['content' => "/a /typography 301\n", 'format' => 'netlify'])->status);
    }

    // ------------------------------------------------------------------ crawler files

    public function testACrawlerExportCreatesSuggestionsInsteadOfRules(): void
    {
        $this->site()->writePage('ueber-uns', 'Ueber uns', 'x', null, '20');
        // The Screaming Frog fixture lists URLs of www.example.com; only URLs of this site count.
        $this->site()->writeSystemConfig(['custom_base_url' => 'https://www.example.com']);
        $csv = (string) file_get_contents(self::FIXTURES . 'screamingfrog-client-error.csv');

        $preview = $this->api->post('/redirects/import/preview', ['content' => $csv, 'filename' => 'client_error_4xx.csv']);
        self::assertSame(200, $preview->status, $preview->describe());
        $data = $preview->data();
        self::assertSame('crawler_csv', $data['format']);
        self::assertSame([], $data['rows'], 'no rules');
        self::assertSame(['/alte-seite', '/produkte/kaputt', '/entfernt', '/blog/über-uns'], $data['not_found_paths'], 'unique, decoded, without the query, only this site');
        self::assertSame(4, $data['counts']['not_found']);

        $commit = $this->api->post('/redirects/import/commit', ['content' => $csv, 'format' => 'crawler_csv']);
        self::assertSame(200, $commit->status, $commit->describe());
        self::assertSame(['crawler_csv', 0, 0, 4], [$commit->data()['format'], $commit->data()['created'], $commit->data()['skipped'], $commit->data()['not_found']]);
        self::assertSame(1, $commit->data()['suggestions'], 'only /blog/über-uns has a page to suggest');
        self::assertSame([], $this->stored(), 'no rules');

        $suggestions = $this->api->get('/redirects/suggestions')->data();
        self::assertCount(1, $suggestions);
        self::assertSame(['/blog/über-uns', '/ueber-uns', 'crawler', 'open'], [$suggestions[0]['path'], $suggestions[0]['target'], $suggestions[0]['source'], $suggestions[0]['status']]);
        self::assertSame(1, $this->api->get('/redirects/suggestions', ['source' => 'crawler'])->meta()['total']);
    }

    public function testACrawlerExportWithRelativePathsNeedsNoHost(): void
    {
        $this->site()->writePage('kontakt', 'Kontakt', 'x', null, '20');
        $csv = (string) file_get_contents(self::FIXTURES . 'sitebulb-404.csv');
        $preview = $this->api->post('/redirects/import/preview', ['content' => $csv, 'format' => 'crawler_csv'])->data();
        // Sitebulb lists example.com URLs and one relative path; only the path belongs to this site (127.0.0.1).
        self::assertSame(['/relative/pfad'], $preview['not_found_paths']);
        self::assertSame([], $preview['rows']);
    }

    // ------------------------------------------------------------------ sitemap diff

    private function sitemap(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://old.example.com/typography</loc></url>'
            . '<url><loc>https://old.example.com/blog-postt</loc></url>'
            . '<url><loc>https://old.example.com/redirected</loc></url>'
            . '<url><loc>https://old.example.com/verschwunden/seite</loc></url>'
            . '<url><loc>https://old.example.com/</loc></url></urlset>';
    }

    public function testSitemapDiffFindsMissingPathsAndCreatesSuggestions(): void
    {
        $this->site()->writePage('blog-post', 'Blog Post', 'x', null, '20');
        $this->rules([['id' => 'covered', 'source' => '/redirected', 'target' => '/typography']]);
        $response = $this->api->post('/redirects/import/sitemap', ['content' => $this->sitemap()]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(
            ['total' => 5, 'existing' => 2, 'redirected' => 1, 'missing' => 2, 'missing_paths' => ['/blog-postt', '/verschwunden/seite'], 'suggestions_created' => 1],
            $response->data(),
        );
        $suggestions = $this->api->get('/redirects/suggestions', ['source' => 'sitemap'])->data();
        self::assertSame([['/blog-postt', '/blog-post', 'sitemap']], array_map(static fn (array $s): array => [$s['path'], $s['target'], $s['source']], $suggestions));

        $again = $this->api->post('/redirects/import/sitemap', ['content' => $this->sitemap()])->data();
        self::assertSame(0, $again['suggestions_created'], 'the suggestion exists already');
    }

    public function testSitemapCanBeSentBase64EncodedAndGzipped(): void
    {
        foreach ([base64_encode($this->sitemap()), base64_encode((string) gzencode($this->sitemap()))] as $content) {
            $response = $this->api->post('/redirects/import/sitemap', ['content' => $content, 'encoding' => 'base64']);
            self::assertSame(200, $response->status, $response->describe());
            self::assertSame([5, 3], [$response->data()['total'], $response->data()['missing']], 'nothing covers /redirected in this test');
        }
    }

    public function testSitemapErrors(): void
    {
        $bad = $this->api->post('/redirects/import/sitemap', ['content' => 'this is not xml']);
        $this->assertProblem($bad, 422);
        self::assertSame(['content', 'sitemap_invalid'], [$bad->errors()[0]['field'], $bad->errors()[0]['code']]);

        $b64 = $this->api->post('/redirects/import/sitemap', ['content' => '!!!', 'encoding' => 'base64']);
        $this->assertProblem($b64, 422);
        self::assertSame('invalid_encoding', $b64->errors()[0]['code']);

        $enc = $this->api->post('/redirects/import/sitemap', ['content' => $this->sitemap(), 'encoding' => 'rot13']);
        $this->assertProblem($enc, 422);
        self::assertSame('encoding', $enc->errors()[0]['field']);

        $index = $this->api->post('/redirects/import/sitemap', ['content' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://x.example/a.xml</loc></sitemap></sitemapindex>']);
        $this->assertProblem($index, 422);
        self::assertSame('sitemap_index', $index->errors()[0]['code']);
        $this->assertProblem($this->api->post('/redirects/import/sitemap', []), 422);
    }

    // ------------------------------------------------------------------ export

    /**
     * @return list<array<string, mixed>>
     */
    private function exportRules(): array
    {
        $rows = [
            ['id' => 'r1', 'source' => '/old', 'target' => '/typography', 'status' => 301, 'group' => 'g1', 'note' => 'n'],
            ['id' => 'r2', 'source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard', 'status' => 302, 'group' => 'g2'],
            ['id' => 'r3', 'source' => '^/p/(\d+)$', 'target' => '/q/$1', 'match_type' => 'regex', 'status' => 307, 'enabled' => false],
            ['id' => 'r4', 'source' => '/gone', 'target' => '', 'status' => 410],
        ];
        $this->rules($rows);

        return $rows;
    }

    public function testEveryExportableFormatReturnsContent(): void
    {
        $this->exportRules();
        $formats = $this->api->get('/redirects/import/formats')->data();
        $exportable = array_values(array_filter($formats, static fn (array $f): bool => $f['export']));
        self::assertCount(10, $exportable);

        foreach ($exportable as $format) {
            $query = ['format' => $format['id']];
            if ($format['id'] === 'cloudflare_csv') {
                $query['host'] = 'example.com'; // Cloudflare sources need a host name
            }
            $response = $this->api->get('/redirects/export', $query);
            self::assertSame(200, $response->status, $format['id'] . ' ' . $response->describe());
            $data = $response->data();
            self::assertSame(['filename', 'mime', 'content', 'skipped', 'lossy', 'exported'], array_keys($data), $format['id']);
            self::assertSame($format['mime'], $data['mime'], $format['id']);
            if ($format['id'] === 'netlify') {
                self::assertSame('_redirects', $data['filename'], 'Netlify wants exactly this file name (the listed extension is txt)');
            } else {
                self::assertStringEndsWith('.' . $format['extension'], (string) $data['filename'], $format['id'] . ' file extension');
            }
            self::assertNotSame('', trim((string) $data['content']), $format['id'] . ' has content');
            self::assertGreaterThan(0, $data['exported'], $format['id']);
            self::assertLessThanOrEqual(4, $data['exported'], $format['id']);
            self::assertIsArray($data['skipped']);
            self::assertStringContainsString('old', (string) $data['content'], $format['id'] . ' mentions the first rule');
            foreach ($data['skipped'] as $note) {
                self::assertSame(['rule_id', 'code', 'reason'], array_keys($note), $format['id']);
            }
        }
    }

    public function testExportDefaultsToCsvAndNamesTheSkippedRules(): void
    {
        $this->exportRules();
        $csv = $this->api->get('/redirects/export')->data();
        self::assertSame(['redirects.csv', 'text/csv', 4], [$csv['filename'], $csv['mime'], $csv['exported']]);
        self::assertStringStartsWith('source,target,status,match_type,enabled,priority,group,note', $csv['content']);

        $netlify = $this->api->get('/redirects/export', ['format' => 'netlify'])->data();
        self::assertSame(['_redirects', 2], [$netlify['filename'], $netlify['exported']]);
        self::assertSame([['r3', 'disabled'], ['r4', 'status_not_supported']], array_map(static fn (array $n): array => [$n['rule_id'], $n['code']], $netlify['skipped']));
        self::assertStringContainsString('/blog/* /news/:splat 302', $netlify['content']);

        $htaccess = $this->api->get('/redirects/export', ['format' => 'htaccess'])->data();
        self::assertStringContainsString('RewriteRule ^old/?$ /typography [R=301,L,NC]', $htaccess['content']);
        self::assertStringContainsString('[G,NC]', $htaccess['content'], '410 becomes a gone rule');
    }

    public function testExportFilters(): void
    {
        $this->exportRules();
        $sources = fn (array $query): array => array_column($this->parseCsv($this->api->get('/redirects/export', $query + ['format' => 'csv'])->data()['content']), 'source');

        self::assertEqualsCanonicalizing(['/old', '/gone', '/blog/*', '^/p/(\d+)$'], $sources([]));
        self::assertEqualsCanonicalizing(['/old', '/gone', '/blog/*'], $sources(['only_enabled' => 1]));
        self::assertSame(['/old'], $sources(['group' => 'g1']));
        self::assertSame(['/blog/*'], $sources(['group' => 'g2']));
        self::assertEqualsCanonicalizing(['/gone', '^/p/(\d+)$'], $sources(['group' => '']), 'an empty group means "no group"');
        self::assertEqualsCanonicalizing(['/old', '/blog/*'], $sources(['status' => '301,302']));
        self::assertSame(['/gone'], $sources(['status' => 410]));
        self::assertSame(['/old'], $sources(['status' => 301, 'only_enabled' => 'true']));
        self::assertSame(0, $this->api->get('/redirects/export', ['group' => 'nope'])->data()['exported']);
    }

    public function testCloudflareExportNeedsAHostAndSkipsWhatItCannotDo(): void
    {
        $this->exportRules();
        $without = $this->api->get('/redirects/export', ['format' => 'cloudflare_csv'])->data();
        self::assertSame(0, $without['exported']);
        self::assertContains('export_host_required', array_column($without['skipped'], 'code'));

        $with = $this->api->get('/redirects/export', ['format' => 'cloudflare_csv', 'host' => 'example.com'])->data();
        self::assertSame(2, $with['exported']);
        self::assertStringContainsString('example.com/old,https://example.com/typography,301', $with['content']);
        self::assertContains('status_not_supported', array_column($with['skipped'], 'code'), '410 is no Cloudflare redirect');
    }

    public function testExportRefusesUnknownAndImportOnlyFormats(): void
    {
        $unknown = $this->api->get('/redirects/export', ['format' => 'nope']);
        $this->assertProblem($unknown, 422);
        self::assertSame(['format', 'unknown_format'], [$unknown->errors()[0]['field'], $unknown->errors()[0]['code']]);
        $crawler = $this->api->get('/redirects/export', ['format' => 'crawler_csv']);
        $this->assertProblem($crawler, 422);
        self::assertSame('format_not_exportable', $crawler->errors()[0]['code']);
    }

    /**
     * @param string $format import format id
     */
    #[DataProvider('roundTripFormats')]
    public function testExportedFilesImportBackToTheSameRules(string $format, int $expected): void
    {
        $this->exportRules();
        $export = $this->api->get('/redirects/export', ['format' => $format])->data();
        $this->rules([]);

        $commit = $this->api->post('/redirects/import/commit', ['content' => $export['content'], 'format' => $format, 'skip_invalid' => false]);
        self::assertSame(200, $commit->status, $commit->describe());
        self::assertSame($expected, $commit->data()['created']);
        $imported = array_column($this->stored(), null, 'source');
        self::assertSame(['/typography', 301], [$imported['/old']['target'], $imported['/old']['status']]);
        if ($expected === 4) {
            self::assertSame([410, '', false], [$imported['/gone']['status'], $imported['/gone']['target'], $imported['^/p/(\d+)$']['enabled']]);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roundTripFormats(): array
    {
        return [
            'csv' => ['csv', 4],
            'json' => ['json', 4],
            'yaml' => ['yaml', 4],
            'wordpress_csv' => ['wordpress_csv', 4],
            'wordpress_json' => ['wordpress_json', 4],
            'htaccess' => ['htaccess', 3],
            'nginx' => ['nginx', 3],
            'netlify' => ['netlify', 2],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', '\\'), explode("\n", trim($csv))), static fn (array $l): bool => $l !== [null]));
        $header = array_shift($lines) ?? [];

        return array_map(static fn (array $l): array => array_combine($header, array_pad($l, count($header), '')), $lines);
    }

    // ------------------------------------------------------------------ site.yaml

    public function testSiteConfigShowsGravsOwnRedirects(): void
    {
        $empty = $this->api->get('/redirects/site-config');
        self::assertSame(200, $empty->status, $empty->describe());
        self::assertSame([], $empty->data()['redirects']);
        self::assertSame([], $empty->data()['routes']);
        self::assertStringContainsString('"redirects":{},"routes":{}', $empty->body, 'empty maps, not lists');
        self::assertArrayHasKey('redirect_default_code', $empty->data()['settings']);

        $this->site()->writeSystemConfig(['pages' => ['redirect_default_code' => 301]]);
        $this->site()->writeFile('user/config/site.yaml', "title: T\nredirects:\n    '/alte-seite': '/typography'\n    '/impressum.html': '/typography [301]'\nroutes:\n    '/alias': '/typography'\n");
        $data = $this->api->get('/redirects/site-config')->data();
        self::assertSame(['/alte-seite' => '/typography', '/impressum.html' => '/typography [301]'], $data['redirects']);
        self::assertSame(['/alias' => '/typography'], $data['routes']);
        self::assertSame(301, $data['settings']['redirect_default_code']);
    }

    public function testSiteConfigImportCreatesRulesAndLeavesSiteYamlAlone(): void
    {
        $yaml = "title: T\nredirects:\n    '/alte-seite': '/typography'\n    '/impressum.html': '/typography [301]'\n    '/blog/(.*)': '/typography [302]'\nroutes:\n    '/alias': '/typography'\n";
        $this->site()->writeFile('user/config/site.yaml', $yaml);
        $before = md5((string) $this->site()->readFile('user/config/site.yaml'));

        $response = $this->api->post('/redirects/site-config/import');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['grav_site', 4, 0], [$response->data()['format'], $response->data()['created'], $response->data()['skipped']]);
        self::assertSame(['/alte-seite', '/impressum.html', '/blog/*', '/alias'], array_column($this->stored(), 'source'));
        self::assertSame(['site.yaml'], array_unique(array_column($this->stored(), 'group')));
        self::assertSame(['import'], array_unique(array_column($this->stored(), 'origin')));
        self::assertSame($before, md5((string) $this->site()->readFile('user/config/site.yaml')), 'site.yaml is not modified');

        $this->assertRedirect($this->get('/impressum.html'), 301, '/typography');
        $this->assertRedirect($this->get('/blog/x'), 302, '/typography');
        $alias = $this->get('/alias');
        self::assertSame(200, $alias->status, 'a Grav route alias serves the page under the alias URL');
        self::assertNull($alias->header('location'));

        $second = $this->api->post('/redirects/site-config/import');
        self::assertSame([0, 4], [$second->data()['created'], $second->data()['skipped']], 'duplicates are skipped');
    }

    public function testSiteConfigImportCanLeaveOutRoutesOrRedirects(): void
    {
        $this->site()->writeFile('user/config/site.yaml', "redirects:\n    '/a': '/typography'\nroutes:\n    '/alias': '/typography'\n");
        self::assertSame(1, $this->api->post('/redirects/site-config/import', ['routes' => false])->data()['created']);
        self::assertSame(['/a'], array_column($this->stored(), 'source'));
        self::assertSame(1, $this->api->post('/redirects/site-config/import', ['redirects' => false])->data()['created']);
        self::assertSame(['/a', '/alias'], array_column($this->stored(), 'source'));
    }

    public function testSiteConfigImportWithNothingToImportIs422(): void
    {
        $response = $this->api->post('/redirects/site-config/import');
        $this->assertProblem($response, 422);
        self::assertSame('nothing_to_import', $response->errors()[0]['code']);
    }

    // ------------------------------------------------------------------ contract notes

    public function testPreviewSerializesEmptyMapsAsObjects(): void
    {
        $preview = $this->api->post('/redirects/import/preview', ['content' => "source,target,status\n/a,/typography,301\n", 'format' => 'csv']);
        self::assertStringContainsString('"query_params":{}', $preview->body);
        $empty = $this->api->post('/redirects/import/preview', ['content' => '', 'format' => 'csv']);
        self::assertStringContainsString('"params":{}', $empty->body);
        $created = $this->createRule(['source' => '/x', 'target' => '/typography', 'status' => 301]);
        self::assertSame([], $created['query_params']);
        self::assertStringContainsString('"query_params":{}', $this->api->get('/redirects/rules/' . $created['id'])->body);
    }
}
