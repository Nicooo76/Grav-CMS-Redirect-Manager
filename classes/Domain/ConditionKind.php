<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum ConditionKind: string
{
    case Header = 'header';
    case Cookie = 'cookie';
}
