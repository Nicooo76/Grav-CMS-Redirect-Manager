<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * Result of following one rule's redirect through the rule set for one language. Internal to the analysis.
 *
 * Kinds: chain (two or more hops), loop, self_redirect, too_deep, skipped (no sample could be derived).
 */
final readonly class ChainOutcome
{
    public const CHAIN = 'chain';
    public const LOOP = 'loop';
    public const SELF = 'self_redirect';
    public const TOO_DEEP = 'too_deep';
    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $ruleIds   every rule applied on the way, start rule first
     * @param list<string> $paths     source sample, then the location of each redirect
     * @param list<string> $cycleIds  rules that form the loop (loop and self_redirect only)
     */
    public function __construct(
        public string $kind,
        public string $startId,
        public ?string $language,
        public int $hops,
        public array $ruleIds,
        public array $paths,
        public array $cycleIds = [],
        public ?string $shortcut = null,
        public ?int $shortcutStatus = null,
    ) {
    }
}
