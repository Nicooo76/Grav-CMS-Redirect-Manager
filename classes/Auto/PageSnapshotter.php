<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Throwable;

/**
 * Reads pages from $grav['pages'] into PageSnapshot objects (Grav adapter of the auto-redirect feature).
 *
 * Routes per language come from PageInterface::translatedLanguages(): Grav 2 resolves the translated slugs of
 * the whole ancestor chain there, without switching languages. Single-language sites use PageSnapshot::ANY.
 * Pages that serve no URL (unpublished, not routable, modular) have no routes and no snapshot.
 */
final class PageSnapshotter
{
    /** Descendants beyond this are not captured. */
    public const MAX_DESCENDANTS = 5000;

    public function __construct(private readonly Grav $grav)
    {
    }

    /** A page and its subtree, or null when the page itself serves no URL. */
    public function snapshot(PageInterface $page): ?PageSnapshot
    {
        $routes = $this->routesOf($page);
        if ($routes === []) {
            return null;
        }
        $languages = array_keys($routes);
        $descendants = [];
        $this->collect($page, rtrim((string) $page->path(), '/'), $descendants);

        return new PageSnapshot(
            (string) $page->title(),
            (string) $page->rawRoute(),
            $routes,
            $descendants,
            $this->ancestors($page, $languages),
            (bool) $page->home(),
        );
    }

    /**
     * Public routes of one page per language; empty when it serves no URL.
     *
     * @return array<string, string>
     */
    public function routesOf(PageInterface $page): array
    {
        if (!$page->routable() || !$page->published() || $page->modular() || $page->root()) {
            return [];
        }

        return $this->routeMap($page);
    }

    /**
     * Route per language of any page (no published/routable checks), for ancestors and the old parent of a moved page.
     *
     * @return array<string, string>
     */
    public function routeMap(PageInterface $page): array
    {
        $route = (string) $page->route();
        if ($route === '') {
            $route = '/';
        }
        if (!$this->multilingual()) {
            return [PageSnapshot::ANY => $route];
        }
        $out = [];
        try {
            foreach ($page->translatedLanguages(false) as $code => $translated) {
                if (is_string($translated) && $translated !== '') {
                    $out[(string) $code] = $translated;
                }
            }
        } catch (Throwable) {
            // fall through to the active language's route
        }
        if ($out === []) {
            $out[$this->activeLanguage() ?? PageSnapshot::ANY] = $route;
        }

        return $out;
    }

    /** Whether the route is served by a page in the active language (other languages: unknown, reported as no). */
    public function isLive(string $route, string $language): bool
    {
        if ($language !== PageSnapshot::ANY && $language !== $this->activeLanguage()) {
            return false;
        }
        $page = $this->pages()->find($route);

        return $page instanceof PageInterface && !$page->root() && $page->routable() && $page->published() && self::servesRoute($page, $route);
    }

    /** Whether the ancestor route exists as a page (active language only, other languages are assumed to exist). */
    public function routeExists(string $route, string $language): bool
    {
        if ($language !== PageSnapshot::ANY && $language !== $this->activeLanguage()) {
            return true;
        }

        $page = $this->pages()->find($route);

        return $page instanceof PageInterface && !$page->root() && self::servesRoute($page, $route);
    }

    /**
     * Pages::find() also resolves the structural route (folder names), which is not a URL of the page once it has a
     * "slug" or "routes.default" override: only the page's own route counts.
     */
    private static function servesRoute(PageInterface $page, string $route): bool
    {
        return strcasecmp(rtrim((string) $page->route(), '/'), rtrim($route, '/')) === 0;
    }

    /** Rebuilds the page tree from disk, so routes reflect what was just saved. */
    public function reload(): void
    {
        $pages = $this->pages();
        $pages->reset();
        $pages->enablePages();
    }

    /** The page whose folder is $path in the current tree. */
    public function byPath(string $path): ?PageInterface
    {
        $page = $this->pages()->get($path);

        return $page instanceof PageInterface ? $page : null;
    }

    /** A page by public route, falling back to the structural route (what Admin 2 sends). */
    public function byRoute(string $route): ?PageInterface
    {
        $route = '/' . trim($route, '/');
        $pages = $this->pages();
        $page = $pages->find($route);
        if ($page instanceof PageInterface) {
            return $page;
        }
        foreach ($pages->instances() as $candidate) {
            if ($candidate instanceof PageInterface && $candidate->rawRoute() === $route) {
                return $candidate;
            }
        }

        return null;
    }

    public function activeLanguage(): ?string
    {
        if (!$this->multilingual()) {
            return null;
        }
        /** @var Language $language */
        $language = $this->grav['language'];
        $active = $language->getLanguage();
        $active = is_string($active) && $active !== '' ? $active : $language->getDefault();

        return is_string($active) && $active !== '' ? $active : null;
    }

    public function multilingual(): bool
    {
        /** @var Language $language */
        $language = $this->grav['language'];

        return (bool) $language->enabled();
    }

    /**
     * @param list<PageNode> $out
     */
    private function collect(PageInterface $page, string $rootPath, array &$out): void
    {
        foreach ($page->children() as $child) {
            if (!$child instanceof PageInterface) {
                continue;
            }
            if (count($out) >= self::MAX_DESCENDANTS) {
                return;
            }
            $routes = $this->routesOf($child);
            if ($routes !== []) {
                $out[] = new PageNode(ltrim(substr(rtrim((string) $child->path(), '/'), strlen($rootPath)), '/'), $routes);
            }
            $this->collect($child, $rootPath, $out);
        }
    }

    /**
     * @param list<string> $languages
     *
     * @return array<string, list<string>>
     */
    private function ancestors(PageInterface $page, array $languages): array
    {
        $out = array_fill_keys($languages, []);
        $parent = $page->parent();
        while ($parent instanceof PageInterface && !$parent->root()) {
            if ($parent->routable() && $parent->published() && !$parent->modular()) {
                $map = $this->routeMap($parent);
                foreach ($languages as $language) {
                    $route = $map[$language] ?? $map[PageSnapshot::ANY] ?? (string) $parent->route();
                    if ($route !== '' && $route !== '/') {
                        $out[$language][] = $route;
                    }
                }
            }
            $parent = $parent->parent();
        }

        return $out;
    }

    private function pages(): Pages
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        // The API and the CLI start with the page tree disabled; enabling it is a no-op when it is on already.
        $pages->enablePages();

        return $pages;
    }
}
