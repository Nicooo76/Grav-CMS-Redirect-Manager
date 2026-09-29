<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\SystemClock;

/**
 * Parses import files into a preview. Nothing is stored and nothing is executed: input is treated
 * as untrusted text.
 */
final class Importer
{
    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Reads a file (size is checked before reading) and previews it. With a null format it is
     * detected from name and content.
     */
    public function previewFile(string $path, ?Format $format, ImportOptions $options): ImportPreview
    {
        if (!is_file($path) || !is_readable($path)) {
            return self::failed($format, new ImportIssue('file_unreadable', 'The file does not exist or cannot be read.'));
        }
        $size = filesize($path);
        if ($size === false) {
            return self::failed($format, new ImportIssue('file_unreadable', 'The file size could not be determined.'));
        }
        if ($size > $options->maxBytes) {
            return self::failed($format, new ImportIssue('file_too_large', 'The file is larger than the allowed import size.', ['size' => $size, 'max' => $options->maxBytes]));
        }
        $raw = file_get_contents($path, false, null, 0, $options->maxBytes + 1);
        if ($raw === false) {
            return self::failed($format, new ImportIssue('file_unreadable', 'The file could not be read.'));
        }

        return $this->run($raw, $format, basename($path), $options);
    }

    public function preview(string $content, Format $format, ImportOptions $options): ImportPreview
    {
        return $this->run($content, $format, '', $options);
    }

    private function run(string $raw, ?Format $format, string $filename, ImportOptions $options): ImportPreview
    {
        if (strlen($raw) > $options->maxBytes) {
            return self::failed($format, new ImportIssue('file_too_large', 'The file is larger than the allowed import size.', ['size' => strlen($raw), 'max' => $options->maxBytes]));
        }

        $normalized = TextNormalizer::normalize($raw);
        if ($normalized['binary']) {
            return self::failed($format, new ImportIssue('binary_content', 'The file is not a text file.'));
        }
        $text = $normalized['text'];
        if (trim($text) === '') {
            return self::failed($format, new ImportIssue('empty_file', 'The file is empty.'));
        }

        $format ??= Format::detect($filename, $text);
        if ($format === null) {
            return self::failed(null, new ImportIssue('unknown_format', 'The file format could not be detected.'));
        }
        $adapter = Adapters::importer($format, $this->clock);
        if ($adapter === null) {
            return self::failed($format, new ImportIssue('format_not_importable', 'This format can only be exported.', ['format' => $format->value]));
        }

        $warnings = [];
        if ($normalized['converted'] !== null) {
            $warnings[] = new ImportIssue('encoding_converted', 'The file was converted to UTF-8.', ['from' => $normalized['converted']]);
        }

        try {
            $rows = $adapter->parse($text, $options);
            $paths = $adapter instanceof NotFoundPathProvider ? $adapter->notFoundPaths($text, $options) : [];
        } catch (ImportException $e) {
            return new ImportPreview([], $format, [$e->issue], $warnings);
        } catch (\Throwable $e) {
            return new ImportPreview([], $format, [new ImportIssue('parse_failed', 'The file could not be parsed.', ['error' => $e::class])], $warnings);
        }

        if (count($rows) > $options->maxRows || count($paths) > $options->maxRows) {
            return new ImportPreview([], $format, [new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows])], $warnings);
        }
        if ($rows === [] && $paths === []) {
            $warnings[] = new ImportIssue('no_rows', 'The file contains no rules.');
        }

        return new ImportPreview(self::markDuplicates($rows, $options->existingRules), $format, [], $warnings, $paths);
    }

    /**
     * @param list<ImportRow> $rows
     * @param list<Rule>      $existing
     * @return list<ImportRow>
     */
    private static function markDuplicates(array $rows, array $existing): array
    {
        $stored = [];
        foreach ($existing as $rule) {
            $stored[self::key($rule)] ??= $rule->id;
        }
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if ($row->rule === null) {
                $out[] = $row;
                continue;
            }
            $key = self::key($row->rule);
            $duplicateOf = $stored[$key] ?? null;
            $inFile = isset($seen[$key]);
            $out[] = $duplicateOf === null && !$inFile ? $row : $row->withDuplicate($duplicateOf, $inFile);
            $seen[$key] = true;
        }

        return $out;
    }

    /**
     * Identity of a rule for duplicate detection: source, match type, query and conditions.
     */
    public static function key(Rule $rule): string
    {
        $source = $rule->source;
        if ($rule->matchType !== MatchType::Regex) {
            if (!$rule->caseSensitive) {
                $source = mb_strtolower($source);
            }
            if ($rule->ignoreTrailingSlash && $source !== '/') {
                $q = strpos($source, '?');
                $path = $q === false ? $source : substr($source, 0, $q);
                $source = rtrim($path, '/') . ($q === false ? '' : substr($source, $q));
                $source = $source === '' ? '/' : $source;
            }
        }
        $params = $rule->queryParams;
        ksort($params);
        $conditions = $rule->conditions->toArray();
        sort($conditions['hosts']);
        sort($conditions['languages']);
        sort($conditions['schemes']);

        return implode('|', [
            $rule->matchType->value,
            $source,
            $rule->queryMode->value === QueryMode::Params->value ? json_encode($params) : '',
            json_encode($conditions),
        ]);
    }

    private static function failed(?Format $format, ImportIssue $issue): ImportPreview
    {
        return new ImportPreview([], $format, [$issue]);
    }
}
