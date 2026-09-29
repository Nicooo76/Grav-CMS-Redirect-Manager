<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;

/** Persistence of 404 entries. Implementations: JsonlLogStore, SqliteLogStore. */
interface LogStore
{
    /** Stores one entry. Concurrent callers are safe. May drop the entry when a size cap is hit. */
    public function append(NotFoundEntry $e): void;

    /**
     * Entries with $from <= time <= $to, oldest first. Corrupt records are skipped.
     *
     * @return iterable<NotFoundEntry>
     */
    public function entries(DateTimeImmutable $from, DateTimeImmutable $to): iterable;

    /** Groups entries by path; see GroupQuery for the filters. Streams, never loads a whole log. */
    public function groups(GroupQuery $q): GroupPage;

    /**
     * Hits per UTC day, zero-filled over the range (at most 366 days ending at $to).
     *
     * @return array<string, int>
     */
    public function countsByDay(DateTimeImmutable $from, DateTimeImmutable $to, bool $includeBots): array;

    /** Deletes entries older than $before (time < $before). Returns the number of deleted entries. */
    public function purge(DateTimeImmutable $before): int;

    /** Deletes all entries of one path. Returns the number of deleted entries. */
    public function deletePath(string $path): int;

    /** Bytes used on disk. */
    public function sizeBytes(): int;

    /** Deletes everything. */
    public function clear(): void;
}
