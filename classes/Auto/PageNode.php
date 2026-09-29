<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/**
 * One page inside a snapshot: where it lives (folder path relative to the snapshot root, stable across
 * renames and moves) and its public route per language.
 */
final readonly class PageNode
{
    /**
     * @param string                $key    folder path relative to the snapshot root, "" for the root itself
     * @param array<string, string> $routes language => route without language prefix; PageSnapshot::ANY on single-language sites
     */
    public function __construct(public string $key, public array $routes)
    {
    }

    /**
     * @return array{key: string, routes: array<string, string>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'routes' => $this->routes];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_scalar($data['key'] ?? null) ? (string) $data['key'] : '',
            PageSnapshot::stringMap($data['routes'] ?? []),
        );
    }
}
