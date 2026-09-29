<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use FilesystemIterator;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use SplFileInfo;
use Throwable;

/**
 * Builds the Suggest\PageIndex from $grav['pages'], for every supported language.
 *
 * Grav holds one language's page tree at a time, and a page that exists only in a language outside the
 * active language's fallback chain (a `default.de.md` next to no English file) is not part of that tree.
 * The builder therefore walks the tree once per supported language: it sets the active language, resets the
 * pages, collects every page whose own file is in that language (a file without language extension counts as
 * the default language) and finally restores the original language and tree. Routes, titles, taxonomy and
 * publication state of each entry come from the page of its own language; `translations` lists the routes
 * of the same page folder in the other languages (published translations only).
 *
 * Single-language sites take one pass and report language null.
 *
 * The result is cached in cache://redirect-manager/page-index-<hash>.php. The key is a stamp of the page
 * files, independent of the language: Grav's own language-neutral pages hash when its page cache is on,
 * else a scan of the page directories (path, size and mtime of *.md and *.yaml).
 */
final class GravPageIndexBuilder
{
    public function __construct(private readonly Grav $grav, private readonly PageIndexCache $cache)
    {
    }

    public function index(): PageIndex
    {
        $fingerprint = implode('|', [$this->stamp(), implode(',', $this->supportedLanguages()), $this->defaultLanguage() ?? '-', 'v2']);

        return $this->cache->remember($fingerprint, fn (): PageIndex => new PageIndex($this->pages()));
    }

    /**
     * @return list<PageInfo>
     */
    public function pages(): array
    {
        $languages = $this->supportedLanguages();
        if (!$this->languageEnabled() || $languages === []) {
            return $this->collectPass(null)['infos'];
        }

        /** @var Language $language */
        $language = $this->grav['language'];
        $original = $language->getActive();
        $originalCode = is_string($original) && $original !== '' ? $original : null;

        // The language that is active now goes last: the tree Grav holds afterwards is already the right one.
        $order = array_values(array_filter($languages, static fn (string $code): bool => $code !== $originalCode));
        $lastIsOriginal = $originalCode !== null && in_array($originalCode, $languages, true);
        if ($lastIsOriginal) {
            $order[] = $originalCode;
        }

        /** @var array<string, array<string, PageInfo>> $rows language => page folder => page, in tree order */
        $rows = [];
        /** @var array<string, array<string, string>> $routes page folder => language => route */
        $routes = [];
        try {
            foreach ($order as $code) {
                $this->switchTo($code);
                $pass = $this->collectPass($code);
                foreach ($pass['rows'] as $key => $row) {
                    $rows[$code][$key] = $row;
                    if ($row->published) {
                        $routes[$key][$code] = $row->route;
                    }
                }
            }
        } finally {
            $this->restore($original, $lastIsOriginal);
        }

        // Output order does not depend on which language was active: supported languages in their configured order.
        $infos = [];
        foreach ($languages as $code) {
            foreach ($rows[$code] ?? [] as $key => $row) {
                $others = $routes[$key] ?? [];
                unset($others[$code]);
                $infos[] = new PageInfo(
                    route: $row->route,
                    rawRoute: $row->rawRoute,
                    slug: $row->slug,
                    title: $row->title,
                    language: $code,
                    parentRoute: $row->parentRoute,
                    routable: $row->routable,
                    published: $row->published,
                    taxonomy: $row->taxonomy,
                    translations: $others,
                    modified: $row->modified,
                );
            }
        }

        return $infos;
    }

    /**
     * Reads the tree Grav holds now. With a language code only pages whose own file is in that language
     * (or, for the default language, has no language extension) are taken.
     *
     * @return array{infos: list<PageInfo>, rows: array<string, PageInfo>}
     */
    private function collectPass(?string $code): array
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $default = $this->defaultLanguage();

        $infos = [];
        $rows = [];
        foreach ($pages->all() as $page) {
            if (!$page instanceof PageInterface || $page->root()) {
                continue;
            }
            if ($code !== null) {
                // Grav puts an empty placeholder into a tree for a folder that has no file in a language of its
                // fallback chain; only real files count, and only those written in this pass' language.
                if (!$page->exists()) {
                    continue;
                }
                $own = self::fileLanguage($page) ?? $default;
                if ($own !== $code) {
                    continue;
                }
            }
            $info = $this->info($page, $code);
            $infos[] = $info;
            $rows[(string) $page->path()] = $info;
        }

        return ['infos' => $infos, 'rows' => $rows];
    }

    private function info(PageInterface $page, ?string $language): PageInfo
    {
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
            translations: [],
            modified: $page->modified() ?: null,
        );
    }

    /** Language code from the page's file extension ("default.de.md" gives "de"), null for "default.md". */
    private static function fileLanguage(PageInterface $page): ?string
    {
        $extension = trim((string) $page->extension(), '.');
        $code = preg_replace('/(^|\.)md$/', '', $extension);

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function switchTo(string $code): void
    {
        /** @var Language $language */
        $language = $this->grav['language'];
        $language->setActive($code);
        $language->resetFallbackPageExtensions();
        Pages::resetHomeRoute();
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->reset();
    }

    /** Puts the language and the page tree back the way they were. */
    private function restore(string|false|null $original, bool $treeIsCurrent): void
    {
        /** @var Language $language */
        $language = $this->grav['language'];
        if (is_string($original) && $original !== '') {
            $language->setActive($original);
        } else {
            // setActive() cannot unset the language: write the property back.
            try {
                (new ReflectionProperty($language, 'active'))->setValue($language, $original);
            } catch (Throwable) {
                // stays on the last pass' language
            }
        }
        $language->resetFallbackPageExtensions();
        Pages::resetHomeRoute();
        if (!$treeIsCurrent) {
            /** @var Pages $pages */
            $pages = $this->grav['pages'];
            $pages->reset();
        }
    }

    /**
     * Fingerprint of the page files, the same for every language.
     */
    private function stamp(): string
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $hash = (string) $pages->getSimplePagesHash();
        if ($hash !== '') {
            return 'grav:' . md5($hash);
        }

        $parts = [];
        foreach ($this->pageDirectories() as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $name = strtolower($file->getFilename());
                if (str_ends_with($name, '.md') || str_ends_with($name, '.yaml')) {
                    $parts[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
                }
            }
        }
        sort($parts);

        return 'scan:' . md5(implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    private function pageDirectories(): array
    {
        /** @var Config $config */
        $config = $this->grav['config'];
        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $dirs = [];
        foreach ((array) $config->get('system.pages.dirs', ['page://']) as $dir) {
            $path = $locator->findResource((string) $dir);
            if (is_string($path) && is_dir($path) && !in_array($path, $dirs, true)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
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

    /**
     * @return list<string>
     */
    private function supportedLanguages(): array
    {
        if (!$this->languageEnabled()) {
            return [];
        }
        /** @var Language $language */
        $language = $this->grav['language'];

        return array_values(array_filter(
            array_map(static fn (mixed $code): string => is_scalar($code) ? (string) $code : '', (array) $language->getLanguages()),
            static fn (string $code): bool => $code !== '',
        ));
    }

    private function defaultLanguage(): ?string
    {
        /** @var Language $language */
        $language = $this->grav['language'];
        $default = $language->getDefault();

        return is_string($default) && $default !== '' ? $default : null;
    }
}
