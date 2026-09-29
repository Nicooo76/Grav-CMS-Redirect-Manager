<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;
use Generator;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Util\Clock;
use PDO;
use PDOException;
use PDOStatement;

/**
 * 404 log in one SQLite file (WAL mode, busy timeout 3 s). Same semantics as JsonlLogStore.
 * Only usable when pdo_sqlite is loaded, check isAvailable() first.
 *
 * Columns: id, t (unix seconds), day (UTC "YYYY-MM-DD"), path, query, referer, ua, class, ip, lang, host, method.
 * Indexes on t and path. The path search of groups() runs in PHP on the grouped paths
 * (mb_stripos), so it behaves exactly like the JSONL store for non-ASCII paths.
 */
final class SqliteLogStore implements LogStore
{
    private const BUSY_TIMEOUT_MS = 3000;
    private const INIT_ATTEMPTS = 20;

    private ?PDO $pdo = null;

    private ?PDOStatement $insert = null;

    public function __construct(
        private readonly string $dbFile,
        private readonly Clock $clock,
    ) {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    public function append(NotFoundEntry $e): void
    {
        $insert = $this->insert ??= $this->pdo()->prepare(
            'INSERT INTO log (t, day, path, query, referer, ua, class, ip, lang, host, method)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $t = $e->time->getTimestamp();
        $insert->execute([
            $t,
            DayRange::key($t),
            $e->path,
            $e->query,
            $e->referer,
            $e->userAgent,
            $e->uaClass->value,
            $e->ip,
            $e->language,
            $e->host,
            $e->method,
        ]);
    }

    public function entries(DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $stmt = $this->pdo()->prepare(
            'SELECT t, path, query, referer, ua, class, ip, lang, host, method FROM log'
            . ' WHERE t >= ? AND t <= ? ORDER BY t, id',
        );
        $stmt->execute([$from->getTimestamp(), $to->getTimestamp()]);

        return $this->hydrate($stmt);
    }

    public function groups(GroupQuery $q): GroupPage
    {
        [$where, $params] = $this->where($q);

        $stmt = $this->pdo()->prepare(
            'SELECT path, COUNT(*), MIN(t), MAX(t) FROM log WHERE ' . $where . ' GROUP BY path',
        );
        $stmt->execute($params);
        $aggregates = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $aggregates[(string) $row[0]] = [(int) $row[1], (int) $row[2], (int) $row[3]];
        }
        $stmt->closeCursor();

        $plan = GroupPlanner::plan($aggregates, $q);
        unset($aggregates);
        $zeroDays = DayRange::zeroFilled($q->from, $q->to);
        if ($plan->visible === []) {
            return new GroupPage([], 0, new GroupTotals(0, 0, $zeroDays));
        }

        $byDay = $zeroDays;
        $stmt = $this->pdo()->prepare(
            'SELECT path, day, COUNT(*) FROM log WHERE ' . $where . ' GROUP BY path, day',
        );
        $stmt->execute($params);
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (isset($plan->visible[(string) $row[0]])) {
                $day = (string) $row[1];
                $byDay[$day] = ($byDay[$day] ?? 0) + (int) $row[2];
            }
        }
        $stmt->closeCursor();
        ksort($byDay);

        $details = [];
        if ($plan->page !== []) {
            $marks = implode(',', array_fill(0, count($plan->page), '?'));
            $stmt = $this->pdo()->prepare(
                'SELECT t, path, query, referer, ua, class, ip, lang, host, method FROM log WHERE '
                . $where . ' AND path IN (' . $marks . ') ORDER BY t, id',
            );
            $stmt->execute([...$params, ...$plan->page]);
            foreach ($this->hydrate($stmt) as $entry) {
                ($details[$entry->path] ??= new GroupDetail())->add($entry);
            }
        }

        $rows = [];
        foreach ($plan->page as $path) {
            $rows[] = ($details[$path] ?? new GroupDetail())->toRow($path, $plan->visible[$path], $zeroDays);
        }

        return new GroupPage($rows, count($plan->visible), new GroupTotals($plan->hits, count($plan->visible), $byDay));
    }

    public function countsByDay(DateTimeImmutable $from, DateTimeImmutable $to, bool $includeBots): array
    {
        $counts = DayRange::zeroFilled($from, $to);
        $sql = 'SELECT day, COUNT(*) FROM log WHERE t >= ? AND t <= ?'
            . ($includeBots ? '' : " AND class != 'bot'") . ' GROUP BY day';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$from->getTimestamp(), $to->getTimestamp()]);
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $counts[(string) $row[0]] = (int) $row[1];
        }
        ksort($counts);

        return $counts;
    }

    public function purge(DateTimeImmutable $before): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM log WHERE t < ?');
        $stmt->execute([$before->getTimestamp()]);

        return $stmt->rowCount();
    }

    /**
     * Housekeeping: deletes entries older than $retentionDays days (0 = keep all), folds the WAL back into
     * the database file and lets SQLite reuse freed pages. Returns the number of deleted entries.
     */
    public function maintain(int $retentionDays = 0): int
    {
        $removed = $retentionDays > 0
            ? $this->purge($this->clock->now()->modify('-' . $retentionDays . ' days'))
            : 0;
        $this->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->pdo()->exec('PRAGMA optimize');

        return $removed;
    }

    public function deletePath(string $path): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM log WHERE path = ?');
        $stmt->execute([$path]);

        return $stmt->rowCount();
    }

    public function sizeBytes(): int
    {
        clearstatcache();
        $size = 0;
        foreach ([$this->dbFile, $this->dbFile . '-wal'] as $file) {
            $size += (int) @filesize($file);
        }

        return $size;
    }

    public function clear(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM log');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('VACUUM');
    }

    /**
     * WHERE clause for time range, bots, classes, language and host (the filters GroupQuery::acceptsEntry() applies).
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(GroupQuery $q): array
    {
        $sql = 't >= ? AND t <= ?';
        $params = [$q->from->getTimestamp(), $q->to->getTimestamp()];
        if (!$q->includeBots) {
            $sql .= ' AND class != ?';
            $params[] = UserAgentClass::Bot->value;
        }
        if ($q->uaClasses !== []) {
            $sql .= ' AND class IN (' . implode(',', array_fill(0, count($q->uaClasses), '?')) . ')';
            foreach ($q->uaClasses as $class) {
                $params[] = $class->value;
            }
        }
        if ($q->language !== null) {
            $sql .= ' AND lang = ?';
            $params[] = $q->language;
        }
        if ($q->host !== null) {
            $sql .= ' AND host = ?';
            $params[] = $q->host;
        }

        return [$sql, $params];
    }

    /** @return Generator<int, NotFoundEntry> */
    private function hydrate(PDOStatement $stmt): Generator
    {
        try {
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                yield new NotFoundEntry(
                    new DateTimeImmutable('@' . (int) $row[0]),
                    (string) $row[1],
                    (string) $row[2],
                    (string) $row[3],
                    (string) $row[4],
                    UserAgentClass::tryFrom((string) $row[5]) ?? UserAgentClass::Unknown,
                    $row[6] === null ? null : (string) $row[6],
                    $row[7] === null ? null : (string) $row[7],
                    (string) $row[8],
                    (string) $row[9],
                );
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        AtomicFile::ensureDir(dirname($this->dbFile));

        $lastError = null;
        for ($attempt = 0; $attempt < self::INIT_ATTEMPTS; $attempt++) {
            try {
                $pdo = new PDO('sqlite:' . $this->dbFile, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
                $this->initSchema($pdo);

                return $this->pdo = $pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                usleep(50_000);
            }
        }

        throw new LogStoreException(sprintf('Cannot open 404 database "%s".', $this->dbFile), 0, $lastError);
    }

    private function initSchema(PDO $pdo): void
    {
        $mode = $pdo->query('PRAGMA journal_mode');
        $current = '';
        if ($mode !== false) {
            $current = strtolower((string) $mode->fetchColumn());
            $mode->closeCursor();
        }
        if ($current !== 'wal') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'log'");
        $found = false;
        if ($exists !== false) {
            $found = $exists->fetchColumn() !== false;
            $exists->closeCursor();
        }
        if ($found) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, t INTEGER NOT NULL, day TEXT NOT NULL, path TEXT NOT NULL,'
            . ' query TEXT NOT NULL, referer TEXT NOT NULL, ua TEXT NOT NULL, class TEXT NOT NULL, ip TEXT,'
            . ' lang TEXT, host TEXT NOT NULL, method TEXT NOT NULL)',
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_t ON log (t)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_path ON log (path)');
    }
}
