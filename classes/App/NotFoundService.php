<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupRow;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\IgnoreList;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use stdClass;
use Throwable;

/**
 * The 404 monitor: grouped paths with filters, sorting and paging, the daily trend, raw entries of one path, and the
 * actions on the log (ignore pattern, mark as resolved, delete). The REST controllers and the CLI call this and nothing
 * below it.
 *
 * Query parameters arrive as strings (query string) and are validated here; a bad value raises
 * InvalidInputException with the parameter name as field. Out-of-range numbers (days, per_page) are clamped instead.
 * Time ranges are UTC: `days` = N counts today and the N-1 days before it, a date-only `to` means the end of that day.
 *
 * "Resolved" paths are hidden from the list until they get a new hit (see ResolvedPaths); include_resolved=1 shows them
 * with `resolved: true` as long as no newer hit exists.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class NotFoundService
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;
    public const DEFAULT_PER_PAGE = 50;
    public const ENTRIES_LIMIT = 100;
    public const ENTRIES_DAYS = 90;
    public const MAX_RESOLVE = 500;
    public const MAX_PATTERN_LENGTH = 300;

    private ?Suggester $suggester = null;
    private ?bool $anyRules = null;

    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site,
        private readonly RuleService $rules,
        private readonly ?ConfigWriter $config,
    ) {
    }

    // ---------------------------------------------------------------- reading

    /**
     * @param array<string, mixed> $query
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, meta: array{totals: array{hits: int, paths: int, by_day: array<string, int>|stdClass}}}
     */
    public function groups(array $query): array
    {
        $this->anyRules = null;
        [$from, $to] = $this->range($query);

        $class = null;
        $classValue = $this->string($query, 'class');
        if ($classValue !== null) {
            $class = UserAgentClass::tryFrom(strtolower($classValue));
            if ($class === null) {
                throw new InvalidInputException('"class" must be one of browser, bot, monitoring, unknown.', field: 'class');
            }
        }

        $sortValue = $this->string($query, 'sort');
        $sort = $sortValue === null ? GroupSort::Hits : GroupSort::tryFrom(strtolower($sortValue));
        if ($sort === null) {
            throw new InvalidInputException('"sort" must be one of hits, last, first, path.', field: 'sort');
        }

        $dirValue = $this->string($query, 'dir');
        $direction = $dirValue === null
            ? ($sort === GroupSort::Path ? SortDirection::Asc : SortDirection::Desc)
            : SortDirection::tryFrom(strtolower($dirValue));
        if ($direction === null) {
            throw new InvalidInputException('"dir" must be asc or desc.', field: 'dir');
        }

        $page = $this->integer($query, 'page', 1);
        if ($page < 1) {
            throw new InvalidInputException('"page" must be 1 or greater.', field: 'page');
        }
        $perPage = max(1, min(GroupQuery::MAX_PER_PAGE, $this->integer($query, 'per_page', self::DEFAULT_PER_PAGE)));

        $language = $this->string($query, 'language');
        $host = $this->string($query, 'host');
        $resolved = $this->services->resolvedPaths()->all();
        $includeResolved = $this->flag($query, 'include_resolved');

        $result = $this->services->logStore()->groups(new GroupQuery(
            from: $from,
            to: $to,
            includeBots: $this->flag($query, 'bots') || $class === UserAgentClass::Bot,
            uaClasses: $class === null ? [] : [$class],
            search: $this->string($query, 'q') ?? '',
            language: $language,
            host: $host === null ? null : strtolower($host),
            sort: $sort,
            direction: $direction,
            page: $page,
            perPage: $perPage,
            hidePaths: $includeResolved ? [] : $resolved,
        ));

        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = $this->row($row, $resolved);
        }

        $byDay = $result->totals->byDay;

        return [
            'rows' => $rows,
            'total' => $result->total,
            'page' => $page,
            'per_page' => $perPage,
            'meta' => [
                'totals' => [
                    'hits' => $result->totals->hits,
                    'paths' => $result->totals->uniquePaths,
                    'by_day' => $byDay === [] ? new stdClass() : $byDay,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{days: array<string, int>}
     */
    public function trend(array $query): array
    {
        $days = $this->days($query);
        $to = $this->now();

        return ['days' => $this->services->logStore()->countsByDay($this->startOfDay($to, $days), $to, $this->flag($query, 'bots'))];
    }

    /**
     * The latest raw entries of exactly this path, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $path): array
    {
        if ($path === '') {
            throw new InvalidInputException('"path" is required.', field: 'path', errorCode: 'required');
        }

        $now = $this->now();
        $buffer = [];
        foreach ($this->services->logStore()->entries($now->modify('-' . self::ENTRIES_DAYS . ' days'), $now) as $entry) {
            if ($entry->path !== $path) {
                continue;
            }
            $buffer[] = $entry;
            if (count($buffer) >= self::ENTRIES_LIMIT * 2) {
                $buffer = $this->newest($buffer);
            }
        }

        $out = [];
        foreach ($this->newest($buffer) as $entry) {
            $row = [
                'time' => $entry->time->format(DateTimeImmutable::ATOM),
                'path' => $entry->path,
                'query' => $entry->query,
                'referer' => $entry->referer,
                'ua' => $entry->userAgent,
                'ua_class' => $entry->uaClass->value,
                'language' => $entry->language ?? '',
                'host' => $entry->host,
                'method' => $entry->method,
            ];
            if ($entry->ip !== null) {
                $row['ip'] = $entry->ip;
            }
            $out[] = $row;
        }

        return $out;
    }

    // ---------------------------------------------------------------- actions

    /**
     * Adds a glob to `log.ignore_patterns` and optionally deletes the logged paths it matches.
     *
     * "purged" counts the deleted log entries (one per logged request), "purged_paths" the distinct paths they belonged
     * to, which is what the 404 monitor lists.
     *
     * @return array{pattern: string, patterns: list<string>, purged: int, purged_paths: int}
     */
    public function ignore(string $pattern, bool $purge): array
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new InvalidInputException('"pattern" is required.', field: 'pattern', errorCode: 'required');
        }
        if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidInputException(sprintf('"pattern" can have at most %d characters.', self::MAX_PATTERN_LENGTH), field: 'pattern', errorCode: 'too_long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
            throw new InvalidInputException('"pattern" must not contain control characters.', field: 'pattern');
        }
        if (preg_match('~^[*/]+$~', $pattern) === 1) {
            throw new InvalidInputException('"pattern" would ignore every path.', field: 'pattern', errorCode: 'too_broad');
        }
        if ($this->config === null) {
            throw new UnavailableException('The plugin configuration cannot be written in this context.');
        }

        $patterns = $this->config->ignorePatterns();
        $known = false;
        foreach ($patterns as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($pattern)) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $patterns[] = $pattern;
            $this->config->saveIgnorePatterns($patterns);
        }

        $purged = $purge ? $this->purgeMatching(new IgnoreList([$pattern])) : ['entries' => 0, 'paths' => 0];

        return [
            'pattern' => $pattern,
            'patterns' => $patterns,
            'purged' => $purged['entries'],
            'purged_paths' => $purged['paths'],
        ];
    }

    /**
     * @param list<mixed> $paths
     *
     * @return array{resolved: int} number of paths handled
     */
    public function resolve(array $paths, bool $resolved): array
    {
        if ($paths === []) {
            throw new InvalidInputException('"paths" must be a non-empty list.', field: 'paths', errorCode: 'required');
        }
        if (count($paths) > self::MAX_RESOLVE) {
            throw new InvalidInputException(sprintf('At most %d paths can be handled at once.', self::MAX_RESOLVE), field: 'paths', errorCode: 'too_many');
        }
        $unique = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new InvalidInputException('"paths" must contain non-empty strings only.', field: 'paths');
            }
            $unique[$path] = $path;
        }
        $unique = array_values($unique);

        $store = $this->services->resolvedPaths();
        if ($resolved) {
            $store->markManyResolved($unique, $this->now());
        } else {
            foreach ($unique as $path) {
                $store->unmark($path);
            }
        }

        return ['resolved' => count($unique)];
    }

    /**
     * Deletes the log entries of one path, or all of them.
     *
     * @return array{deleted: int}
     */
    public function delete(?string $path, bool $all): array
    {
        $store = $this->services->logStore();
        if ($all) {
            $now = $this->now();
            $count = array_sum($store->countsByDay($this->startOfDay($now, self::MAX_DAYS), $now, true));
            $store->clear();

            return ['deleted' => $count];
        }
        if ($path === null || $path === '') {
            throw new InvalidInputException('Give a "path" or set "all".', field: 'path', errorCode: 'required');
        }

        $deleted = $store->deletePath($path);
        if ($deleted === 0) {
            throw new ResourceNotFoundException('404 path', $path);
        }

        return ['deleted' => $deleted];
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param array<string, DateTimeImmutable> $resolved
     *
     * @return array<string, mixed>
     */
    private function row(GroupRow $row, array $resolved): array
    {
        $languages = array_map('strval', array_keys($row->languages));
        $hosts = array_map('strval', array_keys($row->hosts));
        $language = $languages[0] ?? null;

        $referers = [];
        foreach ($row->topReferers as $referer => $hits) {
            $referers[] = ['referer' => (string) $referer, 'hits' => $hits];
        }

        // Entries without a stored host are matched as requests to the site's own host.
        $hasRule = $this->hasRule($row->path, $hosts[0] ?? $this->site->host(), $language);
        $suggestion = null;
        if (!$hasRule && $this->services->pageIndex()->count() > 0) {
            $best = $this->suggester()->suggest($row->path, $language, 1)[0] ?? null;
            $suggestion = $best?->toArray();
        }

        return [
            'path' => $row->path,
            'hits' => $row->hits,
            'first_seen' => $row->firstSeen->format(DateTimeImmutable::ATOM),
            'last_seen' => $row->lastSeen->format(DateTimeImmutable::ATOM),
            'top_referers' => $referers,
            'daily' => $row->daily === [] ? new stdClass() : $row->daily,
            'ua' => $row->uaBreakdown === [] ? new stdClass() : $row->uaBreakdown,
            'languages' => $languages,
            'hosts' => $hosts,
            'sample_query' => $row->sampleQuery,
            'has_rule' => $hasRule,
            'best_suggestion' => $suggestion,
            'resolved' => isset($resolved[$row->path]) && $row->lastSeen <= $resolved[$row->path],
        ];
    }

    /**
     * The latest ENTRIES_LIMIT entries, newest first. Stores return entries in write order, which is chronological
     * in practice; sorting anyway keeps the result right for logs that were merged or imported.
     *
     * @param list<NotFoundEntry> $entries
     *
     * @return list<NotFoundEntry>
     */
    private function newest(array $entries): array
    {
        $entries = array_reverse($entries);
        usort($entries, static fn (NotFoundEntry $a, NotFoundEntry $b): int => $b->time <=> $a->time);

        return array_slice($entries, 0, self::ENTRIES_LIMIT);
    }

    private function hasRule(string $path, string $host, ?string $language): bool
    {
        // Asked once per call, not once per row: RuleService::all() reads and hydrates every rule (0.1 s at 10,000 rules),
        // summary() only counts the stored rows.
        $this->anyRules ??= $this->rules->summary()['total'] > 0;
        if (!$this->anyRules) {
            return false;
        }
        try {
            $context = new RequestContext(path: $path, host: $host, language: $language);

            return $this->services->matcher()->match($context, MatchPhase::Any) !== null;
        } catch (Throwable) {
            // A rule the matcher cannot evaluate must not break the list; the row simply shows no rule.
            return false;
        }
    }

    private function suggester(): Suggester
    {
        return $this->suggester ??= new Suggester($this->services->pageIndex());
    }

    /**
     * Deletes every logged path (last 366 days, bots included) that the ignore list matches.
     *
     * @return array{entries: int, paths: int} deleted log entries and the paths they belonged to
     */
    private function purgeMatching(IgnoreList $list): array
    {
        $now = $this->now();
        $from = $this->startOfDay($now, self::MAX_DAYS);
        $store = $this->services->logStore();

        // Collect first, delete afterwards: deleting while paging would shift the pages.
        $paths = [];
        $page = 1;
        do {
            $result = $store->groups(new GroupQuery(
                from: $from,
                to: $now,
                includeBots: true,
                sort: GroupSort::Path,
                direction: SortDirection::Asc,
                page: $page,
                perPage: GroupQuery::MAX_PER_PAGE,
            ));
            foreach ($result->rows as $row) {
                if ($list->matches($row->path)) {
                    $paths[] = $row->path;
                }
            }
            ++$page;
        } while ($result->rows !== [] && ($page - 1) * GroupQuery::MAX_PER_PAGE < $result->total);

        $entries = 0;
        $deletedPaths = 0;
        foreach ($paths as $path) {
            $deleted = $store->deletePath($path);
            $entries += $deleted;
            $deletedPaths += $deleted > 0 ? 1 : 0;
        }

        return ['entries' => $entries, 'paths' => $deletedPaths];
    }

    private function now(): DateTimeImmutable
    {
        return $this->services->clock()->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /** Midnight (UTC) of the first of $days days that end with the day of $end. */
    private function startOfDay(DateTimeImmutable $end, int $days): DateTimeImmutable
    {
        return $end->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function range(array $query): array
    {
        $fromValue = $this->string($query, 'from');
        $toValue = $this->string($query, 'to');
        if ($fromValue === null && $toValue === null) {
            $now = $this->now();

            return [$this->startOfDay($now, $this->days($query)), $now];
        }

        $to = $toValue === null ? $this->now() : $this->date($toValue, 'to', true);
        $from = $fromValue === null ? $this->startOfDay($to, $this->days($query)) : $this->date($fromValue, 'from', false);
        if ($from > $to) {
            throw new InvalidInputException('"from" must not be after "to".', field: 'from');
        }

        return [$from, $to];
    }

    private function date(string $value, string $field, bool $endOfDay): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $endOfDay ? $date->setTime(23, 59, 59) : $date;
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?\s?(Z|[+-]\d{2}:?\d{2})?$/i', $value) === 1) {
            try {
                return (new DateTimeImmutable($value, $utc))->setTimezone($utc);
            } catch (Exception) {
                // falls through to the error below
            }
        }

        throw new InvalidInputException(sprintf('"%s" must be an ISO date (2026-09-29) or date and time.', $field), field: $field);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function days(array $query): int
    {
        return max(1, min(self::MAX_DAYS, $this->integer($query, 'days', self::DEFAULT_DAYS)));
    }

    /**
     * A trimmed scalar parameter; null when missing or empty.
     *
     * @param array<string, mixed> $query
     */
    private function string(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidInputException(sprintf('"%s" must be a string.', $key), field: $key);
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function integer(array $query, string $key, int $default): int
    {
        $value = $this->string($query, $key);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1) {
            throw new InvalidInputException(sprintf('"%s" must be an integer.', $key), field: $key);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function flag(array $query, string $key): bool
    {
        $value = $query[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        $value = $this->string($query, $key);
        if ($value === null) {
            return false;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidInputException(sprintf('"%s" must be 0 or 1.', $key), field: $key),
        };
    }
}
