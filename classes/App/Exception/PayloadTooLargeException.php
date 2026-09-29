<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use RuntimeException;

/** An import body is larger than import.max_mb. Maps to HTTP 413. */
final class PayloadTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $size, public readonly int $max)
    {
        parent::__construct(sprintf('The import is %d bytes; the limit is %d bytes.', $size, $max));
    }
}
