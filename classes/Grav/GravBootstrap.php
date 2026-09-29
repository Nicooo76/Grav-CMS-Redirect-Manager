<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\App\RedirectService;
use Grav\Plugin\RedirectManager\App\SiteContext;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Throwable;

/**
 * Builds the plugin's services from a running Grav: the ServiceFactory (shared with the plugin class, the API
 * controllers and the CLI), the SiteContext and the RedirectService application service.
 */
final class GravBootstrap
{
    public const SLUG = 'redirect-manager';

    public static function factory(Grav $grav): ServiceFactory
    {
        /** @var Config $config */
        $config = $grav['config'];
        /** @var UniformResourceLocator $locator */
        $locator = $grav['locator'];

        $languages = self::languages($config);
        $default = (string) $config->get('system.languages.default_lang', '');
        if ($default === '' && $languages !== []) {
            $default = $languages[0];
        }

        $cacheDir = rtrim((string) $locator->findResource('cache://', true, true), '/') . '/' . self::SLUG;

        return new ServiceFactory(
            (array) $config->get('plugins.' . self::SLUG, []),
            [
                'languages' => $languages,
                'default_language' => $default,
                'custom_base_url' => (string) $config->get('system.custom_base_url', ''),
                'api_route' => (string) $config->get('plugins.api.route', '/api'),
                'admin_route' => (string) $config->get('plugins.admin2.route', '/admin'),
                'base_url' => self::baseUrl($grav, $config),
                'site_title' => (string) $config->get('site.title', ''),
            ],
            rtrim((string) $locator->findResource('user://data', true, true), '/') . '/' . self::SLUG,
            $cacheDir,
            $grav['log'],
            null,
            static function () use ($grav, $cacheDir) {
                // API and CLI requests start without a page tree; on frontend requests both calls do nothing.
                /** @var Pages $pages */
                $pages = $grav['pages'];
                $pages->enablePages();
                $pages->init();

                return (new GravPageIndexBuilder($grav, new PageIndexCache($cacheDir)))->index();
            },
        );
    }

    /**
     * The application service for API controllers and CLI commands.
     */
    public static function service(Grav $grav, ?ServiceFactory $factory = null, ?string $baseUrl = null): RedirectService
    {
        $factory ??= self::factory($grav);
        $site = self::siteContext($grav);
        if ($baseUrl !== null && trim($baseUrl) !== '') {
            $site = new SiteContext($site->server, rtrim(trim($baseUrl), '/'), $site->siteRedirects, $site->siteRoutes, $site->pageSettings, $site->redirectDefaultCode, $site->languages, $site->defaultLanguage);
        }

        return new RedirectService(
            $factory,
            $site,
            new GravConfigWriter($grav),
            RuleEvents::fromGrav($grav),
            null,
            static function () use ($factory): int {
                try {
                    return $factory->autoState()->pendingCount();
                } catch (Throwable) {
                    return 0;
                }
            },
        );
    }

    public static function siteContext(Grav $grav): SiteContext
    {
        /** @var Config $config */
        $config = $grav['config'];

        $languages = self::languages($config);
        $default = (string) $config->get('system.languages.default_lang', '');
        $pages = (array) $config->get('system.pages', []);
        $settings = [];
        foreach ($pages as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'redirect_')) {
                $settings[$key] = $value;
            }
        }
        $code = $settings['redirect_default_code'] ?? 301;

        return new SiteContext(
            server: $_SERVER,
            baseUrl: self::baseUrl($grav, $config),
            siteRedirects: self::stringMap($config->get('site.redirects', [])),
            siteRoutes: self::stringMap($config->get('site.routes', [])),
            pageSettings: $settings,
            redirectDefaultCode: is_numeric($code) ? (int) $code : 301,
            languages: $languages,
            defaultLanguage: $default !== '' ? $default : ($languages[0] ?? null),
        );
    }

    private static function baseUrl(Grav $grav, Config $config): string
    {
        // Runs without a request (scheduler, CLI) cannot know the public URL: the plugin's base_url setting says.
        $configured = rtrim(trim((string) $config->get('plugins.' . self::SLUG . '.base_url', '')), '/');
        if ($configured !== '') {
            return $configured;
        }
        $custom = rtrim(trim((string) $config->get('system.custom_base_url', '')), '/');
        if ($custom !== '') {
            return $custom;
        }
        try {
            $root = rtrim((string) $grav['uri']->rootUrl(true), '/');
            if ($root !== '') {
                return $root;
            }
        } catch (Throwable) {
            // fall through
        }

        return 'http://localhost';
    }

    /**
     * @return list<string>
     */
    private static function languages(Config $config): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $l): string => is_scalar($l) ? trim((string) $l) : '', (array) $config->get('system.languages.supported', [])),
            static fn (string $l): bool => $l !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_scalar($item)) {
                $out[(string) $key] = (string) $item;
            }
        }

        return $out;
    }
}
