<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Matching;

use Grav\Plugin\RedirectManager\Security\TargetGuard;

final readonly class MatcherOptions
{
    public TargetGuard $guard;

    /**
     * @param int          $maxChainDepth     most rules applied in one "continue" chain
     * @param int          $backtrackLimit    pcre.backtrack_limit while matching
     * @param int          $recursionLimit    pcre.recursion_limit while matching
     * @param string|null  $defaultLanguage   value of {lang} when the request carries no language
     * @param list<string> $globalQueryIgnore parameter name patterns ignored by query mode "exact" ("*" is a glob)
     */
    public function __construct(
        public int $maxChainDepth = 10,
        public int $backtrackLimit = 100000,
        public int $recursionLimit = 10000,
        ?TargetGuard $guard = null,
        public ?string $defaultLanguage = null,
        public array $globalQueryIgnore = ['utm_*', 'fbclid', 'gclid', 'msclkid'],
    ) {
        $this->guard = $guard ?? new TargetGuard();
    }
}
