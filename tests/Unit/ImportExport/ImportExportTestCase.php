<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Importer;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportPreview;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\TestCase;

abstract class ImportExportTestCase extends TestCase
{
    /** Every field except the ones that change by design on import (id, origin, timestamps). */
    protected const ALL_FIELDS = [
        'source', 'target', 'matchType', 'status', 'enabled', 'priority', 'group', 'note', 'tags', 'caseSensitive',
        'ignoreTrailingSlash', 'queryMode', 'queryParams', 'queryIgnore', 'onlyIfNotFound', 'continue', 'expiresAt',
        'activeFrom', 'conditions', 'targetType',
    ];

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanup = [];
    }

    protected function importer(): Importer
    {
        return new Importer(new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00')));
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    protected function fixturePath(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    protected function preview(string $content, Format $format, ?ImportOptions $options = null): ImportPreview
    {
        return $this->importer()->preview($content, $format, $options ?? new ImportOptions());
    }

    protected function tempFile(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rm-import-');
        self::assertIsString($path);
        $this->cleanup[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * The rules of all valid rows; fails when the file or a row has errors.
     *
     * @return list<Rule>
     */
    protected function rulesOf(ImportPreview $preview): array
    {
        self::assertSame([], array_map(static fn ($i) => $i->code, $preview->errors), 'file errors');
        foreach ($preview->rows as $row) {
            self::assertSame([], array_map(static fn ($i) => $i->code, $row->errors), 'row ' . $row->line . ': ' . $row->raw);
        }

        return $preview->rules();
    }

    /**
     * Error, then warning codes of a row.
     *
     * @return list<string>
     */
    protected static function codes(ImportRow $row): array
    {
        return array_map(static fn ($i): string => $i->code, [...$row->errors, ...$row->warnings]);
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    protected static function view(Rule $rule, array $fields): array
    {
        $all = [
            'source' => $rule->source,
            'target' => $rule->target,
            'matchType' => $rule->matchType->value,
            'status' => $rule->status->value,
            'enabled' => $rule->enabled,
            'priority' => $rule->priority,
            'group' => $rule->group,
            'note' => $rule->note,
            'tags' => $rule->tags,
            'caseSensitive' => $rule->caseSensitive,
            'ignoreTrailingSlash' => $rule->ignoreTrailingSlash,
            'queryMode' => $rule->queryMode->value,
            'queryParams' => $rule->queryParams,
            'queryIgnore' => $rule->queryIgnore,
            'onlyIfNotFound' => $rule->onlyIfNotFound,
            'continue' => $rule->continueMatching,
            'expiresAt' => $rule->expiresAt?->getTimestamp(),
            'activeFrom' => $rule->activeFrom?->getTimestamp(),
            'conditions' => $rule->conditions->toArray(),
            'targetType' => $rule->targetType->value,
        ];
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $all[$field];
        }

        return $out;
    }

    /**
     * Compact one-line description of a rule for assertions: type:source=>target#status plus non-default flags.
     */
    protected static function sig(Rule $rule): string
    {
        $out = $rule->matchType->value[0] . ':' . $rule->source . '=>' . $rule->target . '#' . $rule->status->value;
        if ($rule->caseSensitive) {
            $out .= ' cs';
        }
        if (!$rule->ignoreTrailingSlash) {
            $out .= ' strict';
        }
        if ($rule->queryMode->value !== 'ignore') {
            $out .= ' q=' . $rule->queryMode->value;
            if ($rule->queryParams !== []) {
                $out .= json_encode($rule->queryParams);
            }
        }
        if ($rule->conditions->hosts !== []) {
            $out .= ' hosts=' . implode('|', $rule->conditions->hosts);
        }
        if ($rule->conditions->schemes !== []) {
            $out .= ' schemes=' . implode('|', $rule->conditions->schemes);
        }
        foreach ($rule->conditions->rules as $c) {
            $out .= ' ' . $c->kind->value . ':' . $c->name . ($c->negate ? '!' : '') . $c->operator->value . '(' . $c->value . ')';
        }
        if ($rule->onlyIfNotFound) {
            $out .= ' notfound';
        }

        return $out;
    }

    /**
     * Signatures of all valid rules of a preview, in file order.
     *
     * @return list<string>
     */
    protected static function sigs(ImportPreview $preview): array
    {
        return array_map(self::sig(...), $preview->rules());
    }

    /**
     * Codes of all errors and warnings per row that has any, keyed by row index.
     *
     * @return array<int, list<string>>
     */
    protected static function issuesByRow(ImportPreview $preview): array
    {
        $out = [];
        foreach ($preview->rows as $i => $row) {
            $codes = self::codes($row);
            if ($codes !== []) {
                $out[$i] = $codes;
            }
        }

        return $out;
    }
}
