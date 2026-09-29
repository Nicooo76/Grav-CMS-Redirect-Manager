<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/**
 * A 404 path prepared for matching: language prefix, extension and "index" removed, segments normalized.
 *
 * @internal used by Suggester
 */
final readonly class ParsedPath
{
    /**
     * @param list<string> $segments       normalized segments ("ueber-uns")
     * @param string       $route          normalized route ("/blog/ueber-uns"), "/" when nothing is left
     * @param string       $slug           normalized last segment, "" when there is none
     * @param list<string> $contentTokens  meaningful tokens of the last segment (no stop words)
     * @param string|null  $language       language from the prefix or the caller, null when unknown
     */
    public function __construct(
        public array $segments,
        public string $route,
        public string $slug,
        public array $contentTokens,
        public ?string $language,
    ) {
    }
}
