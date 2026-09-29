<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use RuntimeException;

/** A capability that needs Grav (config writing, HTTP client) is not available in this context. Maps to HTTP 503. */
final class UnavailableException extends RuntimeException
{
}
