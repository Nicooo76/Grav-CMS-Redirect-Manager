<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Events\PageEvent;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Events\PluginsLoadedEvent;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
use Grav\Plugin\RedirectManager\Grav\AdminIntegration;
use Grav\Plugin\RedirectManager\Grav\FrontendRedirectHandler;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Grav\Plugin\RedirectManager\Grav\RouteRegistrar;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\SchedulerJobs;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Grav\TwigIntegration;
use RocketTheme\Toolbox\Event\Event;

/**
 * Redirect Manager: event wiring only. Every event method delegates into classes/ (FrontendRedirectHandler for the
 * request flow, AdminIntegration, TwigIntegration, RouteRegistrar, SchedulerJobs, AutoRedirectListener).
 * No class of the plugin may be a base class or trait of this one: the file loads before autoload() runs.
 */
class RedirectManagerPlugin extends Plugin
{
    public const SLUG = 'redirect-manager';

    private ?ServiceFactory $services = null;

    private ?AutoRedirectListener $auto = null;

    private ?FrontendRedirectHandler $frontend = null;

    /**
     * @return array<string, array<int|string, mixed>>
     */
    public static function getSubscribedEvents(): array
    {
        // Subscribed statically, never behind isAdmin(): on API/Admin 2 requests
        // $grav['admin'] only exists after route dispatch (see docs/GRAV2-NOTES.md).
        $events = [
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            // First thing a plugin can see, before session and trailing-slash redirect: see docs/DECISIONS.md D-004.
            PluginsLoadedEvent::class => ['onPluginsLoaded', 10000],
            'onPagesInitialized' => ['onPagesInitialized', 10],
            // Above the error plugin (priority 0), which stops propagation.
            'onPageNotFound' => ['onPageNotFound', 10],
            // Late, so theme template paths come first and a theme can override our templates.
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', -1000],
            // Two listeners: the plugin's REST routes and the auto-redirect routes (pending decisions, badge).
            'onApiRegisterRoutes' => [['onApiRegisterRoutes', 0], ['onApiRegisterAutoRoutes', 0]],
        ];
        // Same-named method at priority 0. onSchedulerInitialized only fires in scheduler runs, never on page requests.
        // The page-change events come from the API plugin (Admin 2, REST, MCP), never fire without it and share one method.
        foreach ([
            'onTwigInitialized', 'onBuildTwigSandboxPolicy', 'onSchedulerInitialized',
            'onApiSidebarItems', 'onApiPluginPageInfo', 'onApiDashboardWidgets', 'onApiMcpTools',
        ] as $name) {
            $events[$name] = [$name, 0];
        }
        foreach (array_keys(AutoRedirectListener::EVENTS) as $name) {
            $events[$name] = ['onApiPageEvent', 0];
        }

        return $events;
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsLoaded(PluginsLoadedEvent $event): void
    {
        $this->frontend()->onPluginsLoaded();
    }

    public function onPagesInitialized(): void
    {
        $this->frontend()->onPagesInitialized();
    }

    public function onPageNotFound(PageEvent $event): void
    {
        $this->frontend()->onPageNotFound($event);
    }

    public function onTwigTemplatePaths(): void
    {
        $this->twig()->templatePaths();
    }

    public function onTwigInitialized(): void
    {
        $this->twig()->initialized();
    }

    public function onBuildTwigSandboxPolicy(Event $event): void
    {
        $this->twig()->sandboxPolicy($event);
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $this->admin()->registerPermissions($event);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $this->admin()->sidebarItems($event);
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        $this->admin()->pluginPageInfo($event);
    }

    public function onApiDashboardWidgets(Event $event): void
    {
        $this->admin()->dashboardWidgets($event);
    }

    public function onApiMcpTools(Event $event): void
    {
        $this->admin()->registerMcpTools($event);
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        RouteRegistrar::register($event['routes']);
    }

    public function onApiRegisterAutoRoutes(Event $event): void
    {
        RouteRegistrar::registerAuto($event['routes']);
    }

    /** onApiBeforePageUpdate, onApiPageUpdated, onApiPageMoved, onApiBeforePageDelete, onApiPageDeleted, onApiBeforePagesReorganize, onApiPagesReorganized */
    public function onApiPageEvent(Event $event, string $eventName): void
    {
        $this->autoRedirects()->dispatch($eventName, $event);
    }

    public function onSchedulerInitialized(Event $event): void
    {
        SchedulerJobs::onInitialized($this->grav, $event['scheduler'], $this->services(...));
    }

    /** The services, built lazily from Grav's config and locator. Also used by the API and CLI code. */
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

    private function frontend(): FrontendRedirectHandler
    {
        return $this->frontend ??= new FrontendRedirectHandler($this->grav, $this->services(...), $this->events(...), $_SERVER);
    }

    private function twig(): TwigIntegration
    {
        return new TwigIntegration($this->grav, $this->services(...), __DIR__, $_SERVER);
    }

    private function admin(): AdminIntegration
    {
        return new AdminIntegration($this->grav, __DIR__);
    }
}
