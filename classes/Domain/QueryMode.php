<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum QueryMode: string
{
    /** The query string is ignored for matching and dropped from the target. */
    case Ignore = 'ignore';
    /** The query string must match exactly (order-insensitive, ignored params excluded). */
    case Exact = 'exact';
    /** The query string is ignored for matching and appended to the target. */
    case Pass = 'pass';
    /** Only the listed parameters must be present (with value, if given); others are ignored. */
    case Params = 'params';
}
