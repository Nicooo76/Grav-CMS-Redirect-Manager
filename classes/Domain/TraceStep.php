<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/**
 * One evaluated candidate in the rule tester trace.
 *
 * Reasons: matched, no_match, disabled, expired, scheduled, phase, query, host, language,
 * scheme, condition, regex_error.
 */
final readonly class TraceStep
{
    public function __construct(
        public string $ruleId,
        public string $source,
        public MatchType $matchType,
        public int $priority,
        public bool $matched,
        public string $reason,
        public string $path,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'source' => $this->source,
            'match_type' => $this->matchType->value,
            'priority' => $this->priority,
            'matched' => $this->matched,
            'reason' => $this->reason,
            'path' => $this->path,
        ];
    }
}
