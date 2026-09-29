<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use RuntimeException;

/** The record changed since the caller read it, or its state does not allow the action. Maps to HTTP 409. */
final class RevisionConflictException extends RuntimeException
{
}
