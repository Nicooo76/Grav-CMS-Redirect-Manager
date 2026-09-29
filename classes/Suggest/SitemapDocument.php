<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/** Result of reading one sitemap file: either a URL set or a sitemap index. */
final readonly class SitemapDocument
{
    /** @param list<string> $locations raw <loc> values, unique, in document order */
    public function __construct(
        public bool $isIndex,
        public array $locations,
    ) {
    }
}
