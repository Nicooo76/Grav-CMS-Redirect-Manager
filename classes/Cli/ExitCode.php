<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Cli;

/**
 * Exit codes of the `bin/plugin redirect-manager` commands.
 *
 *  0  OK        success
 *  1  ERROR     runtime error (unexpected exception, file cannot be written, service unavailable)
 *  2  INVALID   invalid input or a rule that fails validation
 *  3  MISMATCH  `test --expect-status/--expect-location` did not match, or `check-targets` found dead targets
 *  4  NOT_FOUND a rule id (or other record) does not exist
 */
final class ExitCode
{
    public const OK = 0;
    public const ERROR = 1;
    public const INVALID = 2;
    public const MISMATCH = 3;
    public const NOT_FOUND = 4;

    private function __construct()
    {
    }
}
