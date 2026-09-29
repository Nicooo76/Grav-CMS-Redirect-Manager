<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\Analysis\Severity;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Analysis\ValidationResult;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\Importer;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportPreview;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\TextNormalizer;
use Grav\Plugin\RedirectManager\Suggest\SitemapDiff;
use Grav\Plugin\RedirectManager\Suggest\SitemapException;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Import preview and commit, sitemap comparison, export and Grav's own site.yaml redirects, for the REST
 * controllers and the CLI.
 *
 * Every valid row of an import is validated like a hand-made rule (RuleService::checkBatch): rows with errors turn
 * into error rows, validation warnings are added to the row. A commit either stores everything valid or, when
 * invalid rows are not to be skipped, nothing at all. site.yaml is never read or written; its content comes from
 * SiteContext.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class ImportExportService
{
    public const MAX_RETURNED_RULES = 500;
    public const MAX_ROW_ISSUES = 50;

    private const LABELS = [
        'csv' => 'CSV',
        'json' => 'JSON',
        'yaml' => 'YAML',
        'grav_site' => 'Grav site.yaml',
        'htaccess' => 'Apache .htaccess',
        'nginx' => 'Nginx',
        'wordpress_json' => 'WordPress Redirection (JSON)',
        'wordpress_csv' => 'WordPress Redirection (CSV)',
        'crawler_csv' => 'Crawler export (broken URLs)',
        'cloudflare_csv' => 'Cloudflare bulk redirects (CSV)',
        'netlify' => 'Netlify _redirects',
    ];

    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site,
        private readonly RuleService $rules,
        private readonly SuggestionService $suggestions,
    ) {
    }

    // ---------------------------------------------------------------- formats

    /**
     * @return list<array{id: string, label: string, import: bool, export: bool, extension: string, mime: string}>
     */
    public function formats(): array
    {
        $out = [];
        foreach (Format::cases() as $format) {
            $out[] = [
                'id' => $format->value,
                'label' => self::LABELS[$format->value] ?? $format->value,
                'import' => $format->canImport(),
                'export' => $format->canExport(),
                'extension' => $format->extension(),
                'mime' => $format->mimeType(),
            ];
        }

        return $out;
    }

    /** Largest accepted import (decoded), from import.max_mb. */
    public function maxBytes(): int
    {
        return max(1, $this->services->int('import.max_mb', 10)) * 1048576;
    }

    // ---------------------------------------------------------------- import

    /**
     * @param array<mixed> $body
     *
     * @return Row
     *
     * @throws InvalidInputException
     * @throws PayloadTooLargeException
     */
    public function preview(array $body): array
    {
        [$preview, $candidates] = $this->analyse($body);

        return self::objects($preview->toArray()) + ['relations_checked' => $candidates <= RuleService::RELATION_CHECK_LIMIT];
    }

    /**
     * Empty maps (rule query_params, issue params) must be JSON objects, not lists.
     *
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    private static function objects(array $preview): array
    {
        $fixIssues = static function (mixed $issues): mixed {
            if (!is_array($issues)) {
                return $issues;
            }
            foreach ($issues as $i => $issue) {
                if (is_array($issue) && ($issue['params'] ?? null) === []) {
                    $issues[$i]['params'] = new \stdClass();
                }
            }

            return $issues;
        };
        foreach (['errors', 'warnings'] as $key) {
            $preview[$key] = $fixIssues($preview[$key] ?? []);
        }
        if (is_array($preview['rows'] ?? null)) {
            foreach ($preview['rows'] as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['errors'] = $fixIssues($row['errors'] ?? []);
                $row['warnings'] = $fixIssues($row['warnings'] ?? []);
                if (is_array($row['rule'] ?? null) && ($row['rule']['query_params'] ?? null) === []) {
                    $row['rule']['query_params'] = new \stdClass();
                }
                $preview['rows'][$i] = $row;
            }
        }

        return $preview;
    }

    /**
     * @param array<mixed> $body
     *
     * @return Row
     *
     * @throws InvalidInputException
     * @throws PayloadTooLargeException
     */
    public function commit(array $body): array
    {
        $skipDuplicates = self::flag($body['skip_duplicates'] ?? null, true);
        $skipInvalid = self::flag($body['skip_invalid'] ?? null, false);
        $lines = $this->lines($body['lines'] ?? null);

        [$preview] = $this->analyse($body);
        if ($preview->hasFileErrors()) {
            $first = $preview->errors[0];
            throw new InvalidInputException($first->message, [ValidationIssue::error($first->code, 'content', $first->message, $first->params)], 'content', $first->code);
        }

        $scope = [];
        foreach ($preview->rows as $row) {
            if ($lines === null || isset($lines[$row->line])) {
                $scope[] = $row;
            }
        }

        if (!$skipInvalid) {
            $issues = [];
            $bad = 0;
            foreach ($scope as $row) {
                if ($row->errors === []) {
                    continue;
                }
                ++$bad;
                if (count($issues) < self::MAX_ROW_ISSUES) {
                    $issues[] = ValidationIssue::error($row->errors[0]->code, 'rows.' . $row->line, $row->errors[0]->message, ['line' => $row->line]);
                }
            }
            if ($bad > 0) {
                throw new InvalidInputException('The import has invalid rows.', $issues, 'rows', 'import_invalid');
            }
        }

        $rules = [];
        foreach ($scope as $row) {
            if ($row->rule !== null && !($skipDuplicates && $row->isDuplicate())) {
                $rules[] = $row->rule;
            }
        }

        $result = $rules === [] ? ['created' => [], 'rejected' => []] : $this->rules->createMany($rules, RuleEvents::ACTION_IMPORT);
        $created = $result['created'];

        $suggested = 0;
        if ($preview->notFoundPaths !== []) {
            $suggested = $this->suggestions->suggestForPaths($preview->notFoundPaths, SuggestionStore::SOURCE_CRAWLER)['suggested'];
        }

        return [
            'format' => $preview->format?->value,
            'created' => count($created),
            'skipped' => max(0, count($scope) - count($created)),
            'rules' => array_map($this->rules->plain(...), array_slice($created, 0, self::MAX_RETURNED_RULES)),
            'rules_truncated' => count($created) > self::MAX_RETURNED_RULES,
            'suggestions' => $suggested,
            'not_found' => count($preview->notFoundPaths),
        ];
    }

    /**
     * Compares an old sitemap with the current pages and rules and stores suggestions for the missing paths.
     *
     * @param array<mixed> $body
     *
     * @return Row
     *
     * @throws InvalidInputException
     * @throws PayloadTooLargeException
     */
    public function sitemap(array $body): array
    {
        $xml = $this->decodeContent($body);
        $reader = new SitemapDiff();
        try {
            $paths = $reader->parse($xml, $this->maxBytes());
            if ($paths === [] && $reader->parseIndex($xml, $this->maxBytes()) !== []) {
                throw new InvalidInputException('This is a sitemap index; upload the nested sitemaps one by one.', null, 'content', 'sitemap_index');
            }
        } catch (SitemapException $e) {
            throw new InvalidInputException($e->getMessage(), null, 'content', 'sitemap_invalid');
        }

        $matcher = $this->services->matcher();
        $isRedirected = static fn (string $route, ?string $language): bool => $matcher->match(new RequestContext(path: $route, language: $language), MatchPhase::Any) !== null;
        $result = $reader->diff($paths, $this->services->pageIndex(), $isRedirected, $this->site->languages !== [] ? $this->site->languages : null);

        $suggested = 0;
        if ($result->missingPaths !== []) {
            $suggested = $this->suggestions->suggestForPaths($result->missingPaths, SuggestionStore::SOURCE_SITEMAP)['suggested'];
        }

        return $result->toArray() + ['suggestions_created' => $suggested];
    }

    /**
     * Turns Grav's site.redirects / site.routes into plugin rules. site.yaml itself is not touched.
     *
     * @param array<mixed> $body
     *
     * @return Row
     *
     * @throws InvalidInputException
     */
    public function importSiteConfig(array $body): array
    {
        $document = [];
        if (self::flag($body['redirects'] ?? null, true) && $this->site->siteRedirects !== []) {
            $document['redirects'] = $this->site->siteRedirects;
        }
        if (self::flag($body['routes'] ?? null, true) && $this->site->siteRoutes !== []) {
            $document['routes'] = $this->site->siteRoutes;
        }
        if ($document === []) {
            throw new InvalidInputException('There are no site.yaml redirects or routes to import.', null, 'redirects', 'nothing_to_import');
        }

        return $this->commit([
            'content' => Yaml::dump($document, 3, 4),
            'filename' => 'site.yaml',
            'format' => Format::GravSite->value,
            'options' => ['default_group' => 'site.yaml'],
            'skip_duplicates' => self::flag($body['skip_duplicates'] ?? null, true),
            'skip_invalid' => self::flag($body['skip_invalid'] ?? null, true),
        ]);
    }

    // ---------------------------------------------------------------- export

    /**
     * Query: `format`, `only_enabled`, `group`, `status`, `host`, and `ids` (comma separated rule ids; unknown ids
     * are ignored, a blank value means no filter). All filters combine with AND.
     *
     * @param array<mixed> $query
     *
     * @return array{filename: string, mime: string, content: string, skipped: list<array{rule_id: string, code: string, reason: string}>, lossy: list<array{rule_id: string, code: string, reason: string}>, exported: int}
     *
     * @throws InvalidInputException
     */
    public function export(array $query): array
    {
        $id = $query['format'] ?? 'csv';
        $format = is_string($id) && $id !== '' ? Format::tryFrom($id) : Format::Csv;
        if ($format === null) {
            throw new InvalidInputException('Unknown export format.', null, 'format', 'unknown_format');
        }
        if (!$format->canExport()) {
            throw new InvalidInputException('This format can only be imported.', null, 'format', 'format_not_exportable');
        }

        $statuses = [];
        $status = $query['status'] ?? null;
        if (is_string($status) || is_int($status)) {
            foreach (explode(',', (string) $status) as $part) {
                if (is_numeric(trim($part))) {
                    $statuses[] = (int) trim($part);
                }
            }
        }
        $host = $query['host'] ?? null;
        $options = new ExportOptions(
            onlyEnabled: self::flag($query['only_enabled'] ?? null, false),
            group: array_key_exists('group', $query) && is_scalar($query['group']) ? (string) $query['group'] : null,
            statuses: $statuses === [] ? null : $statuses,
            exportHost: is_string($host) && trim($host) !== '' ? trim($host) : null,
        );

        $rules = $this->rules->all();
        $ids = self::idFilter($query['ids'] ?? null);
        if ($ids !== null) {
            $rules = array_values(array_filter($rules, static fn (Rule $r): bool => isset($ids[$r->id])));
        }

        $result = (new Exporter($this->services->clock()))->export($rules, $format, $options);
        $note = static fn (ExportNote $n): array => $n->toArray();

        return [
            'filename' => $result->filename,
            'mime' => $result->mimeType,
            'content' => $result->content,
            'skipped' => array_map($note, $result->skipped),
            'lossy' => array_map($note, $result->lossy),
            'exported' => $result->exported,
        ];
    }

    /**
     * Grav's own redirect settings, read-only.
     *
     * @return array{redirects: array<mixed>|stdClass, routes: array<mixed>|stdClass, settings: array<mixed>|stdClass}
     */
    public function siteConfig(): array
    {
        return [
            'redirects' => $this->site->siteRedirects === [] ? new stdClass() : $this->site->siteRedirects,
            'routes' => $this->site->siteRoutes === [] ? new stdClass() : $this->site->siteRoutes,
            'settings' => $this->site->pageSettings === [] ? new stdClass() : $this->site->pageSettings,
        ];
    }

    // ---------------------------------------------------------------- internals

    /**
     * The `ids` filter of an export: comma separated rule ids (or a list). Null means no filter, which is the case
     * for an absent or blank value; any other value filters, so ids that match nothing export nothing.
     *
     * @return array<string, true>|null ids as keys
     */
    private static function idFilter(mixed $value): ?array
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }
            $parts = explode(',', $value);
        } elseif (is_array($value)) {
            $parts = array_filter($value, is_string(...));
            if ($parts === []) {
                return null;
            }
        } else {
            return null;
        }
        $ids = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $ids[$part] = true;
            }
        }

        return $ids;
    }

    /**
     * Parses the upload and validates every valid row.
     *
     * @param array<mixed> $body
     *
     * @return array{0: ImportPreview, 1: int} the rebuilt preview and the number of validated candidates
     */
    private function analyse(array $body): array
    {
        $content = $this->decodeContent($body);
        $filename = is_string($body['filename'] ?? null) ? $body['filename'] : '';
        $format = $this->requestedFormat($body['format'] ?? null);
        $options = $this->options($body['options'] ?? null);

        if ($format === null) {
            $format = Format::detect($filename, TextNormalizer::normalize($content)['text']);
            if ($format === null) {
                return [new ImportPreview([], null, [new ImportIssue('unknown_format', 'The file format could not be detected.')]), 0];
            }
        }

        $preview = (new Importer($this->services->clock()))->preview($content, $format, $options);
        if ($preview->hasFileErrors()) {
            return [$preview, 0];
        }

        $candidates = [];
        foreach ($preview->rows as $row) {
            if ($row->rule !== null) {
                $candidates[] = $row->rule;
            }
        }
        $verdicts = $candidates === [] ? [] : $this->rules->checkBatch($candidates, $options->existingRules);

        $rows = [];
        $next = 0;
        foreach ($preview->rows as $row) {
            $rows[] = $row->rule === null ? $row : $this->withVerdict($row, $verdicts[$next++]);
        }

        return [new ImportPreview($rows, $preview->format, $preview->errors, $preview->warnings, $preview->notFoundPaths), count($candidates)];
    }

    private function withVerdict(ImportRow $row, ValidationResult $verdict): ImportRow
    {
        $errors = [];
        $warnings = [];
        foreach ($verdict->issues as $issue) {
            if ($issue->severity === Severity::Error) {
                $errors[] = self::importIssue($issue);
            } elseif ($issue->severity === Severity::Warning) {
                $warnings[] = self::importIssue($issue);
            }
        }
        if ($errors === [] && $warnings === []) {
            return $row;
        }

        return new ImportRow(
            $row->line,
            $row->raw,
            $errors === [] ? $row->rule : null,
            [...$row->errors, ...$errors],
            [...$row->warnings, ...$warnings],
            $row->duplicateOf,
            $row->duplicateInFile,
        );
    }

    private static function importIssue(ValidationIssue $issue): ImportIssue
    {
        $params = [];
        foreach ($issue->params as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $params[(string) $key] = $value;
            } elseif (is_array($value) && array_is_list($value) && $value === array_filter($value, is_scalar(...))) {
                $params[(string) $key] = implode(', ', array_map(strval(...), $value));
            }
        }
        $params['field'] = $issue->field;

        return new ImportIssue($issue->code, $issue->message, $params);
    }

    /**
     * @throws InvalidInputException
     */
    private function requestedFormat(mixed $value): ?Format
    {
        if ($value === null || $value === '') {
            return null;
        }
        $format = is_string($value) ? Format::tryFrom($value) : null;
        if ($format === null) {
            throw new InvalidInputException('Unknown import format.', null, 'format', 'unknown_format');
        }
        if (!$format->canImport()) {
            throw new InvalidInputException('This format can only be exported.', null, 'format', 'format_not_importable');
        }

        return $format;
    }

    /**
     * @throws InvalidInputException
     */
    private function options(mixed $raw): ImportOptions
    {
        $raw = is_array($raw) ? $raw : [];

        $columns = [];
        if (is_array($raw['columns'] ?? null)) {
            foreach ($raw['columns'] as $field => $column) {
                if (is_string($field) && (is_int($column) || (is_string($column) && $column !== ''))) {
                    $columns[$field] = $column;
                }
            }
        }

        $delimiter = $raw['delimiter'] ?? null;
        $delimiter = is_string($delimiter) && $delimiter !== '' ? $delimiter : null;
        if ($delimiter !== null && in_array(strtolower($delimiter), ['tab', '\t'], true)) {
            $delimiter = "\t";
        }

        $status = $this->rules->defaultStatus()->value;
        if (isset($raw['default_status']) && $raw['default_status'] !== '') {
            $parsed = is_numeric($raw['default_status']) ? StatusCode::tryFrom((int) $raw['default_status']) : null;
            if ($parsed === null) {
                throw new InvalidInputException('The default status is not a supported status code.', null, 'options.default_status', 'invalid_value');
            }
            $status = $parsed->value;
        }

        $host = $this->site->host();

        return new ImportOptions(
            csvMapping: $columns,
            delimiter: $delimiter,
            hasHeader: array_key_exists('has_header', $raw) && $raw['has_header'] !== null && $raw['has_header'] !== '' ? self::flag($raw['has_header'], true) : null,
            defaultStatus: $status,
            defaultGroup: is_string($raw['default_group'] ?? null) ? trim($raw['default_group']) : '',
            origin: RuleSource::Import,
            maxBytes: $this->maxBytes(),
            existingRules: $this->rules->all(),
            siteLanguages: $this->site->languages,
            baseHosts: $host !== '' ? [$host] : [],
            redirectDefaultCode: $this->site->redirectDefaultCode,
        );
    }

    /**
     * The uploaded text or bytes, decoded and size checked.
     *
     * @param array<mixed> $body
     *
     * @throws InvalidInputException
     * @throws PayloadTooLargeException
     */
    private function decodeContent(array $body): string
    {
        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            throw new InvalidInputException('The content is required.', null, 'content', 'required');
        }
        $max = $this->maxBytes();
        $encoding = $body['encoding'] ?? null;
        if ($encoding !== null && $encoding !== '' && $encoding !== 'base64' && !in_array($encoding, ['text', 'plain', 'utf-8'], true)) {
            throw new InvalidInputException('The encoding must be "base64" or omitted.', null, 'encoding', 'invalid_value');
        }

        if ($encoding === 'base64') {
            $estimate = intdiv(strlen($content) * 3, 4);
            if ($estimate > $max + 8) {
                throw new PayloadTooLargeException($estimate, $max);
            }
            $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? $content, true);
            if ($decoded === false) {
                throw new InvalidInputException('The content is not valid base64.', null, 'content', 'invalid_encoding');
            }
            $content = $decoded;
        }
        if (strlen($content) > $max) {
            throw new PayloadTooLargeException(strlen($content), $max);
        }

        return $content;
    }

    /**
     * @return array<int, true>|null line numbers to import, null for all
     *
     * @throws InvalidInputException
     */
    private function lines(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidInputException('The lines must be a list of line numbers.', null, 'lines', 'invalid_value');
        }
        $out = [];
        foreach ($value as $line) {
            if (is_int($line) || (is_string($line) && ctype_digit($line))) {
                $out[(int) $line] = true;
            }
        }

        return $out;
    }

    private static function flag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }
}
