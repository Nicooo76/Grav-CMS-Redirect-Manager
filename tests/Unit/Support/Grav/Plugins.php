<?php

declare(strict_types=1);

namespace Grav\Common {
    if (!class_exists(Plugins::class, false)) {
        class Plugins
        {
            /** @var array<string, object> */
            public static array $registry = [];

            public static function getPlugin(string $name): ?object
            {
                return self::$registry[$name] ?? null;
            }
        }
    }
}

namespace Grav\Plugin {
    use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
    use Grav\Plugin\RedirectManager\Grav\RuleEvents;
    use Grav\Plugin\RedirectManager\Grav\ServiceFactory;

    if (!class_exists(RedirectManagerPlugin::class, false)) {
        /** Exposes the accessors SchedulerJobs and the auto redirect REST controller read from the plugin instance. */
        class RedirectManagerPlugin
        {
            public function __construct(
                private readonly ServiceFactory $services,
                private readonly RuleEvents $events,
                private readonly ?AutoRedirectListener $auto = null,
            ) {
            }

            public function autoRedirects(): AutoRedirectListener
            {
                return $this->auto ?? throw new \LogicException('No auto redirect listener was given to the plugin stand-in.');
            }

            public function services(): ServiceFactory
            {
                return $this->services;
            }

            public function events(): RuleEvents
            {
                return $this->events;
            }
        }
    }
}
