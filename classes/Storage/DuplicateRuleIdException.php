<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use InvalidArgumentException;

/** Thrown when a rule list contains the same id twice, or a rule has an empty id. */
final class DuplicateRuleIdException extends InvalidArgumentException
{
}
