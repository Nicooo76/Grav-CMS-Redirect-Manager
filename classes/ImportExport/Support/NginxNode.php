<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/** A directive of an nginx config: `name arg arg;` or `name arg { children }`. */
final readonly class NginxNode
{
    /**
     * @param list<string>          $args
     * @param list<NginxNode>|null $children null for simple directives
     */
    public function __construct(
        public string $name,
        public array $args,
        public int $line,
        public ?array $children = null,
    ) {
    }

    public function text(): string
    {
        $parts = array_map(Args::quote(...), $this->args);

        return trim($this->name . ' ' . implode(' ', $parts));
    }
}
