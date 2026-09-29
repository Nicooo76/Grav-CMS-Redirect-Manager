<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use RuntimeException;
use Throwable;

/** Thrown when rules.yaml exists but is not a valid rules document. The file is never overwritten silently. */
final class CorruptRulesFileException extends RuntimeException
{
    public function __construct(public readonly string $rulesFile, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Rules file "%s" is corrupt: %s', $rulesFile, $reason), 0, $previous);
    }
}
