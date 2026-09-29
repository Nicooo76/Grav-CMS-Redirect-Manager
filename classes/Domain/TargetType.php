<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum TargetType: string
{
    /** Internal route such as /new-page, may contain $1, {name} and {lang} placeholders. */
    case Route = 'route';
    /** Absolute URL (https://...), subject to the external host allowlist. */
    case Url = 'url';
    /** A Grav page, referenced by its route; the target follows the page when it moves. */
    case Page = 'page';
}
