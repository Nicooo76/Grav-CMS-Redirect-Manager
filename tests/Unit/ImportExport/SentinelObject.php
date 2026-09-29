<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

/** Payload class for the YAML object-injection test: any instantiation or unserialization flips the flag. */
final class SentinelObject
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }

    public function __construct()
    {
    }

    public function __destruct()
    {
    }
}
