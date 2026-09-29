<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum ConditionOperator: string
{
    case Exists = 'exists';
    case Equals = 'equals';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Regex = 'regex';
}
