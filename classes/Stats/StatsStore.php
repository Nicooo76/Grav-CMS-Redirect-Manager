<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Stats;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Grav\Plugin\RedirectManager\NotFound\DayRange;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Folds the append-only hit logs of HitRecorder into "stats.json".
 *
 * stats.json: {"version": 1, "rules": {"<id>": {"total": n, "last_hit": ATOM, "daily": {"YYYY-MM-DD": n}}},
 * "processed": ["<file name>", ...]}
 *
 * aggregate() is safe against concurrent recording, concurrent aggregation and crashes:
 * - It runs under an exclusive lock ("<statsFile>.lock"), so only one aggregation runs at a time.
 * - Every pending hit file, today's included, is renamed to "<day>.processing.<random>"; recorders
 *   reopen a new file (see HitRecorder). Before reading a renamed file we take its lock, which waits
 *   for a recorder that was mid-write.
 * - The names of merged files are stored in stats.json in the same atomic write as the new counts and
 *   only then deleted. A crash after the write leaves a file that is recognized as merged and deleted
 *   next time; a crash before the write leaves a file that is merged next time. Nothing is counted twice or lost.
 * - Corrupt stats.json is moved to "stats.json.corrupt-<timestamp>" and counting restarts.
 *
 * Daily buckets are kept for $keepDays days (today and the $keepDays - 1 before it); totals are never trimmed.
 * Buckets are trimmed whenever aggregate() writes. Read methods work on the file as last written, so call
 * aggregate() first when pending hits must be included.
 */
final class StatsStore
{
    public const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** Seconds a stats.json must be old before all() keeps its decoded content (see all()). */
    private const MEMO_AFTER = 2;

    /** @var array{signature: array{int, int, int}, rules: array<string, RuleStats>}|null */
    private ?array $memo = null;

    public function __construct(
        private readonly string $statsFile,
        private readonly string $hitsDir,
        private readonly Clock $clock,
        private readonly int $keepDays = 90,
    ) {
    }

    /**
     * Merges all pending hits into stats.json.
     *
     * @return int number of hits merged by this call
     */
    public function aggregate(): int
    {
        $this->memo = null;

        return AtomicFile::withLock($this->lockPath(), fn (): int => $this->aggregateLocked());
    }

    public function forRule(string $ruleId): RuleStats
    {
        return $this->all()[$ruleId] ?? new RuleStats();
    }

    /**
     * Statistics of every rule. Building them means decoding stats.json (about 30 ms for 3,000 rules with a month of
     * daily buckets) and one list request asks several times, so the result is kept for as long as the file is
     * unchanged (same modification time, size and inode). A file written within the last two seconds is not kept:
     * the modification time has a resolution of one second, so an edit in the same second would go unnoticed.
     *
     * @return array<string, RuleStats>
     */
    public function all(): array
    {
        clearstatcache(true, $this->statsFile);
        $stat = @stat($this->statsFile);
        $signature = $stat === false ? null : [$stat['mtime'], $stat['size'], $stat['ino']];
        if ($signature !== null && $this->memo !== null && $this->memo['signature'] === $signature) {
            return $this->memo['rules'];
        }

        $result = [];
        foreach ($this->load()['rules'] as $id => $rule) {
            $result[(string) $id] = $this->toRuleStats($rule);
        }
        $this->memo = $signature !== null && time() - $signature[0] > self::MEMO_AFTER ? ['signature' => $signature, 'rules' => $result] : null;

        return $result;
    }

    /**
     * Ids from $createdAtById that have not been hit since $since.
     * - Hit before: unused. Hit on or after: used (past days count as hit at the end of that day).
     * - Never hit: unused only when the rule was created before $since. Unknown creation time (null)
     *   is never reported, a young rule must not be flagged.
     *
     * @param array<string, DateTimeImmutable|null> $createdAtById rule id => creation time
     *
     * @return list<string>
     */
    public function unusedSince(array $createdAtById, DateTimeImmutable $since): array
    {
        $stats = $this->all();
        $unused = [];
        foreach ($createdAtById as $id => $createdAt) {
            $id = (string) $id;
            $lastHit = isset($stats[$id]) ? $stats[$id]->lastHit : null;
            if ($lastHit !== null) {
                if ($lastHit < $since) {
                    $unused[] = $id;
                }
            } elseif ($createdAt !== null && $createdAt < $since) {
                $unused[] = $id;
            }
        }

        return $unused;
    }

    /**
     * Hits of all rules per UTC day, zero-filled (at most 366 days ending at $to).
     *
     * @return array<string, int>
     */
    public function totalsByDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = DayRange::zeroFilled($from, $to);
        $fromDay = DayRange::key($from->getTimestamp());
        $toDay = DayRange::key($to->getTimestamp());
        foreach ($this->all() as $rule) {
            foreach ($rule->daily as $day => $count) {
                $day = (string) $day;
                if ($day >= $fromDay && $day <= $toDay) {
                    $days[$day] = ($days[$day] ?? 0) + $count;
                }
            }
        }
        ksort($days);

        return $days;
    }

    /**
     * Drops the statistics of rules (call when rules are deleted).
     *
     * @param list<string> $ids
     */
    public function forget(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->memo = null;
        AtomicFile::withLock($this->lockPath(), function () use ($ids): void {
            $state = $this->load(true);
            $before = count($state['rules']);
            foreach ($ids as $id) {
                unset($state['rules'][$id]);
            }
            if (count($state['rules']) !== $before) {
                $this->save($state);
            }
        });
    }

    private function lockPath(): string
    {
        return $this->statsFile . '.lock';
    }

    private function aggregateLocked(): int
    {
        $state = $this->load(true);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $today = DayRange::key($now->getTimestamp());

        foreach ($this->pendingFiles() as $name => $day) {
            @rename($this->hitsDir . '/' . $name . '.log', $this->hitsDir . '/' . $day . '.processing.' . bin2hex(random_bytes(4)));
        }

        $previous = $state['processed'];
        $alreadyMerged = array_flip($previous);
        $state['processed'] = [];
        $merged = 0;
        $changed = false;
        $delete = [];

        foreach ($this->processingFiles() as $name => $day) {
            if (isset($alreadyMerged[$name])) {
                // Merged by an aggregation that crashed before deleting the file: keep the name until it is gone.
                $state['processed'][] = $name;
                $delete[] = $name;
                continue;
            }
            $counts = $this->readCounts($this->hitsDir . '/' . $name);
            if ($counts === null) {
                continue;
            }
            foreach ($counts as $id => $count) {
                $id = (string) $id;
                $rule = $state['rules'][$id] ?? ['total' => 0, 'last_hit' => null, 'daily' => []];
                $rule['total'] += $count;
                $rule['daily'][$day] = ($rule['daily'][$day] ?? 0) + $count;
                $hitAt = $day >= $today ? $now->getTimestamp() : (int) strtotime($day . ' 23:59:59 UTC');
                $last = $rule['last_hit'] === null ? null : (int) strtotime($rule['last_hit']);
                $rule['last_hit'] = gmdate(DateTimeImmutable::ATOM, $last === null ? $hitAt : max($last, $hitAt));
                $state['rules'][$id] = $rule;
                $merged += $count;
            }
            $state['processed'][] = $name;
            $delete[] = $name;
            $changed = true;
        }

        if ($changed) {
            $this->trim($state, $today);
            $this->save($state);
        } elseif ($state['processed'] !== $previous) {
            $this->save($state);
        }

        foreach ($delete as $name) {
            @unlink($this->hitsDir . '/' . $name);
        }

        return $merged;
    }

    /**
     * @param array{version: int, rules: array<string, array{total: int, last_hit: string|null, daily: array<string, int>}>, processed: list<string>} $state
     */
    private function trim(array &$state, string $today): void
    {
        $cutoff = gmdate('Y-m-d', (int) strtotime($today . ' UTC') - (max(1, $this->keepDays) - 1) * 86400);
        foreach ($state['rules'] as $id => $rule) {
            foreach (array_keys($rule['daily']) as $day) {
                if ((string) $day < $cutoff) {
                    unset($state['rules'][$id]['daily'][$day]);
                }
            }
        }
    }

    /** @return array<string, string> file name without ".log" => day */
    private function pendingFiles(): array
    {
        $files = [];
        foreach ($this->listDir() as $name) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', $name, $m) === 1) {
                $files[$m[1]] = $m[1];
            }
        }

        return $files;
    }

    /** @return array<string, string> file name => day */
    private function processingFiles(): array
    {
        $files = [];
        foreach ($this->listDir() as $name) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.processing\.[a-f0-9]+$/', $name, $m) === 1) {
                $files[$name] = $m[1];
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function listDir(): array
    {
        $names = is_dir($this->hitsDir) ? @scandir($this->hitsDir) : false;

        return $names === false ? [] : $names;
    }

    /**
     * Hit counts per rule id in a renamed hit file. Takes the file lock first so that a recorder that
     * opened the file before the rename has finished writing. Invalid lines are ignored.
     *
     * @return array<string, int>|null null when the file cannot be opened
     */
    private function readCounts(string $file): ?array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        flock($handle, LOCK_EX);
        $counts = [];
        while (($line = fgets($handle, 4096)) !== false) {
            $id = rtrim($line, "\r\n");
            if (preg_match(self::ID_PATTERN, $id) === 1) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        return $counts;
    }

    /**
     * @return array{version: int, rules: array<string, array{total: int, last_hit: string|null, daily: array<string, int>}>, processed: list<string>}
     */
    private function load(bool $quarantineCorrupt = false): array
    {
        $empty = ['version' => 1, 'rules' => [], 'processed' => []];
        $raw = AtomicFile::read($this->statsFile);
        if ($raw === null) {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            if ($quarantineCorrupt) {
                @rename($this->statsFile, $this->statsFile . '.corrupt-' . gmdate('YmdHis'));
            }

            return $empty;
        }

        $rules = [];
        $source = $data['rules'] ?? [];
        foreach (is_array($source) ? $source : [] as $id => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $daily = [];
            $rawDaily = $rule['daily'] ?? [];
            foreach (is_array($rawDaily) ? $rawDaily : [] as $day => $count) {
                if (is_int($count) && $count > 0) {
                    $daily[(string) $day] = $count;
                }
            }
            $lastHit = $rule['last_hit'] ?? null;
            $rules[(string) $id] = [
                'total' => is_int($rule['total'] ?? null) ? $rule['total'] : 0,
                'last_hit' => is_string($lastHit) ? $lastHit : null,
                'daily' => $daily,
            ];
        }

        $processed = [];
        $rawProcessed = $data['processed'] ?? [];
        foreach (is_array($rawProcessed) ? $rawProcessed : [] as $name) {
            if (is_string($name)) {
                $processed[] = $name;
            }
        }

        return ['version' => 1, 'rules' => $rules, 'processed' => $processed];
    }

    /**
     * @param array{version: int, rules: array<string, array{total: int, last_hit: string|null, daily: array<string, int>}>, processed: list<string>} $state
     */
    private function save(array $state): void
    {
        $out = ['version' => 1, 'rules' => [], 'processed' => $state['processed']];
        foreach ($state['rules'] as $id => $rule) {
            $out['rules'][$id] = [
                'total' => $rule['total'],
                'last_hit' => $rule['last_hit'],
                'daily' => (object) $rule['daily'],
            ];
        }
        $out['rules'] = (object) $out['rules'];

        AtomicFile::write($this->statsFile, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /** @param array{total: int, last_hit: string|null, daily: array<string, int>} $rule */
    private function toRuleStats(array $rule): RuleStats
    {
        $lastHit = null;
        if ($rule['last_hit'] !== null) {
            try {
                $lastHit = (new DateTimeImmutable($rule['last_hit']))->setTimezone(new DateTimeZone('UTC'));
            } catch (Exception) {
                $lastHit = null;
            }
        }
        $daily = $rule['daily'];
        ksort($daily);

        return new RuleStats($rule['total'], $lastHit, $daily);
    }
}
