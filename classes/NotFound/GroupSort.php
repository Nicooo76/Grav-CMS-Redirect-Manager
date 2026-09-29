<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

enum GroupSort: string
{
    case Hits = 'hits';
    case Last = 'last';
    case First = 'first';
    case Path = 'path';
}
