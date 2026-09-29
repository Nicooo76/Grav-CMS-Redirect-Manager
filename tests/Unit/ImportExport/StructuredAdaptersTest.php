<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\JsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\YamlAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Support\StructuredRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(JsonAdapter::class)]
#[CoversClass(YamlAdapter::class)]
#[CoversClass(StructuredRules::class)]
final class StructuredAdaptersTest extends ImportExportTestCase
{
    /**
     * @return iterable<string, array{Format}>
     */
    public static function formats(): iterable
    {
        yield 'json' => [Format::Json];
        yield 'yaml' => [Format::Yaml];
    }

    #[DataProvider('formats')]
    public function testExportsTheDocumentedStructure(Format $format): void
    {
        $out = (new Exporter())->export(FixtureRules::only(['exact_301', 'wild_blog']), $format, new ExportOptions())->content;
        $data = $format === Format::Json ? json_decode($out, true) : \Symfony\Component\Yaml\Yaml::parse($out);

        self::assertIsArray($data);
        self::assertSame(1, $data['version']);
        self::assertCount(2, $data['rules']);
        self::assertSame(FixtureRules::all()['exact_301']->toArray()['source'], $data['rules'][0]['source']);
        self::assertSame(array_keys(FixtureRules::all()['exact_301']->toArray()), array_keys($data['rules'][0]), 'same keys as rules.yaml');
    }

    /**
     * @return iterable<string, array{Format, string}>
     */
    public static function bareLists(): iterable
    {
        yield 'json' => [Format::Json, '[{"source":"/a","target":"/b"},{"source":"/c/*","target":"/d/$1","status":302}]'];
        yield 'yaml' => [Format::Yaml, "- source: /a\n  target: /b\n- {source: '/c/*', target: '/d/\$1', status: 302}\n"];
    }

    #[DataProvider('bareLists')]
    public function testBareListOfRules(Format $format, string $content): void
    {
        $rules = $this->rulesOf($this->preview($content, $format));

        self::assertCount(2, $rules);
        self::assertSame('/b', $rules[0]->target);
        self::assertSame('wildcard', $rules[1]->matchType->value);
        self::assertSame(302, $rules[1]->status->value);
    }

    #[DataProvider('formats')]
    public function testEntriesThatAreNotRulesBecomeRowErrors(Format $format): void
    {
        $content = $format === Format::Json
            ? '{"rules":[{"source":"/a","target":"/b"}, "text", [1,2], {"target":"/x"}, {"source":"/z","target":"/y","match_type":"nope"}]}'
            : "rules:\n  - {source: /a, target: /b}\n  - text\n  - [1, 2]\n  - {target: /x}\n  - {source: /z, target: /y, match_type: nope}\n";
        $preview = $this->preview($content, $format);

        self::assertSame([null, ['invalid_row'], ['invalid_row'], ['missing_source'], ['invalid_match_type']], array_map(
            static fn ($row): ?array => $row->rule !== null ? null : array_map(static fn ($i) => $i->code, $row->errors),
            $preview->rows,
        ));
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn ($r) => $r->line, $preview->rows));
    }

    public function testEmptyRuleListIsFine(): void
    {
        $preview = $this->preview('{"version":1,"rules":[]}', Format::Json);

        self::assertSame([], $preview->errors);
        self::assertSame('no_rows', $preview->warnings[0]->code);
    }

    public function testSuppliedIdsAndOriginAreReplaced(): void
    {
        $rule = Rule::fromArray(['id' => 'r-keep', 'source' => '/a', 'target' => '/b', 'origin' => 'manual', 'created_at' => '2020-01-01T00:00:00+00:00']);
        $json = (new Exporter())->export([$rule], Format::Json, new ExportOptions())->content;
        $imported = $this->rulesOf($this->preview($json, Format::Json))[0];

        self::assertNotSame('r-keep', $imported->id, 'ids must not collide with stored rules');
        self::assertSame('import', $imported->origin->value);
        self::assertSame('2020-01-01T00:00:00+00:00', $imported->createdAt?->format('c'), 'creation date of an exported rule is kept');
    }

    public function testYamlDatesComeBackAsTheSameInstant(): void
    {
        $rule = Rule::fromArray(['source' => '/a', 'target' => '/b', 'expires_at' => '2030-06-01T12:00:00+02:00']);
        $yaml = (new Exporter())->export([$rule], Format::Yaml, new ExportOptions())->content;
        $back = $this->rulesOf($this->preview($yaml, Format::Yaml))[0];

        self::assertSame($rule->expiresAt?->getTimestamp(), $back->expiresAt?->getTimestamp());
    }
}
