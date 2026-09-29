<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

enum StatusCode: int
{
    case MovedPermanently = 301;
    case Found = 302;
    case TemporaryRedirect = 307;
    case PermanentRedirect = 308;
    case Gone = 410;
    case UnavailableForLegalReasons = 451;
    /** Internal rewrite: the target page is served under the requested URL, no redirect. */
    case PassThrough = 200;

    public function isRedirect(): bool
    {
        return $this->value >= 300 && $this->value < 400;
    }

    public function isPermanent(): bool
    {
        return $this === self::MovedPermanently || $this === self::PermanentRedirect;
    }

    /** Whether a rule with this status needs a target. 410 and 451 do not. */
    public function needsTarget(): bool
    {
        return $this->isRedirect() || $this === self::PassThrough;
    }

    public function isError(): bool
    {
        return $this === self::Gone || $this === self::UnavailableForLegalReasons;
    }
}
