<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/**
 * Outcome of matching one request.
 *
 * $rule is the rule that decided the response (the last one in a "continue" chain),
 * $rules all rules applied in order. $location is the final target: a path starting with "/"
 * (possibly with query string) or an absolute URL; empty for 410/451.
 */
final readonly class MatchResult
{
    /**
     * @param list<Rule>                $rules
     * @param array<int|string, string> $captures
     * @param list<TraceStep>           $trace
     */
    public function __construct(
        public Rule $rule,
        public StatusCode $status,
        public string $location,
        public array $rules,
        public array $captures = [],
        public array $trace = [],
    ) {
    }

    public function isExternal(): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $this->location);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->rule->id,
            'status' => $this->status->value,
            'location' => $this->location,
            'rules' => array_map(static fn (Rule $r): string => $r->id, $this->rules),
            'captures' => $this->captures,
            'trace' => array_map(static fn (TraceStep $s): array => $s->toArray(), $this->trace),
        ];
    }
}
