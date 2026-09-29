<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Rate limit for live checks started by hand from the admin UI or the API.
 *
 * tryAcquire() takes an exclusive flock and holds it until release() (or the end of the request),
 * so two runs never overlap. The lock file holds the Unix time of the last acquire/release; a new
 * run is refused until $minIntervalSeconds have passed since then. A crashed run releases the
 * flock with its process and only the interval remains.
 */
final class RunGuard
{
    /** @var resource|null */
    private $handle;

    public function __construct(
        private readonly string $lockFile,
        private readonly Clock $clock,
        private readonly int $minIntervalSeconds = 300,
    ) {
    }

    public function __destruct()
    {
        $this->release();
    }

    /** True when the caller may run now and now holds the lock. */
    public function tryAcquire(): bool
    {
        if ($this->handle !== null) {
            return false;
        }
        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $now = $this->clock->now()->getTimestamp();
        $last = $this->readTimestamp($handle);
        if ($last !== null && $now - $last < $this->minIntervalSeconds) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }

        $this->writeTimestamp($handle, $now);
        $this->handle = $handle;

        return true;
    }

    /** Ends a run: stamps the finish time and frees the lock. Harmless without a prior acquire. */
    public function release(): void
    {
        $handle = $this->handle;
        if ($handle === null) {
            return;
        }
        $this->handle = null;
        $this->writeTimestamp($handle, $this->clock->now()->getTimestamp());
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** Seconds until the next run is allowed (0 when it is allowed now). For Retry-After headers. */
    public function retryAfter(): int
    {
        $raw = is_file($this->lockFile) ? @file_get_contents($this->lockFile) : false;
        if ($raw === false || !ctype_digit(trim($raw))) {
            return 0;
        }

        return max(0, (int) trim($raw) + $this->minIntervalSeconds - $this->clock->now()->getTimestamp());
    }

    /**
     * @param resource $handle
     */
    private function readTimestamp($handle): ?int
    {
        rewind($handle);
        $raw = trim((string) stream_get_contents($handle));

        return ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * @param resource $handle
     */
    private function writeTimestamp($handle, int $timestamp): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $timestamp);
        fflush($handle);
    }
}
