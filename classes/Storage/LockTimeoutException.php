<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

/** Thrown when a lock file could not be acquired within the timeout. */
final class LockTimeoutException extends AtomicFileException
{
}
