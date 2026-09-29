<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

enum UserAgentClass: string
{
    case Browser = 'browser';
    case Bot = 'bot';
    case Monitoring = 'monitoring';
    case Unknown = 'unknown';
}
