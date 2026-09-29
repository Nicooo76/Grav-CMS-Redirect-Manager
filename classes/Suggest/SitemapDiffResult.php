<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/** Outcome of comparing an old sitemap with the current pages and redirect rules. */
final readonly class SitemapDiffResult
{
    /**
     * @param int          $total      unique paths compared
     * @param int          $existing   paths that are a page route (in any language)
     * @param int          $redirected paths already covered by a redirect rule
     * @param int          $missing    paths that are neither
     * @param list<string> $missingPaths the missing paths, normalized, in input order
     */
    public function __construct(
        public int $total,
        public int $existing,
        public int $redirected,
        public int $missing,
        public array $missingPaths,
    ) {
    }

    /** @return array{total: int, existing: int, redirected: int, missing: int, missing_paths: list<string>} */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'existing' => $this->existing,
            'redirected' => $this->redirected,
            'missing' => $this->missing,
            'missing_paths' => $this->missingPaths,
        ];
    }
}
