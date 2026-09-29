<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use DateTimeImmutable;
use Throwable;

/** A deleted page waiting for the editor's decision (auto_redirect.on_delete = ask). */
final readonly class PendingDelete
{
    public function __construct(
        public string $id,
        public PageSnapshot $snapshot,
        public DateTimeImmutable $deletedAt,
    ) {
    }

    public function title(): string
    {
        return $this->snapshot->title;
    }

    /**
     * @return array<string, string> language => route
     */
    public function routes(): array
    {
        return $this->snapshot->routes;
    }

    public function route(): string
    {
        return $this->snapshot->primaryRoute();
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->snapshot->languages();
    }

    /**
     * Routes of the descendants that were deleted with the page (first language of each).
     *
     * @return list<string>
     */
    public function children(): array
    {
        $out = [];
        foreach ($this->snapshot->descendants as $node) {
            foreach ($node->routes as $route) {
                $out[] = $route;
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{id: string, snapshot: array<string, mixed>, deleted_at: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'snapshot' => $this->snapshot->toArray(), 'deleted_at' => $this->deletedAt->format(DATE_ATOM)];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['id'] ?? null) || $data['id'] === '' || !is_array($data['snapshot'] ?? null)) {
            return null;
        }
        try {
            $at = new DateTimeImmutable(is_string($data['deleted_at'] ?? null) ? $data['deleted_at'] : 'now');
        } catch (Throwable) {
            return null;
        }

        return new self($data['id'], PageSnapshot::fromArray($data['snapshot']), $at);
    }
}
