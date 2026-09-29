<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

use DateTimeInterface;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\Ids;
use RuntimeException;

/**
 * Persists suggestions in suggestions.json ({"version": 1, "suggestions": [...]}).
 *
 * Every change is a read-modify-write under an exclusive lock on "<file>.lock" and is written atomically
 * (temp file + rename), so readers never see a half-written file. A corrupt file is moved aside to
 * "<file>.corrupt-<timestamp>" and the store starts empty; individually malformed records are skipped.
 *
 * One open record exists per path (the best suggestion). A rejected path + target pair is never suggested
 * again. Decisions are only possible on open records: repeating the same decision is idempotent, the opposite
 * one returns null. When the file grows past $maxRecords, the oldest accepted and then the lowest-scoring open
 * records are dropped; rejected records stay because they suppress re-suggestions.
 *
 * @phpstan-type Record array{id: string, path: string, target: string, score: float, reason: string, title: string, status: string, createdAt: string, decidedAt: string|null, source: string}
 */
final class SuggestionStore
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_NOT_FOUND = '404';
    public const SOURCE_SITEMAP = 'sitemap';
    public const SOURCE_CRAWLER = 'crawler';

    private const VERSION = 1;

    public function __construct(
        private readonly string $file,
        private readonly Clock $clock,
        private readonly int $maxRecords = 5000,
    ) {
    }

    /**
     * Stores the suggestion as the open record of the path unless it is weaker than the one already there.
     * Returns the resulting open record, or null when path + target was rejected before.
     *
     * @param 'sitemap'|'404'|'crawler'|string $source
     *
     * @return Record|null
     */
    public function upsertOpen(string $path, Suggestion $suggestion, string $source = self::SOURCE_NOT_FOUND): ?array
    {
        // Invalid UTF-8 would be rewritten by json_encode; scrub it up front so the path stays a stable key.
        if (preg_match('//u', $path) !== 1) {
            $path = mb_scrub($path, 'UTF-8');
        }

        return $this->mutate(function (array &$records) use ($path, $suggestion, $source): ?array {
            $openIndex = null;
            foreach ($records as $i => $record) {
                if ($record['path'] !== $path) {
                    continue;
                }
                if ($record['status'] === self::STATUS_REJECTED && $record['target'] === $suggestion->target) {
                    return null;
                }
                if ($record['status'] === self::STATUS_OPEN) {
                    $openIndex = $i;
                }
            }

            if ($openIndex !== null) {
                if ($suggestion->score > $records[$openIndex]['score']) {
                    $records[$openIndex]['target'] = $suggestion->target;
                    $records[$openIndex]['score'] = $suggestion->score;
                    $records[$openIndex]['reason'] = $suggestion->reason->value;
                    $records[$openIndex]['title'] = $suggestion->pageTitle;
                    $records[$openIndex]['source'] = $source;
                }

                return $records[$openIndex];
            }

            $record = [
                'id' => 's' . Ids::sortable(),
                'path' => $path,
                'target' => $suggestion->target,
                'score' => $suggestion->score,
                'reason' => $suggestion->reason->value,
                'title' => $suggestion->pageTitle,
                'status' => self::STATUS_OPEN,
                'createdAt' => $this->now(),
                'decidedAt' => null,
                'source' => $source,
            ];
            $records[] = $record;
            $records = $this->prune($records);

            return $record;
        });
    }

    /** @return Record|null null when the id is unknown or the record was rejected before */
    public function accept(string $id): ?array
    {
        return $this->decide($id, self::STATUS_ACCEPTED);
    }

    /** @return Record|null null when the id is unknown or the record was accepted before */
    public function reject(string $id): ?array
    {
        return $this->decide($id, self::STATUS_REJECTED);
    }

    /**
     * Accepts every open record with score >= $minScore and returns them; the caller turns them into rules.
     *
     * @return list<Record>
     */
    public function bulkAccept(float $minScore): array
    {
        return $this->mutate(function (array &$records) use ($minScore): array {
            $accepted = [];
            $now = $this->now();
            foreach ($records as $i => $record) {
                if ($record['status'] === self::STATUS_OPEN && $record['score'] >= $minScore) {
                    $records[$i]['status'] = self::STATUS_ACCEPTED;
                    $records[$i]['decidedAt'] = $now;
                    $accepted[] = $records[$i];
                }
            }

            return $accepted;
        });
    }

    /**
     * Open records, best score first.
     *
     * @return list<Record>
     */
    public function open(float $minScore = 0.0): array
    {
        $open = array_values(array_filter(
            $this->load(),
            static fn (array $r): bool => $r['status'] === self::STATUS_OPEN && $r['score'] >= $minScore,
        ));
        usort($open, static fn (array $a, array $b): int => [$b['score'], $a['path'], $a['id']] <=> [$a['score'], $b['path'], $b['id']]);

        return $open;
    }

    /** @return list<Record> in creation order */
    public function all(): array
    {
        return $this->load();
    }

    /** @return Record|null */
    public function find(string $id): ?array
    {
        foreach ($this->load() as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    public function isRejected(string $path, string $target): bool
    {
        foreach ($this->load() as $record) {
            if ($record['status'] === self::STATUS_REJECTED && $record['path'] === $path && $record['target'] === $target) {
                return true;
            }
        }

        return false;
    }

    /** @return Record|null */
    private function decide(string $id, string $status): ?array
    {
        return $this->mutate(function (array &$records) use ($id, $status): ?array {
            foreach ($records as $i => $record) {
                if ($record['id'] !== $id) {
                    continue;
                }
                if ($record['status'] === $status) {
                    return $record;
                }
                if ($record['status'] !== self::STATUS_OPEN) {
                    return null;
                }
                $records[$i]['status'] = $status;
                $records[$i]['decidedAt'] = $this->now();

                return $records[$i];
            }

            return null;
        });
    }

    /**
     * @template T
     *
     * @param callable(list<Record>&): T $change receives the records by reference
     *
     * @return T
     */
    private function mutate(callable $change): mixed
    {
        $this->ensureDirectory();
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the suggestions lock file.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the suggestions file.');
            }
            $records = $this->load();
            $before = $records;
            $result = $change($records);
            if ($records !== $before) {
                $this->write($records);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<Record> $records
     *
     * @return list<Record>
     */
    private function prune(array $records): array
    {
        $excess = count($records) - $this->maxRecords;
        if ($excess <= 0) {
            return $records;
        }

        foreach ([self::STATUS_ACCEPTED, self::STATUS_OPEN] as $status) {
            foreach ($records as $i => $record) {
                if ($excess <= 0) {
                    break 2;
                }
                if ($record['status'] === $status) {
                    unset($records[$i]);
                    --$excess;
                }
            }
        }

        return array_values($records);
    }

    /** @return list<Record> */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $json = @file_get_contents($this->file);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['suggestions'] ?? null)) {
            $this->quarantine();

            return [];
        }

        $records = [];
        foreach ($data['suggestions'] as $row) {
            $record = is_array($row) ? self::sanitize($row) : null;
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<mixed> $row
     *
     * @return Record|null
     */
    private static function sanitize(array $row): ?array
    {
        $score = $row['score'] ?? null;
        $status = $row['status'] ?? null;
        if (
            !is_string($row['id'] ?? null) || $row['id'] === ''
            || !is_string($row['path'] ?? null)
            || !is_string($row['target'] ?? null)
            || (!is_int($score) && !is_float($score))
            || !in_array($status, [self::STATUS_OPEN, self::STATUS_ACCEPTED, self::STATUS_REJECTED], true)
        ) {
            return null;
        }

        return [
            'id' => $row['id'],
            'path' => $row['path'],
            'target' => $row['target'],
            'score' => (float) $score,
            'reason' => is_string($row['reason'] ?? null) ? $row['reason'] : SuggestionReason::SimilarRoute->value,
            'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
            'status' => $status,
            'createdAt' => is_string($row['createdAt'] ?? null) ? $row['createdAt'] : '',
            'decidedAt' => is_string($row['decidedAt'] ?? null) ? $row['decidedAt'] : null,
            'source' => is_string($row['source'] ?? null) ? $row['source'] : self::SOURCE_NOT_FOUND,
        ];
    }

    private function quarantine(): void
    {
        $target = $this->file . '.corrupt-' . $this->clock->now()->format('Ymd-His');
        // Best effort: if the move fails the next write simply replaces the corrupt file.
        @rename($this->file, $target);
    }

    /** @param list<Record> $records */
    private function write(array $records): void
    {
        $json = json_encode(
            ['version' => self::VERSION, 'suggestions' => array_values($records)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        $temp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $json . "\n") === false) {
            @unlink($temp);
            throw new RuntimeException('Cannot write the suggestions file.');
        }
        @chmod($temp, 0664);
        if (!rename($temp, $this->file)) {
            @unlink($temp);
            throw new RuntimeException('Cannot replace the suggestions file.');
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $dir));
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format(DateTimeInterface::ATOM);
    }
}
