<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Stats;

use Closure;
use Grav\Plugin\RedirectManager\NotFound\DayRange;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Util\Clock;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records one redirect hit as one line "ruleId\n" in "<dir>/YYYY-MM-DD.log" (UTC day).
 *
 * Cost per hit: open, flock, one stat, write, close. StatsStore::aggregate() renames the file away
 * while writers may be active, so after locking the writer checks that its handle still belongs
 * to the file at the path and reopens otherwise. That makes aggregation lossless: a writer that holds the
 * lock and passed the check finishes before the aggregator, which locks the renamed file, reads it; a writer
 * that opened the old file but locks it later sees a different file at the path and starts over.
 */
final class HitRecorder
{
    /**
     * Every failed attempt means an aggregation renamed the file since this writer opened it, so a writer only
     * runs out of attempts when aggregations follow each other without a break for a long time. The limit exists
     * so that a broken file system cannot spin forever, not to be reached: a hit dropped here is a lost hit.
     */
    private const MAX_ATTEMPTS = 1000;

    /**
     * @param Closure(int): void|null $afterOpen test hook: called with the attempt number after the log was opened and
     *                                             before it is locked, i.e. inside the window in which an aggregation
     *                                             can rename the file away. Never set in production.
     */
    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly ?Closure $afterOpen = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the id is empty or contains characters other than A-Z a-z 0-9 . _ : -
     * @throws RuntimeException         when the file cannot be written
     */
    public function record(string $ruleId): void
    {
        if (preg_match(StatsStore::ID_PATTERN, $ruleId) !== 1) {
            throw new InvalidArgumentException('Invalid rule id for hit recording.');
        }

        $file = $this->dir . '/' . DayRange::key($this->clock->now()->getTimestamp()) . '.log';
        $line = $ruleId . "\n";

        $dirEnsured = false;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                // The directory may be missing, or another writer may just have created it: create it (a no-op
                // when it exists) and retry once, and only then call the file unopenable.
                if (!$dirEnsured) {
                    $dirEnsured = true;
                    AtomicFile::ensureDir($this->dir);
                    continue;
                }

                throw new RuntimeException(sprintf('Cannot open hit log "%s".', $file));
            }
            if ($this->afterOpen !== null) {
                ($this->afterOpen)($attempt);
            }
            flock($handle, LOCK_EX);
            clearstatcache(true, $file);
            $onDisk = @stat($file);
            $open = fstat($handle);
            if ($onDisk === false || $open === false || $onDisk['ino'] !== $open['ino'] || $onDisk['dev'] !== $open['dev']) {
                fclose($handle);
                continue;
            }

            if ($open['size'] === 0) {
                @chmod($file, 0664);
            }
            $ok = fwrite($handle, $line) === strlen($line);
            flock($handle, LOCK_UN);
            fclose($handle);
            if (!$ok) {
                throw new RuntimeException(sprintf('Short write to hit log "%s".', $file));
            }

            return;
        }

        throw new RuntimeException(sprintf('Hit log "%s" kept changing while writing.', $file));
    }
}
