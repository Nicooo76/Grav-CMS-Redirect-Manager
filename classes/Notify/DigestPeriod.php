<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

enum DigestPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
