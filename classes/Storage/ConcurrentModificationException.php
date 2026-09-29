<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use RuntimeException;

/** Thrown when rules.yaml changed since the caller read it (optimistic locking). */
final class ConcurrentModificationException extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        parent::__construct('The rules were changed by someone else. Reload and try again.');
    }
}
