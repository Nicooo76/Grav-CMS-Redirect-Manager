<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;

/** All 404 hits of one path within a query. */
final readonly class GroupRow
{
    /**
     * @param array<string, int> $topReferers up to 3 referers (scheme + host + path) by count, highest first
     * @param array<string, int> $daily       "YYYY-MM-DD" => hits, one bucket per day of the range (sparkline)
     * @param array<string, int> $uaBreakdown UserAgentClass value => hits, highest first
     * @param array<string, int> $languages   language => hits, highest first
     * @param array<string, int> $hosts       host => hits, highest first
     * @param string             $sampleQuery query string of the latest hit that had one, "" if none
     */
    public function __construct(
        public string $path,
        public int $hits,
        public DateTimeImmutable $firstSeen,
        public DateTimeImmutable $lastSeen,
        public array $topReferers = [],
        public array $daily = [],
        public array $uaBreakdown = [],
        public array $languages = [],
        public array $hosts = [],
        public string $sampleQuery = '',
    ) {
    }
}
