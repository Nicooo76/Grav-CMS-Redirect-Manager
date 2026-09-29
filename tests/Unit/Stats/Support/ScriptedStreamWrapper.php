<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats\Support;

/**
 * In-memory stream wrapper whose stat() answers can be scripted, so the races HitRecorder guards against
 * (the log renamed away between fopen and flock, a short write) happen deterministically in one process.
 *
 * Register it under a protocol, call reset(), set the public statics, then hand "<protocol>://dir" to the code under test.
 */
final class ScriptedStreamWrapper
{
    /** Inode reported for every open handle. */
    public const OPEN_INODE = 1;

    /** How many upcoming url_stat() calls report a different inode than the open handle (file replaced). */
    public static int $replacedFor = 0;

    /** How many upcoming url_stat() calls report a missing file. */
    public static int $missingFor = 0;

    /** Bytes one handle accepts in total before its writes return 0 (a full disk); null accepts everything. */
    public static ?int $writeLimit = null;

    /** How many upcoming stream_open() calls fail (the directory did not exist a moment ago, or a concurrent writer is creating it). */
    public static int $failOpens = 0;

    /** Successful stream_open() calls. */
    public static int $opens = 0;

    /** @var list<string> */
    public static array $chmods = [];

    /** @var array<string, string> path => content */
    public static array $files = [];

    public mixed $context = null;

    private string $path = '';

    private int $written = 0;

    public static function reset(): void
    {
        self::$replacedFor = 0;
        self::$missingFor = 0;
        self::$writeLimit = null;
        self::$failOpens = 0;
        self::$opens = 0;
        self::$chmods = [];
        self::$files = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (self::$failOpens > 0) {
            --self::$failOpens;

            return false;
        }
        ++self::$opens;
        $this->path = $path;
        self::$files[$path] ??= '';

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $chunk = $data;
        if (self::$writeLimit !== null) {
            $chunk = substr($data, 0, max(0, self::$writeLimit - $this->written));
        }
        self::$files[$this->path] .= $chunk;
        $this->written += strlen($chunk);

        return strlen($chunk);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['ino' => self::OPEN_INODE, 'dev' => 7, 'size' => strlen(self::$files[$this->path] ?? '')];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!str_ends_with($path, '.log')) {
            // the directory: always there
            return ['mode' => 040775, 'ino' => 99, 'dev' => 7, 'size' => 0];
        }
        if (self::$missingFor > 0) {
            --self::$missingFor;

            return false;
        }
        $inode = self::OPEN_INODE;
        if (self::$replacedFor > 0) {
            --self::$replacedFor;
            $inode = self::OPEN_INODE + 1;
        }

        return ['ino' => $inode, 'dev' => 7, 'size' => strlen(self::$files[$path] ?? '')];
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        if ($option === STREAM_META_ACCESS) {
            self::$chmods[] = $path . ':' . decoct((int) $value);
        }

        return true;
    }
}
