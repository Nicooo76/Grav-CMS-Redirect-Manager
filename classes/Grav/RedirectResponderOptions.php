<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

final readonly class RedirectResponderOptions
{
    /**
     * @param list<string> $languages known language codes (system.languages.supported), lowercase
     */
    public function __construct(
        public bool $keepLanguagePrefix = true,
        public string $cacheControlPermanent = 'public, max-age=3600',
        public string $cacheControlTemporary = 'no-store',
        public array $languages = [],
    ) {
    }
}
