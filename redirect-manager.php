<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use RocketTheme\Toolbox\Event\Event;

/**
 * Redirect Manager: event wiring only. Logic lives in classes/.
 */
class RedirectManagerPlugin extends Plugin
{
    public const SLUG = 'redirect-manager';

    /**
     * @return array<string, array<int|string, mixed>>
     */
    public static function getSubscribedEvents(): array
    {
        // Subscribed statically, never behind isAdmin(): on API/Admin 2 requests
        // $grav['admin'] only exists after route dispatch (see docs/GRAV2-NOTES.md).
        return [
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            'onApiDashboardWidgets' => ['onApiDashboardWidgets', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
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
