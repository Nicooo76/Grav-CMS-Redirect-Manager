<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

use DateTimeImmutable;

/** The numbers behind one digest mail. Collected by the caller, formatted by DigestBuilder. */
final readonly class DigestData
{
    /**
     * @param array<string, int>                                                                       $notFoundTopPaths path => hits, most requested first (only the first 10 are shown)
     * @param list<array{id: string, source: string, hits: int}>                                       $topRules         most used rules (only the first 10 are shown)
     * @param list<array{source: string, target: string, status?: int|null, error?: string|null}>      $deadTargets      redirect targets that do not answer
     */
    public function __construct(
        public DigestPeriod $period,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $siteName,
        public string $siteUrl,
        public int $notFoundTotal,
        public array $notFoundTopPaths = [],
        public int $newPaths = 0,
        public int $redirectHits = 0,
        public array $topRules = [],
        public int $openSuggestions = 0,
        public array $deadTargets = [],
        public string $adminUrl = '',
    ) {
    }
}
