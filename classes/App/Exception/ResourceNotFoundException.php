<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use RuntimeException;

/** A rule, suggestion or other record does not exist. Maps to HTTP 404 and CLI exit code 4. */
final class ResourceNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly string $id)
    {
        parent::__construct(sprintf('%s "%s" was not found.', ucfirst($kind), $id));
    }
}
