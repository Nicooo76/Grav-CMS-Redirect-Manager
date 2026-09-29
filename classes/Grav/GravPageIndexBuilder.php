<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use SplFileInfo;
use Throwable;

/**
 * Builds the Suggest\PageIndex from $grav['pages'].
 *
 * The tree is read once, in the active language. Every page reports its routes in all languages through
 * translatedLanguages() (Grav 2 resolves translated slugs of the whole ancestor chain there, no language
 * switching and no second tree build needed). For the other languages the title and taxonomy are read
 * from the translation's own front matter. Pages that exist only in a non-active language and have no
 * fallback are not part of the tree Grav builds, so they are missing from the index.
 *
 * The result is cached in cache://redirect-manager/page-index-<hash>.php, keyed by the pages cache id
 * (content hash), the last modification time, the page count and the active language.
 */
final class GravPageIndexBuilder
{
    public function __construct(private readonly Grav $grav, private readonly PageIndexCache $cache)
    {
    }

    public function index(): PageIndex
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $language = $this->activeLanguage();
        $fingerprint = implode('|', [(string) $pages->getPagesCacheId(), (string) $pages->lastModified(), (string) count($pages->instances()), $language ?? '-', 'v1']);

        return $this->cache->remember($fingerprint, fn (): PageIndex => new PageIndex($this->pages()));
    }

    /**
     * @return list<PageInfo>
     */
    public function pages(): array
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $active = $this->activeLanguage();
        $multi = $this->languageEnabled();

        $infos = [];
        foreach ($pages->all() as $page) {
            if (!$page instanceof PageInterface || $page->root()) {
                continue;
            }
            $translations = $multi ? $this->translations($page) : [];
            $infos[] = $this->info($page, $multi ? $active : null, $translations, $active);

            foreach ($translations as $code => $route) {
                if ($code === $active || $route === '') {
                    continue;
                }
                $infos[] = $this->translationInfo($page, $code, $route, $translations);
            }
        }

        return $infos;
    }

    /**
     * @return array<string, string> language => route, empty routes dropped
     */
    private function translations(PageInterface $page): array
    {
        try {
            /** @var array<string, string|null> $routes */
            $routes = $page->translatedLanguages(true);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($routes as $code => $route) {
            if (is_string($route) && $route !== '') {
                $out[(string) $code] = $route;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $translations
     */
    private function info(PageInterface $page, ?string $language, array $translations, ?string $active): PageInfo
    {
        $others = $translations;
        if ($language !== null) {
            unset($others[$language]);
        }
        $parent = $page->parent();
        $parentRoute = $parent instanceof PageInterface && !$parent->root() ? $parent->route() : null;

        return new PageInfo(
            route: (string) $page->route(),
            rawRoute: (string) $page->rawRoute(),
            slug: (string) $page->slug(),
            title: (string) $page->title(),
            language: $language,
            parentRoute: $parentRoute,
            routable: (bool) $page->routable(),
            published: (bool) $page->published(),
            taxonomy: self::taxonomy($page->taxonomy()),
            translations: $others,
            modified: $page->modified() ?: null,
        );
    }

    /**
     * @param array<string, string> $translations
     */
    private function translationInfo(PageInterface $page, string $code, string $route, array $translations): PageInfo
    {
        $title = (string) $page->title();
        $taxonomy = self::taxonomy($page->taxonomy());
        $published = (bool) $page->published();
        $routable = (bool) $page->routable();

        $file = $this->translationFile($page, $code);
        if ($file !== null) {
            try {
                $translated = new Page();
                $translated->init(new SplFileInfo($file), '.' . $code . '.md');
                $title = (string) $translated->title() !== '' ? (string) $translated->title() : $title;
                $taxonomy = self::taxonomy($translated->taxonomy()) ?: $taxonomy;
                $published = (bool) $translated->published();
                $routable = (bool) $translated->routable();
            } catch (Throwable) {
                // keep the values of the active language
            }
        }

        $others = $translations;
        unset($others[$code]);
        $parts = explode('/', trim($route, '/'));
        array_pop($parts);
        $parentRoute = $parts === [] ? null : '/' . implode('/', $parts);

        return new PageInfo(
            route: $route,
            rawRoute: (string) $page->rawRoute(),
            slug: basename($route),
            title: $title,
            language: $code,
            parentRoute: $parentRoute,
            routable: $routable,
            published: $published,
            taxonomy: $taxonomy,
            translations: $others,
            modified: $page->modified() ?: null,
        );
    }

    private function translationFile(PageInterface $page, string $code): ?string
    {
        $path = $page->filePath();
        if (!is_string($path) || $path === '') {
            return null;
        }
        $dir = dirname($path);
        $name = preg_replace('/(\.[a-z]{2,3}(?:-[A-Za-z]+)?)?\.md$/', '', basename($path));
        foreach ([$dir . '/' . $name . '.' . $code . '.md', $dir . '/' . $name . '.md'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param mixed $taxonomy
     *
     * @return array<string, list<string>>
     */
    private static function taxonomy(mixed $taxonomy): array
    {
        $out = [];
        if (!is_array($taxonomy)) {
            return $out;
        }
        foreach ($taxonomy as $type => $values) {
            $list = [];
            foreach ((array) $values as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $list[] = (string) $value;
                }
            }
            if ($list !== []) {
                $out[(string) $type] = $list;
            }
        }

        return $out;
    }

    private function languageEnabled(): bool
    {
        /** @var Language $language */
        $language = $this->grav['language'];

        return (bool) $language->enabled();
    }

    private function activeLanguage(): ?string
    {
        if (!$this->languageEnabled()) {
            return null;
        }
        /** @var Language $language */
        $language = $this->grav['language'];
        $active = $language->getLanguage();
        $active = is_string($active) && $active !== '' ? $active : $language->getDefault();

        return is_string($active) && $active !== '' ? $active : null;
    }
}
