<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;

/**
 * A small bilingual site (en default, de with translated slugs), ~45 pages including
 * unpublished and non-routable ones.
 */
final class PageTreeFixture
{
    public static function index(): PageIndex
    {
        return new PageIndex(self::pages());
    }

    /**
     * @param array<string, list<string>> $taxonomy
     * @param array<string, string>       $translations
     */
    private static function page(
        string $route,
        string $title,
        string $language = 'en',
        array $taxonomy = [],
        array $translations = [],
        ?int $modified = null,
        bool $routable = true,
        bool $published = true,
    ): PageInfo {
        $segments = explode('/', trim($route, '/'));
        $slug = (string) end($segments);
        $parent = count($segments) > 1 ? '/' . implode('/', array_slice($segments, 0, -1)) : ($route === '/' ? null : '/');

        return new PageInfo(
            route: $route,
            rawRoute: $route,
            slug: $slug,
            title: $title,
            language: $language,
            parentRoute: $parent,
            routable: $routable,
            published: $published,
            taxonomy: $taxonomy,
            translations: $translations,
            modified: $modified,
        );
    }

    /** @return list<PageInfo> */
    public static function pages(): array
    {
        return [
            // English
            self::page('/', 'Home', translations: ['de' => '/']),
            self::page('/about', 'About us', translations: ['de' => '/ueber-uns']),
            self::page('/contact', 'Contact', translations: ['de' => '/kontakt']),
            self::page('/blog', 'Blog', translations: ['de' => '/blog']),
            self::page('/blog/my-post', 'My post', taxonomy: ['tag' => ['php', 'grav'], 'category' => ['tutorials']], translations: ['de' => '/blog/mein-beitrag'], modified: 1700000000),
            self::page('/blog/second-post', 'Second post', taxonomy: ['tag' => ['php']], modified: 1700000100),
            self::page('/blog/grav-tips-and-tricks', 'Grav tips and tricks', taxonomy: ['tag' => ['grav', 'tips'], 'category' => ['tutorials']], modified: 1700000200),
            self::page('/blog/hello-world', 'Hello world', taxonomy: ['tag' => ['news']]),
            self::page('/blog/draft-post', 'Draft post', published: false),
            self::page('/blog/hidden', 'Hidden page', routable: false),
            self::page('/products', 'Products', translations: ['de' => '/produkte']),
            self::page('/products/widget-pro', 'Widget Pro', taxonomy: ['tag' => ['widgets']]),
            self::page('/products/widget-lite', 'Widget Lite', taxonomy: ['tag' => ['widgets']]),
            self::page('/products/gadget-x', 'Gadget X', taxonomy: ['tag' => ['gadgets']]),
            self::page('/products/faq', 'Product FAQ'),
            self::page('/services', 'Services'),
            self::page('/services/web-design', 'Web design', taxonomy: ['tag' => ['web design']]),
            self::page('/services/seo-consulting', 'SEO consulting'),
            self::page('/services/hosting', 'Hosting'),
            self::page('/team', 'Our team'),
            self::page('/team/anna-schmidt', 'Anna Schmidt'),
            self::page('/team/jan-mueller', 'Jan Müller'),
            self::page('/privacy', 'Privacy policy', translations: ['de' => '/datenschutz']),
            self::page('/imprint', 'Imprint', translations: ['de' => '/impressum']),
            self::page('/docs', 'Documentation'),
            self::page('/docs/getting-started', 'Getting started'),
            self::page('/docs/installation', 'Installation guide'),
            self::page('/docs/configuration', 'Configuration'),
            self::page('/docs/faq', 'FAQ'),
            self::page('/docs/cluster-setup', 'Cluster setup', taxonomy: ['tag' => ['kubernetes']]),
            self::page('/news', 'News'),
            self::page('/news/annual-report-2024', 'Annual report 2024'),
            self::page('/jobs', 'Jobs'),
            self::page('/jobs/frontend-developer', 'Frontend developer'),
            self::page('/gallery', 'Gallery'),
            self::page('/gallery/summer', 'Summer'),
            // German (translated slugs)
            self::page('/', 'Startseite', 'de'),
            self::page('/ueber-uns', 'Über uns', 'de'),
            self::page('/kontakt', 'Kontakt', 'de'),
            self::page('/blog', 'Blog', 'de'),
            self::page('/blog/mein-beitrag', 'Mein Beitrag', 'de'),
            self::page('/produkte', 'Produkte', 'de'),
            self::page('/datenschutz', 'Datenschutz', 'de'),
            self::page('/impressum', 'Impressum', 'de'),
        ];
    }
}
