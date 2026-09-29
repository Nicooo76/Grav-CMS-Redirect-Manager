<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Stats;

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
 * to the file at the path and reopens otherwise. That makes aggregation lossless.
 */
final class HitRecorder
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
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

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                if (!is_dir($this->dir)) {
                    AtomicFile::ensureDir($this->dir);
                    continue;
                }

                throw new RuntimeException(sprintf('Cannot open hit log "%s".', $file));
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
