<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App\Exception;

use RuntimeException;

/** A manual live check was started too soon after the last one. Maps to HTTP 429 with Retry-After. */
final class RateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(sprintf('A check ran recently. Try again in %d seconds.', $retryAfter));
    }
}
