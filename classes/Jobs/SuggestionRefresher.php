<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Jobs;

use Closure;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\ResolvedPaths;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Keeps suggestions fresh without anyone clicking "generate": 404 paths with at least $minHits hits in the last
 * $days days that no rule handles get their best suggestion stored (score at least $minScore).
 *
 * Returns the records that are new or better than before, so the caller can fire onSuggestionCreated for them.
 */
final class SuggestionRefresher
{
    /**
     * @param Closure(): Suggester                    $suggester lazy: building the page index is expensive
     * @param Closure(string, ?string): bool          $hasRule   whether an existing rule already handles the path (path, language)
     */
    public function __construct(
        private readonly LogStore $log,
        private readonly SuggestionStore $store,
        private readonly Closure $suggester,
        private readonly Closure $hasRule,
        private readonly ResolvedPaths $resolved,
        private readonly Clock $clock,
        private readonly float $minScore = 0.5,
        private readonly int $minHits = 3,
        private readonly int $days = 7,
        private readonly int $limit = 200,
    ) {
    }

    /**
     * @return list<array<string, mixed>> stored records that were created or improved
     */
    public function refresh(): array
    {
        $now = $this->clock->now();
        $query = new GroupQuery(
            from: $now->modify('-' . $this->days . ' days'),
            to: $now,
            includeBots: false,
            sort: GroupSort::Hits,
            direction: SortDirection::Desc,
            page: 1,
            perPage: $this->limit,
            hidePaths: $this->resolved->all(),
        );

        $candidates = [];
        foreach ($this->log->groups($query)->rows as $row) {
            if ($row->hits < $this->minHits) {
                break; // sorted by hits, descending
            }
            $language = null;
            foreach (array_keys($row->languages) as $code) {
                $language = (string) $code;
                break;
            }
            if (!($this->hasRule)($row->path, $language)) {
                $candidates[] = [$row->path, $language];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $before = [];
        foreach ($this->store->open(0.0) as $record) {
            $before[$record['path']] = $record;
        }
        $suggester = ($this->suggester)();
        $changed = [];
        foreach ($candidates as [$path, $language]) {
            $best = $suggester->suggest($path, $language, 3)[0] ?? null;
            if ($best === null || $best->score < $this->minScore) {
                continue;
            }
            $record = $this->store->upsertOpen($path, $best, SuggestionStore::SOURCE_NOT_FOUND);
            if ($record === null) {
                continue; // rejected before
            }
            $old = $before[$path] ?? null;
            if ($old === null || $old['target'] !== $record['target'] || $old['score'] !== $record['score']) {
                $changed[] = $record;
            }
        }

        return $changed;
    }
}
