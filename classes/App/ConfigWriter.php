<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

/**
 * Persists the one plugin setting the API changes: `log.ignore_patterns`. The Grav implementation writes
 * user/config/plugins/redirect-manager.yaml (or the active environment's file); tests use a memory copy.
 */
interface ConfigWriter
{
    /**
     * @return list<string> the configured (not the built-in) ignore patterns
     */
    public function ignorePatterns(): array;

    /**
     * @param list<string> $patterns
     */
    public function saveIgnorePatterns(array $patterns): void;
}
