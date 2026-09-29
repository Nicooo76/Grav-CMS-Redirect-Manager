<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\ImportExportService;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ImportExportService::class)]
final class ImportExportServiceTest extends AppTestCase
{
    private const CSV = "source,target,status\n/old-a,/new-a,301\n/old-b,/new-b,302\n";

    private function service(): ImportExportService
    {
        return $this->app->importExport();
    }

    /**
     * @param array<string, mixed> $preview
     *
     * @return list<string>
     */
    private static function rowCodes(array $preview, int $index, string $kind = 'errors'): array
    {
        return array_map(static fn (array $i): string => $i['code'], $preview['rows'][$index][$kind]);
    }

    private function storedIds(): array
    {
        return array_map(static fn ($r): string => $r->source, $this->app->rules()->all());
    }

    // ---------------------------------------------------------------- formats

    public function testFormatsListsEveryFormat(): void
    {
        $formats = $this->service()->formats();

        self::assertCount(count(Format::cases()), $formats);
        $byId = array_column($formats, null, 'id');
        foreach (Format::cases() as $format) {
            self::assertArrayHasKey($format->value, $byId);
            self::assertNotSame($format->value, $byId[$format->value]['label']);
            self::assertSame($format->canImport(), $byId[$format->value]['import']);
            self::assertSame($format->canExport(), $byId[$format->value]['export']);
            self::assertSame($format->extension(), $byId[$format->value]['extension']);
            self::assertSame($format->mimeType(), $byId[$format->value]['mime']);
        }
        self::assertSame('Grav site.yaml', $byId['grav_site']['label']);
        self::assertFalse($byId['cloudflare_csv']['import']);
        self::assertFalse($byId['crawler_csv']['export']);
    }

    public function testMaxBytesFollowsConfig(): void
    {
        self::assertSame(10 * 1048576, $this->service()->maxBytes());
        self::assertSame(2 * 1048576, $this->makeApp(['import' => ['max_mb' => 2]])->importExport()->maxBytes());
        self::assertSame(1048576, $this->makeApp(['import' => ['max_mb' => 0]])->importExport()->maxBytes());
    }

    // ---------------------------------------------------------------- preview

    public function testPreviewCsvReportsRowsAndRelationsChecked(): void
    {
        $preview = $this->service()->preview(['content' => self::CSV, 'filename' => 'r.csv']);

        self::assertSame('csv', $preview['format']);
        self::assertSame(2, $preview['counts']['valid']);
        self::assertSame(0, $preview['counts']['errors']);
        self::assertTrue($preview['relations_checked']);
        self::assertSame('/old-a', $preview['rows'][0]['rule']['source']);
        self::assertSame([], $this->storedIds(), 'a preview stores nothing');
    }

    public function testPreviewAppliesOptions(): void
    {
        $content = "from;to;code\n/x;/y;302\n";
        $preview = $this->service()->preview([
            'content' => $content,
            'format' => 'csv',
            'options' => ['delimiter' => ';', 'has_header' => true, 'default_group' => 'import', 'default_status' => 307],
        ]);

        self::assertSame(1, $preview['counts']['valid']);
        self::assertSame('import', $preview['rows'][0]['rule']['group']);
        self::assertSame(302, $preview['rows'][0]['rule']['status']);
    }

    public function testPreviewColumnMappingAndDefaultStatus(): void
    {
        $preview = $this->service()->preview([
            'content' => "a,b\n/x,/y\n",
            'format' => 'csv',
            'options' => ['columns' => ['source' => 'a', 'target' => 'b'], 'default_status' => '308'],
        ]);

        self::assertSame(308, $preview['rows'][0]['rule']['status']);
    }

    public function testPreviewRejectsUnusableDefaultStatus(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->service()->preview(['content' => self::CSV, 'options' => ['default_status' => 999]]);
    }

    public function testUnsafeRegexInCsvBecomesRowError(): void
    {
        $content = "source,target,status,match_type\n/ok,/fine,301,exact\n^/(a+)+\$,/x,301,regex\n";
        $preview = $this->service()->preview(['content' => $content, 'filename' => 'r.csv']);

        self::assertSame(1, $preview['counts']['valid']);
        self::assertSame(1, $preview['counts']['errors']);
        $row = $preview['rows'][1];
        self::assertNull($row['rule']);
        self::assertSame('regex_catastrophic', $row['errors'][0]['code']);
        self::assertSame('source', $row['errors'][0]['params']['field']);
    }

    public function testSyntaxErrorInRegexStaysTheImportersRowError(): void
    {
        $content = "source,target,status,match_type\n^/broken(,/x,301,regex\n";
        $preview = $this->service()->preview(['content' => $content, 'filename' => 'r.csv']);

        self::assertSame(1, $preview['counts']['errors']);
        self::assertSame('invalid_regex', $preview['rows'][0]['errors'][0]['code']);
    }

    public function testInvalidRegexInHtaccessBecomesRowError(): void
    {
        $content = "RedirectMatch 301 ^/(a+)+\$ /q\nRedirect 301 /fine /ok\n";
        $preview = $this->service()->preview(['content' => $content, 'filename' => '.htaccess']);

        self::assertSame('htaccess', $preview['format']);
        self::assertSame(1, $preview['counts']['errors']);
        self::assertSame(1, $preview['counts']['valid']);
        self::assertNull($preview['rows'][0]['rule']);
        self::assertSame('regex_catastrophic', $preview['rows'][0]['errors'][0]['code']);
        self::assertSame('source', $preview['rows'][0]['errors'][0]['params']['field']);
    }

    public function testPreviewMarksDuplicatesOfStoredAndInFile(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/old-a', 'target' => '/new-a']]);
        $content = self::CSV . "/old-b,/other,301\n";
        $preview = $this->service()->preview(['content' => $content, 'format' => 'csv']);

        self::assertSame('r1', $preview['rows'][0]['duplicate_of']);
        self::assertFalse($preview['rows'][1]['duplicate_in_file']);
        self::assertTrue($preview['rows'][2]['duplicate_in_file']);
        self::assertSame(2, $preview['counts']['duplicates']);
        self::assertContains('duplicate', self::rowCodes($preview, 0, 'warnings'));
    }

    public function testPreviewAddsValidationWarnings(): void
    {
        $content = "source,target,status\n/plain,/somewhere/\$1,301\n";
        $preview = $this->service()->preview(['content' => $content, 'format' => 'csv']);

        self::assertNotNull($preview['rows'][0]['rule']);
        self::assertContains('target_placeholder_unknown', self::rowCodes($preview, 0, 'warnings'));
        self::assertSame('target', $preview['rows'][0]['warnings'][0]['params']['field']);
    }

    public function testPreviewRelationsNotCheckedForLargeImports(): void
    {
        $lines = ["source,target,status"];
        for ($i = 0; $i < 301; ++$i) {
            $lines[] = "/o{$i},/n{$i},301";
        }
        $preview = $this->service()->preview(['content' => implode("\n", $lines), 'format' => 'csv']);

        self::assertFalse($preview['relations_checked']);
        self::assertSame(301, $preview['counts']['valid']);
    }

    public function testUnknownFormatGivesFileError(): void
    {
        $preview = $this->service()->preview(['content' => 'just some words', 'filename' => 'notes.txt']);

        self::assertNull($preview['format']);
        self::assertSame('unknown_format', $preview['errors'][0]['code']);
        self::assertSame([], $preview['rows']);
    }

    public function testFormatIsValidated(): void
    {
        foreach (['nonsense' => 'unknown_format', 'cloudflare_csv' => 'format_not_importable'] as $format => $code) {
            try {
                $this->service()->preview(['content' => self::CSV, 'format' => $format]);
                self::fail('Expected an exception for ' . $format);
            } catch (InvalidInputException $e) {
                self::assertSame('format', $e->field);
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testContentIsRequired(): void
    {
        foreach ([[], ['content' => 5], ['content' => null]] as $body) {
            try {
                $this->service()->preview($body);
                self::fail('Expected an exception');
            } catch (InvalidInputException $e) {
                self::assertSame('content', $e->field);
                self::assertSame('required', $e->errorCode);
            }
        }
    }

    public function testBase64Content(): void
    {
        $preview = $this->service()->preview(['content' => base64_encode(self::CSV), 'encoding' => 'base64', 'filename' => 'r.csv']);

        self::assertSame(2, $preview['counts']['valid']);
    }

    public function testInvalidBase64IsRejected(): void
    {
        try {
            $this->service()->preview(['content' => '%%% not base64 %%%', 'encoding' => 'base64']);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('content', $e->field);
        }
        $this->expectException(InvalidInputException::class);
        $this->service()->preview(['content' => self::CSV, 'encoding' => 'rot13']);
    }

    public function testTooLargeContentIsRejected(): void
    {
        $service = $this->makeApp(['import' => ['max_mb' => 1]])->importExport();
        $big = str_repeat('a', 1048576 + 1);

        try {
            $service->preview(['content' => $big]);
            self::fail('Expected an exception');
        } catch (PayloadTooLargeException $e) {
            self::assertSame(1048577, $e->size);
            self::assertSame(1048576, $e->max);
        }
        $this->expectException(PayloadTooLargeException::class);
        $service->commit(['content' => base64_encode($big), 'encoding' => 'base64']);
    }

    // ---------------------------------------------------------------- commit

    public function testCommitCreatesRulesAndFiresImportEvents(): void
    {
        $result = $this->service()->commit(['content' => self::CSV, 'filename' => 'r.csv']);

        self::assertSame('csv', $result['format']);
        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertFalse($result['rules_truncated']);
        self::assertSame('/old-a', $result['rules'][0]['source']);
        self::assertSame(0, $result['suggestions']);
        self::assertSame(0, $result['not_found']);
        self::assertSame(['/old-a', '/old-b'], $this->storedIds());
        self::assertSame('import', $this->events[0]['payload']['action'] ?? RuleEvents::ACTION_IMPORT);
        self::assertSame('import', $this->app->rules()->all()[0]->origin->value);
    }

    public function testCommitRefusesInvalidRowsAndStoresNothing(): void
    {
        $content = "source,target,status,match_type\n/ok,/fine,301,exact\n^/(a+)+\$,/x,301,regex\n";

        try {
            $this->service()->commit(['content' => $content, 'filename' => 'r.csv']);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('The import has invalid rows.', $e->getMessage());
            self::assertCount(1, $e->issues);
            self::assertSame('rows.3', $e->issues[0]->field);
            self::assertSame('regex_catastrophic', $e->issues[0]->code);
        }
        self::assertSame([], $this->storedIds());
    }

    public function testCommitSkipsInvalidRowsWhenAsked(): void
    {
        $content = "source,target,status,match_type\n/ok,/fine,301,exact\n^/(a+)+\$,/x,301,regex\n";
        $result = $this->service()->commit(['content' => $content, 'filename' => 'r.csv', 'skip_invalid' => true]);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['/ok'], $this->storedIds());
    }

    public function testCommitSkipsDuplicatesByDefault(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/old-a', 'target' => '/new-a']]);

        $result = $this->service()->commit(['content' => self::CSV, 'format' => 'csv']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['skipped']);

        $again = $this->service()->commit(['content' => self::CSV, 'format' => 'csv', 'skip_duplicates' => false]);
        self::assertSame(2, $again['created']);
    }

    public function testCommitLinesFilter(): void
    {
        $content = "source,target,status\n/a,/x,301\n/b,/y,301\n/c,/z,301\n";
        $result = $this->service()->commit(['content' => $content, 'format' => 'csv', 'lines' => [2, 4]]);

        self::assertSame(2, $result['created']);
        self::assertSame(['/a', '/c'], $this->storedIds());
    }

    public function testInvalidRowOutsideLinesDoesNotBlockCommit(): void
    {
        $content = "source,target,status,match_type\n/ok,/fine,301,exact\n^/(a+)+\$,/x,301,regex\n";
        $result = $this->service()->commit(['content' => $content, 'format' => 'csv', 'lines' => [2]]);

        self::assertSame(1, $result['created']);
    }

    public function testCommitReturnsAtMost500Rules(): void
    {
        $lines = ['source,target,status'];
        for ($i = 0; $i < 520; ++$i) {
            $lines[] = "/o{$i},/n{$i},301";
        }
        $result = $this->service()->commit(['content' => implode("\n", $lines), 'format' => 'csv']);

        self::assertSame(520, $result['created']);
        self::assertCount(500, $result['rules']);
        self::assertTrue($result['rules_truncated']);
        self::assertCount(520, $this->app->rules()->all());
    }

    public function testCommitWithFileErrorThrows(): void
    {
        try {
            $this->service()->commit(['content' => 'just some words', 'filename' => 'notes.txt']);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('unknown_format', $e->errorCode);
        }
    }

    public function testCrawlerCsvCreatesSuggestions(): void
    {
        $this->app = $this->makeApp([], [new PageInfo('/neue-seite', slug: 'neue-seite', title: 'Neue Seite'), new PageInfo('/produkte/kaputt-repariert', slug: 'kaputt-repariert', title: 'Kaputt repariert')], new SiteContext(baseUrl: 'https://www.example.com'));
        $content = (string) file_get_contents(__DIR__ . '/../ImportExport/fixtures/screamingfrog-client-error.csv');
        $preview = $this->service()->preview(['content' => $content, 'filename' => 'internal_client_error_(4xx).csv']);
        self::assertSame('crawler_csv', $preview['format']);
        self::assertNotSame([], $preview['not_found_paths']);

        $result = $this->service()->commit(['content' => $content, 'filename' => 'internal_client_error_(4xx).csv']);

        self::assertSame(count($preview['not_found_paths']), $result['not_found']);
        self::assertSame(0, $result['created'], 'crawler files produce no rules');
        self::assertGreaterThan(0, $result['suggestions']);
        self::assertCount($result['suggestions'], $this->app->services()->suggestionStore()->all());
        self::assertSame('crawler', $this->app->services()->suggestionStore()->all()[0]['source']);
    }

    // ---------------------------------------------------------------- sitemap

    private static function urlset(string ...$paths): string
    {
        $urls = '';
        foreach ($paths as $path) {
            $urls .= '<url><loc>https://old.example.com' . $path . '</loc></url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
    }

    public function testSitemapDiffCreatesSuggestions(): void
    {
        $app = $this->makeApp([], [
            new PageInfo('/exists', slug: 'exists', title: 'Exists'),
            new PageInfo('/target', slug: 'target', title: 'Target'),
            new PageInfo('/services/gone-a', slug: 'gone-a', title: 'Gone A'),
            new PageInfo('/services/gone-b', slug: 'gone-b', title: 'Gone B'),
        ]);
        $this->app = $app;
        $this->seedRules([['id' => 'r1', 'source' => '/redirected', 'target' => '/target']]);

        $result = $app->importExport()->sitemap(['content' => self::urlset('/exists', '/redirected', '/old/gone-a', '/old/gone-b')]);

        self::assertSame(4, $result['total']);
        self::assertSame(1, $result['existing']);
        self::assertSame(1, $result['redirected']);
        self::assertSame(2, $result['missing']);
        self::assertSame(['/old/gone-a', '/old/gone-b'], $result['missing_paths']);
        self::assertSame(2, $result['suggestions_created']);
        self::assertCount(2, $app->services()->suggestionStore()->all());
    }

    public function testSitemapAcceptsGzipInBase64(): void
    {
        $gz = (string) gzencode(self::urlset('/gone'));
        $result = $this->service()->sitemap(['content' => base64_encode($gz), 'encoding' => 'base64']);

        self::assertSame(1, $result['missing']);
    }

    public function testSitemapIndexIsRejected(): void
    {
        $index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://old.example.com/s1.xml</loc></sitemap></sitemapindex>';

        try {
            $this->service()->sitemap(['content' => $index]);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('sitemap_index', $e->errorCode);
            self::assertSame('content', $e->field);
        }
    }

    public function testSitemapWithDoctypeIsRejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY x "y">]><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.test/&x;</loc></url></urlset>';

        try {
            $this->service()->sitemap(['content' => $xml]);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('sitemap_invalid', $e->errorCode);
        }
    }

    public function testSitemapChecksBodyAndSize(): void
    {
        try {
            $this->service()->sitemap([]);
            self::fail('Expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame('required', $e->errorCode);
        }
        $this->expectException(PayloadTooLargeException::class);
        $this->makeApp(['import' => ['max_mb' => 1]])->importExport()->sitemap(['content' => str_repeat(' ', 1048577)]);
    }

    // ---------------------------------------------------------------- site config

    /**
     * @return array<string, mixed>
     */
    private static function siteData(): array
    {
        return [
            'redirects' => ['/alte-seite' => '/neue-seite', '/impressum.html' => '/impressum [301]'],
            'routes' => ['/alias' => '/echte-seite'],
        ];
    }

    private function siteApp(): void
    {
        $data = self::siteData();
        $this->app = $this->makeApp(site: new SiteContext(
            baseUrl: 'http://localhost:8080',
            siteRedirects: $data['redirects'],
            siteRoutes: $data['routes'],
            pageSettings: ['redirect_default_route' => '/home'],
        ));
    }

    public function testSiteConfigReadsSiteContext(): void
    {
        $this->siteApp();
        $config = $this->app->importExport()->siteConfig();

        self::assertSame(self::siteData()['redirects'], $config['redirects']);
        self::assertSame(self::siteData()['routes'], $config['routes']);
        self::assertSame(['redirect_default_route' => '/home'], $config['settings']);

        $empty = $this->makeApp()->importExport()->siteConfig();
        self::assertInstanceOf(\stdClass::class, $empty['redirects']);
        self::assertInstanceOf(\stdClass::class, $empty['routes']);
        self::assertInstanceOf(\stdClass::class, $empty['settings']);
        self::assertSame('{"redirects":{},"routes":{},"settings":{}}', json_encode($empty));
    }

    public function testImportSiteConfigCreatesRulesWithoutTouchingFiles(): void
    {
        $this->siteApp();
        $result = $this->app->importExport()->importSiteConfig([]);

        self::assertSame('grav_site', $result['format']);
        self::assertSame(3, $result['created']);
        $groups = array_unique(array_map(static fn ($r): string => $r->group, $this->app->rules()->all()));
        self::assertSame(['site.yaml'], $groups);
        self::assertSame([], glob($this->tmp . '/{,*/,*/*/}site.yaml', GLOB_BRACE));
    }

    public function testImportSiteConfigSectionsAndDuplicates(): void
    {
        $this->siteApp();
        $service = $this->app->importExport();

        $routesOnly = $service->importSiteConfig(['redirects' => false]);
        self::assertSame(1, $routesOnly['created']);

        $again = $service->importSiteConfig([]);
        self::assertSame(2, $again['created'], 'the route is skipped as duplicate');
        self::assertSame(1, $again['skipped']);
    }

    public function testImportSiteConfigNothingToImport(): void
    {
        foreach ([[$this->service(), []], [null, ['redirects' => false, 'routes' => false]]] as [$service, $body]) {
            $this->siteApp();
            try {
                ($service ?? $this->app->importExport())->importSiteConfig($body);
                self::fail('Expected an exception');
            } catch (InvalidInputException $e) {
                self::assertSame('nothing_to_import', $e->errorCode);
            }
        }
    }

    // ---------------------------------------------------------------- export

    private function seedForExport(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/b', 'status' => 301, 'group' => 'blog'],
            ['id' => 'r2', 'source' => '/c', 'target' => '/d', 'status' => 302, 'group' => ''],
            ['id' => 'r3', 'source' => '/e', 'target' => '/f', 'status' => 301, 'group' => 'blog', 'enabled' => false],
            ['id' => 'r4', 'source' => '/gone', 'target' => '', 'status' => 410],
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exportableFormats(): iterable
    {
        foreach (Format::cases() as $format) {
            if ($format->canExport()) {
                yield $format->value => [$format->value];
            }
        }
    }

    #[DataProvider('exportableFormats')]
    public function testExportEveryFormat(string $id): void
    {
        $this->seedForExport();
        $result = $this->service()->export(['format' => $id, 'host' => 'www.example.com']);

        $format = Format::from($id);
        self::assertSame($format->filename(), $result['filename']);
        self::assertSame($format->mimeType(), $result['mime']);
        self::assertNotSame('', trim($result['content']));
        self::assertGreaterThan(0, $result['exported']);
        self::assertIsList($result['skipped']);
        self::assertIsList($result['lossy']);
    }

    public function testExportDefaultsToCsvWithAllRules(): void
    {
        $this->seedForExport();
        $result = $this->service()->export([]);

        self::assertSame('redirects.csv', $result['filename']);
        self::assertSame(4, $result['exported']);
        self::assertStringContainsString('/a', $result['content']);
    }

    public function testExportFilters(): void
    {
        $this->seedForExport();

        self::assertSame(3, $this->service()->export(['only_enabled' => '1'])['exported']);
        self::assertSame(3, $this->service()->export(['only_enabled' => 'true'])['exported']);
        self::assertSame(4, $this->service()->export(['only_enabled' => '0'])['exported']);
        self::assertSame(2, $this->service()->export(['group' => 'blog'])['exported']);
        self::assertSame(2, $this->service()->export(['group' => ''])['exported'], 'an empty group filters for rules without group');
        self::assertSame(1, $this->service()->export(['status' => '302'])['exported']);
        self::assertSame(2, $this->service()->export(['status' => '302, 410,x'])['exported']);
        self::assertSame(1, $this->service()->export(['group' => 'blog', 'only_enabled' => true])['exported']);
    }

    public function testExportReportsSkippedRules(): void
    {
        $this->seedForExport();
        $result = $this->service()->export(['format' => 'htaccess']);

        self::assertSame('disabled', $result['skipped'][0]['code']);
        self::assertSame('r3', $result['skipped'][0]['rule_id']);
    }

    public function testExportRejectsBadFormats(): void
    {
        foreach (['nonsense' => 'unknown_format', 'crawler_csv' => 'format_not_exportable'] as $format => $code) {
            try {
                $this->service()->export(['format' => $format]);
                self::fail('Expected an exception for ' . $format);
            } catch (InvalidInputException $e) {
                self::assertSame('format', $e->field);
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testExportRoundTripsThroughImport(): void
    {
        $this->seedForExport();
        $export = $this->service()->export(['format' => 'json']);

        $app = $this->makeApp();
        $preview = $app->importExport()->preview(['content' => $export['content'], 'filename' => $export['filename']]);

        self::assertSame('json', $preview['format']);
        self::assertSame(4, $preview['counts']['valid']);
    }
}
