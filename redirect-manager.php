<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Events\PageEvent;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Events\PluginsLoadedEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\GravPageIndexBuilder;
use Grav\Plugin\RedirectManager\Grav\PageIndexCache;
use Grav\Plugin\RedirectManager\Grav\RedirectLookup;
use Grav\Plugin\RedirectManager\Grav\RequestContextResult;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Grav\TwigExtension;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

/**
 * Redirect Manager: event wiring only. Logic lives in classes/.
 */
class RedirectManagerPlugin extends Plugin
{
    public const SLUG = 'redirect-manager';

    private ?ServiceFactory $services = null;

    /** Request as seen at PluginsLoadedEvent; false = nothing to do for this request (excluded, invalid, disabled). */
    private RequestContextResult|false|null $request = null;

    /** A 410, 451 or pass-through match waiting for onPagesInitialized. */
    private ?MatchResult $pending = null;

    /**
     * @return array<string, array<int|string, mixed>>
     */
    public static function getSubscribedEvents(): array
    {
        // Subscribed statically, never behind isAdmin(): on API/Admin 2 requests
        // $grav['admin'] only exists after route dispatch (see docs/GRAV2-NOTES.md).
        return [
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            // First thing a plugin can see, before session and trailing-slash redirect: see docs/DECISIONS.md D-004.
            PluginsLoadedEvent::class => ['onPluginsLoaded', 10000],
            'onPagesInitialized' => ['onPagesInitialized', 10],
            // Above the error plugin (priority 0), which stops propagation.
            'onPageNotFound' => ['onPageNotFound', 10],
            // Late, so theme template paths come first and a theme can override our templates.
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', -1000],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onBuildTwigSandboxPolicy' => ['onBuildTwigSandboxPolicy', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            'onApiDashboardWidgets' => ['onApiDashboardWidgets', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Early phase: rules without "only if not found". 30x answers are sent from here (no session yet, so
     * no cookie and cacheable); 410, 451 and pass-through wait for onPagesInitialized.
     * Anything that goes wrong is logged and Grav carries on: a broken rule file never takes the site down.
     */
    public function onPluginsLoaded(PluginsLoadedEvent $event): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $services = $this->services();
            if (!$services->enabled()) {
                $this->request = false;

                return;
            }
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }

            $result = $services->matcher()->match($request->context, MatchPhase::Early);
            if ($result === null) {
                return;
            }
            $result = $this->dispatchMatched($result, $request);
            if ($result === null) {
                return;
            }
            if ($result->status->isRedirect()) {
                $this->send($result, $request, false);

                return;
            }
            $this->pending = $result;
        } catch (Throwable $e) {
            $this->logFailure('redirect matching', $e);
        }
    }

    /** Finishes 410, 451 and pass-through matches from the early phase. */
    public function onPagesInitialized(): void
    {
        $result = $this->pending;
        if ($result === null) {
            return;
        }
        $this->pending = null;
        try {
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }
            if ($result->status->isError()) {
                // The session started before onPagesInitialized: strip its headers again.
                $this->send($result, $request, true);

                return;
            }
            $page = $this->findTargetPage($result);
            if ($page === null) {
                $this->grav['log']->warning(sprintf(
                    'Redirect Manager: pass-through rule %s points to "%s", which is not a routable page. Falling through.',
                    $result->rule->id,
                    $result->location,
                ));

                return;
            }
            $this->recordHits($result);
            unset($this->grav['page']);
            $this->grav['page'] = $page;
        } catch (Throwable $e) {
            $this->logFailure('redirect handling', $e);
        }
    }

    /** Logs the 404, then applies "only if not found" rules. */
    public function onPageNotFound(PageEvent $event): void
    {
        try {
            $services = $this->services();
            if (!$services->enabled()) {
                return;
            }
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }

            $this->logNotFound($request);

            $result = $services->matcher()->match($request->context, MatchPhase::NotFound);
            if ($result === null) {
                return;
            }
            $result = $this->dispatchMatched($result, $request);
            if ($result === null) {
                return;
            }
            if ($result->status === StatusCode::PassThrough) {
                $page = $this->findTargetPage($result);
                if ($page === null) {
                    $this->grav['log']->warning(sprintf(
                        'Redirect Manager: pass-through rule %s points to "%s", which is not a routable page.',
                        $result->rule->id,
                        $result->location,
                    ));

                    return;
                }
                $this->recordHits($result);
                $event->page = $page;
                $event->stopPropagation();

                return;
            }
            // The session is running by now: strip what PHP added so the redirect stays cacheable.
            $this->send($result, $request, true);
        } catch (Throwable $e) {
            $this->logFailure('404 handling', $e);
        }
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigInitialized(): void
    {
        try {
            $services = $this->services();
            $lookup = new RedirectLookup($services, $_SERVER, (string) ($_SERVER['HTTP_HOST'] ?? ''));
            $this->grav['twig']->twig()->addExtension(new TwigExtension($lookup->lookup(...)));
        } catch (Throwable $e) {
            $this->logFailure('Twig extension', $e);
        }
    }

    public function onBuildTwigSandboxPolicy(Event $event): void
    {
        $functions = $event['functions'];
        $functions[] = 'redirect_for';
        $event['functions'] = $functions;

        $filters = $event['filters'];
        $filters[] = 'redirect_target';
        $event['filters'] = $filters;
    }

    /**
     * The services, built lazily from Grav's config and locator. Also used by the API and CLI code.
     */
    public function services(): ServiceFactory
    {
        if ($this->services !== null) {
            return $this->services;
        }

        /** @var \Grav\Common\Config\Config $config */
        $config = $this->grav['config'];
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        $languages = array_values(array_filter(
            array_map(static fn (mixed $l): string => is_scalar($l) ? trim((string) $l) : '', (array) $config->get('system.languages.supported', [])),
            static fn (string $l): bool => $l !== '',
        ));
        $default = (string) $config->get('system.languages.default_lang', '');
        if ($default === '' && $languages !== []) {
            $default = $languages[0];
        }

        $cacheDir = rtrim((string) $locator->findResource('cache://', true, true), '/') . '/' . self::SLUG;
        $factory = new ServiceFactory(
            (array) $config->get('plugins.' . self::SLUG, []),
            [
                'languages' => $languages,
                'default_language' => $default,
                'custom_base_url' => (string) $config->get('system.custom_base_url', ''),
                'api_route' => (string) $config->get('plugins.api.route', '/api'),
                'admin_route' => (string) $config->get('plugins.admin2.route', '/admin'),
            ],
            rtrim((string) $locator->findResource('user://data', true, true), '/') . '/' . self::SLUG,
            $cacheDir,
            $this->grav['log'],
            null,
            fn () => (new GravPageIndexBuilder($this->grav, new PageIndexCache($cacheDir)))->index(),
        );

        return $this->services = $factory;
    }

    /** Fires onRedirectRuleSaved and friends; the API, the CLI and the auto-redirect listener use it. */
    public function events(): RuleEvents
    {
        return RuleEvents::fromGrav($this->grav);
    }

    private function requestContext(): ?RequestContextResult
    {
        if ($this->request === null) {
            $factory = $this->services()->requestFactory();
            /** @var \Psr\Http\Message\ServerRequestInterface $psr */
            $psr = $this->grav['request'];
            $request = $factory->fromRequest($psr, $_SERVER);
            $this->request = $request === null || $factory->isExcluded($request->context->path) ? false : $request;
        }

        return $this->request === false ? null : $this->request;
    }

    /**
     * onRedirectMatched: listeners may replace the result or cancel. Returns null when cancelled.
     */
    private function dispatchMatched(MatchResult $result, RequestContextResult $request): ?MatchResult
    {
        $payload = $this->events()->matched(['result' => $result, 'context' => $request->context, 'request' => $request]);
        if (!empty($payload['cancel'])) {
            return null;
        }

        return ($payload['result'] ?? null) instanceof MatchResult ? $payload['result'] : $result;
    }

    /**
     * Sends the redirect or error response and ends the request.
     */
    private function send(MatchResult $result, RequestContextResult $request, bool $sessionRunning): void
    {
        $this->recordHits($result);

        $html = '';
        if ($result->status->isError()) {
            $template = $result->status === StatusCode::Gone ? 'gone' : 'unavailable';
            $html = $this->grav['twig']->processTemplate('redirect-manager/' . $template . '.html.twig', [
                'redirect_status' => $result->status->value,
                'redirect_path' => $request->context->path,
            ]);
        }
        $response = $this->services()->responder()->respond($result, $request, $html);

        if ($sessionRunning && !headers_sent()) {
            foreach (['Set-Cookie', 'Expires', 'Pragma', 'Cache-Control'] as $name) {
                header_remove($name);
            }
        }
        $this->grav->close($response->toPsr7());
    }

    private function recordHits(MatchResult $result): void
    {
        try {
            $recorder = $this->services()->hitRecorder();
            foreach ($result->rules as $rule) {
                $recorder->record($rule->id);
            }
        } catch (Throwable $e) {
            $this->logFailure('hit recording', $e);
        }
    }

    private function logNotFound(RequestContextResult $request): void
    {
        try {
            $services = $this->services();
            $context = $request->context;
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($services->bool('security.trust_proxy_headers', false) && $context->header('x-forwarded-for') !== null) {
                $ip = trim(explode(',', (string) $context->header('x-forwarded-for'))[0]);
            }
            $entry = $services->notFoundLogger()->log(
                $context->path,
                $request->rawQuery,
                $context->header('referer'),
                $context->header('user-agent'),
                $ip !== '' ? $ip : null,
                $context->language,
                $context->host,
                $request->method,
            );
            if ($entry !== null) {
                $this->events()->notFoundLogged(['entry' => $entry]);
            }
        } catch (Throwable $e) {
            $this->logFailure('404 logging', $e);
        }
    }

    private function findTargetPage(MatchResult $result): ?PageInterface
    {
        $route = (string) preg_replace('/[?#].*$/', '', $result->location);
        if ($route === '' || $route[0] !== '/' || $result->isExternal()) {
            return null;
        }
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->find(rawurldecode($route));

        return $page instanceof PageInterface && $page->routable() ? $page : null;
    }

    private function logFailure(string $what, Throwable $e): void
    {
        try {
            $this->grav['log']->error(sprintf('Redirect Manager: %s failed: %s (%s:%d)', $what, $e->getMessage(), basename($e->getFile()), $e->getLine()));
        } catch (Throwable) {
            // nothing left to do
        }
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml('plugin://' . self::SLUG . '/permissions.yaml');
        $event->permissions->addActions($actions);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'PLUGIN_REDIRECT_MANAGER.TITLE',
            'icon' => 'fa-route',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 5,
            'authorize' => ['admin.super', 'api.super', 'api.redirects.read'],
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== self::SLUG) {
            return;
        }
        // The page title is rendered verbatim by Admin 2, so translate it here.
        $title = $this->grav['language']->translate(['PLUGIN_REDIRECT_MANAGER.TITLE']);
        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => is_string($title) && $title !== '' ? $title : 'Redirects',
            'icon' => 'fa-route',
            'page_type' => 'component',
        ];
    }

    public function onApiDashboardWidgets(Event $event): void
    {
        $widgets = $event['widgets'] ?? [];
        $widgets[] = [
            'id' => 'redirect-manager.overview',
            'plugin' => self::SLUG,
            'label' => 'PLUGIN_REDIRECT_MANAGER.WIDGET.TITLE',
            'icon' => 'Route',
            'sizes' => ['sm', 'md', 'lg'],
            'defaultSize' => 'md',
            'authorize' => ['admin.super', 'api.super', 'api.redirects.read'],
            'priority' => 40,
            'scriptUrl' => '/gpm/plugins/' . self::SLUG . '/widget-script',
            'dataEndpoint' => '/redirects/stats',
        ];
        $event['widgets'] = $widgets;
    }
}
