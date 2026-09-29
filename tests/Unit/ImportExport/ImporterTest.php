<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\JsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\YamlAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Importer;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportPreview;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\Support\Args;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use Grav\Plugin\RedirectManager\ImportExport\Support\HeaderCond;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxConfig;
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxFrame;
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxNode;
use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use Grav\Plugin\RedirectManager\ImportExport\Support\PatternFields;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\SafeYaml;
use Grav\Plugin\RedirectManager\ImportExport\Support\StatusParser;
use Grav\Plugin\RedirectManager\ImportExport\Support\StructuredRules;
use Grav\Plugin\RedirectManager\ImportExport\TextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(Importer::class)]
#[CoversClass(ImportPreview::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(ImportIssue::class)]
#[CoversClass(SafeYaml::class)]
#[CoversClass(HeaderCond::class)]
#[CoversClass(PatternFields::class)]
#[CoversClass(NginxNode::class)]
#[CoversClass(NginxFrame::class)]
#[CoversClass(NginxConfig::class)]
#[CoversClass(ImportException::class)]
#[CoversClass(ImportOptions::class)]
#[CoversClass(TextNormalizer::class)]
#[CoversClass(JsonAdapter::class)]
#[CoversClass(YamlAdapter::class)]
#[CoversClass(StructuredRules::class)]
#[CoversClass(Csv::class)]
#[CoversClass(RowFactory::class)]
#[CoversClass(Pattern::class)]
#[CoversClass(Args::class)]
#[CoversClass(StatusParser::class)]
#[CoversClass(Lossy::class)]
#[Group('import')]
final class ImporterTest extends ImportExportTestCase
{
    /**
     * @return list<string>
     */
    private static function fileCodes(ImportPreview $p): array
    {
        return array_map(static fn (ImportIssue $i): string => $i->code, $p->errors);
    }

    // ---------------------------------------------------------------- file level

    public function testHugeFileFailsFastWithoutReading(): void
    {
        $path = $this->tempFile();
        $handle = fopen($path, 'w');
        self::assertNotFalse($handle);
        fseek($handle, 100 * 1024 * 1024 - 1);
        fwrite($handle, "\0");
        fclose($handle);
        clearstatcache();
        self::assertSame(100 * 1024 * 1024, filesize($path));

        $before = memory_get_peak_usage(true);
        $start = microtime(true);
        $preview = $this->importer()->previewFile($path, Format::Csv, new ImportOptions());

        self::assertSame(['file_too_large'], self::fileCodes($preview));
        self::assertSame([], $preview->rows);
        self::assertSame(100 * 1024 * 1024, $preview->errors[0]->params['size']);
        self::assertSame(ImportOptions::DEFAULT_MAX_BYTES, $preview->errors[0]->params['max']);
        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'the file must not be read into memory');
    }

    public function testMaxBytesIsConfigurableForStrings(): void
    {
        $preview = $this->preview("source,target\n/a,/b\n", Format::Csv, new ImportOptions(maxBytes: 10));

        self::assertSame(['file_too_large'], self::fileCodes($preview));
    }

    public function testFileSizeLimitAppliesToReadBytesToo(): void
    {
        $path = $this->tempFile("source,target\n/a,/b\n");
        $preview = $this->importer()->previewFile($path, Format::Csv, new ImportOptions(maxBytes: 16));

        self::assertSame(['file_too_large'], self::fileCodes($preview));
    }

    public function testMissingFileAndDirectory(): void
    {
        $missing = $this->importer()->previewFile('/nonexistent/redirects.csv', Format::Csv, new ImportOptions());
        self::assertSame(['file_unreadable'], self::fileCodes($missing));

        $dir = $this->importer()->previewFile(sys_get_temp_dir(), Format::Csv, new ImportOptions());
        self::assertSame(['file_unreadable'], self::fileCodes($dir));
    }

    public function testEmptyFileIsRejected(): void
    {
        foreach (['', "  \n\t\n"] as $content) {
            $preview = $this->preview($content, Format::Csv);
            self::assertSame(['empty_file'], self::fileCodes($preview));
        }
    }

    public function testFileWithOnlyHeaderHasNoRulesButNoError(): void
    {
        $preview = $this->preview("source,target,status\n", Format::Csv);

        self::assertSame([], $preview->errors);
        self::assertSame([], $preview->rows);
        self::assertSame(['no_rows'], array_map(static fn (ImportIssue $i): string => $i->code, $preview->warnings));
        self::assertSame(0, $preview->counts()['total']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function binaryGarbage(): iterable
    {
        yield 'nul bytes' => ["\x00\x01\x02binary\x00\x00garbage"];
        yield 'png' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"];
        yield 'gzip' => ["\x1f\x8b\x08\x00\x00\x00\x00\x00"];
        yield 'zip' => ["PK\x03\x04\x14\x00\x00\x00"];
        yield 'pdf' => ["%PDF-1.7\n1 0 obj"];
        yield 'random high bytes' => [implode('', array_map(static fn (int $i): string => chr(0x80 + ($i * 37) % 0x7F), range(1, 300)))];
        yield 'control characters' => [str_repeat("abc\x01\x02\x03\x04", 40)];
    }

    #[DataProvider('binaryGarbage')]
    public function testBinaryContentIsRejected(string $content): void
    {
        foreach ([Format::Csv, Format::Json, Format::Yaml, Format::Htaccess] as $format) {
            $preview = $this->preview($content, $format);
            self::assertSame(['binary_content'], self::fileCodes($preview), $format->value);
            self::assertSame([], $preview->rows);
        }
    }

    public function testUnknownFormatAndNonImportableFormat(): void
    {
        $path = $this->tempFile("just some words\nnothing to see\n");
        $unknown = $this->importer()->previewFile($path, null, new ImportOptions());
        self::assertSame(['unknown_format'], self::fileCodes($unknown));
        self::assertNull($unknown->format);

        $cloudflare = $this->preview("source_url,target_url\nexample.com/a,https://example.com/b\n", Format::CloudflareCsv);
        self::assertSame(['format_not_importable'], self::fileCodes($cloudflare));
    }

    /**
     * @return iterable<string, array{string, Format}>
     */
    public static function detectableFixtures(): iterable
    {
        yield 'htaccess' => ['sample.htaccess', Format::Htaccess];
        yield 'nginx' => ['sample-nginx.conf', Format::Nginx];
        yield 'grav site' => ['site.yaml', Format::GravSite];
        yield 'wordpress json' => ['wordpress-redirection.json', Format::WordpressJson];
        yield 'wordpress csv' => ['wordpress-redirection.csv', Format::WordpressCsv];
        yield 'screaming frog' => ['screamingfrog-client-error.csv', Format::CrawlerCsv];
        yield 'sitebulb' => ['sitebulb-404.csv', Format::CrawlerCsv];
        yield 'netlify' => ['netlify-redirects.txt', Format::Netlify];
    }

    #[DataProvider('detectableFixtures')]
    public function testFormatIsDetectedFromTheFile(string $fixture, Format $expected): void
    {
        $preview = $this->importer()->previewFile($this->fixturePath($fixture), null, new ImportOptions());

        self::assertSame($expected, $preview->format);
        self::assertSame([], $preview->errors);
    }

    // ---------------------------------------------------------------- encodings

    public function testUtf8BomIsStripped(): void
    {
        $preview = $this->preview("\xEF\xBB\xBFsource,target\n/alt,/neu\n", Format::Csv);

        $rules = $this->rulesOf($preview);
        self::assertCount(1, $rules);
        self::assertSame('/alt', $rules[0]->source);
        self::assertSame([], $preview->warnings, 'a BOM is not worth a warning');
    }

    public function testUtf16WithBomIsConverted(): void
    {
        $text = "source;target\n/über-uns;/über\n";
        foreach (['UTF-16LE' => "\xFF\xFE", 'UTF-16BE' => "\xFE\xFF"] as $encoding => $bom) {
            $preview = $this->preview($bom . mb_convert_encoding($text, $encoding, 'UTF-8'), Format::Csv);

            $rules = $this->rulesOf($preview);
            self::assertSame('/über-uns', $rules[0]->source, $encoding);
            self::assertSame('/über', $rules[0]->target);
            self::assertSame(['encoding_converted'], array_map(static fn (ImportIssue $i): string => $i->code, $preview->warnings));
            self::assertSame($encoding, $preview->warnings[0]->params['from']);
        }
    }

    public function testWindows1252IsConvertedToUtf8(): void
    {
        $csv = mb_convert_encoding("source;target;note\n/über-uns;/ärzte;Größe – „Test“ €\n", 'Windows-1252', 'UTF-8');
        self::assertFalse(mb_check_encoding($csv, 'UTF-8'));

        $preview = $this->preview($csv, Format::Csv);

        $rules = $this->rulesOf($preview);
        self::assertSame('/über-uns', $rules[0]->source);
        self::assertSame('/ärzte', $rules[0]->target);
        self::assertSame('Größe – „Test“ €', $rules[0]->note);
        self::assertSame('Windows-1252', $preview->warnings[0]->params['from']);
    }

    public function testLineEndings(): void
    {
        $lf = "source,target,note\n/a,/b,\"two\nlines\"\n/c,/d,x\n";
        $expected = $this->rulesOf($this->preview($lf, Format::Csv));

        foreach (["\r\n", "\r"] as $eol) {
            $rules = $this->rulesOf($this->preview(str_replace("\n", $eol, $lf), Format::Csv));
            self::assertCount(2, $rules, bin2hex($eol));
            self::assertSame($expected[0]->note, $rules[0]->note);
            self::assertSame("two\nlines", $rules[0]->note);
            self::assertSame('/c', $rules[1]->source);
        }
    }

    // ---------------------------------------------------------------- hostile input

    public function testYamlObjectTagsAreRejectedAndNothingRuns(): void
    {
        SentinelObject::$woke = false;
        $payload = serialize(new SentinelObject());
        $yamls = [
            'object' => "rules:\n  - source: /a\n    target: !php/object '" . str_replace("'", "''", $payload) . "'\n",
            'object map' => "rules:\n  - !php/object:" . SentinelObject::class . " {}\n",
            'constant' => "rules:\n  - source: !php/const PHP_VERSION\n    target: /b\n",
            'custom tag' => "rules:\n  - source: !evil /a\n    target: /b\n",
        ];

        foreach ($yamls as $name => $yaml) {
            $preview = $this->preview($yaml, Format::Yaml);
            self::assertSame(['invalid_yaml'], self::fileCodes($preview), $name);
            self::assertSame([], $preview->rows, $name);
        }
        self::assertFalse(SentinelObject::$woke, 'no object may be instantiated or unserialized');
        self::assertSame([], array_filter(get_declared_classes(), static fn (string $c): bool => $c === 'PWNED'));
    }

    public function testYamlSyntaxErrorIsAFileError(): void
    {
        $preview = $this->preview("rules:\n  - source: [unclosed\n", Format::Yaml);

        self::assertSame(['invalid_yaml'], self::fileCodes($preview));
    }

    public function testDeeplyNestedInputsAreRejectedQuickly(): void
    {
        $bombs = [
            [Format::Json, str_repeat('[', 100000) . str_repeat(']', 100000)],
            [Format::Json, '{"rules":' . str_repeat('{"a":', 5000) . '1' . str_repeat('}', 5000) . '}'],
            [Format::Yaml, str_repeat('[', 100000)],
            [Format::Yaml, str_repeat('- ', 20000) . 'x'],
            [Format::Yaml, implode("\n", array_map(static fn (int $n): string => str_repeat(' ', $n) . 'a:', range(0, 3000)))],
            [Format::GravSite, str_repeat('{a: ', 5000) . '1' . str_repeat('}', 5000)],
            [Format::WordpressJson, '{"redirects":' . str_repeat('[', 5000) . str_repeat(']', 5000) . '}'],
            [Format::Nginx, str_repeat('location / { ', 500) . str_repeat('}', 500)],
        ];
        foreach ($bombs as $i => [$format, $content]) {
            $start = microtime(true);
            $peak = memory_get_peak_usage();
            $preview = $this->preview($content, $format);

            self::assertSame(['too_deep'], self::fileCodes($preview), $i . ' ' . $format->value);
            self::assertLessThan(2.0, microtime(true) - $start);
            self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $peak);
        }
    }

    public function testYamlAliasBombDoesNotExplode(): void
    {
        $yaml = "a: &a [x,x,x,x,x,x,x,x,x]\n";
        $prev = 'a';
        foreach (range('b', 'i') as $name) {
            $yaml .= $name . ': &' . $name . ' [' . implode(',', array_fill(0, 9, '*' . $prev)) . "]\n";
            $prev = $name;
        }
        $start = microtime(true);
        $preview = $this->preview($yaml, Format::Yaml);

        self::assertSame(['invalid_structure'], self::fileCodes($preview));
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testInvalidJson(): void
    {
        self::assertSame(['invalid_json'], self::fileCodes($this->preview('{"rules": [', Format::Json)));
        self::assertSame(['invalid_structure'], self::fileCodes($this->preview('"just a string"', Format::Json)));
        self::assertSame(['invalid_structure'], self::fileCodes($this->preview('{"nothing": true}', Format::Json)));
        self::assertSame(['unsupported_version'], self::fileCodes($this->preview('{"version": 2, "rules": []}', Format::Json)));
    }

    // ---------------------------------------------------------------- limits

    public function testMaxRowsExceededRejectsTheFile(): void
    {
        $options = new ImportOptions(maxRows: 3);
        $csv = "source,target\n/a,/1\n/b,/2\n/c,/3\n/d,/4\n/e,/5\n";
        $preview = $this->preview($csv, Format::Csv, $options);
        self::assertSame(['too_many_rows'], self::fileCodes($preview));
        self::assertSame([], $preview->rows);
        self::assertSame(3, $preview->errors[0]->params['max']);

        $exact = $this->preview("source,target\n/a,/1\n/b,/2\n/c,/3\n", Format::Csv, $options);
        self::assertSame([], $exact->errors);
        self::assertCount(3, $exact->rows);

        $lines = static fn (string $prefix, string $suffix): string => implode("\n", array_map(static fn (int $i): string => $prefix . $i . $suffix, range(1, 10)));
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($lines('/a', ' /b 301'), Format::Netlify, $options)));
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($lines('Redirect 301 /a', ' /b'), Format::Htaccess, $options)));
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($lines('rewrite ^/a', ' /b permanent;'), Format::Nginx, $options)));
        $json = json_encode(['rules' => array_map(static fn (int $i): array => ['source' => '/a' . $i, 'target' => '/b'], range(1, 10))], JSON_THROW_ON_ERROR);
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($json, Format::Json, $options)));
        $yaml = "rules:\n" . $lines('  - {source: /a', ', target: /b}');
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($yaml, Format::Yaml, $options)));
        $grav = "redirects:\n" . $lines("    '/a", "': '/b'");
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($grav, Format::GravSite, $options)));
        $wp = json_encode(['redirects' => array_map(static fn (int $i): array => ['url' => '/a' . $i, 'action_data' => ['url' => '/b']], range(1, 10))], JSON_THROW_ON_ERROR);
        self::assertSame(['too_many_rows'], self::fileCodes($this->preview($wp, Format::WordpressJson, $options)));
    }

    public function testMissingColumnsAreRowErrors(): void
    {
        $preview = $this->preview("source,target,status\n/a\n/b,/c\n,/d\n/e,,410\n/f,\n", Format::Csv);

        self::assertSame([], $preview->errors);
        $codes = array_map(self::codes(...), $preview->rows);
        self::assertSame([], $codes[1] ?? [], '/b,/c is fine (status defaults)');
        self::assertContains('missing_target', $codes[0]);
        self::assertContains('missing_source', $codes[2]);
        self::assertSame([], $codes[3]);
        self::assertContains('missing_target', $codes[4]);
        self::assertSame(2, $preview->counts()['valid']);
        self::assertSame(3, $preview->counts()['errors']);
    }

    public function testHeaderWithoutSourceColumnIsAFileError(): void
    {
        $preview = $this->preview("target,status\n/a,301\n", Format::Csv);

        self::assertSame(['missing_column'], self::fileCodes($preview));
    }

    // ---------------------------------------------------------------- duplicates

    public function testDuplicatesInFileAndAgainstStoredRules(): void
    {
        $existing = [
            Rule::fromArray(['id' => 'r-stored', 'source' => '/Alt', 'target' => '/x']),
            Rule::fromArray(['id' => 'r-regex', 'source' => '^/p/(\d+)$', 'target' => '/y', 'match_type' => 'regex']),
            Rule::fromArray(['id' => 'r-host', 'source' => '/hosted', 'target' => '/y', 'conditions' => ['hosts' => ['a.com']]]),
        ];
        $csv = implode("\n", [
            'source,target,match_type',
            '/alt,/n1,exact',       // same as stored (case-insensitive)
            '/alt/,/n2,exact',      // trailing slash ignored: duplicate of stored and of row 1
            '/neu,/n3,exact',
            '/neu,/n4,exact',       // duplicate in file
            '/alt,/n5,wildcard',    // different match type: no duplicate
            '^/p/(\d+)$,/n6,regex',
            '/hosted,/n7,exact',    // stored rule has a host condition: no duplicate
        ]);
        $preview = $this->preview($csv, Format::Csv, new ImportOptions(existingRules: $existing));

        $dupOf = array_map(static fn (ImportRow $r): ?string => $r->duplicateOf, $preview->rows);
        $inFile = array_map(static fn (ImportRow $r): bool => $r->duplicateInFile, $preview->rows);
        self::assertSame(['r-stored', 'r-stored', null, null, null, 'r-regex', null], $dupOf);
        self::assertSame([false, true, false, true, false, false, false], $inFile);
        self::assertSame(4, $preview->counts()['duplicates']);
        self::assertCount(7, $preview->rules());
        self::assertCount(3, $preview->rules(skipDuplicates: true));
    }

    public function testQueryParamsAndSensitiveCaseAreDistinctKeys(): void
    {
        $a = Rule::fromArray(['id' => 'a', 'source' => '/Case', 'case_sensitive' => true]);
        $b = Rule::fromArray(['id' => 'b', 'source' => '/case', 'case_sensitive' => true]);
        $c = Rule::fromArray(['id' => 'c', 'source' => '/s', 'query_mode' => 'params', 'query_params' => ['x' => '1']]);
        $d = Rule::fromArray(['id' => 'd', 'source' => '/s', 'query_mode' => 'params', 'query_params' => ['x' => '2']]);

        self::assertNotSame(Importer::key($a), Importer::key($b));
        self::assertNotSame(Importer::key($c), Importer::key($d));
        self::assertSame(Importer::key($c), Importer::key($c->with(['id' => 'e'])));
        self::assertSame(Importer::key(Rule::fromArray(['source' => '/'])), Importer::key(Rule::fromArray(['source' => '/'])));
        self::assertSame(
            Importer::key(Rule::fromArray(['source' => '/a/?x=1'])),
            Importer::key(Rule::fromArray(['source' => '/a?x=1'])),
        );
    }

    // ---------------------------------------------------------------- preview object

    public function testRulesGetOptionsOriginGroupAndFileOrder(): void
    {
        $csv = "source,target\n/c,/1\n/a,/2\n/b,/3\n";
        $preview = $this->preview($csv, Format::Csv, new ImportOptions(defaultGroup: 'Import 2026', defaultStatus: 302, origin: RuleSource::Suggestion));

        $rules = $this->rulesOf($preview);
        self::assertSame(['/c', '/a', '/b'], array_map(static fn (Rule $r): string => $r->source, $rules));
        foreach ($rules as $rule) {
            self::assertSame('Import 2026', $rule->group);
            self::assertSame(302, $rule->status->value);
            self::assertSame(RuleSource::Suggestion, $rule->origin);
            self::assertSame('2026-09-29T10:00:00+00:00', $rule->createdAt?->format(Rule::DATE_FORMAT));
            self::assertMatchesRegularExpression('/^r[0-9a-f]{17}$/', $rule->id);
        }
        $ids = array_map(static fn (Rule $r): string => $r->id, $rules);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($ids, $sorted, 'ids sort in file order so equal-priority rules keep it');
        self::assertCount(3, array_unique($ids));
    }

    public function testLargeImportsStayFast(): void
    {
        $csv = "source,target,status\n";
        for ($i = 0; $i < 20000; ++$i) {
            $csv .= "/old/page-{$i},/new/page-{$i},301\n";
        }
        $start = microtime(true);
        $before = memory_get_usage();
        $preview = $this->preview($csv, Format::Csv);

        self::assertSame(20000, $preview->counts()['valid']);
        self::assertSame(0, $preview->counts()['duplicates']);
        self::assertLessThan(5.0, microtime(true) - $start);
        self::assertLessThan(64 * 1024 * 1024, memory_get_usage() - $before, 'about 1 KB per rule is retained');
    }

    public function testRowsKeepLineNumbersAndTruncateRaw(): void
    {
        $long = '/' . str_repeat('ä', 400);
        $preview = $this->preview("source,target\n\n/a,/b\n{$long},/c\n", Format::Csv);

        self::assertSame(3, $preview->rows[0]->line);
        self::assertSame(4, $preview->rows[1]->line);
        self::assertLessThanOrEqual(ImportRow::RAW_LIMIT, strlen($preview->rows[1]->raw));
        self::assertTrue(mb_check_encoding($preview->rows[1]->raw, 'UTF-8'));
    }

    public function testPreviewSerializesForTheApi(): void
    {
        $preview = $this->preview("source,target\n/a,/b\n/a,/b\n,/x\n", Format::Csv);
        $data = $preview->toArray();

        self::assertSame('csv', $data['format']);
        self::assertSame(['total' => 3, 'valid' => 2, 'errors' => 1, 'duplicates' => 1, 'warnings' => 0, 'skipped' => 0, 'not_found' => 0], $data['counts']);
        self::assertCount(3, $data['rows']);
        self::assertSame('/a', $data['rows'][0]['rule']['source']);
        self::assertTrue($data['rows'][1]['duplicate_in_file']);
        self::assertSame('missing_source', $data['rows'][2]['errors'][0]['code']);
        self::assertNull($data['rows'][2]['rule']);
        self::assertSame([], $data['not_found_paths']);
        self::assertJson((string) json_encode($data));
        self::assertTrue($preview->rows[0]->isValid());
        self::assertFalse($preview->rows[2]->isValid());
        self::assertFalse($preview->hasFileErrors());
        self::assertSame(['code' => 'x', 'message' => 'm', 'params' => ['a' => 1]], (new ImportIssue('x', 'm', ['a' => 1]))->toArray());
    }
}
