<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\CloudflareCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\CrawlerCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CrawlerCsvAdapter::class)]
#[CoversClass(CloudflareCsvAdapter::class)]
final class CrawlerAndCloudflareTest extends ImportExportTestCase
{
    public function testScreamingFrogExport(): void
    {
        $preview = $this->preview($this->fixture('screamingfrog-client-error.csv'), Format::CrawlerCsv);

        self::assertSame([], $preview->errors);
        self::assertSame([], $preview->rows, 'crawler files produce no rules');
        self::assertSame(['/alte-seite', '/produkte/kaputt', '/entfernt', '/blog/über-uns', '/extern'], $preview->notFoundPaths, 'unique, decoded, without query; 200 and 301 rows are ignored');
        self::assertSame(5, $preview->counts()['not_found']);
        self::assertSame([], $preview->rules());
    }

    public function testBaseHostsFilterExternalUrls(): void
    {
        $preview = $this->preview($this->fixture('screamingfrog-client-error.csv'), Format::CrawlerCsv, new ImportOptions(baseHosts: ['example.com']));

        self::assertSame(['/alte-seite', '/produkte/kaputt', '/entfernt', '/blog/über-uns'], $preview->notFoundPaths);
    }

    public function testSitebulbExport(): void
    {
        $preview = $this->preview($this->fixture('sitebulb-404.csv'), Format::CrawlerCsv);

        self::assertSame(['/downloads/broschuere.pdf', '/team/ehemalig', '/relative/pfad'], $preview->notFoundPaths);
    }

    public function testStatusTextIsUsedWhenThereIsNoCodeColumn(): void
    {
        $csv = "Address,Status\nhttps://x.test/a,Not Found\nhttps://x.test/b,Gone\nhttps://x.test/c,OK\nhttps://x.test/d,\n";
        $preview = $this->preview($csv, Format::CrawlerCsv);

        self::assertSame(['/a', '/b'], $preview->notFoundPaths);
    }

    public function testTitleLineBeforeTheHeaderAndOtherDelimiters(): void
    {
        $csv = "\"Response Codes - Client Error (4xx)\"\n\nAddress;Status Code\nhttps://x.test/a;404\nhttps://x.test/b?q=1#frag;410\nhttps://x.test/;404\n//x.test/c;404\nmailto:a@x.test;404\n";
        $preview = $this->preview($csv, Format::CrawlerCsv);

        self::assertSame(['/a', '/b', '/', '/c'], $preview->notFoundPaths);
    }

    public function testMissingColumnsAreFileErrors(): void
    {
        foreach (["Address,Inlinks\nhttps://x.test/a,3\n", "Foo,Bar\n1,2\n"] as $csv) {
            $preview = $this->preview($csv, Format::CrawlerCsv);
            self::assertSame(['missing_column'], array_map(static fn ($i) => $i->code, $preview->errors));
        }
    }

    public function testOnlyHeaderMeansNothingFound(): void
    {
        $preview = $this->preview("Address,Status Code\n", Format::CrawlerCsv);

        self::assertSame([], $preview->notFoundPaths);
        self::assertSame('no_rows', $preview->warnings[0]->code);
    }

    public function testPathsWithControlCharactersStayEncoded(): void
    {
        $preview = $this->preview("Address,Status Code\nhttps://x.test/a%0Ab,404\nhttps://x.test/s%C3%A4,404\nhttps://x.test/%FF,404\n", Format::CrawlerCsv);

        self::assertSame(['/a%0Ab', '/sä', '/%FF'], $preview->notFoundPaths);
    }

    // ---------------------------------------------------------------- cloudflare

    /**
     * @param list<string> $names
     * @return list<list<string>>
     */
    private function cloudflare(array $names, ?ExportOptions $options = null): array
    {
        $result = (new Exporter())->export(FixtureRules::only($names), Format::CloudflareCsv, $options ?? new ExportOptions(exportHost: 'example.com'));

        return array_map(static fn (array $r): array => $r['cells'], Csv::parse($result->content));
    }

    public function testExportRows(): void
    {
        $rows = $this->cloudflare(['exact_301', 'exact_302', 'wild_blog', 'query_pass', 'wild_external', 'exact_308']);

        self::assertSame(['source_url', 'target_url', 'status_code', 'preserve_query_string', 'include_subdomains', 'subpath_matching', 'preserve_path_suffix'], $rows[0]);
        $byHost = [];
        foreach (array_slice($rows, 1) as $row) {
            $byHost[$row[0]] = $row;
        }
        self::assertSame(['example.com/old-page', 'https://example.com/new-page', '301', 'FALSE', 'FALSE', 'FALSE', 'FALSE'], $byHost['example.com/old-page']);
        self::assertSame(['example.com/temp', 'https://example.com/elsewhere', '302', 'FALSE', 'FALSE', 'FALSE', 'FALSE'], $byHost['example.com/temp']);
        self::assertSame(['example.com/perm308', 'https://example.com/x308', '308', 'FALSE', 'FALSE', 'FALSE', 'FALSE'], $byHost['example.com/perm308']);
        self::assertSame(['example.com/pass-query', 'https://example.com/target', '301', 'TRUE', 'FALSE', 'FALSE', 'FALSE'], $byHost['example.com/pass-query']);
        self::assertSame(['example.com/blog', 'https://example.com/news', '301', 'FALSE', 'FALSE', 'TRUE', 'TRUE'], $byHost['example.com/blog']);
        self::assertSame(['example.com/media', 'https://cdn.example.org/media', '301', 'FALSE', 'FALSE', 'TRUE', 'TRUE'], $byHost['example.com/media']);
    }

    public function testExportHostVariantsAndRuleHosts(): void
    {
        $rows = $this->cloudflare(['host', 'host_wild', 'exact_301'], new ExportOptions(exportHost: 'https://Shop.Example.com/'));
        $sources = array_column(array_slice($rows, 1), 0);
        sort($sources);

        self::assertSame(['example.com/hosted', 'example.org/wildhost', 'shop.example.com/old-page', 'www.example.com/hosted'], $sources);
        $wild = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'example.org/wildhost'))[0];
        self::assertSame('TRUE', $wild[4], 'wildcard hosts become include_subdomains');
        self::assertSame('https://shop.example.com/wh-target', $wild[1]);

        $hostOnly = $this->cloudflare(['host'], new ExportOptions());
        self::assertSame(['example.com/hosted', 'https://example.com/h-target'], array_slice($hostOnly[1], 0, 2), 'a rule host is enough when no export host is set');
    }

    public function testExportSkips(): void
    {
        $rules = FixtureRules::only(['exact_301', 'gone_410', 'legal_451', 'alias_200', 'scheme', 'regex_number', 'query_exact']);
        $wildcard = FixtureRules::all()['wild_blog']->with(['id' => 'r-w1', 'source' => '/a/*/b']);
        $placeholder = FixtureRules::all()['wild_blog']->with(['id' => 'r-w2', 'target' => '/n/$1/x']);
        $exact = FixtureRules::all()['exact_301']->with(['id' => 'r-e', 'target' => '/n/$1']);
        $result = (new Exporter())->export([...$rules, $wildcard, $placeholder, $exact], Format::CloudflareCsv, new ExportOptions(exportHost: 'example.com'));

        $codes = [];
        foreach ($result->skipped as $note) {
            $codes[$note->ruleId] = $note->code;
            self::assertNotSame('', $note->reason);
        }
        ksort($codes);
        self::assertSame([
            'r-e' => 'placeholder_not_supported',
            'r-w1' => 'wildcard_not_representable',
            'r-w2' => 'wildcard_not_representable',
            'r005-gone_410' => 'status_not_supported',
            'r006-legal_451' => 'status_not_supported',
            'r007-alias_200' => 'status_not_supported',
            'r015-regex_number' => 'regex_not_supported',
            'r026-scheme' => 'conditions_not_supported',
        ], $codes);
        self::assertSame(2, $result->exported, 'the plain rules');
        self::assertSame(['export_host_required'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$rules[0]], Format::CloudflareCsv, new ExportOptions())->skipped));
    }

    public function testExportIsNotImportable(): void
    {
        self::assertFalse(Format::CloudflareCsv->canImport());
        self::assertFalse(Format::CrawlerCsv->canExport());
        $this->expectException(\InvalidArgumentException::class);
        (new Exporter())->export([], Format::CrawlerCsv, new ExportOptions());
    }

    public function testExportWithoutHeader(): void
    {
        $result = (new Exporter())->export(FixtureRules::only(['exact_301']), Format::CloudflareCsv, new ExportOptions(exportHost: 'example.com', includeHeader: false));

        self::assertSame("example.com/old-page,https://example.com/new-page,301,FALSE,FALSE,FALSE,FALSE\n", $result->content);
        self::assertSame('redirects-cloudflare.csv', $result->filename);
    }
}
