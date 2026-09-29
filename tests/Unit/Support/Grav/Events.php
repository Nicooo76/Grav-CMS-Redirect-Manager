<?php

declare(strict_types=1);

namespace Grav\Events {
    use Grav\Common\Page\Interfaces\PageInterface;
    use RocketTheme\Toolbox\Event\Event;

    if (!class_exists(PageEvent::class, false)) {
        /** The event of onPageNotFound: a listener may set $page and stop propagation. */
        class PageEvent extends Event
        {
            public ?PageInterface $page = null;
        }
    }
}
