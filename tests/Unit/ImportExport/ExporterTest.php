<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\Adapters;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Exporter::class)]
#[CoversClass(ExportOptions::class)]
#[CoversClass(ExportResult::class)]
#[CoversClass(ExportNote::class)]
#[CoversClass(Adapters::class)]
#[CoversClass(Lossy::class)]
final class ExporterTest extends TestCase
{
    /**
     * @param list<Rule> $rules
     * @return list<string> sources in export order (csv, first column)
     */
    private function sources(array $rules, ?ExportOptions $options = null): array
    {
        $result = (new Exporter())->export($rules, Format::Csv, $options ?? new ExportOptions(includeHeader: false));

        return array_map(static fn (string $line): string => explode(',', $line)[0], array_filter(explode("\n", $result->content)));
    }

    private function rule(string $id, string $source, array $extra = []): Rule
    {
        return Rule::fromArray($extra + ['id' => $id, 'source' => $source, 'target' => '/t', 'created_at' => '2026-01-01T00:00:00+00:00']);
    }

    public function testOnlyEnabledIsTheDefault(): void
    {
        $rules = [$this->rule('a', '/on'), $this->rule('b', '/off', ['enabled' => false])];

        self::assertSame(['/on'], $this->sources($rules));
        self::assertSame(['/on', '/off'], $this->sources($rules, new ExportOptions(onlyEnabled: false, includeHeader: false)));
    }

    public function testGroupFilter(): void
    {
        $rules = [$this->rule('a', '/one', ['group' => 'A']), $this->rule('b', '/two', ['group' => 'B']), $this->rule('c', '/none')];

        self::assertSame(['/one'], $this->sources($rules, new ExportOptions(group: 'A', includeHeader: false)));
        self::assertSame(['/none'], $this->sources($rules, new ExportOptions(group: '', includeHeader: false)), 'an empty group name selects rules without group');
        self::assertSame(['/one', '/two', '/none'], $this->sources($rules, new ExportOptions(group: null, includeHeader: false)));
        self::assertSame([], $this->sources($rules, new ExportOptions(group: 'Z', includeHeader: false)));
    }

    public function testStatusFilter(): void
    {
        $rules = [$this->rule('a', '/p', ['status' => 301]), $this->rule('b', '/t', ['status' => 302]), $this->rule('c', '/g', ['status' => 410, 'target' => ''])];

        self::assertSame(['/t', '/g'], $this->sources($rules, new ExportOptions(statuses: [302, 410], includeHeader: false)));
        self::assertSame([], $this->sources($rules, new ExportOptions(statuses: [], includeHeader: false)));
    }

    public function testRulesAreExportedInEvaluationOrder(): void
    {
        $rules = [
            $this->rule('e', '^/regex$', ['match_type' => 'regex']),
            $this->rule('d', '/wild/*', ['match_type' => 'wildcard']),
            $this->rule('c', '/exact-newer', ['created_at' => '2026-06-01T00:00:00+00:00']),
            $this->rule('b', '/exact-b'),
            $this->rule('a', '/exact-a'),
            $this->rule('z', '/high-priority-regex', ['match_type' => 'regex', 'priority' => 10]),
            $this->rule('y', '/no-date', ['created_at' => null]),
        ];

        self::assertSame(
            ['/high-priority-regex', '/no-date', '/exact-a', '/exact-b', '/exact-newer', '/wild/*', '^/regex$'],
            $this->sources($rules),
        );
    }

    /**
     * @return iterable<string, array{Format, string, string}>
     */
    public static function fileNames(): iterable
    {
        yield 'csv' => [Format::Csv, 'redirects.csv', 'text/csv'];
        yield 'json' => [Format::Json, 'redirects.json', 'application/json'];
        yield 'yaml' => [Format::Yaml, 'redirects.yaml', 'application/yaml'];
        yield 'grav' => [Format::GravSite, 'site-redirects.yaml', 'application/yaml'];
        yield 'htaccess' => [Format::Htaccess, 'redirects.htaccess', 'text/plain'];
        yield 'nginx' => [Format::Nginx, 'redirects.nginx.conf', 'text/plain'];
        yield 'netlify' => [Format::Netlify, '_redirects', 'text/plain'];
        yield 'wordpress json' => [Format::WordpressJson, 'redirects-wordpress.json', 'application/json'];
        yield 'wordpress csv' => [Format::WordpressCsv, 'redirects-wordpress.csv', 'text/csv'];
        yield 'cloudflare' => [Format::CloudflareCsv, 'redirects-cloudflare.csv', 'text/csv'];
    }

    #[DataProvider('fileNames')]
    public function testResultCarriesFileNameAndMimeType(Format $format, string $filename, string $mime): void
    {
        $result = (new Exporter(new SystemClock()))->export([$this->rule('a', '/a')], $format, new ExportOptions(exportHost: 'example.com'));

        self::assertSame($filename, $result->filename);
        self::assertSame($mime, $result->mimeType);
        self::assertNotSame('', $result->content);
        self::assertStringEndsWith("\n", $result->content);
        self::assertSame(1, $result->exported);
    }

    public function testExporterRemovesWhatAFormatCannotHold(): void
    {
        $rules = [$this->rule('a', '/off', ['enabled' => false]), $this->rule('b', '/lang', ['target' => '/{lang}/x'])];
        $options = new ExportOptions(onlyEnabled: false, includeHeader: false);

        $htaccess = (new Exporter())->export($rules, Format::Htaccess, $options);
        self::assertSame(['a:disabled', 'b:lang_placeholder'], array_map(static fn (ExportNote $n): string => $n->ruleId . ':' . $n->code, $htaccess->skipped));
        self::assertSame(0, $htaccess->exported);

        $csv = (new Exporter())->export($rules, Format::Csv, $options);
        self::assertSame([], $csv->skipped, 'csv keeps disabled rules and {lang}');
        self::assertSame(2, $csv->exported);
    }

    public function testCrawlerFormatCannotBeExported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Exporter())->export([], Format::CrawlerCsv);
    }

    public function testDefaultOptions(): void
    {
        $options = new ExportOptions();

        self::assertTrue($options->onlyEnabled);
        self::assertNull($options->group);
        self::assertNull($options->statuses);
        self::assertNull($options->exportHost);
        self::assertTrue($options->includeHeader);
    }

    public function testResultAndNoteObjects(): void
    {
        $result = new ExportResult('body');
        $named = $result->withFile('x.txt', 'text/x');

        self::assertSame(['', 'text/plain', 'body'], [$result->filename, $result->mimeType, $result->content]);
        self::assertSame(['x.txt', 'text/x', 'body'], [$named->filename, $named->mimeType, $named->content]);
        self::assertSame(['rule_id' => 'r', 'code' => 'c', 'reason' => 'why'], (new ExportNote('r', 'c', 'why'))->toArray());
    }

    public function testAdaptersMapEveryFormat(): void
    {
        $clock = new SystemClock();
        foreach (Format::cases() as $format) {
            self::assertSame($format->canImport(), Adapters::importer($format, $clock) !== null, $format->value);
            self::assertSame($format->canExport(), Adapters::exporter($format, $clock) !== null, $format->value);
        }
    }

    public function testLossyNotes(): void
    {
        $rule = $this->rule('a', '/a', [
            'case_sensitive' => true, 'ignore_trailing_slash' => false, 'query_mode' => 'pass', 'query_ignore' => ['x'],
            'continue' => true, 'only_if_not_found' => true, 'target_type' => 'page', 'expires_at' => '2030-01-01T00:00:00+00:00',
            'active_from' => '2029-01-01T00:00:00+00:00', 'conditions' => ['languages' => ['de']],
        ]);
        $all = ['conditions', 'query', 'query_pass', 'query_ignore', 'case', 'trailing_slash', 'active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page'];

        $codes = array_map(static fn (ExportNote $n): string => $n->code, Lossy::notes($rule, $all));
        self::assertSame(['dropped_conditions', 'dropped_query_pass', 'dropped_query_ignore', 'dropped_case', 'dropped_trailing_slash', 'dropped_active_from', 'dropped_expires_at', 'dropped_continue', 'dropped_only_if_not_found', 'dropped_target_page'], $codes);
        self::assertSame([], Lossy::notes($rule, []));
        self::assertSame([], Lossy::notes($rule, ['unknown_feature']));
        self::assertSame(['dropped_query'], array_map(static fn (ExportNote $n): string => $n->code, Lossy::notes($rule->with(['query_mode' => 'exact']), ['query'])));
        self::assertSame([], Lossy::notes($this->rule('b', '/b', ['case_sensitive' => true, 'match_type' => 'regex', 'source' => '^/b$']), ['case']), 'regex rules carry their own flags');
        self::assertTrue(Lossy::usesLangPlaceholder($this->rule('c', '/c', ['target' => '/{lang}/c'])));
        self::assertFalse(Lossy::usesLangPlaceholder($rule));
    }
}
