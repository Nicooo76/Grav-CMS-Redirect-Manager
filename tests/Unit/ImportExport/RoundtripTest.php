<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\JsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\YamlAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Importer;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
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

/**
 * export -> import for every bidirectional format, one rule at a time so failures name the rule and format.
 * Each format compares only the fields it can carry; the rest is asserted through the export's skipped and
 * lossy reports.
 */
#[CoversClass(Exporter::class)]
#[CoversClass(Importer::class)]
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
#[Group('roundtrip')]
final class RoundtripTest extends ImportExportTestCase
{
    /**
     * Per format: fields to compare, rules the format cannot express (skip), and rules whose imported form
     * differs by design (override: field => expected value).
     *
     * @return array<string, array{fields: list<string>, skip: list<string>, override: array<string, array<string, mixed>>, unchecked?: list<string>}>
     */
    private static function profiles(): array
    {
        $core = ['source', 'target', 'matchType', 'status'];
        $ci = ['caseSensitive', 'ignoreTrailingSlash'];

        return [
            'csv' => [
                'fields' => [...$core, 'enabled', 'priority', 'group', 'note', 'tags', ...$ci, 'queryMode', 'queryParams', 'onlyIfNotFound', 'expiresAt'],
                'skip' => [],
                'override' => [],
            ],
            'json' => ['fields' => self::ALL_FIELDS, 'skip' => [], 'override' => []],
            'yaml' => ['fields' => self::ALL_FIELDS, 'skip' => [], 'override' => []],
            'grav_site' => [
                'fields' => [...$core, 'caseSensitive', 'enabled'],
                'skip' => ['disabled', 'exact_308', 'gone_410', 'legal_451', 'gone_cs', 'query_exact', 'query_params', 'host', 'host_wild', 'scheme', 'header_ua', 'header_ref', 'cookie', 'language', 'lang_target'],
                'override' => [
                    'regex_named' => ['target' => '/archive/$1/$2'],
                    'alias_200' => ['caseSensitive' => true],
                ],
            ],
            'htaccess' => [
                'fields' => [...$core, ...$ci, 'caseSensitive', 'queryMode', 'queryParams', 'onlyIfNotFound', 'conditions'],
                'skip' => ['disabled', 'alias_200', 'cookie', 'language', 'lang_target'],
                'override' => ['regex_named' => ['target' => '/archive/$1/$2']],
            ],
            'nginx' => [
                'fields' => [...$core, ...$ci, 'queryMode', 'queryParams', 'conditions'],
                'skip' => ['disabled', 'alias_200', 'query_params', 'soft', 'language', 'lang_target'],
                'override' => [],
            ],
            'netlify' => [
                'fields' => [...$core, 'queryMode', 'queryParams'],
                'skip' => ['disabled', 'gone_410', 'legal_451', 'gone_cs', 'host_wild', 'scheme', 'header_ua', 'header_ref', 'cookie', 'language', 'lang_target', 'regex_number', 'regex_named', 'regex_files'],
                'unchecked' => ['host'],
                'override' => [
                    'query_exact' => ['source' => '/search', 'queryMode' => 'params', 'queryParams' => ['q' => '1']],
                    'query_pass' => ['queryMode' => 'ignore'],
                ],
            ],
            'wordpress_json' => [
                'fields' => [...$core, 'enabled', 'group', 'note', ...$ci, 'queryMode', 'conditions'],
                'skip' => ['legal_451', 'query_params', 'host', 'host_wild', 'scheme', 'language', 'lang_target'],
                'override' => [
                    'wild_blog' => ['source' => '^/blog/(.*)$', 'matchType' => 'regex'],
                    'wild_cs' => ['source' => '^/docs/v1/(.*)$', 'matchType' => 'regex'],
                    'wild_external' => ['source' => '^/media/(.*)$', 'matchType' => 'regex'],
                    'regex_named' => ['target' => '/archive/$1/$2'],
                ],
            ],
            'wordpress_csv' => [
                'fields' => [...$core, 'enabled', 'note'],
                'skip' => ['legal_451', 'query_params', 'host', 'host_wild', 'scheme', 'header_ua', 'header_ref', 'cookie', 'language', 'lang_target'],
                'override' => [
                    'wild_blog' => ['source' => '^/blog/(.*)$', 'matchType' => 'regex'],
                    'wild_cs' => ['source' => '^/docs/v1/(.*)$', 'matchType' => 'regex'],
                    'wild_external' => ['source' => '^/media/(.*)$', 'matchType' => 'regex'],
                    'regex_named' => ['target' => '/archive/$1/$2'],
                ],
            ],
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everyRuleAndFormat(): iterable
    {
        foreach (array_keys(self::profiles()) as $format) {
            foreach (array_keys(FixtureRules::all()) as $name) {
                yield $format . ' / ' . $name => [$format, $name];
            }
        }
    }

    #[DataProvider('everyRuleAndFormat')]
    public function testSingleRuleSurvivesOrIsReported(string $formatName, string $name): void
    {
        $profile = self::profiles()[$formatName];
        $format = Format::from($formatName);
        $rule = FixtureRules::all()[$name];
        if (in_array($name, $profile['unchecked'] ?? [], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $export = (new Exporter())->export([$rule], $format, new ExportOptions(onlyEnabled: false, exportHost: 'example.com'));
        $preview = $this->importer()->preview($export->content, $format, new ImportOptions(baseHosts: ['mysite.test']));
        $imported = $this->rulesOf($preview);

        if (in_array($name, $profile['skip'], true)) {
            self::assertSame(0, $export->exported, 'exported although listed as unsupported');
            self::assertSame([], $imported);
            self::assertContains($rule->id, array_map(static fn ($n): string => $n->ruleId, $export->skipped));

            return;
        }

        self::assertSame([], array_map(static fn ($n): string => $n->ruleId . ':' . $n->code, $export->skipped), 'unexpectedly skipped');
        self::assertCount(1, $imported, $export->content);
        $expected = array_replace(self::view($rule, $profile['fields']), $profile['override'][$name] ?? []);
        self::assertEquals($expected, self::view($imported[0], $profile['fields']), $export->content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bidirectionalFormats(): iterable
    {
        foreach (array_keys(self::profiles()) as $format) {
            yield $format => [$format];
        }
    }

    /** Export everything, import, export again, import again: the second import equals the first. */
    #[DataProvider('bidirectionalFormats')]
    public function testImportExportImportIsStable(string $formatName): void
    {
        $format = Format::from($formatName);
        $exporter = new Exporter();
        $exportOptions = new ExportOptions(onlyEnabled: false, exportHost: 'example.com');
        $importOptions = new ImportOptions(baseHosts: ['mysite.test']);

        $first = $this->importer()->preview($exporter->export(array_values(FixtureRules::all()), $format, $exportOptions)->content, $format, $importOptions);
        $rules1 = $this->rulesOf($first);
        self::assertNotEmpty($rules1);

        $second = $this->importer()->preview($exporter->export($rules1, $format, $exportOptions)->content, $format, $importOptions);
        $rules2 = $this->rulesOf($second);

        self::assertSame([], $exporter->export($rules1, $format, $exportOptions)->skipped, 'imported rules must be exportable again');
        self::assertEquals(self::sorted($rules1), self::sorted($rules2));
    }

    #[DataProvider('bidirectionalFormats')]
    public function testExportReportsEverySkippedRule(string $formatName): void
    {
        $profile = self::profiles()[$formatName];
        $format = Format::from($formatName);
        $all = FixtureRules::all();

        $export = (new Exporter())->export(array_values($all), $format, new ExportOptions(onlyEnabled: false, exportHost: 'example.com'));
        $skippedNames = [];
        foreach ($export->skipped as $note) {
            $skippedNames[] = substr($note->ruleId, 5);
            self::assertNotSame('', $note->code);
            self::assertNotSame('', $note->reason);
        }
        $expected = $profile['skip'];
        sort($expected);
        sort($skippedNames);
        self::assertSame(array_values(array_unique($expected)), array_values(array_unique($skippedNames)));
        self::assertSame(count($all) - count(array_unique($expected)), $export->exported);
    }

    /**
     * @param list<Rule> $rules
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rules): array
    {
        $views = array_map(static fn (Rule $r): array => self::view($r, self::ALL_FIELDS), $rules);
        usort($views, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

        return $views;
    }

    public function testLossyReportsNameWhatIsDropped(): void
    {
        $rules = FixtureRules::only(['host', 'cs_noslash', 'expiry', 'chain', 'soft', 'page_target', 'query_pass']);
        $codes = static function (Format $format) use ($rules): array {
            $result = (new Exporter())->export($rules, $format, new ExportOptions(exportHost: 'example.com'));
            $out = [];
            foreach ($result->lossy as $note) {
                $out[] = substr($note->ruleId, 5) . ':' . $note->code;
            }
            sort($out);

            return $out;
        };

        self::assertSame(
            ['chain:dropped_continue', 'expiry:dropped_active_from', 'host:dropped_conditions', 'page_target:dropped_target_page'],
            $codes(Format::Csv),
        );
        self::assertSame([], $codes(Format::Json));
        self::assertContains('cs_noslash:dropped_case', $codes(Format::Netlify));
        self::assertContains('query_pass:dropped_query_pass', $codes(Format::Netlify));
        self::assertContains('expiry:dropped_expires_at', $codes(Format::Htaccess));

    }
}
