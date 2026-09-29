<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;

/**
 * 404 paths the admin marked as done, with the time of marking ("404-state.json").
 * Pass all() as GroupQuery::$hidePaths: a resolved path stays hidden until it gets a new hit.
 *
 * File format: {"version": 1, "resolved": {"/path": "2026-09-29T10:00:00+00:00"}}. Other top-level
 * keys are preserved. A missing or unreadable file counts as empty.
 */
final class ResolvedPaths
{
    public function __construct(private readonly string $stateFile)
    {
    }

    public function markResolved(string $path, DateTimeImmutable $time): void
    {
        $this->markManyResolved([$path], $time);
    }

    /** @param list<string> $paths */
    public function markManyResolved(array $paths, DateTimeImmutable $time): void
    {
        $this->modify(static function (array $resolved) use ($paths, $time): array {
            foreach ($paths as $path) {
                $resolved[$path] = $time->format(DateTimeImmutable::ATOM);
            }

            return $resolved;
        });
    }

    public function unmark(string $path): void
    {
        $this->modify(static function (array $resolved) use ($path): array {
            unset($resolved[$path]);

            return $resolved;
        });
    }

    /** @return array<string, DateTimeImmutable> path => time it was marked, UTC */
    public function all(): array
    {
        $resolved = [];
        $stored = $this->load()['resolved'] ?? [];
        foreach (is_array($stored) ? $stored : [] as $path => $time) {
            if (!is_string($time)) {
                continue;
            }
            try {
                $resolved[(string) $path] = (new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'));
            } catch (Exception) {
                continue;
            }
        }

        return $resolved;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function modify(callable $change): void
    {
        AtomicFile::withLock($this->stateFile . '.lock', function () use ($change): void {
            $state = $this->load();
            $resolved = $state['resolved'] ?? [];
            $state['version'] = 1;
            $state['resolved'] = (object) $change(is_array($resolved) ? $resolved : []);
            AtomicFile::write(
                $this->stateFile,
                json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
            );
        });
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $raw = AtomicFile::read($this->stateFile);
        if ($raw === null) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
