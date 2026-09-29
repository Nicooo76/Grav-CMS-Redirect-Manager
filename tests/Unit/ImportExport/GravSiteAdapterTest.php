<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\GravSiteAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(GravSiteAdapter::class)]
final class GravSiteAdapterTest extends ImportExportTestCase
{
    public function testRealisticSiteYaml(): void
    {
        $preview = $this->preview($this->fixture('site.yaml'), Format::GravSite);

        self::assertSame([], $preview->errors);
        self::assertSame([
            'e:/alte-seite=>/neue-seite#302 cs',
            'e:/impressum.html=>/impressum#301 cs',
            'e:/kontakt=>/kontakt-neu#302 cs',
            'e:/Gross=>/klein#307',
            'w:/blog/*=>/magazin/$1#301 cs',
            'r:^/produkt/(\d+)=>/shop/$1#302 cs',
            'r:^/anker/(?<name>[a-z]+)$=>/ziel/$1#301 cs',
            'e:/extern=>https://www.example.org/ziel#301 cs',
            'e:/alias=>/echte-seite#200 cs',
            'e:/home-alias=>/home#200 cs',
        ], self::sigs($preview));

        self::assertSame(['invalid_row'], self::codes($preview->rows[8]));
        self::assertSame(['invalid_row'], self::codes($preview->rows[9]));
        self::assertSame(['grav_prefix_semantics'], self::codes($preview->rows[5]));
        self::assertSame(['alias_pass_through'], self::codes($preview->rows[10]));
    }

    public function testDefaultRedirectCodeComesFromTheOptions(): void
    {
        $yaml = "redirects:\n  /a: /b\n  /c: '/d [307]'\n";

        self::assertSame([302, 307], array_map(static fn ($r) => $r->status->value, $this->rulesOf($this->preview($yaml, Format::GravSite))));
        self::assertSame([301, 307], array_map(static fn ($r) => $r->status->value, $this->rulesOf($this->preview($yaml, Format::GravSite, new ImportOptions(redirectDefaultCode: 301)))));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function patterns(): iterable
    {
        yield 'plain' => ['/a', '/b', 'e:/a=>/b#302 cs'];
        yield 'anchored' => ['^/a$', '/b', 'e:/a=>/b#302 cs'];
        yield 'caret only' => ['^/a', '/b', 'e:/a=>/b#302 cs'];
        yield 'dollar' => ['/a$', '/b', 'e:/a=>/b#302 cs'];
        yield 'escaped dot' => ['/a\.html$', '/b', 'e:/a.html=>/b#302 cs'];
        yield 'bare dot' => ['/a.html', '/b', 'e:/a.html=>/b#302 cs'];
        yield 'case flag' => ['(?i)/a$', '/b', 'e:/a=>/b#302'];
        yield 'wildcard' => ['/blog/(.*)$', '/n/$1', 'w:/blog/*=>/n/$1#302 cs'];
        yield 'wildcard without end' => ['/blog/(.*)', '/n/$1', 'w:/blog/*=>/n/$1#302 cs'];
        yield 'regex' => ['/p/(\d+)$', '/q/$1', 'r:^/p/(\d+)$=>/q/$1#302 cs'];
        yield 'target code' => ['/a', '/b [301]', 'e:/a=>/b#301 cs'];
        yield 'target code with spaces' => ['/a', '/b   [307]', 'e:/a=>/b#307 cs'];
        yield 'unsupported code stays in the target and is refused' => ['/a', '/b [308]', 'invalid_target'];
    }

    #[DataProvider('patterns')]
    public function testPatternMapping(string $pattern, string $target, string $expected): void
    {
        $yaml = Yaml::dump(['redirects' => [$pattern => $target]]);
        $preview = $this->preview($yaml, Format::GravSite);

        self::assertSame($expected, self::sigs($preview)[0] ?? self::codes($preview->rows[0])[0]);
    }

    public function testStructureVariants(): void
    {
        $wrapped = $this->preview("site:\n  redirects:\n    /a: /b\n", Format::GravSite);
        self::assertSame(['e:/a=>/b#302 cs'], self::sigs($wrapped));

        $flat = $this->preview("/a: /b\n'^/c/(.*)$': /d/\$1\n", Format::GravSite);
        self::assertSame(['e:/a=>/b#302 cs', 'w:/c/*=>/d/$1#302 cs'], self::sigs($flat));

        $routesOnly = $this->preview("routes:\n  /x: /y\n", Format::GravSite);
        self::assertSame(['e:/x=>/y#200 cs'], self::sigs($routesOnly));

        $emptyRedirects = $this->preview("title: T\nredirects:\nroutes:\n  /x: /y\n", Format::GravSite);
        self::assertSame(['e:/x=>/y#200 cs'], self::sigs($emptyRedirects));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStructures(): iterable
    {
        yield 'nothing useful' => ["title: T\nmetadata: {}\n"];
        yield 'scalar' => ['just text'];
        yield 'list' => ["- a\n- b\n"];
        yield 'redirects is a list' => ["redirects:\n  - /a\n"];
        yield 'redirects is a string' => ["redirects: nope\n"];
        yield 'mixed flat map' => ["/a: /b\ntitle: T\n"];
    }

    #[DataProvider('invalidStructures')]
    public function testInvalidStructureIsAFileError(string $yaml): void
    {
        $preview = $this->preview($yaml, Format::GravSite);

        self::assertSame(['invalid_structure'], array_map(static fn ($i) => $i->code, $preview->errors));
    }

    public function testExportFormat(): void
    {
        $rules = FixtureRules::only(['exact_301', 'cs_slash', 'wild_blog', 'regex_number', 'regex_named', 'alias_200', 'exact_302']);
        $out = (new Exporter())->export($rules, Format::GravSite, new ExportOptions())->content;
        $data = Yaml::parse($out);

        self::assertSame([
            '(?i)/old-page$' => '/new-page [301]',
            '(?i)/temp$' => '/elsewhere [302]',
            '/case-slash$' => '/t [301]',
            '(?i)/blog/(.*)$' => '/news/$1 [301]',
            '(?i)/product/(\d+)$' => '/shop/$1 [301]',
            '/(?<year>\d{4})/(?<slug>[^/]+)$' => '/archive/$1/$2 [301]',
        ], $data['redirects']);
        self::assertSame(['/alias' => '/actual-page'], $data['routes']);
        self::assertStringStartsWith('# Redirects exported by Redirect Manager', $out);
    }

    public function testExportNoHeaderAndEmpty(): void
    {
        $empty = (new Exporter())->export([], Format::GravSite, new ExportOptions(includeHeader: false));
        self::assertSame("redirects: {}\n", $empty->content);

        $skippedOnly = (new Exporter())->export(FixtureRules::only(['gone_410']), Format::GravSite, new ExportOptions(includeHeader: false));
        self::assertSame("# skipped r005-gone_410 (status_not_supported): /gone-page\nredirects: {}\n", $skippedOnly->content);
    }

    public function testExportSkipsAndDuplicates(): void
    {
        $rules = FixtureRules::only(['legal_451', 'query_exact', 'host', 'exact_308', 'regex_files']);
        $unanchored = FixtureRules::all()['regex_files']->with(['id' => 'r-un', 'source' => 'files/(.+)']);
        $named = FixtureRules::all()['regex_named']->with(['id' => 'r-nm', 'target' => '/x/{nope}']);
        $pass = FixtureRules::all()['alias_200']->with(['id' => 'r-pass', 'match_type' => 'wildcard', 'source' => '/a/*']);
        $dup = FixtureRules::all()['exact_301']->with(['id' => 'r-d1']);
        $dup2 = FixtureRules::all()['exact_301']->with(['id' => 'r-d2', 'target' => '/other']);
        $result = (new Exporter())->export([...$rules, $unanchored, $named, $pass, $dup, $dup2], Format::GravSite, new ExportOptions());

        $codes = [];
        foreach ($result->skipped as $note) {
            $codes[$note->ruleId] = $note->code;
        }
        ksort($codes);
        self::assertSame([
            'r-d2' => 'duplicate_key',
            'r-nm' => 'named_group_unresolved',
            'r-pass' => 'pass_through_not_representable',
            'r-un' => 'regex_not_anchored',
            'r004-exact_308' => 'status_not_supported',
            'r006-legal_451' => 'status_not_supported',
            'r021-query_exact' => 'query_not_supported',
            'r024-host' => 'conditions_not_supported',
        ], $codes);
        self::assertSame(2, $result->exported, 'regex_files and the first exact rule');
    }

    public function testExportedFileIsReadableByGravsOwnRules(): void
    {
        // Grav builds '#^' . ltrim($pattern, '^') . '#' and preg_replace()s the route.
        $rules = FixtureRules::only(['exact_301', 'cs_slash', 'wild_blog', 'regex_number']);
        $data = Yaml::parse((new Exporter())->export($rules, Format::GravSite, new ExportOptions())->content);
        $route = static function (string $path) use ($data): ?string {
            foreach ($data['redirects'] as $pattern => $replace) {
                $regex = '#^' . str_replace('/', '\/', ltrim($pattern, '^')) . '#';
                $found = preg_replace($regex, preg_replace('/\s*\[\d+\]$/', '', $replace) ?? '', $path);
                if ($found !== null && $found !== $path) {
                    return $found;
                }
            }

            return null;
        };

        self::assertSame('/new-page', $route('/old-page'));
        self::assertSame('/new-page', $route('/OLD-PAGE'));
        self::assertNull($route('/old-page/child'), 'exact rules must not match as a prefix');
        self::assertSame('/t', $route('/case-slash'));
        self::assertNull($route('/CASE-SLASH'));
        self::assertSame('/news/2024/x', $route('/blog/2024/x'));
        self::assertSame('/shop/42', $route('/product/42'));
        self::assertNull($route('/product/abc'));
    }
}
