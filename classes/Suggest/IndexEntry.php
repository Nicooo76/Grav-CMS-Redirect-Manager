<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/**
 * A target page plus everything precomputed for matching.
 *
 * @internal used by PageIndex and Suggester
 */
final readonly class IndexEntry
{
    /**
     * @param string                $normRoute       normalized route, e.g. "/ueber-uns"
     * @param string                $normSlug        normalized slug of the page
     * @param string                $lastSegment     last segment of the normalized route ("" for "/")
     * @param list<string>          $titleTokens     unique normalized title tokens
     * @param list<list<string>>    $taxonomyValues  token list per taxonomy value
     * @param list<string>          $taxonomyLabels  raw taxonomy values, parallel to $taxonomyValues
     */
    public function __construct(
        public PageInfo $page,
        public string $normRoute,
        public string $normSlug,
        public string $lastSegment,
        public array $titleTokens,
        public array $taxonomyValues,
        public array $taxonomyLabels,
    ) {
    }
}
