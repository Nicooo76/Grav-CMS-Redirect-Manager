<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Cli\ExitCode;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\CliRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * `bin/plugin redirect-manager import --format=<x>` and `export --format=<x>` for every format, through the real
 * console command in a real Grav site (H-4d). Each import format gets a clean file and a real-world fixture.
 */
#[Group('cli')]
final class CliImportExportTest extends IntegrationTestCase
{
    private const FIXTURES = __DIR__ . '/../Unit/ImportExport/fixtures/';

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function cli(array $args): array
    {
        return (new CliRunner($this->site()))->run($args);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function cliJson(array $args, int $expectedCode = ExitCode::OK): array
    {
        $result = $this->cli($args);
        self::assertSame($expectedCode, $result['code'], implode(' ', $args) . "\n" . $result['stdout'] . $result['stderr']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded, 'stdout is not JSON: ' . $result['stdout'] . $result['stderr']);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return array_map(static fn ($rule): array => $rule->toArray(), $this->site()->repository()->all());
    }

    /**
     * @return list<string> "source -> target [status]" of the stored rules, in file order
     */
    private function pairs(): array
    {
        return array_map(static fn (array $r): string => $r['source'] . ' -> ' . $r['target'] . ' [' . $r['status'] . ']', $this->stored());
    }

    // ------------------------------------------------------------------ import: a clean file per format

    /**
     * A row without a status gets Grav's `system.pages.redirect_default_code` (302 on the test site).
     *
     * @return iterable<string, array{string, string, string, list<string>}> format, file name, content, stored rules
     */
    public static function cleanFiles(): iterable
    {
        yield 'csv' => ['csv', 'rules.csv', "source,target,status\n/i1,/x,301\n/i2,/y,302\n", ['/i1 -> /x [301]', '/i2 -> /y [302]']];
        yield 'json' => ['json', 'rules.json', '[{"source":"/j1","target":"/x"},{"source":"/j2","target":"/y","status":302}]', ['/j1 -> /x [302]', '/j2 -> /y [302]']];
        yield 'yaml' => ['yaml', 'rules.yaml', "- source: /y1\n  target: /x\n- {source: /y2, target: /y, status: 302}\n", ['/y1 -> /x [302]', '/y2 -> /y [302]']];
        yield 'htaccess' => ['htaccess', '.htaccess', "Redirect 301 /h1 /x\nRedirect 302 /h2 /y\nRedirectMatch 301 ^/blog/(\\d+)\$ /news/\$1\n", ['/h1 -> /x [301]', '/h2 -> /y [302]', '^/blog/(\\d+)$ -> /news/$1 [301]']];
        yield 'nginx' => ['nginx', 'redirects.conf', "server {\n    location = /alt { return 301 /neu; }\n    location = /alt2 { return 302 /neu2; }\n}\n", ['/alt -> /neu [301]', '/alt2 -> /neu2 [302]']];
        yield 'netlify' => ['netlify', '_redirects', "/n1 /x 301\n/n2 /y 302\n/blog/* /news/:splat 301\n", ['/n1 -> /x [301]', '/n2 -> /y [302]', '/blog/* -> /news/$1 [301]']];
        yield 'wordpress_csv' => ['wordpress_csv', 'redirection.csv', "source,target,regex,code,type,hits,title,status\n/w1,/x,0,301,url,0,,enabled\n/w2,/y,0,302,url,0,,enabled\n", ['/w1 -> /x [301]', '/w2 -> /y [302]']];
        yield 'grav_site' => ['grav_site', 'site.yaml', "title: Demo\nredirects:\n    '/g1': '/x'\n    '/g2': '/y [302]'\n", ['/g1 -> /x [302]', '/g2 -> /y [302]']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('cleanFiles')]
    public function testImportWithAnExplicitFormat(string $format, string $name, string $content, array $expected): void
    {
        $this->site()->writeFile('import/' . $name, $content);
        $file = $this->site()->dir . '/import/' . $name;

        $dry = $this->cliJson(['import', $file, '--format=' . $format, '--dry-run', '--json']);
        self::assertSame($format, $dry['format']);
        self::assertSame(count($expected), $dry['counts']['valid'], 'valid rows in the preview');
        self::assertSame(0, $dry['counts']['errors']);
        self::assertSame([], $this->stored(), 'a dry run saves nothing');

        $done = $this->cliJson(['import', $file, '--format=' . $format, '--json']);
        self::assertSame($format, $done['format']);
        self::assertSame(count($expected), $done['created']);
        self::assertSame(0, $done['skipped']);
        self::assertSame($expected, $this->pairs());
        self::assertSame(0, $done['not_found']);

        // The first imported rule is live in the frontend.
        $first = $this->stored()[0];
        $r = $this->get($first['match_type'] === 'exact' ? $first['source'] : '/none');
        if ($first['match_type'] === 'exact') {
            self::assertSame((int) $first['status'], $r->status, $first['source'] . ' ' . $r->describe());
            self::assertSame($first['target'], $r->location());
        }

        // The same file again: every row is a duplicate now.
        $again = $this->cliJson(['import', $file, '--format=' . $format, '--json']);
        self::assertSame(0, $again['created']);
        self::assertSame(count($expected), $again['skipped']);
        self::assertCount(count($expected), $this->stored());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('cleanFiles')]
    public function testTheFormatIsDetectedWhenNoneIsGiven(string $format, string $name, string $content, array $expected): void
    {
        $this->site()->writeFile('import/' . $name, $content);
        $done = $this->cliJson(['import', $this->site()->dir . '/import/' . $name, '--json']);
        self::assertSame($format, $done['format'], 'detected from the file name and content');
        self::assertSame($expected, $this->pairs());
    }

    public function testACrawlerFileCreatesNoRulesButCandidatesForSuggestions(): void
    {
        $this->site()->writePage('ueber-uns', 'Ueber uns', 'x', null, '20');
        $this->site()->writeSystemConfig(['custom_base_url' => 'https://www.example.com']);
        $file = self::FIXTURES . 'screamingfrog-client-error.csv';

        $dry = $this->cliJson(['import', $file, '--format=crawler_csv', '--dry-run', '--json']);
        self::assertSame('crawler_csv', $dry['format']);
        self::assertSame(4, $dry['counts']['not_found']);
        self::assertSame(['/alte-seite', '/produkte/kaputt', '/entfernt', '/blog/über-uns'], $dry['not_found_paths']);
        self::assertSame(0, $dry['counts']['total'], 'no rule rows');

        $done = $this->cliJson(['import', $file, '--format=crawler_csv', '--json']);
        self::assertSame(['crawler_csv', 0, 4, 1], [$done['format'], $done['created'], $done['not_found'], $done['suggestions']]);
        self::assertSame([], $this->stored());

        $suggestions = $this->cliJson(['suggest', '--dry-run', '--json']);
        self::assertTrue($suggestions['dry_run']);

        $text = $this->cli(['import', $file, '--format=crawler_csv']);
        self::assertSame(ExitCode::OK, $text['code'], $text['stderr']);
        self::assertStringContainsString('0 rule(s) created', $text['stdout']);
    }

    public function testACrawlerFileOfAnotherHostHasNoCandidates(): void
    {
        $done = $this->cliJson(['import', self::FIXTURES . 'screamingfrog-client-error.csv', '--format=crawler_csv', '--json']);
        self::assertSame(0, $done['not_found'], 'the URLs belong to www.example.com, this site is 127.0.0.1');
    }

    // ------------------------------------------------------------------ import: real-world files

    /**
     * @return iterable<string, array{string, string, array<string, int>, int, int}> format, fixture, dry-run counts, rules created with --skip-invalid, rows skipped
     */
    public static function fixtures(): iterable
    {
        yield 'htaccess' => ['htaccess', 'sample.htaccess', ['total' => 29, 'valid' => 19, 'errors' => 3, 'skipped' => 7], 19, 10];
        yield 'nginx' => ['nginx', 'sample-nginx.conf', ['total' => 20, 'valid' => 11, 'errors' => 2, 'skipped' => 7], 11, 9];
        yield 'netlify' => ['netlify', 'netlify-redirects.txt', ['total' => 14, 'valid' => 9, 'errors' => 5, 'skipped' => 0], 9, 5];
        yield 'wordpress_csv' => ['wordpress_csv', 'wordpress-redirection.csv', ['total' => 6, 'valid' => 5, 'errors' => 0, 'skipped' => 1], 5, 1];
        yield 'wordpress_json' => ['wordpress_json', 'wordpress-redirection.json', ['total' => 10, 'valid' => 5, 'errors' => 3, 'skipped' => 2], 5, 5];
        yield 'grav_site' => ['grav_site', 'site.yaml', ['total' => 12, 'valid' => 9, 'errors' => 3, 'skipped' => 0], 9, 3];
    }

    /**
     * @param array<string, int> $counts
     */
    #[DataProvider('fixtures')]
    public function testRealFilesReportProblemsWithExitCode2AndImportTheValidRowsOnRequest(string $format, string $fixture, array $counts, int $created, int $skipped): void
    {
        $file = self::FIXTURES . $fixture;

        $dry = $this->cli(['import', $file, '--format=' . $format, '--dry-run', '--json']);
        $preview = json_decode($dry['stdout'], true);
        self::assertIsArray($preview);
        self::assertSame($counts['errors'] > 0 ? ExitCode::INVALID : ExitCode::OK, $dry['code'], 'dry run: ' . $dry['stderr']);
        foreach ($counts as $key => $value) {
            self::assertSame($value, $preview['counts'][$key], $format . ' ' . $key);
        }

        $strict = $this->cli(['import', $file, '--format=' . $format]);
        self::assertSame($counts['errors'] > 0 ? ExitCode::INVALID : ExitCode::OK, $strict['code'], $strict['stdout'] . $strict['stderr']);
        if ($counts['errors'] > 0) {
            self::assertSame([], $this->stored(), 'nothing is saved while a row is invalid');
        }

        $this->rules([]);
        $lenient = $this->cliJson(['import', $file, '--format=' . $format, '--skip-invalid', '--json']);
        self::assertSame($format, $lenient['format']);
        self::assertSame($created, $lenient['created']);
        self::assertSame($skipped, $lenient['skipped'], 'invalid and skipped rows');
        self::assertCount($created, $this->stored(), 'the valid rows are stored');
    }

    /**
     * @return iterable<string, array{string, int}> wrong format for a CSV file, exit code
     */
    public static function wrongFormatsForACsvFile(): iterable
    {
        // Structured formats fail while reading the file.
        yield 'json' => ['json', ExitCode::INVALID];
        yield 'yaml' => ['yaml', ExitCode::INVALID];
        yield 'grav_site' => ['grav_site', ExitCode::INVALID];
        yield 'wordpress_json' => ['wordpress_json', ExitCode::INVALID];
        yield 'crawler_csv' => ['crawler_csv', ExitCode::INVALID];
        // Line formats read a CSV row as an unfinished line.
        yield 'netlify' => ['netlify', ExitCode::INVALID];
        // These find no directive in a CSV file and report the file-level error no_directives.
        yield 'htaccess' => ['htaccess', ExitCode::INVALID];
        yield 'nginx' => ['nginx', ExitCode::INVALID];
    }

    #[DataProvider('wrongFormatsForACsvFile')]
    public function testAWrongExplicitFormatNeverImportsARuleFromACsvFile(string $wrong, int $exit): void
    {
        $this->site()->writeFile('import/rules.csv', "source,target,status\n/a,/x,301\n");
        $file = $this->site()->dir . '/import/rules.csv';

        $result = $this->cli(['import', $file, '--format=' . $wrong]);
        self::assertSame($exit, $result['code'], $wrong . ' ' . $result['stdout'] . $result['stderr']);
        self::assertSame([], $this->stored(), $wrong . ' saved something');
        if ($exit === ExitCode::OK) {
            self::assertStringContainsString('0 rule(s) created', $result['stdout']);
        } else {
            self::assertSame('', trim($result['stdout']));
            self::assertNotSame('', trim($result['stderr']), 'the error goes to stderr');
        }
    }

    public function testAnUnknownImportFormatIsRefused(): void
    {
        $this->site()->writeFile('import/rules.csv', "source,target,status\n/a,/x,301\n");
        $unknown = $this->cli(['import', $this->site()->dir . '/import/rules.csv', '--format=docx']);
        self::assertSame(ExitCode::INVALID, $unknown['code']);
        self::assertSame([], $this->stored());
    }

    public function testOnlyImportableFormatsAreAcceptedByImport(): void
    {
        $this->site()->writeFile('import/rules.csv', "source,target,status\n/a,/x,301\n");
        $result = $this->cli(['import', $this->site()->dir . '/import/rules.csv', '--format=cloudflare_csv']);
        self::assertSame(ExitCode::INVALID, $result['code']);
        self::assertSame([], $this->stored());
    }

    // ------------------------------------------------------------------ export: every format

    /**
     * @return iterable<string, array{string, string, list<string>}> format, mime type, strings the export must contain
     */
    public static function exportFormats(): iterable
    {
        yield 'csv' => ['csv', 'text/csv', ['source,target,status', '/e1,/x,301', '/e2,/y,302']];
        yield 'json' => ['json', 'application/json', ['"source": "/e1"', '"target": "/y"']];
        yield 'yaml' => ['yaml', 'application/yaml', ['source: /e1', 'target: /y', 'status: 302']];
        yield 'grav_site' => ['grav_site', 'application/yaml', ['redirects:', "'(?i)/e1\$': '/x [301]'", "'(?i)/e2\$': '/y [302]'"]];
        yield 'htaccess' => ['htaccess', 'text/plain', ['RewriteRule ^e1/?$ /x [R=301,L,NC]', 'RewriteRule ^e2/?$ /y [R=302,L,NC]']];
        yield 'nginx' => ['nginx', 'text/plain', ['location ~* ^/e1/?$ {', 'return 301 /x;', 'return 302 /y;']];
        yield 'wordpress_json' => ['wordpress_json', 'application/json', ['"url": "/e1"', '"action_code": 302']];
        yield 'wordpress_csv' => ['wordpress_csv', 'text/csv', ['source,target,regex,code', '/e1,/x,0,301,url', '/e2,/y,0,302,url']];
        yield 'cloudflare_csv' => ['cloudflare_csv', 'text/csv', ['source_url,target_url,status_code', 'example.org/e1,https://example.org/x,301', 'example.org/e2,https://example.org/y,302']];
        yield 'netlify' => ['netlify', 'text/plain', ['/e1 /x 301', '/e2 /y 302']];
    }

    /**
     * @param list<string> $contains
     */
    #[DataProvider('exportFormats')]
    public function testExportWithEveryFormat(string $format, string $mime, array $contains): void
    {
        $this->rules([
            ['id' => 'e1', 'source' => '/e1', 'target' => '/x', 'status' => 301],
            ['id' => 'e2', 'source' => '/e2', 'target' => '/y', 'status' => 302],
        ]);

        // Cloudflare sources need a host; every other format ignores the option.
        $host = $format === 'cloudflare_csv' ? ['--host=example.org'] : [];
        $stdout = $this->cli(['export', '--format=' . $format, ...$host]);
        self::assertSame(ExitCode::OK, $stdout['code'], $stdout['stderr']);
        foreach ($contains as $needle) {
            self::assertStringContainsString($needle, $stdout['stdout'], $format . ' on stdout');
        }

        $file = $this->site()->dir . '/out.' . $format;
        $facts = $this->cliJson(['export', '--format=' . $format, ...$host, '--output=' . $file, '--json']);
        self::assertSame(2, $facts['exported'], $format);
        self::assertSame($mime, $facts['mime'], $format);
        $written = (string) file_get_contents($file);
        foreach ($contains as $needle) {
            self::assertStringContainsString($needle, $written, $format . ' in the file');
        }
        // Two runs, two clock readings: the WordPress header carries the time of the export, to the second, so the
        // two calls can straddle a second boundary. Everything else must match to the byte.
        self::assertSame(self::withoutExportTime(trim($stdout['stdout'])), self::withoutExportTime(trim($written)), 'stdout and file carry the same export');
    }

    /**
     * The only time-dependent part of any export: `"plugin": {"date": "Thu, 01 Oct 2026 04:33:37 +0000"}` in the
     * WordPress Redirection JSON. Replaced by a fixed text, after checking that it is a date.
     */
    private static function withoutExportTime(string $export): string
    {
        return (string) preg_replace_callback(
            '/"date": "([^"]*)"/',
            static function (array $m): string {
                self::assertNotFalse(DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $m[1]), 'the export date is a date: ' . $m[1]);

                return '"date": "<time of the export>"';
            },
            $export,
        );
    }

    public function testTwoExportsInDifferentSecondsDifferOnlyInTheWordpressHeaderTime(): void
    {
        $this->rules([['id' => 'e1', 'source' => '/e1', 'target' => '/x', 'status' => 301]]);

        $first = $this->cli(['export', '--format=wordpress_json'])['stdout'];
        sleep(1);
        $second = $this->cli(['export', '--format=wordpress_json'])['stdout'];

        self::assertNotSame($first, $second, 'the header carries the time of the export');
        self::assertSame(self::withoutExportTime($first), self::withoutExportTime($second));
        foreach (['csv', 'json', 'yaml', 'grav_site', 'htaccess', 'nginx', 'wordpress_csv', 'netlify'] as $format) {
            self::assertSame($this->cli(['export', '--format=' . $format])['stdout'], $this->cli(['export', '--format=' . $format])['stdout'], $format . ' does not depend on the time');
        }
    }

    public function testExportCanBeNarrowedToEnabledRulesAndOneGroup(): void
    {
        $this->rules([
            ['id' => 'e1', 'source' => '/e1', 'target' => '/x', 'group' => 'a'],
            ['id' => 'e2', 'source' => '/e2', 'target' => '/y', 'group' => 'b', 'enabled' => false],
            ['id' => 'e3', 'source' => '/e3', 'target' => '/z', 'group' => 'b'],
        ]);

        self::assertSame(1, $this->cliJson(['export', '--format=csv', '--group=a', '--output=' . $this->site()->dir . '/g.csv', '--json'])['exported']);
        self::assertSame(2, $this->cliJson(['export', '--format=csv', '--only-enabled', '--output=' . $this->site()->dir . '/e.csv', '--json'])['exported']);
        self::assertSame(3, $this->cliJson(['export', '--format=csv', '--output=' . $this->site()->dir . '/all.csv', '--json'])['exported']);
    }

    public function testCloudflareNeedsAHostAndSaysSoInsteadOfWritingAnEmptyFileSilently(): void
    {
        $this->rules([['id' => 'e1', 'source' => '/e1', 'target' => '/x']]);

        $facts = $this->cliJson(['export', '--format=cloudflare_csv', '--output=' . $this->site()->dir . '/cf.csv', '--json']);
        self::assertSame(0, $facts['exported']);
        self::assertSame(['e1'], array_column($facts['skipped'], 'rule_id'));
        self::assertSame('export_host_required', $facts['skipped'][0]['code']);

        $text = $this->cli(['export', '--format=cloudflare_csv']);
        self::assertSame(ExitCode::OK, $text['code']);
        self::assertStringContainsString('Set the export host', $text['stderr'], 'the reason is on stderr');
    }

    public function testACrawlerFormatCannotBeExportedAndAnUnknownOneIsRefused(): void
    {
        $this->rules([['id' => 'e1', 'source' => '/e1', 'target' => '/x']]);
        $crawler = $this->cli(['export', '--format=crawler_csv']);
        self::assertSame(ExitCode::INVALID, $crawler['code'], $crawler['stdout'] . $crawler['stderr']);
        self::assertSame('', trim($crawler['stdout']));
        self::assertSame(ExitCode::INVALID, $this->cli(['export', '--format=docx'])['code']);
    }

    // ------------------------------------------------------------------ export, then import into an empty site

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTripFormats(): iterable
    {
        foreach (['csv', 'json', 'yaml', 'grav_site', 'htaccess', 'nginx', 'wordpress_json', 'wordpress_csv', 'netlify'] as $format) {
            yield $format => [$format];
        }
    }

    #[DataProvider('roundTripFormats')]
    public function testWhatTheCliExportsTheCliImportsAgain(string $format): void
    {
        $this->rules([
            ['id' => 'e1', 'source' => '/e1', 'target' => '/x', 'status' => 301],
            ['id' => 'e2', 'source' => '/e2', 'target' => '/y', 'status' => 302],
        ]);
        $file = $this->site()->dir . '/round.' . $format;
        $this->cliJson(['export', '--format=' . $format, '--output=' . $file, '--json']);

        $this->rules([]);
        self::assertSame([], $this->stored());
        $this->assertNotRedirected($this->get('/e1'));
        $done = $this->cliJson(['import', $file, '--format=' . $format, '--json']);
        self::assertSame(2, $done['created'], $format);

        // Formats like .htaccess and nginx come back as patterns, so compare what visitors get.
        $this->assertRedirect($this->get('/e1'), 301, '/x', $format);
        $this->assertRedirect($this->get('/e2'), 302, '/y', $format);
        $this->assertNotRedirected($this->get('/e3'), $format);
    }
}
