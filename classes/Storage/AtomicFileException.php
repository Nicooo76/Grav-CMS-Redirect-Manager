<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use RuntimeException;

/** Thrown when a file cannot be written, read or locked. */
class AtomicFileException extends RuntimeException
{
}
