<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

/**
 * The few facts about the surrounding Grav site the application services need, as plain data.
 *
 * $server is $_SERVER (base path detection for the rule tester), $baseUrl the public root URL of the site
 * without trailing slash (live checks), $siteRedirects / $siteRoutes Grav's own `site.redirects` and
 * `site.routes`, $pageSettings the `system.pages.redirect_*` settings.
 */
final readonly class SiteContext
{
    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $siteRedirects
     * @param array<string, string> $siteRoutes
     * @param array<string, mixed>  $pageSettings  system.pages.redirect_* without the prefix, e.g. default_code
     * @param list<string>          $languages
     */
    public function __construct(
        public array $server = [],
        public string $baseUrl = 'http://localhost',
        public array $siteRedirects = [],
        public array $siteRoutes = [],
        public array $pageSettings = [],
        public int $redirectDefaultCode = 301,
        public array $languages = [],
        public ?string $defaultLanguage = null,
    ) {
    }

    /** Host of the site, lowercase, or "" when the base URL has none. */
    public function host(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
