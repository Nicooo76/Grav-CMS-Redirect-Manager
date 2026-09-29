<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;

/**
 * Filter, sort and paging for LogStore::groups().
 *
 * - from / to: inclusive bounds on the entry time.
 * - includeBots false drops UserAgentClass::Bot entries. Monitoring and unknown stay
 *   (a monitor pinging a missing URL is a real finding). uaClasses narrows further.
 * - search: case-insensitive substring of the path.
 * - hidePaths: resolved paths (path => resolvedAt). A path is hidden as long as it has no
 *   hit (within the other filters) after its resolvedAt; a new hit brings it back.
 * - page is 1-based, perPage is clamped to 1..500.
 */
final readonly class GroupQuery
{
    public const MAX_PER_PAGE = 500;

    /**
     * @param list<UserAgentClass>              $uaClasses empty = all classes
     * @param array<string, DateTimeImmutable>  $hidePaths
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public bool $includeBots = false,
        public array $uaClasses = [],
        public string $search = '',
        public ?string $language = null,
        public ?string $host = null,
        public GroupSort $sort = GroupSort::Hits,
        public SortDirection $direction = SortDirection::Desc,
        public int $page = 1,
        public int $perPage = 50,
        public array $hidePaths = [],
    ) {
    }

    public function pageNumber(): int
    {
        return max(1, $this->page);
    }

    public function pageSize(): int
    {
        return min(self::MAX_PER_PAGE, max(1, $this->perPage));
    }

    /** Everything except search and hidePaths, which apply per path. */
    public function acceptsEntry(NotFoundEntry $entry): bool
    {
        $t = $entry->time->getTimestamp();
        if ($t < $this->from->getTimestamp() || $t > $this->to->getTimestamp()) {
            return false;
        }
        if (!$this->includeBots && $entry->uaClass === UserAgentClass::Bot) {
            return false;
        }
        if ($this->uaClasses !== [] && !in_array($entry->uaClass, $this->uaClasses, true)) {
            return false;
        }
        if ($this->language !== null && $entry->language !== $this->language) {
            return false;
        }

        return $this->host === null || $entry->host === $this->host;
    }

    public function acceptsPath(string $path): bool
    {
        return $this->search === '' || mb_stripos($path, $this->search) !== false;
    }
}
