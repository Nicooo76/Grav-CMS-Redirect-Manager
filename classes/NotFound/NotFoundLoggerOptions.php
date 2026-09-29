<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/** Settings of NotFoundLogger. Defaults are privacy-first: no IP is stored unless storeIp is set. */
final readonly class NotFoundLoggerOptions
{
    public function __construct(
        public bool $enabled = true,
        public bool $logBots = true,
        public bool $storeIp = false,
        public int $maxPathBytes = 2048,
        public int $maxQueryBytes = 1024,
        public int $maxRefererBytes = 1024,
        public int $maxUserAgentBytes = 512,
    ) {
    }
}
