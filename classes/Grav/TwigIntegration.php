<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

/**
 * Twig side of the plugin: template paths (410/451 pages), the redirect_for() function, the redirect_target
 * filter and the sandbox allow list for both.
 */
final class TwigIntegration
{
    /**
     * @param Closure(): ServiceFactory $services
     * @param string                    $pluginDir absolute path of the plugin directory
     * @param array<string, mixed>      $server    $_SERVER of the request
     */
    public function __construct(
        private readonly Grav $grav,
        private readonly Closure $services,
        private readonly string $pluginDir,
        private readonly array $server = [],
    ) {
    }

    /** Late (priority -1000), so theme template paths come first and a theme can override our templates. */
    public function templatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = $this->pluginDir . '/templates';
    }

    public function initialized(): void
    {
        try {
            $lookup = new RedirectLookup(($this->services)(), $this->server, (string) ($this->server['HTTP_HOST'] ?? ''));
            $this->grav['twig']->twig()->addExtension(new TwigExtension($lookup->lookup(...)));
        } catch (Throwable $e) {
            try {
                $this->grav['log']->error(sprintf('Redirect Manager: Twig extension failed: %s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine()));
            } catch (Throwable) {
                // nothing left to do
            }
        }
    }

    public function sandboxPolicy(Event $event): void
    {
        $functions = $event['functions'];
        $functions[] = 'redirect_for';
        $event['functions'] = $functions;

        $filters = $event['filters'];
        $filters[] = 'redirect_target';
        $event['filters'] = $filters;
    }
}
