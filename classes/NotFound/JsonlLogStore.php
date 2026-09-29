<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;
use Generator;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Util\Clock;
use InvalidArgumentException;

/**
 * 404 log as JSON Lines, one file per UTC day: "<dir>/YYYY-MM-DD.jsonl".
 *
 * Writing: fopen('ab') + flock(LOCK_EX) + one fwrite of one line. The lock is taken on the day
 * file itself; after acquiring it the writer checks that the path still points at the locked
 * inode, because purge() and deletePath() replace files by rename. If not, it reopens.
 *
 * Caps: once a day file would exceed $maxDayBytes, further entries of that day are dropped and
 * counted (one byte per drop in "<day>.drops", see droppedCount()). When a new day file is
 * created and the directory holds more than $maxBytes, the oldest day files are deleted first;
 * the newest file and today's file (per Clock) are never deleted by rotation.
 *
 * Reading streams line by line (fgets); partial, oversized and corrupt lines are skipped.
 * groups() needs two passes over the range (aggregates per path, then details for one page)
 * and keeps only small per-path aggregates in memory.
 */
final class JsonlLogStore implements LogStore
{
    public const DEFAULT_MAX_BYTES = 52_428_800;
    public const DEFAULT_MAX_DAY_BYTES = 10_485_760;

    private const MAX_LINE = 65536;
    /** Bits of a line position (see rows()) that hold the byte offset; the rest is the index of the day file. */
    private const POSITION_BITS = 40;
    private const POSITION_MASK = (1 << self::POSITION_BITS) - 1;
    /**
     * A writer starts over only when purge(), deletePath() or rotation replaced the file since it opened it, so it
     * runs out of attempts only when such rewrites follow each other without a break. The limit stops a broken file
     * system from spinning forever, it is not meant to be reached: an entry dropped here is a lost entry.
     */
    private const WRITE_ATTEMPTS = 1000;
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $maxDayBytes = self::DEFAULT_MAX_DAY_BYTES,
    ) {
    }

    public function append(NotFoundEntry $e): void
    {
        $line = json_encode($e->toArray(), self::JSON_FLAGS) . "\n";
        $day = DayRange::key($e->time->getTimestamp());
        $file = $this->dayPath($day);
        AtomicFile::ensureDir($this->dir);

        for ($attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++) {
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                throw new LogStoreException(sprintf('Cannot open 404 log "%s".', $file));
            }
            flock($handle, LOCK_EX);
            if (!$this->isCurrentFile($handle, $file)) {
                fclose($handle);
                continue;
            }

            $size = (int) (fstat($handle)['size'] ?? 0);
            $created = $size === 0;
            $length = strlen($line);
            $dropped = $size + $length > $this->maxDayBytes;
            if (!$dropped) {
                $written = fwrite($handle, $line);
                if ($written !== $length) {
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    throw new LogStoreException(sprintf('Short write to 404 log "%s".', $file));
                }
                fflush($handle);
            }
            if ($created) {
                @chmod($file, 0664);
            }
            flock($handle, LOCK_UN);
            fclose($handle);

            if ($dropped) {
                @file_put_contents($this->dropPath($day), 'x', FILE_APPEND);
            }
            if ($created) {
                $this->rotate();
            }

            return;
        }

        throw new LogStoreException(sprintf('404 log "%s" kept changing while writing.', $file));
    }

    public function entries(DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        foreach ($this->dayFiles(DayRange::key($fromTs), DayRange::key($toTs)) as $file) {
            foreach ($this->readFile($file) as $entry) {
                $t = $entry->time->getTimestamp();
                if ($t >= $fromTs && $t <= $toTs) {
                    yield $entry;
                }
            }
        }
    }

    public function groups(GroupQuery $q): GroupPage
    {
        $fromTs = $q->from->getTimestamp();
        $toTs = $q->to->getTimestamp();
        $files = $this->dayFiles(DayRange::key($fromTs), DayRange::key($toTs));

        // One pass over the range. Besides the per-path aggregates it remembers, for every accepted line, its path,
        // its time and where it sits ("positions"), so the totals per day and the details of the paths on the page
        // come from memory and a few seeks instead of a second pass over 50,000 lines.
        $aggregates = [];
        $pathIds = [];
        $rowPath = [];
        $rowTime = [];
        $rowPos = [];
        foreach ($this->rows($files, $fromTs, $toTs) as $pos => $row) {
            if (!$q->acceptsRow($row)) {
                continue;
            }
            $t = $row['t'];
            $path = $row['p'];
            $known = $aggregates[$path] ?? null;
            if ($known === null) {
                $aggregates[$path] = [1, $t, $t];
                $pathIds[$path] = count($pathIds);
            } else {
                $aggregates[$path] = [$known[0] + 1, min($known[1], $t), max($known[2], $t)];
            }
            $rowPath[] = $pathIds[$path];
            $rowTime[] = $t;
            $rowPos[] = $pos;
        }

        $plan = GroupPlanner::plan($aggregates, $q);
        unset($aggregates);
        $zeroDays = DayRange::zeroFilled($q->from, $q->to);
        if ($plan->visible === []) {
            return new GroupPage([], 0, new GroupTotals(0, 0, $zeroDays));
        }

        if ($q->aggregatesOnly) {
            $rows = [];
            foreach ($plan->page as $path) {
                $rows[] = (new GroupDetail())->toRow($path, $plan->visible[$path], $zeroDays);
            }

            return new GroupPage($rows, count($plan->visible), new GroupTotals($plan->hits, count($plan->visible), $zeroDays));
        }

        $visibleIds = [];
        foreach ($plan->visible as $path => $_) {
            $visibleIds[$pathIds[(string) $path]] = true;
        }
        $pageIds = [];
        foreach ($plan->page as $path) {
            $pageIds[$pathIds[$path]] = $path;
        }
        unset($pathIds);

        $dayCounts = [];
        $pageRows = [];
        foreach ($rowPath as $i => $id) {
            if (!isset($visibleIds[$id])) {
                continue;
            }
            $day = intdiv($rowTime[$i], 86400);
            $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
            if (isset($pageIds[$id])) {
                $pageRows[] = $i;
            }
        }
        $byDay = $zeroDays;
        foreach ($dayCounts as $day => $count) {
            $key = DayRange::key($day * 86400);
            $byDay[$key] = ($byDay[$key] ?? 0) + $count;
        }
        ksort($byDay);

        // Only the entries of the paths on this page become objects, read back by position.
        $details = [];
        $handles = [];
        try {
            foreach ($pageRows as $i) {
                $path = $pageIds[$rowPath[$i]];
                $row = $this->readAt($handles, $files, $rowPos[$i]);
                // A purge or a rewrite between the pass and now moved the line: skip what is not the same entry.
                if ($row !== null && $row['p'] === $path && $row['t'] === $rowTime[$i]) {
                    ($details[$path] ??= new GroupDetail())->add(NotFoundEntry::fromArray($row));
                }
            }
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        $rows = [];
        foreach ($plan->page as $path) {
            $rows[] = ($details[$path] ?? new GroupDetail())->toRow($path, $plan->visible[$path], $zeroDays);
        }

        return new GroupPage($rows, count($plan->visible), new GroupTotals($plan->hits, count($plan->visible), $byDay));
    }

    /**
     * The decoded line at a position of rows(), or null when the file or the line is gone.
     *
     * @param array<int, resource|null> $handles open files by index, filled on demand
     * @param list<string>              $files
     *
     * @return (array{t: int, p: string}&array<mixed>)|null
     */
    private function readAt(array &$handles, array $files, int $position): ?array
    {
        $index = $position >> self::POSITION_BITS;
        if (!array_key_exists($index, $handles)) {
            $handles[$index] = isset($files[$index]) ? (@fopen($files[$index], 'rb') ?: null) : null;
        }
        $handle = $handles[$index];
        if ($handle === null || fseek($handle, $position & self::POSITION_MASK) !== 0) {
            return null;
        }
        $line = fgets($handle, self::MAX_LINE);
        $row = is_string($line) ? json_decode($line, true, 8) : null;

        return is_array($row) && is_int($row['t'] ?? null) && is_string($row['p'] ?? null) ? $row : null;
    }

    public function countsByDay(DateTimeImmutable $from, DateTimeImmutable $to, bool $includeBots): array
    {
        $counts = DayRange::zeroFilled($from, $to);
        $files = $this->dayFiles(DayRange::key($from->getTimestamp()), DayRange::key($to->getTimestamp()));
        foreach ($this->rows($files, $from->getTimestamp(), $to->getTimestamp()) as $row) {
            if (!$includeBots && ($row['c'] ?? null) === UserAgentClass::Bot->value) {
                continue;
            }
            $day = DayRange::key($row['t']);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    public function purge(DateTimeImmutable $before): int
    {
        $cut = $before->getTimestamp();
        $removed = 0;
        foreach ($this->dayFiles('0000-00-00', DayRange::key($cut)) as $file) {
            $removed += $this->filterFile($file, static fn (NotFoundEntry $e): bool => $e->time->getTimestamp() >= $cut);
        }

        return $removed;
    }

    public function deletePath(string $path): int
    {
        $removed = 0;
        foreach ($this->dayFiles() as $file) {
            $removed += $this->filterFile($file, static fn (NotFoundEntry $e): bool => $e->path !== $path);
        }

        return $removed;
    }

    public function sizeBytes(): int
    {
        clearstatcache();
        $size = 0;
        foreach ($this->dayFiles() as $file) {
            $size += (int) @filesize($file);
        }

        return $size;
    }

    public function clear(): void
    {
        foreach ($this->dayFiles() as $file) {
            $this->deleteFile($file);
        }
    }

    /** Entries dropped because a day file reached its size cap (all days). */
    public function droppedCount(): int
    {
        clearstatcache();
        $count = 0;
        foreach ($this->scan() as $name) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}\.drops$/', $name) === 1) {
                $count += (int) @filesize($this->dir . '/' . $name);
            }
        }

        return $count;
    }

    /**
     * Deletes the oldest day files while the directory is above the size cap.
     * Runs automatically when a day file is created. Returns the number of deleted files.
     */
    public function rotate(): int
    {
        clearstatcache();
        $files = $this->dayFiles();
        $sizes = [];
        $total = 0;
        foreach ($files as $file) {
            $sizes[$file] = (int) @filesize($file);
            $total += $sizes[$file];
        }

        $keepToday = $this->dayPath(DayRange::key($this->clock->now()->getTimestamp()));
        $keepNewest = $files === [] ? '' : $files[count($files) - 1];
        $deleted = 0;
        foreach ($files as $file) {
            if ($total <= $this->maxBytes) {
                break;
            }
            if ($file === $keepToday || $file === $keepNewest) {
                continue;
            }
            $this->deleteFile($file);
            $total -= $sizes[$file];
            $deleted++;
        }

        return $deleted;
    }

    private function dayPath(string $day): string
    {
        return $this->dir . '/' . $day . '.jsonl';
    }

    private function dropPath(string $day): string
    {
        return $this->dir . '/' . $day . '.drops';
    }

    /** @return list<string> file names in the directory */
    private function scan(): array
    {
        $names = is_dir($this->dir) ? @scandir($this->dir) : false;

        return $names === false ? [] : $names;
    }

    /**
     * Day files (full paths), oldest first, optionally limited to days between $fromDay and $toDay.
     *
     * @return list<string>
     */
    private function dayFiles(string $fromDay = '0000-00-00', string $toDay = '9999-99-99'): array
    {
        $files = [];
        foreach ($this->scan() as $name) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.jsonl$/', $name, $m) !== 1) {
                continue;
            }
            if ($m[1] >= $fromDay && $m[1] <= $toDay) {
                $files[] = $this->dir . '/' . $name;
            }
        }
        sort($files);

        return $files;
    }

    /** @param resource $handle */
    private function isCurrentFile($handle, string $file): bool
    {
        clearstatcache(true, $file);
        $onDisk = @stat($file);
        $open = fstat($handle);

        return $onDisk !== false && $open !== false && $onDisk['ino'] === $open['ino'] && $onDisk['dev'] === $open['dev'];
    }

    /**
     * Decoded lines (keys as in NotFoundEntry::toArray()) of the given day files whose time lies in [$fromTs, $toTs].
     * The fast path for aggregations: no NotFoundEntry objects. The key of every row is its position:
     * (index in $files << POSITION_BITS) | byte offset of the line.
     *
     * @param list<string> $files
     *
     * @return Generator<int, array{t: int, p: string}&array<mixed>>
     */
    private function rows(array $files, int $fromTs, int $toTs): Generator
    {
        foreach ($files as $index => $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                // lines() and decodeRow() inlined: this loop runs once per logged entry and two generator layers cost
                // about as much as the JSON decoding itself.
                $skipping = false;
                $offset = 0;
                while (($line = fgets($handle, self::MAX_LINE)) !== false) {
                    $start = $offset;
                    $offset += strlen($line);
                    $complete = $line[strlen($line) - 1] === "\n";
                    if ($skipping) {
                        $skipping = !$complete;
                        continue;
                    }
                    if (!$complete && !feof($handle)) {
                        $skipping = true;
                        continue;
                    }
                    if ($line[0] !== '{') {
                        continue;
                    }
                    $row = json_decode($line, true, 8);
                    if (!is_array($row) || !is_int($row['t'] ?? null) || !is_string($row['p'] ?? null)) {
                        continue;
                    }
                    if ($row['t'] >= $fromTs && $row['t'] <= $toTs) {
                        yield ($index << self::POSITION_BITS) | $start => $row;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /** @return Generator<int, NotFoundEntry> */
    private function readFile(string $file): Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            foreach ($this->lines($handle) as $line) {
                $entry = $this->decode($line);
                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Complete non-empty lines; lines longer than MAX_LINE are skipped as corrupt.
     *
     * @param resource $handle
     *
     * @return Generator<int, string>
     */
    private function lines($handle): Generator
    {
        $skipping = false;
        while (($line = fgets($handle, self::MAX_LINE)) !== false) {
            $complete = str_ends_with($line, "\n");
            if ($skipping) {
                $skipping = !$complete;
                continue;
            }
            if (!$complete && !feof($handle)) {
                $skipping = true;
                continue;
            }
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                yield $line;
            }
        }
    }

    private function decode(string $line): ?NotFoundEntry
    {
        if ($line[0] !== '{') {
            return null;
        }
        $data = json_decode($line, true, 8);
        if (!is_array($data)) {
            return null;
        }
        try {
            return NotFoundEntry::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Rewrites a day file keeping only entries for which $keep returns true (corrupt lines are dropped
     * as well). Skips files where nothing is removed. Returns the number of removed entries.
     *
     * @param callable(NotFoundEntry): bool $keep
     */
    private function filterFile(string $file, callable $keep): int
    {
        $affected = false;
        foreach ($this->readFile($file) as $entry) {
            if (!$keep($entry)) {
                $affected = true;
                break;
            }
        }
        if (!$affected) {
            return 0;
        }

        $handle = @fopen($file, 'r+b');
        if ($handle === false) {
            return 0;
        }
        flock($handle, LOCK_EX);
        try {
            if (!$this->isCurrentFile($handle, $file)) {
                return 0;
            }

            $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $out = @fopen($tmp, 'xb');
            if ($out === false) {
                throw new LogStoreException(sprintf('Cannot rewrite 404 log "%s".', $file));
            }
            $removed = 0;
            $kept = 0;
            foreach ($this->lines($handle) as $line) {
                $entry = $this->decode($line);
                if ($entry === null) {
                    continue;
                }
                if ($keep($entry)) {
                    fwrite($out, $line . "\n");
                    $kept++;
                } else {
                    $removed++;
                }
            }
            fflush($out);
            fclose($out);

            if ($kept === 0) {
                @unlink($tmp);
                $this->unlinkDayFiles($file);
            } else {
                @chmod($tmp, 0664);
                if (!@rename($tmp, $file)) {
                    @unlink($tmp);

                    throw new LogStoreException(sprintf('Cannot replace 404 log "%s".', $file));
                }
            }

            return $removed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Deletes a day file (and its drop counter) while holding its lock, so that writers reopen. */
    private function deleteFile(string $file): void
    {
        $handle = @fopen($file, 'r+b');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $this->unlinkDayFiles($file);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function unlinkDayFiles(string $file): void
    {
        @unlink($file);
        @unlink(substr($file, 0, -strlen('.jsonl')) . '.drops');
    }
}
