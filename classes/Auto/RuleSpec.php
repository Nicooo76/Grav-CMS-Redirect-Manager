<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;

/**
 * A rule the planner intends to create, before it is checked against the existing rules.
 *
 * @internal
 */
final readonly class RuleSpec
{
    /**
     * @param list<string> $languages    languages this spec is for (PageSnapshot::ANY on single-language sites)
     * @param list<string> $allLanguages every language the page exists in
     */
    public function __construct(
        public string $source,
        public string $target,
        public MatchType $match,
        public StatusCode $status,
        public TargetType $targetType,
        public array $languages,
        public array $allLanguages,
        public string $note,
        /** An existing auto rule with the same source is re-targeted (page moved). Otherwise the spec is skipped (page deleted). */
        public bool $replaceAuto = true,
    ) {
    }

    public function isWildcard(): bool
    {
        return $this->match === MatchType::Wildcard;
    }

    /**
     * Language condition of the rule: empty when the spec covers every language of the page.
     *
     * @return list<string>
     */
    public function conditionLanguages(): array
    {
        if ($this->languages === [PageSnapshot::ANY]) {
            return [];
        }
        $all = array_values(array_diff($this->allLanguages, [PageSnapshot::ANY]));
        $mine = $this->languages;
        sort($all);
        sort($mine);

        return $all === $mine ? [] : $this->languages;
    }

    /** Source of the rule that would undo this one (page moved back). */
    public function reverseSource(): string
    {
        return $this->isWildcard() ? str_replace('$1', '*', $this->target) : $this->target;
    }

    /** Target of the rule that would undo this one. */
    public function reverseTarget(): string
    {
        return $this->isWildcard() ? str_replace('*', '$1', $this->source) : $this->source;
    }
}
