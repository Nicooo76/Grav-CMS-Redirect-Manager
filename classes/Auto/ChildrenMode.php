<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

enum ChildrenMode: string
{
    /** One rule /old/* for all descendants. */
    case Wildcard = 'wildcard';
    /** One exact rule per descendant page. */
    case Each = 'each';
}
