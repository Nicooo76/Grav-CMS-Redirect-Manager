<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

/** A block that is still being read by NginxConfig. */
final class NginxFrame
{
    /** @var list<NginxNode> */
    public array $children = [];

    /**
     * @param list<string> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly int $line,
    ) {
    }

    public function toNode(): NginxNode
    {
        return new NginxNode($this->name, $this->args, $this->line, $this->children);
    }
}
