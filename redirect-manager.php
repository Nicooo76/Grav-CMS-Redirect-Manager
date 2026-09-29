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
use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Grav\Plugin\RedirectManager\Grav\McpManifest;
use Grav\Plugin\RedirectManager\Grav\RedirectLookup;
use Grav\Plugin\RedirectManager\Grav\RequestContextResult;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\SchedulerJobs;
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

    private ?AutoRedirectListener $auto = null;

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
            'onApiMcpTools' => ['onApiMcpTools', 0],
            // Two listeners: the plugin's REST routes and the auto-redirect routes (pending decisions, badge).
            'onApiRegisterRoutes' => [['onApiRegisterRoutes', 0], ['onApiRegisterAutoRoutes', 0]],
            // Page changes through the API plugin (Admin 2, REST, MCP): automatic redirects. Never fired without the API plugin.
            'onApiBeforePageUpdate' => ['onApiBeforePageUpdate', 0],
            'onApiPageUpdated' => ['onApiPageUpdated', 0],
            'onApiPageMoved' => ['onApiPageMoved', 0],
            'onApiBeforePageDelete' => ['onApiBeforePageDelete', 0],
            'onApiPageDeleted' => ['onApiPageDeleted', 0],
            'onApiBeforePagesReorganize' => ['onApiBeforePagesReorganize', 0],
            'onApiPagesReorganized' => ['onApiPagesReorganized', 0],
            // Scheduler jobs (only fired in scheduler runs, never on page requests).
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
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
        // Only with REDIRECT_MANAGER_TIMING=1 or `debug_timing: true`: one clock read here, one below, no other cost.
        $timer = $this->timingEnabled() ? hrtime(true) : null;
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
            if ($timer !== null) {
                $this->emitTiming($timer);
            }
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
        return $this->services ??= GravBootstrap::factory($this->grav);
    }

    /** Fires onRedirectRuleSaved and friends; the API, the CLI and the auto-redirect listener use it. */
    public function events(): RuleEvents
    {
        return RuleEvents::fromGrav($this->grav);
    }

    /** Automatic redirects for page changes (also used by the REST controller to resolve pending deletes). */
    public function autoRedirects(): AutoRedirectListener
    {
        return $this->auto ??= new AutoRedirectListener($this->grav, $this->services(), $this->events());
    }

    public function onApiBeforePageUpdate(Event $event): void
    {
        $this->autoRedirects()->onBeforePageUpdate($event);
    }

    public function onApiPageUpdated(Event $event): void
    {
        $this->autoRedirects()->onPageUpdated($event);
    }

    public function onApiPageMoved(Event $event): void
    {
        $this->autoRedirects()->onPageMoved($event);
    }

    public function onApiBeforePageDelete(Event $event): void
    {
        $this->autoRedirects()->onBeforePageDelete($event);
    }

    public function onApiPageDeleted(Event $event): void
    {
        $this->autoRedirects()->onPageDeleted($event);
    }

    public function onApiBeforePagesReorganize(Event $event): void
    {
        $this->autoRedirects()->onBeforePagesReorganize($event);
    }

    public function onApiPagesReorganized(Event $event): void
    {
        $this->autoRedirects()->onPagesReorganized($event);
    }

    /** /redirects/pending, /redirects/pending/{id}/resolve, /redirects/badge, /redirects/badge/seen. */
    public function onApiRegisterAutoRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $auto = \Grav\Plugin\RedirectManager\Api\AutoRedirectController::class;

        $routes->get('/redirects/pending', [$auto, 'pending']);
        $routes->post('/redirects/pending/{id}/resolve', [$auto, 'resolve']);
        $routes->get('/redirects/badge', [$auto, 'badge']);
        $routes->post('/redirects/badge/seen', [$auto, 'badgeSeen']);
    }

    /** redirect-manager-maintenance, -check-targets and -digest: see SchedulerJobs. */
    public function onSchedulerInitialized(Event $event): void
    {
        try {
            SchedulerJobs::register($event['scheduler'], $this->services());
        } catch (Throwable $e) {
            $this->logFailure('scheduler registration', $e);
        }
    }

    private function timingEnabled(): bool
    {
        $env = getenv('REDIRECT_MANAGER_TIMING');
        if (is_string($env) && $env !== '' && $env !== '0') {
            return true;
        }

        return (bool) $this->config?->get('plugins.' . self::SLUG . '.debug_timing', false);
    }

    /**
     * X-Redirect-Manager-Time: microseconds the early phase took (request context, compiled rule cache, match).
     * Set before the response is built, so it is on redirects and on pages Grav serves afterwards.
     */
    private function emitTiming(int|float $startedAt): void
    {
        if (!headers_sent()) {
            header('X-Redirect-Manager-Time: ' . number_format((hrtime(true) - $startedAt) / 1000, 1, '.', ''));
        }
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
        // Not in the plugin root: GPM names a direct-installed package after the first *.yaml there (D-024).
        $actions = PermissionsReader::fromYaml('plugin://' . self::SLUG . '/config/permissions.yaml');
        $event->permissions->addActions($actions);
    }

    /**
     * MCP tools for the API plugin (GET /api/v1/mcp/tools). Declared in config/mcp.yaml and registered here
     * because a mcp.yaml in the plugin root breaks `bin/gpm direct-install` (docs/DECISIONS.md D-024).
     */
    public function onApiMcpTools(Event $event): void
    {
        McpManifest::register($event['tools'], self::SLUG, __DIR__ . '/' . McpManifest::FILE);
    }

    /**
     * REST routes below the API prefix (docs/API.md). Static routes come before parameterized ones.
     * The /redirects/pending* and /redirects/badge routes belong to the auto-redirect controller.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $rules = \Grav\Plugin\RedirectManager\Api\ApiController::class;
        $notFound = \Grav\Plugin\RedirectManager\Api\NotFoundApiController::class;
        $suggestions = \Grav\Plugin\RedirectManager\Api\SuggestionApiController::class;
        $files = \Grav\Plugin\RedirectManager\Api\ImportExportApiController::class;
        $system = \Grav\Plugin\RedirectManager\Api\SystemApiController::class;

        $routes->get('/redirects/rules', [$rules, 'index']);
        $routes->post('/redirects/rules', [$rules, 'create']);
        $routes->post('/redirects/rules/restore', [$rules, 'restore']);
        $routes->post('/redirects/rules/bulk', [$rules, 'bulk']);
        $routes->post('/redirects/rules/reorder', [$rules, 'reorder']);
        $routes->post('/redirects/rules/validate', [$rules, 'validate']);
        $routes->get('/redirects/rules/{id}', [$rules, 'show']);
        $routes->patch('/redirects/rules/{id}', [$rules, 'update']);
        $routes->delete('/redirects/rules/{id}', [$rules, 'delete']);
        $routes->post('/redirects/rules/{id}/shorten-chain', [$rules, 'shortenChain']);
        $routes->get('/redirects/analysis', [$rules, 'analysis']);
        $routes->get('/redirects/groups', [$rules, 'groups']);
        $routes->post('/redirects/test', [$rules, 'test']);

        $routes->get('/redirects/404', [$notFound, 'index']);
        $routes->delete('/redirects/404', [$notFound, 'delete']);
        $routes->get('/redirects/404/trend', [$notFound, 'trend']);
        $routes->get('/redirects/404/entries', [$notFound, 'entries']);
        $routes->post('/redirects/404/ignore', [$notFound, 'ignore']);
        $routes->post('/redirects/404/resolve', [$notFound, 'resolve']);

        $routes->get('/redirects/suggest', [$suggestions, 'suggest']);
        $routes->get('/redirects/suggestions', [$suggestions, 'index']);
        $routes->post('/redirects/suggestions/generate', [$suggestions, 'generate']);
        $routes->post('/redirects/suggestions/bulk-accept', [$suggestions, 'bulkAccept']);
        $routes->post('/redirects/suggestions/{id}/accept', [$suggestions, 'accept']);
        $routes->post('/redirects/suggestions/{id}/reject', [$suggestions, 'reject']);

        $routes->get('/redirects/import/formats', [$files, 'formats']);
        $routes->post('/redirects/import/preview', [$files, 'preview']);
        $routes->post('/redirects/import/commit', [$files, 'commit']);
        $routes->post('/redirects/import/sitemap', [$files, 'sitemap']);
        $routes->get('/redirects/export', [$files, 'export']);
        $routes->get('/redirects/site-config', [$files, 'siteConfig']);
        $routes->post('/redirects/site-config/import', [$files, 'siteConfigImport']);

        $routes->get('/redirects/stats', [$system, 'stats']);
        $routes->get('/redirects/checks', [$system, 'checks']);
        $routes->post('/redirects/checks/run', [$system, 'runChecks']);
        $routes->get('/redirects/pages', [$system, 'pages']);
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
            // Unseen automatic redirects plus deleted pages waiting for a decision.
            'badgeEndpoint' => '/redirects/badge',
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
        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => $this->translated('PLUGIN_REDIRECT_MANAGER.TITLE', 'Redirects'),
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
            // Admin 2 renders the widget label verbatim (the sidebar label is translated by the API plugin).
            'label' => $this->translated('PLUGIN_REDIRECT_MANAGER.WIDGET.TITLE', 'Redirects overview'),
            'icon' => 'Route',
            'sizes' => ['sm', 'md', 'lg'],
            'defaultSize' => 'md',
            // A string: DashboardLayoutResolver hands it to PermissionResolver::resolve(string) for every user
            // who is not a super admin (super admins skip the check). An array would end in a 500 for them.
            'authorize' => 'api.redirects.read',
            'priority' => 40,
            'scriptUrl' => '/gpm/plugins/' . self::SLUG . '/widget-script',
            'dataEndpoint' => '/redirects/stats',
        ];
        $event['widgets'] = $widgets;
    }

    /**
     * The translation of a key, or the fallback when the language service has none (returns nothing, an empty
     * string or the key itself).
     */
    private function translated(string $key, string $fallback): string
    {
        $text = $this->grav['language']->translate([$key]);

        return is_string($text) && $text !== '' && $text !== $key ? $text : $fallback;
    }
}
