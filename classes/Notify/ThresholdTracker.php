<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Remembers what was already reported, so a webhook fires once and not on every run.
 *
 * 404 paths: reported when their hit count reaches the threshold, then again only after the
 * cooldown (default 7 days) has passed and the path is still at or above the threshold.
 * Dead targets: reported once; the entry is dropped as soon as the target is no longer in the
 * dead list, so a later relapse is reported again.
 *
 * State lives in one JSON file, read and written under an exclusive lock.
 */
final class ThresholdTracker
{
    public function __construct(
        private readonly string $file,
        private readonly Clock $clock,
        private readonly int $cooldownDays = 7,
    ) {
    }

    /**
     * Paths that reached the threshold and have not been reported within the cooldown.
     *
     * @param array<string, int> $hitsByPath
     *
     * @return list<string>
     */
    public function due(array $hitsByPath, int $threshold): array
    {
        $state = $this->read();
        $cutoff = $this->cutoff();
        $due = [];
        foreach ($hitsByPath as $path => $hits) {
            $path = (string) $path;
            if ($hits < $threshold) {
                continue;
            }
            $entry = $state['not_found'][$path] ?? null;
            if ($entry === null || $entry['notified_at'] <= $cutoff) {
                $due[] = $path;
            }
        }

        return $due;
    }

    /**
     * @param list<string>       $paths
     * @param array<string, int> $hitsByPath hits at the time of the report, kept for reference
     */
    public function markNotified(array $paths, array $hitsByPath = []): void
    {
        if ($paths === []) {
            return;
        }
        $this->update(function (array $state) use ($paths, $hitsByPath): array {
            $now = $this->clock->now()->getTimestamp();
            $cutoff = $this->cutoff();
            foreach ($state['not_found'] as $path => $entry) {
                if ($entry['notified_at'] <= $cutoff) {
                    unset($state['not_found'][$path]);
                }
            }
            foreach ($paths as $path) {
                $state['not_found'][$path] = ['notified_at' => $now, 'hits' => $hitsByPath[$path] ?? 0];
            }

            return $state;
        });
    }

    /**
     * Rule ids that are dead now and were not reported yet. Also forgets ids that are not dead
     * anymore, so they are reported again if they die again.
     *
     * @param list<string> $currentlyDead
     *
     * @return list<string>
     */
    public function dueDead(array $currentlyDead): array
    {
        $due = [];
        $this->update(function (array $state) use ($currentlyDead, &$due): array {
            $dead = array_flip($currentlyDead);
            foreach (array_keys($state['dead']) as $id) {
                if (!isset($dead[$id])) {
                    unset($state['dead'][$id]);
                }
            }
            foreach ($currentlyDead as $id) {
                if (!isset($state['dead'][$id])) {
                    $due[] = $id;
                }
            }

            return $state;
        });

        return array_values(array_unique($due));
    }

    /**
     * @param list<string> $ruleIds
     */
    public function markDeadNotified(array $ruleIds): void
    {
        if ($ruleIds === []) {
            return;
        }
        $this->update(function (array $state) use ($ruleIds): array {
            $now = $this->clock->now()->getTimestamp();
            foreach ($ruleIds as $id) {
                $state['dead'][$id] = ['notified_at' => $now];
            }

            return $state;
        });
    }

    private function cutoff(): int
    {
        return $this->clock->now()->getTimestamp() - $this->cooldownDays * 86400;
    }

    /**
     * @return array{not_found: array<string, array{notified_at: int, hits: int}>, dead: array<string, array{notified_at: int}>}
     */
    private function read(): array
    {
        $state = ['not_found' => [], 'dead' => []];
        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return $state;
        }
        $notFound = $data['not_found'] ?? [];
        if (is_array($notFound)) {
            foreach ($notFound as $path => $entry) {
                if (is_array($entry) && is_int($entry['notified_at'] ?? null)) {
                    $state['not_found'][(string) $path] = [
                        'notified_at' => $entry['notified_at'],
                        'hits' => is_int($entry['hits'] ?? null) ? $entry['hits'] : 0,
                    ];
                }
            }
        }
        $dead = $data['dead'] ?? [];
        if (is_array($dead)) {
            foreach ($dead as $id => $entry) {
                if (is_array($entry) && is_int($entry['notified_at'] ?? null)) {
                    $state['dead'][(string) $id] = ['notified_at' => $entry['notified_at']];
                }
            }
        }

        return $state;
    }

    /**
     * @param callable(array{not_found: array<string, array{notified_at: int, hits: int}>, dead: array<string, array{notified_at: int}>}): array{not_found: array<string, array{notified_at: int, hits: int}>, dead: array<string, array{notified_at: int}>} $mutate
     */
    private function update(callable $mutate): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $dir));
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file for "%s".', $this->file));
        }
        try {
            flock($lock, LOCK_EX);
            $before = $this->read();
            $after = $mutate($before);
            if ($after !== $before) {
                $json = json_encode(['version' => 1] + $after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $this->file)) {
                    @unlink($tmp);
                    throw new \RuntimeException(sprintf('Cannot write "%s".', $this->file));
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
