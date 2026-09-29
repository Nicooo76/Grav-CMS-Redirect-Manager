<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\CsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(CsvAdapter::class)]
final class CsvAdapterTest extends ImportExportTestCase
{
    public function testGermanHeadersAndSemicolons(): void
    {
        $csv = "Quelle;Ziel;Code;Gruppe;Notiz\n/alt;/neu;302;Relaunch;\"Von Hand, geprüft\"\n/weg;;410;Relaunch;\n";
        $rules = $this->rulesOf($this->preview($csv, Format::Csv));

        self::assertCount(2, $rules);
        self::assertSame(['/alt', '/neu', 302, 'Relaunch', 'Von Hand, geprüft'], [$rules[0]->source, $rules[0]->target, $rules[0]->status->value, $rules[0]->group, $rules[0]->note]);
        self::assertSame(410, $rules[1]->status->value);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function headerSynonyms(): iterable
    {
        yield 'from/to' => ["from,to\n/a,/b\n", ['/a', '/b']];
        yield 'old/new' => ["Old URL,New URL\n/a,/b\n", ['/a', '/b']];
        yield 'url/destination' => ["URL,Destination\n/a,/b\n", ['/a', '/b']];
        yield 'alt/neu' => ["alt;neu\n/a;/b\n", ['/a', '/b']];
        yield 'source_url/target_url' => ["source_url,target_url\n/a,/b\n", ['/a', '/b']];
        yield 'quelle/ziel with spaces' => ["  Quelle , Ziel \n/a,/b\n", ['/a', '/b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('headerSynonyms')]
    public function testHeaderNamesAreRecognised(string $csv, array $expected): void
    {
        $rules = $this->rulesOf($this->preview($csv, Format::Csv));

        self::assertSame($expected, [$rules[0]->source, $rules[0]->target]);
    }

    public function testExplicitMappingByIndexAndByName(): void
    {
        $csv = "id,legacy,destination,http\n1,/a,/b,302\n2,/c,/d,301\n";

        $byName = $this->rulesOf($this->preview($csv, Format::Csv, new ImportOptions(csvMapping: ['source' => 'legacy', 'target' => 'DESTINATION', 'status' => 'http'])));
        self::assertSame(['/a', '/b', 302], [$byName[0]->source, $byName[0]->target, $byName[0]->status->value]);

        $byIndex = $this->rulesOf($this->preview($csv, Format::Csv, new ImportOptions(hasHeader: true, csvMapping: ['source' => 1, 'target' => 2])));
        self::assertSame(['/c', '/d'], [$byIndex[1]->source, $byIndex[1]->target]);

        $byNumericString = $this->rulesOf($this->preview($csv, Format::Csv, new ImportOptions(hasHeader: true, csvMapping: ['source' => '1', 'target' => '2'])));
        self::assertSame('/a', $byNumericString[0]->source);

        $mixed = $this->rulesOf($this->preview($csv, Format::Csv, new ImportOptions(csvMapping: ['source' => 'legacy'])));
        self::assertSame('/b', $mixed[0]->target, 'unmapped fields are still auto-detected');
    }

    public function testMappingToUnknownHeaderIsAFileError(): void
    {
        $preview = $this->preview("a,b\n/x,/y\n", Format::Csv, new ImportOptions(csvMapping: ['source' => 'nope']));

        self::assertSame(['missing_column'], array_map(static fn ($i) => $i->code, $preview->errors));
    }

    public function testHeaderlessFilesUsePositionalColumns(): void
    {
        $csv = "/a,/b,302,wildcard,Gruppe,Notiz\n/c/*,/d/\$1\n";
        $rules = $this->rulesOf($this->preview($csv, Format::Csv));

        self::assertCount(2, $rules);
        self::assertSame(['/a', '/b', 302, 'Gruppe', 'Notiz'], [$rules[0]->source, $rules[0]->target, $rules[0]->status->value, $rules[0]->group, $rules[0]->note]);
        self::assertSame(MatchType::Wildcard, $rules[0]->matchType, 'the explicit wildcard column wins even though the source has no star');
        self::assertSame(MatchType::Wildcard, $rules[1]->matchType);
        self::assertSame('/d/$1', $rules[1]->target);
    }

    public function testHasHeaderOverride(): void
    {
        $csv = "source,target\n/a,/b\n";

        $asData = $this->preview($csv, Format::Csv, new ImportOptions(hasHeader: false));
        self::assertSame('/source', $asData->rules()[0]->source, 'the header row is treated as data');

        $forced = $this->rulesOf($this->preview("old,new\n/a,/b\n", Format::Csv, new ImportOptions(hasHeader: true)));
        self::assertSame('/a', $forced[0]->source);

        $noHeaderNamedFile = $this->rulesOf($this->preview("/x,/y\n", Format::Csv, new ImportOptions(hasHeader: false, csvMapping: ['source' => 0, 'target' => 1])));
        self::assertSame('/y', $noHeaderNamedFile[0]->target);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function delimiterNames(): iterable
    {
        yield 'tab word' => ['tab'];
        yield 'escaped tab' => ['\\t'];
        yield 'literal tab' => ["\t"];
    }

    #[DataProvider('delimiterNames')]
    public function testDelimiterOption(string $delimiter): void
    {
        $rules = $this->rulesOf($this->preview("source\ttarget\n/a\t/b,c\n", Format::Csv, new ImportOptions(delimiter: $delimiter)));

        self::assertSame('/b,c', $rules[0]->target);
        self::assertSame('/x', $this->rulesOf($this->preview("source|target\n/a|/x\n", Format::Csv, new ImportOptions(delimiter: 'pipe')))[0]->target);
        self::assertSame('/b', $this->rulesOf($this->preview("source;target\n/a;/b\n", Format::Csv, new ImportOptions(delimiter: 'semicolon')))[0]->target);
        self::assertSame('/b', $this->rulesOf($this->preview("source,target\n/a,/b\n", Format::Csv, new ImportOptions(delimiter: 'comma')))[0]->target);
        self::assertSame('/b', $this->rulesOf($this->preview("source,target\n/a,/b\n", Format::Csv, new ImportOptions(delimiter: '')))[0]->target);
        self::assertSame('/b', $this->rulesOf($this->preview("source:target\n/a:/b\n", Format::Csv, new ImportOptions(delimiter: ':')))[0]->target);
    }

    public function testExtraColumnsAndMultilineFieldsAndEmptyLines(): void
    {
        $csv = "source,target,who cares,note,another\n\n/a,/b,x,\"first\nsecond\",y\n\n\n/c,/d,x,,y\n";
        $preview = $this->preview($csv, Format::Csv);
        $rules = $this->rulesOf($preview);

        self::assertCount(2, $rules);
        self::assertSame("first\nsecond", $rules[0]->note);
        self::assertSame([3, 7], array_map(static fn ($r) => $r->line, $preview->rows));
    }

    public function testShortRowsAreRowErrors(): void
    {
        $preview = $this->preview("target,source\n/b\n/x,/y\n", Format::Csv);

        self::assertSame(['missing_column'], array_map(static fn ($i) => $i->code, $preview->rows[0]->errors));
        self::assertSame(1, $preview->rows[0]->errors[0]->params['column'] - 1);
        self::assertSame('/y', $preview->rows[1]->rule?->source);
    }

    public function testMatchTypeRegexColumnAndValues(): void
    {
        $csv = "source,target,regex,match\n^/a/(\\d+)\$,/b/\$1,yes,\n/c,/d,no,\n/e/*,/f/\$1,,\n/g,/h,1,exact\n";
        $rules = $this->rulesOf($this->preview($csv, Format::Csv));

        self::assertSame([MatchType::Regex, MatchType::Exact, MatchType::Wildcard, MatchType::Exact], array_map(static fn ($r) => $r->matchType, $rules));
    }

    public function testInvalidValuesBecomeRowErrors(): void
    {
        $preview = $this->preview("source,target,status,match_type,query_mode\n/a,/b,banana,,\n/c,/d,,fuzzy,\n/e,/f,,,weird\n/g,/h,,,\n", Format::Csv);

        self::assertSame(['invalid_status'], self::codes($preview->rows[0]));
        self::assertSame(['invalid_match_type'], self::codes($preview->rows[1]));
        self::assertSame(['invalid_query_mode'], self::codes($preview->rows[2]));
        self::assertSame([], self::codes($preview->rows[3]));
    }

    public function testFormulaInjectionInAndOut(): void
    {
        $rule = FixtureRules::all()['grouped']->with([
            'note' => '=HYPERLINK("http://evil.test","click")',
            'group' => '@SUM(1)',
            'tags' => ['+cmd', '-x'],
        ]);
        $out = (new Exporter())->export([$rule], Format::Csv, new ExportOptions());

        self::assertStringContainsString("'=HYPERLINK", $out->content);
        self::assertStringContainsString("'@SUM(1)", $out->content);
        foreach (explode("\n", trim($out->content)) as $line) {
            self::assertDoesNotMatchRegularExpression('/(^|,)"?[=+\-@]/', $line, 'no cell may start with a formula character');
        }

        $back = $this->rulesOf($this->preview($out->content, Format::Csv))[0];
        self::assertSame('=HYPERLINK("http://evil.test","click")', $back->note);
        self::assertSame('@SUM(1)', $back->group);
        self::assertSame(['+cmd', '-x'], $back->tags);
    }

    public function testExportWithoutHeaderAndWithParamsInSource(): void
    {
        $rules = FixtureRules::only(['query_params', 'exact_301']);
        $out = (new Exporter())->export($rules, Format::Csv, new ExportOptions(includeHeader: false));
        $lines = explode("\n", trim($out->content));

        self::assertCount(2, $lines);
        self::assertStringStartsWith('/old-page,/new-page,301,exact,true,0,,,ignore,', $lines[0]);
        self::assertStringStartsWith('/store?id&cat=x,/shop,301,exact,', $lines[1]);

        $back = $this->preview($out->content, Format::Csv, new ImportOptions(hasHeader: false, csvMapping: [
            'source' => 0, 'target' => 1, 'status' => 2, 'match_type' => 3, 'enabled' => 4, 'priority' => 5, 'group' => 6, 'note' => 7, 'query_mode' => 8,
        ]));
        $rules = $this->rulesOf($back);
        self::assertSame(QueryMode::Params, $rules[1]->queryMode);
        self::assertSame(['id' => null, 'cat' => 'x'], $rules[1]->queryParams);
    }

    public function testExportedHeaderMatchesTheSpecification(): void
    {
        self::assertSame(
            'source,target,status,match_type,enabled,priority,group,note,query_mode,case_sensitive,ignore_trailing_slash,only_if_not_found,expires_at,tags',
            implode(',', CsvAdapter::HEADER),
        );
    }
}
