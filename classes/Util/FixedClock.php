<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

use DateTimeImmutable;

/** Clock with a settable time, for tests and deterministic tooling. */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
