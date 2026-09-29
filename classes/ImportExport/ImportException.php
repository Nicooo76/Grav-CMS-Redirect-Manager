<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use RuntimeException;

/** A file-level problem that stops parsing (invalid JSON, too many rows, ...). */
final class ImportException extends RuntimeException
{
    public function __construct(public readonly ImportIssue $issue)
    {
        parent::__construct($issue->message);
    }
}
