<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Storage\AtomicFileException;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\Ids;

/**
 * user://data/redirect-manager/auto-state.json: what the auto-redirect feature keeps between requests.
 *
 *   pending  deleted pages waiting for a decision (delete policy "ask"), with the captured subtree
 *   unseen   ids of auto rules the editor has not looked at yet (sidebar badge, cleared by markSeen())
 *   resolved the last decisions, for the record
 *
 * Every change is a read-modify-write under an exclusive lock and ends in an atomic rename. A corrupt file is
 * copied to "auto-state.json.corrupt-<time>" and the state starts empty.
 */
final class AutoState
{
    public const MAX_PENDING = 200;
    public const MAX_UNSEEN = 500;
    public const MAX_RESOLVED = 50;

    private const VERSION = 1;

    public function __construct(private readonly string $file, private readonly Clock $clock)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Pending decisions, oldest first.
     *
     * @return list<PendingDelete>
     */
    public function pending(): array
    {
        return $this->pendingOf($this->read());
    }

    public function pendingCount(): int
    {
        return count($this->read()['pending']);
    }

    public function findPending(string $id): ?PendingDelete
    {
        foreach ($this->pending() as $pending) {
            if ($pending->id === $id) {
                return $pending;
            }
        }

        return null;
    }

    /**
     * Records a deleted page. A pending entry for the same route replaces the old one.
     */
    public function addPending(PageSnapshot $snapshot): PendingDelete
    {
        $entry = new PendingDelete('d' . Ids::sortable(), $snapshot, $this->clock->now());

        return $this->mutate(function (array &$state) use ($entry): PendingDelete {
            $state['pending'] = array_values(array_filter(
                $state['pending'],
                static fn (array $row): bool => (($row['snapshot']['routes'] ?? null) !== $entry->snapshot->routes),
            ));
            $state['pending'][] = $entry->toArray();
            if (count($state['pending']) > self::MAX_PENDING) {
                $state['pending'] = array_slice($state['pending'], -self::MAX_PENDING);
            }

            return $entry;
        });
    }

    /**
     * Removes a pending decision and keeps a note of what was decided. Returns the removed entry, or null when
     * the id is unknown (already resolved).
     */
    public function resolvePending(string $id, string $action, ?string $target = null): ?PendingDelete
    {
        return $this->mutate(function (array &$state) use ($id, $action, $target): ?PendingDelete {
            foreach ($state['pending'] as $i => $row) {
                if (($row['id'] ?? null) !== $id) {
                    continue;
                }
                $entry = PendingDelete::fromArray($row);
                array_splice($state['pending'], $i, 1);
                if ($entry !== null) {
                    $state['resolved'][] = [
                        'id' => $id,
                        'route' => $entry->route(),
                        'action' => $action,
                        'target' => $target,
                        'resolved_at' => $this->clock->now()->format(DATE_ATOM),
                    ];
                    $state['resolved'] = array_slice($state['resolved'], -self::MAX_RESOLVED);
                }

                return $entry;
            }

            return null;
        });
    }

    /**
     * @return list<string>
     */
    public function unseen(): array
    {
        return $this->read()['unseen'];
    }

    /**
     * @param list<string> $ruleIds
     */
    public function addUnseen(array $ruleIds): void
    {
        if ($ruleIds === []) {
            return;
        }
        $this->mutate(function (array &$state) use ($ruleIds): void {
            $state['unseen'] = array_slice(array_values(array_unique([...$state['unseen'], ...$ruleIds])), -self::MAX_UNSEEN);
        });
    }

    /**
     * @param list<string> $ruleIds
     */
    public function forgetUnseen(array $ruleIds): void
    {
        if ($ruleIds === [] || $this->read()['unseen'] === []) {
            return;
        }
        $this->mutate(function (array &$state) use ($ruleIds): void {
            $state['unseen'] = array_values(array_diff($state['unseen'], $ruleIds));
        });
    }

    /** Clears the unseen list. Returns how many ids it held. */
    public function markSeen(): int
    {
        if ($this->read()['unseen'] === []) {
            return 0;
        }

        return $this->mutate(static function (array &$state): int {
            $count = count($state['unseen']);
            $state['unseen'] = [];

            return $count;
        });
    }

    /**
     * Sidebar badge: unseen auto rules plus pending decisions.
     *
     * @param list<string>|null $existingRuleIds ids of the rules that exist now; unseen ids of deleted rules do not count
     */
    public function badgeCount(?array $existingRuleIds = null): int
    {
        $state = $this->read();
        $unseen = $state['unseen'];
        if ($existingRuleIds !== null) {
            $unseen = array_values(array_intersect($unseen, $existingRuleIds));
        }

        return count($unseen) + count($state['pending']);
    }

    /**
     * @template T
     *
     * @param callable(array{version: int, pending: list<array<string, mixed>>, unseen: list<string>, resolved: list<array<string, mixed>>}&): T $change
     *
     * @return T
     */
    private function mutate(callable $change): mixed
    {
        return AtomicFile::withLock($this->file . '.lock', function () use ($change): mixed {
            $state = $this->read(true);
            $before = $state;
            $result = $change($state);
            if ($state !== $before) {
                AtomicFile::write($this->file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            }

            return $result;
        });
    }

    /**
     * @return array{version: int, pending: list<array<string, mixed>>, unseen: list<string>, resolved: list<array<string, mixed>>}
     */
    private function read(bool $backupCorrupt = false): array
    {
        $empty = ['version' => self::VERSION, 'pending' => [], 'unseen' => [], 'resolved' => []];
        try {
            $raw = AtomicFile::read($this->file);
        } catch (AtomicFileException) {
            return $empty;
        }
        if ($raw === null || trim($raw) === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            if ($backupCorrupt) {
                @copy($this->file, $this->file . '.corrupt-' . $this->clock->now()->format('Ymd\THis'));
            }

            return $empty;
        }

        $pending = [];
        foreach (is_array($data['pending'] ?? null) ? $data['pending'] : [] as $row) {
            if (is_array($row) && PendingDelete::fromArray($row) !== null) {
                /** @var array<string, mixed> $row */
                $pending[] = $row;
            }
        }
        $unseen = [];
        foreach (is_array($data['unseen'] ?? null) ? $data['unseen'] : [] as $id) {
            if (is_string($id) && $id !== '') {
                $unseen[] = $id;
            }
        }
        $resolved = [];
        foreach (is_array($data['resolved'] ?? null) ? $data['resolved'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $resolved[] = $row;
            }
        }

        return ['version' => self::VERSION, 'pending' => $pending, 'unseen' => array_values(array_unique($unseen)), 'resolved' => $resolved];
    }

    /**
     * @param array{version: int, pending: list<array<string, mixed>>, unseen: list<string>, resolved: list<array<string, mixed>>} $state
     *
     * @return list<PendingDelete>
     */
    private function pendingOf(array $state): array
    {
        $out = [];
        foreach ($state['pending'] as $row) {
            $entry = PendingDelete::fromArray($row);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
