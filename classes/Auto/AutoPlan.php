<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Domain\Rule;

/**
 * What one page change does to the rules: rules to add, rules to replace (same id, new content), rules to
 * delete, notes about what was skipped, and a pending decision for a deleted page (policy "ask").
 */
final readonly class AutoPlan
{
    /**
     * @param list<Rule>            $create
     * @param array<string, Rule>   $update  rule id => the rule as it should be stored
     * @param list<string>          $delete  rule ids
     * @param list<PlanNote>        $notes
     */
    public function __construct(
        public array $create = [],
        public array $update = [],
        public array $delete = [],
        public array $notes = [],
        public ?PageSnapshot $pending = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->create === [] && $this->update === [] && $this->delete === [] && $this->pending === null;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'created' => count($this->create),
            'updated' => count($this->update),
            'deleted' => count($this->delete),
            'skipped' => count($this->notes),
            'pending' => $this->pending === null ? 0 : 1,
        ];
    }
}
