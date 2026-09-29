<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use RuntimeException;

/** Thrown when a 404 log cannot be written or opened. */
class LogStoreException extends RuntimeException
{
}
