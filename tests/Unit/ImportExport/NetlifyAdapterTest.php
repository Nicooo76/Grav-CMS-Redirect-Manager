<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\NetlifyAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(NetlifyAdapter::class)]
final class NetlifyAdapterTest extends ImportExportTestCase
{
    public function testRealisticFile(): void
    {
        $preview = $this->preview($this->fixture('netlify-redirects.txt'), Format::Netlify);

        self::assertSame([], $preview->errors);
        self::assertSame([
            'e:/home=>/#301',
            'w:/blog/*=>/news/$1#301',
            'r:^/news/(?<year>[^/]+)/(?<month>[^/]+)/?$=>/archive/{year}-{month}#301',
            'e:/store=>/blog/:id#301 q=params{"id":null}',
            'e:/shop=>/schuhe#302 q=params{"category":"shoes"}',
            'e:/gone=>#410',
            'e:/rewrite=>/page.html#200',
            'w:/en/*=>/de/$1#301',
            'w:/*=>https://neu.example.com/$1#301',
            'e:/mehr=>/dazu#301',
        ], self::sigs($preview));

        $issues = self::issuesByRow($preview);
        self::assertSame([
            3 => ['query_capture_unsupported'],
            7 => ['proxy_not_supported'],
            8 => ['unsupported_condition', 'unsupported_condition'],
            9 => ['source_host_stripped'],
            11 => ['invalid_line'],
            12 => ['invalid_status'],
            13 => ['unknown_placeholder'],
        ], $issues);
    }

    public function testHostsBecomeConditionsWhenSiteHostsAreKnown(): void
    {
        $preview = $this->preview("https://alt.example.com/old /new 301!\nhttps://www.mine.test/x /y\n", Format::Netlify, new ImportOptions(baseHosts: ['mine.test']));

        self::assertSame(['e:/old=>/new#301 hosts=alt.example.com', 'e:/x=>/y#301'], self::sigs($preview));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lines(): iterable
    {
        yield 'simple' => ['/a /b 301', 'e:/a=>/b#301'];
        yield 'default status' => ['/a /b', 'e:/a=>/b#301'];
        yield 'force' => ['/a /b 302!', 'e:/a=>/b#302'];
        yield 'tabs and spaces' => ["/a\t\t/b   307", 'e:/a=>/b#307'];
        yield 'splat' => ['/a/* /b/:splat', 'w:/a/*=>/b/$1#301'];
        yield 'splat mid target' => ['/a/* /b/:splat/index.html 302', 'w:/a/*=>/b/$1/index.html#302'];
        yield 'placeholders' => ['/x/:y/:z /t/:z/:y 302', 'r:^/x/(?<y>[^/]+)/(?<z>[^/]+)/?$=>/t/{z}/{y}#302'];
        yield 'placeholder with splat' => ['/x/:y/* /t/:y/:splat', 'r:^/x/(?<y>[^/]+)/(?<splat>.*)$=>/t/{y}/{splat}#301'];
        yield 'trailing slash placeholder' => ['/x/:y/ /t/:y', 'r:^/x/(?<y>[^/]+)/$=>/t/{y}#301'];
        yield 'literal query' => ['/s page=2 /page-2 301', 'e:/s=>/page-2#301 q=params{"page":"2"}'];
        yield 'two query params' => ['/s a=1 b=:b /t 301', 'e:/s=>/t#301 q=params{"a":"1","b":null}'];
        yield 'query in target' => ['/a /b?x=1 301', 'e:/a=>/b?x=1#301'];
        yield 'external target' => ['/a https://o.test/b 302', 'e:/a=>https://o.test/b#302'];
        yield 'status only 410' => ['/a 410', 'e:/a=>#410'];
        yield 'rewrite' => ['/a /b.html 200', 'e:/a=>/b.html#200'];
        yield 'percent encoded' => ['/caf%C3%A9 /x', 'e:/café=>/x#301'];
        yield 'conditions ignored' => ['/a /b 301 Country=de Role=admin', 'e:/a=>/b#301'];
        yield 'condition without status' => ['/a /b Country=de', 'e:/a=>/b#301'];
    }

    #[DataProvider('lines')]
    public function testLines(string $line, string $expected): void
    {
        $preview = $this->preview($line . "\n", Format::Netlify);

        self::assertSame([], $preview->errors);
        self::assertSame([$expected], self::sigs($preview), json_encode(self::codes($preview->rows[0])) ?: '');
    }

    public function testPlaceholdersInTheTargetMustBeDefinedBySource(): void
    {
        $cases = [
            '/x/:a /t/:missing' => 'unknown_placeholder',
            '/x/* /t/:other' => 'unknown_placeholder',
            '/x /t/:splat' => 'unknown_placeholder',
            '/x/:a/* /t/:a/:splat' => null,
            '/x/* /t/:splat?ref=:ignored' => 'unknown_placeholder',
            '/x /t https://h.test:8080/y' => null,
        ];
        foreach ($cases as $line => $code) {
            $preview = $this->preview($line . "\n", Format::Netlify);
            self::assertSame($code, $preview->rows[0]->errors[0]->code ?? null, $line);
        }
    }

    public function testCommentsAndBlankLines(): void
    {
        $preview = $this->preview("# c\n\n   \n  # indented\n/a /b\n", Format::Netlify);

        self::assertCount(1, $preview->rows);
        self::assertSame(5, $preview->rows[0]->line);
    }

    public function testMultipleSplatsAreReported(): void
    {
        $preview = $this->preview("/a/*/b/* /c/:splat\n", Format::Netlify);

        self::assertSame(['w:/a/*/b/*=>/c/$1#301'], self::sigs($preview));
        self::assertSame(['splat_multiple'], self::codes($preview->rows[0]));
    }

    public function testExportFormat(): void
    {
        $rules = FixtureRules::only(['exact_301', 'wild_blog', 'query_params', 'query_exact', 'alias_200', 'host', 'space', 'wild_external']);
        $out = (new Exporter())->export($rules, Format::Netlify, new ExportOptions())->content;
        $lines = explode("\n", trim($out));

        self::assertStringStartsWith('# Redirects exported', $lines[0]);
        foreach ([
            '/old-page /new-page 301',
            '/blog/* /news/:splat 301',
            '/store id=:id cat=x /shop 301',
            '/search q=1 /found 301',
            '/alias /actual-page 200',
            'https://example.com/hosted /h-target 301',
            'https://www.example.com/hosted /h-target 301',
            '/my%20page /my-page 301',
            '/media/* https://cdn.example.org/media/:splat 301',
        ] as $expected) {
            self::assertContains($expected, $lines);
        }
    }

    public function testExportRegexThatCanBeWrittenAsPlaceholders(): void
    {
        $rule = FixtureRules::all()['regex_named']->with(['source' => '^/news/(?<year>[^/]+)/(?<month>[^/]+)/?$', 'target' => '/archive/{year}-{month}']);
        $splat = FixtureRules::all()['regex_named']->with(['id' => 'r-s', 'source' => '^/x/(?<y>[^/]+)/(?<splat>.*)$', 'target' => '/t/$1/{splat}']);
        $out = (new Exporter())->export([$rule, $splat], Format::Netlify, new ExportOptions(includeHeader: false))->content;

        self::assertSame("/x/:y/* /t/:y/:splat 301\n/news/:year/:month /archive/:year-:month 301\n", $out);
    }

    public function testExportSkips(): void
    {
        $cases = [
            'status_not_supported' => FixtureRules::all()['gone_410'],
            'conditions_not_supported' => FixtureRules::all()['scheme'],
            'wildcard_host_not_supported' => FixtureRules::all()['host_wild'],
            'regex_not_representable' => FixtureRules::all()['regex_number'],
            'wildcard_not_representable' => FixtureRules::all()['wild_blog']->with(['source' => '/a/*/b']),
            'invalid_target' => FixtureRules::all()['exact_301']->with(['target' => '/a b']),
        ];
        foreach ($cases as $code => $rule) {
            $result = (new Exporter())->export([$rule], Format::Netlify, new ExportOptions());

            self::assertSame([$code], array_map(static fn ($n) => $n->code, $result->skipped), $code);
            self::assertSame(0, $result->exported);
            self::assertStringContainsString('# skipped ' . $rule->id . ' (' . $code . ')', $result->content);
            self::assertNotSame('', $result->skipped[0]->reason);
        }

        $twoSplats = FixtureRules::all()['wild_blog']->with(['target' => '/news/$1/$2']);
        self::assertSame(['wildcard_not_representable'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$twoSplats], Format::Netlify, new ExportOptions())->skipped));
        $unnamed = FixtureRules::all()['regex_number']->with(['source' => '^/a/(?<x>[^/]+)/([0-9]+)$', 'target' => '/b/$2']);
        self::assertSame(['regex_not_representable'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$unnamed], Format::Netlify, new ExportOptions())->skipped));
        $splatMid = FixtureRules::all()['regex_number']->with(['source' => '^/a/(?<splat>.*)/z$', 'target' => '/b/{splat}']);
        self::assertSame(['regex_not_representable'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$splatMid], Format::Netlify, new ExportOptions())->skipped));
        $strangeKind = FixtureRules::all()['regex_number']->with(['source' => '^/a/(?<x>\d+)$', 'target' => '/b/{x}']);
        self::assertSame(['regex_not_representable'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$strangeKind], Format::Netlify, new ExportOptions())->skipped));
    }

    public function testExportedFileIsDetectedByItsName(): void
    {
        $out = (new Exporter())->export(FixtureRules::only(['exact_301']), Format::Netlify, new ExportOptions());

        self::assertSame('_redirects', $out->filename);
        self::assertSame(Format::Netlify, Format::detect($out->filename, $out->content));
    }
}
