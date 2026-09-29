<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;

final readonly class ImportOptions
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const DEFAULT_MAX_ROWS = 50000;

    /**
     * @param array<string, int|string> $csvMapping  field => column index (0-based) or header name; empty = auto-detect
     * @param string|null               $delimiter   null = auto-detect (, ; tab |)
     * @param bool|null                 $hasHeader   null = auto-detect
     * @param list<Rule>                $existingRules for duplicate detection
     * @param list<string>              $siteLanguages language codes whose URL prefix is moved into conditions.languages
     * @param list<string>              $baseHosts     hosts that count as "this site"; absolute URLs on them become paths
     * @param int                       $redirectDefaultCode Grav's system.pages.redirect_default_code (site.yaml redirects)
     */
    public function __construct(
        public array $csvMapping = [],
        public ?string $delimiter = null,
        public ?bool $hasHeader = null,
        public int $defaultStatus = 301,
        public string $defaultGroup = '',
        public RuleSource $origin = RuleSource::Import,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxRows = self::DEFAULT_MAX_ROWS,
        public array $existingRules = [],
        public array $siteLanguages = [],
        public array $baseHosts = [],
        public int $redirectDefaultCode = 302,
    ) {
    }
}
