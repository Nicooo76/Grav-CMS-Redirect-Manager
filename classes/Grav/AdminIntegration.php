<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Common\Grav;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use RocketTheme\Toolbox\Event\Event;

/**
 * What the plugin tells the API plugin and Admin 2: permissions, sidebar entry, page definition, dashboard widget
 * and MCP tools.
 */
final class AdminIntegration
{
    public const SLUG = GravBootstrap::SLUG;

    /**
     * @param string $pluginDir absolute path of the plugin directory
     */
    public function __construct(private readonly Grav $grav, private readonly string $pluginDir)
    {
    }

    public function registerPermissions(PermissionsRegisterEvent $event): void
    {
        // Not in the plugin root: GPM names a direct-installed package after the first *.yaml there (D-024).
        $actions = PermissionsReader::fromYaml('plugin://' . self::SLUG . '/config/permissions.yaml');
        $event->permissions->addActions($actions);
    }

    /**
     * MCP tools for the API plugin (GET /api/v1/mcp/tools). Declared in config/mcp.yaml and registered here
     * because a mcp.yaml in the plugin root breaks `bin/gpm direct-install` (docs/DECISIONS.md D-024).
     */
    public function registerMcpTools(Event $event): void
    {
        McpManifest::register($event['tools'], self::SLUG, $this->pluginDir . '/' . McpManifest::FILE);
    }

    public function sidebarItems(Event $event): void
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

    public function pluginPageInfo(Event $event): void
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

    public function dashboardWidgets(Event $event): void
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
