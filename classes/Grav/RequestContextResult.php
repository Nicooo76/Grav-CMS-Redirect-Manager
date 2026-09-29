<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\RedirectManager\Domain\RequestContext;

/**
 * A request as the redirect manager sees it.
 *
 * $rawPath is the path exactly as sent (percent-encoded, base path included), $basePath the subfolder
 * Grav runs in ("" for the web root), $languagePrefix "/de" or "" when the URL carries none.
 */
final readonly class RequestContextResult
{
    public function __construct(
        public RequestContext $context,
        public string $basePath,
        public string $languagePrefix,
        public string $rawPath,
        public string $rawQuery = '',
        public string $method = 'GET',
    ) {
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }
}
