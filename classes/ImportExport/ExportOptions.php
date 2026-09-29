<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

final readonly class ExportOptions
{
    /**
     * @param string|null    $group      only rules of this group (empty string = rules without group)
     * @param list<int>|null $statuses   only rules with one of these status codes
     * @param string|null    $exportHost host for formats that need absolute sources or targets (Cloudflare)
     */
    public function __construct(
        public bool $onlyEnabled = true,
        public ?string $group = null,
        public ?array $statuses = null,
        public ?string $exportHost = null,
        public bool $includeHeader = true,
    ) {
    }
}
