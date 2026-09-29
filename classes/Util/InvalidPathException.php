<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Util;

use InvalidArgumentException;

/** Thrown by PathNormalizer for paths that must never be matched (NUL bytes, bad UTF-8, too long). */
final class InvalidPathException extends InvalidArgumentException
{
}
