<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;
use Throwable;

/**
 * Redirect suggestions: live for one path, stored per open 404 path, accepted into rules or rejected.
 *
 * generate() looks at the 404 paths of the last 30 days that no rule covers (bots and paths marked done are left
 * out) and stores the best page suggestion per path when its score reaches `suggestions.min_score`. Every created
 * or improved record fires onSuggestionCreated. Accepting a suggestion creates a rule with origin "suggestion"
 * (validated like any rule) and only then marks the record accepted, so a rejected rule leaves the suggestion open.
 *
 * Stored records come out as rows `{id, path, target, score, reason, page_title, hits, status, source, created_at,
 * decided_at}`; `hits` are the 404 hits of the last 90 days for the path (known for the 500 busiest paths, else 0).
 *
 * @phpstan-import-type Record from SuggestionStore
 */
final class SuggestionService
{
    public const GENERATE_DAYS = 30;
    public const HITS_DAYS = 90;
    public const MAX_PATHS = 2000;

    private ?Suggester $suggester = null;

    public function __construct(
        private readonly ServiceFactory $services,
        private readonly RuleService $rules,
        private readonly RuleEvents $events,
    ) {
    }

    /**
     * Live suggestions for one path.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidInputException
     */
    public function suggest(string $path, ?string $language = null, int $limit = 5): array
    {
        if (trim($path) === '') {
            throw new InvalidInputException('"path" is required.', field: 'path', errorCode: 'required');
        }
        $language = $language !== null && trim($language) !== '' ? strtolower(trim($language)) : null;

        return array_map(
            static fn (Suggestion $s): array => $s->toArray(),
            $this->suggester()->suggest($path, $language, max(1, min(20, $limit))),
        );
    }

    /**
     * Stored suggestions, best score first.
     *
     * @param array<string, mixed> $query status (open|accepted|rejected|all, default open), min_score, source
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidInputException
     */
    public function list(array $query = []): array
    {
        return $this->listWithCounts($query)['rows'];
    }

    /**
     * Like list(), plus the number of stored suggestions per status. The counts follow `min_score` and `source`
     * but ignore `status`, so tabs for the three states keep stable numbers while the list is filtered.
     *
     * @param array<string, mixed> $query status (open|accepted|rejected|all, default open), min_score, source
     *
     * @return array{rows: list<array<string, mixed>>, counts: array{open: int, accepted: int, rejected: int}}
     *
     * @throws InvalidInputException
     */
    public function listWithCounts(array $query = []): array
    {
        $status = isset($query['status']) && is_string($query['status']) && $query['status'] !== '' ? $query['status'] : SuggestionStore::STATUS_OPEN;
        if (!in_array($status, [SuggestionStore::STATUS_OPEN, SuggestionStore::STATUS_ACCEPTED, SuggestionStore::STATUS_REJECTED, 'all'], true)) {
            throw new InvalidInputException('"status" must be open, accepted, rejected or all.', field: 'status');
        }
        $min = 0.0;
        if (isset($query['min_score']) && $query['min_score'] !== '') {
            if (!is_numeric($query['min_score'])) {
                throw new InvalidInputException('"min_score" must be a number.', field: 'min_score');
            }
            $min = (float) $query['min_score'];
        }
        $source = isset($query['source']) && is_string($query['source']) && $query['source'] !== '' ? $query['source'] : null;

        $matching = array_values(array_filter(
            $this->services->suggestionStore()->all(),
            static fn (array $r): bool => $r['score'] >= $min && ($source === null || $r['source'] === $source),
        ));
        $counts = ['open' => 0, 'accepted' => 0, 'rejected' => 0];
        foreach ($matching as $record) {
            if (isset($counts[$record['status']])) {
                ++$counts[$record['status']];
            }
        }
        $records = $status === 'all' ? $matching : array_values(array_filter($matching, static fn (array $r): bool => $r['status'] === $status));

        return ['rows' => $this->rows($records), 'counts' => $counts];
    }

    /**
     * Stores suggestions for the open 404 paths of the last $days days (default 30) that no rule covers. With
     * $dryRun nothing is stored and no event fires; "preview" lists what would be stored.
     *
     * @return array{paths: int, suggested: int, created: int, improved: int, rejected_before: int, no_suggestion: int, skipped_with_rule: int, preview?: list<array<string, mixed>>}
     *
     * @throws InvalidInputException
     */
    public function generate(?int $days = null, bool $dryRun = false): array
    {
        $days ??= self::GENERATE_DAYS;
        if ($days < 1 || $days > 366) {
            throw new InvalidInputException('"days" must be between 1 and 366.', field: 'days');
        }
        $now = $this->services->clock()->now();
        $from = $now->modify('-' . $days . ' days');
        $hide = $this->services->resolvedPaths()->all();
        $store = $this->services->logStore();

        $items = [];
        for ($page = 1; count($items) < self::MAX_PATHS; ++$page) {
            $result = $store->groups(new GroupQuery($from, $now, false, [], '', null, null, GroupSort::Hits, SortDirection::Desc, $page, GroupQuery::MAX_PER_PAGE, $hide));
            foreach ($result->rows as $row) {
                $languages = array_keys($row->languages);
                $items[] = [$row->path, $languages === [] ? null : (string) $languages[0]];
            }
            if ($result->rows === [] || $page * GroupQuery::MAX_PER_PAGE >= $result->total) {
                break;
            }
        }

        return $this->store(array_slice($items, 0, self::MAX_PATHS), SuggestionStore::SOURCE_NOT_FOUND, $dryRun);
    }

    /**
     * Creates stored suggestions for paths from an import (old sitemap, crawler export).
     *
     * @param list<string> $paths
     * @param string       $source one of the SuggestionStore::SOURCE_* constants
     *
     * @return array{paths: int, suggested: int}
     */
    public function suggestForPaths(array $paths, string $source): array
    {
        $paths = array_values(array_unique($paths));
        $result = $this->store(array_map(static fn (string $p): array => [$p, null], $paths), $source);

        return ['paths' => $result['paths'], 'suggested' => $result['suggested']];
    }

    /**
     * Turns a suggestion into a rule.
     *
     * @param array<mixed> $body optional target and status
     *
     * @return array{rule: array<string, mixed>, suggestion: array<string, mixed>}
     *
     * @throws ResourceNotFoundException
     * @throws RevisionConflictException  when the suggestion was decided before
     * @throws RuleValidationException
     */
    public function accept(string $id, array $body = []): array
    {
        $record = $this->open($id);
        $fields = $this->ruleFields($record, $body);
        $saved = $this->rules->create($fields);
        $accepted = $this->services->suggestionStore()->accept($id) ?? $record;

        return [
            'rule' => $this->rules->presentSaved($saved['rule'], $saved['issues']),
            'suggestion' => $this->rows([$accepted])[0],
        ];
    }

    /**
     * @return array<string, mixed> the rejected suggestion
     *
     * @throws ResourceNotFoundException
     * @throws RevisionConflictException
     */
    public function reject(string $id): array
    {
        $record = $this->services->suggestionStore()->find($id);
        if ($record === null) {
            throw new ResourceNotFoundException('suggestion', $id);
        }
        $rejected = $this->services->suggestionStore()->reject($id);
        if ($rejected === null) {
            throw new RevisionConflictException('The suggestion was accepted before.');
        }

        return $this->rows([$rejected])[0];
    }

    /**
     * The score bulk accept uses when the request names none: `suggestions.bulk_accept_score`, 0.9 by default.
     */
    public function bulkAcceptScore(): float
    {
        $score = (float) $this->services->config('suggestions.bulk_accept_score', 0.9);

        return max(0.0, min(1.0, $score));
    }

    /**
     * Accepts open suggestions with a score of at least $minScore in one go (or previews them).
     *
     * @param list<string>|null $ids limit to these suggestions
     *
     * @return array<string, mixed> dry run: min_score, count, rows; else also rules and skipped (rows rejected by validation)
     *
     * @throws InvalidInputException
     */
    public function bulkAccept(?float $minScore = null, ?array $ids = null, bool $dryRun = false): array
    {
        $min = $minScore ?? $this->bulkAcceptScore();
        if ($min < 0.0 || $min > 1.0) {
            throw new InvalidInputException('"min_score" must be between 0 and 1.', field: 'min_score');
        }
        $wanted = $ids === null ? null : array_flip($ids);
        $candidates = array_values(array_filter(
            $this->services->suggestionStore()->open($min),
            static fn (array $r): bool => $wanted === null || isset($wanted[$r['id']]),
        ));

        if ($dryRun) {
            return ['min_score' => $min, 'count' => count($candidates), 'rows' => $this->rows($candidates)];
        }

        $built = [];
        $skipped = [];
        foreach ($candidates as $record) {
            try {
                $built[] = [$record, $this->rules->buildNew($this->ruleFields($record, []))];
            } catch (RuleValidationException $e) {
                $skipped[] = ['id' => $record['id'], 'issues' => $e->issues];
            }
        }
        $result = $this->rules->createMany(array_map(static fn (array $pair): Rule => $pair[1], $built), RuleEvents::ACTION_CREATE);
        $rejected = [];
        foreach ($result['rejected'] as $reject) {
            $rejected[$reject['index']] = $reject['issues'];
        }

        $accepted = [];
        $created = [];
        $createdIndex = 0;
        foreach ($built as $i => [$record]) {
            if (isset($rejected[$i])) {
                $skipped[] = ['id' => $record['id'], 'issues' => $rejected[$i]];
                continue;
            }
            $done = $this->services->suggestionStore()->accept($record['id']) ?? $record;
            $accepted[] = $done;
            $created[] = $result['created'][$createdIndex++];
        }

        return [
            'min_score' => $min,
            'count' => count($accepted),
            'rows' => $this->rows($accepted),
            'rules' => array_map($this->rules->plain(...), array_slice($created, 0, 500)),
            'skipped' => array_map(static fn (array $s): array => [
                'id' => $s['id'],
                'issues' => array_map(static fn ($i): array => $i->toArray(), $s['issues']),
            ], $skipped),
        ];
    }

    // ---------------------------------------------------------------- internals

    private function suggester(): Suggester
    {
        return $this->suggester ??= new Suggester($this->services->pageIndex());
    }

    /**
     * @param list<array{0: string, 1: string|null}> $items path and language
     *
     * @return array{paths: int, suggested: int, created: int, improved: int, rejected_before: int, no_suggestion: int, skipped_with_rule: int, preview?: list<array<string, mixed>>}
     */
    private function store(array $items, string $source, bool $dryRun = false): array
    {
        $min = (float) $this->services->config('suggestions.min_score', 0.5);
        $store = $this->services->suggestionStore();
        $before = [];
        foreach ($store->open() as $record) {
            $before[$record['path']] = $record['score'];
        }
        $matcher = $this->services->matcher();

        $counts = ['paths' => count($items), 'suggested' => 0, 'created' => 0, 'improved' => 0, 'rejected_before' => 0, 'no_suggestion' => 0, 'skipped_with_rule' => 0];
        if ($dryRun) {
            $counts['preview'] = [];
        }
        foreach ($items as [$path, $language]) {
            $route = $this->stripLanguage($path, $language);
            if ($matcher->match(new RequestContext(path: $route[0], language: $route[1]), MatchPhase::Any) !== null) {
                ++$counts['skipped_with_rule'];
                continue;
            }
            $best = $this->suggester()->suggest($path, $language, 1)[0] ?? null;
            if ($best === null || $best->score < $min) {
                ++$counts['no_suggestion'];
                continue;
            }
            if ($dryRun) {
                if ($store->isRejected($path, $best->target)) {
                    ++$counts['rejected_before'];
                    continue;
                }
                $known = $before[$path] ?? null;
                if ($known !== null && $best->score <= $known) {
                    continue;
                }
                ++$counts[$known === null ? 'created' : 'improved'];
                ++$counts['suggested'];
                $counts['preview'][] = ['path' => $path, 'target' => $best->target, 'score' => $best->score, 'reason' => $best->reason->value, 'page_title' => $best->pageTitle];
                continue;
            }
            $record = $store->upsertOpen($path, $best, $source);
            if ($record === null) {
                ++$counts['rejected_before'];
                continue;
            }
            $known = $before[$path] ?? null;
            if ($known === null) {
                ++$counts['created'];
            } elseif ($record['score'] > $known) {
                ++$counts['improved'];
            } else {
                continue;
            }
            $before[$path] = $record['score'];
            ++$counts['suggested'];
            try {
                $this->events->suggestionCreated($record);
            } catch (Throwable) {
                // A failing listener must not stop the run.
            }
        }

        return $counts;
    }

    /**
     * @return array{0: string, 1: string|null} route without language prefix, language
     */
    private function stripLanguage(string $path, ?string $language): array
    {
        $segments = explode('/', ltrim($path, '/'), 2);
        $first = strtolower($segments[0]);
        if ($first !== '' && in_array($first, $this->services->languages(), true)) {
            $rest = '/' . ($segments[1] ?? '');

            return [$rest, $first];
        }

        return [$path, $language];
    }

    /**
     * @return Record
     */
    private function open(string $id): array
    {
        $record = $this->services->suggestionStore()->find($id);
        if ($record === null) {
            throw new ResourceNotFoundException('suggestion', $id);
        }
        if ($record['status'] !== SuggestionStore::STATUS_OPEN) {
            throw new RevisionConflictException(sprintf('The suggestion was already %s.', $record['status']));
        }

        return $record;
    }

    /**
     * @param Record       $record
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function ruleFields(array $record, array $body): array
    {
        $target = isset($body['target']) && is_string($body['target']) && trim($body['target']) !== '' ? trim($body['target']) : $record['target'];
        $status = $body['status'] ?? $this->rules->defaultStatus()->value;
        [$source, $language] = $this->stripLanguage($record['path'], null);

        $fields = [
            'source' => $source === '' ? '/' : $source,
            'target' => $target,
            'status' => $status,
            'target_type' => preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) === 1 ? 'url' : 'page',
            'origin' => RuleSource::Suggestion->value,
        ];
        if ($language !== null) {
            $fields['conditions'] = ['languages' => [$language]];
        }
        // A path that differs from its target only in case (/shop/Rucksaecke to /shop/rucksaecke) is a loop for a
        // case-insensitive rule. Grav routes are case sensitive, so the rule has to be as well.
        if ($fields['target_type'] === 'page' && PathNormalizer::differsOnlyInCase($fields['source'], $target)) {
            $fields['case_sensitive'] = true;
        }
        if (StatusCode::tryFrom(is_numeric($status) ? (int) $status : 0)?->needsTarget() === false) {
            $fields['target'] = '';
        }

        return $fields;
    }

    /**
     * @param list<Record> $records
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $records): array
    {
        if ($records === []) {
            return [];
        }
        $hits = $this->hitsByPath();
        usort($records, static fn (array $a, array $b): int => [$b['score'], $a['path']] <=> [$a['score'], $b['path']]);

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'path' => $r['path'],
            'target' => $r['target'],
            'score' => $r['score'],
            'reason' => $r['reason'],
            'page_title' => $r['title'],
            'hits' => $hits[$r['path']] ?? 0,
            'status' => $r['status'],
            'source' => $r['source'],
            'created_at' => $r['createdAt'],
            'decided_at' => $r['decidedAt'],
        ], $records);
    }

    /**
     * @return array<string, int>
     */
    private function hitsByPath(): array
    {
        $now = $this->services->clock()->now();
        $page = $this->services->logStore()->groups(new GroupQuery(
            from: $now->modify('-' . self::HITS_DAYS . ' days'),
            to: $now,
            includeBots: true,
            sort: GroupSort::Hits,
            perPage: GroupQuery::MAX_PER_PAGE,
        ));
        $hits = [];
        foreach ($page->rows as $row) {
            $hits[$row->path] = $row->hits;
        }

        return $hits;
    }
}
