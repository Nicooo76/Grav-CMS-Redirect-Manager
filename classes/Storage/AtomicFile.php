<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use Throwable;

/**
 * Crash-safe file writes (temp file + rename) and advisory locking.
 *
 * Readers never see a half-written document: write() renames a fully written temp file
 * over the target. Locks live in separate ".lock" files so that the rename does not
 * invalidate the locked inode.
 */
final class AtomicFile
{
    public const DEFAULT_LOCK_TIMEOUT = 5.0;

    private const DIR_MODE = 0775;
    private const FILE_MODE = 0664;

    /**
     * Replaces the file with the given content atomically.
     *
     * @throws AtomicFileException when the directory, temp file or rename fails; the temp file is removed
     */
    public static function write(string $path, string $content): void
    {
        $dir = dirname($path);
        self::ensureDir($dir);

        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new AtomicFileException(sprintf('Cannot create temp file next to "%s".', $path));
        }

        try {
            $length = strlen($content);
            $written = 0;
            while ($written < $length) {
                $n = @fwrite($handle, substr($content, $written));
                if ($n === false || $n === 0) {
                    throw new AtomicFileException(sprintf('Cannot write to temp file for "%s".', $path));
                }
                $written += $n;
            }
            if (!fflush($handle)) {
                throw new AtomicFileException(sprintf('Cannot flush temp file for "%s".', $path));
            }
            @fsync($handle);
            fclose($handle);
            $handle = null;

            $mode = is_file($path) ? (@fileperms($path) ?: self::FILE_MODE) & 0777 : self::FILE_MODE;
            @chmod($tmp, $mode);

            if (!@rename($tmp, $path)) {
                throw new AtomicFileException(sprintf('Cannot move temp file over "%s".', $path));
            }
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);

            throw $e instanceof AtomicFileException
                ? $e
                : new AtomicFileException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns the file content, or null when the file does not exist.
     *
     * @throws AtomicFileException when the file exists but cannot be read
     */
    public static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            if (!file_exists($path)) {
                return null;
            }

            throw new AtomicFileException(sprintf('Cannot read "%s".', $path));
        }

        return $content;
    }

    /**
     * Runs $fn while holding an flock() on $lockPath (a separate file, created on demand).
     * Polls without blocking until $timeout seconds have passed, then throws.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     *
     * @throws LockTimeoutException
     * @throws AtomicFileException
     */
    public static function withLock(
        string $lockPath,
        callable $fn,
        bool $exclusive = true,
        float $timeout = self::DEFAULT_LOCK_TIMEOUT,
    ): mixed {
        self::ensureDir(dirname($lockPath));

        $existed = file_exists($lockPath);
        $handle = @fopen($lockPath, 'cb');
        if ($handle === false) {
            throw new AtomicFileException(sprintf('Cannot open lock file "%s".', $lockPath));
        }
        if (!$existed) {
            @chmod($lockPath, self::FILE_MODE);
        }

        $operation = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
        $delay = 500;
        while (!flock($handle, $operation)) {
            if (hrtime(true) >= $deadline) {
                fclose($handle);

                throw new LockTimeoutException(sprintf('Timed out waiting for lock "%s".', $lockPath));
            }
            usleep($delay);
            $delay = min($delay * 2, 20_000);
        }

        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Creates the directory (mode 0775, recursive) when it does not exist. */
    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new AtomicFileException(sprintf('Cannot create directory "%s".', $dir));
        }
    }
}
