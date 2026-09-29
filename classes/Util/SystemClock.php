<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
