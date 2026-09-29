<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/** How a deleted page's URLs are handled once the decision is made. */
enum DeleteAction: string
{
    case Gone = 'gone';
    case Parent = 'parent';
    /** Redirect to a target the editor chose. */
    case Redirect = 'redirect';
}
