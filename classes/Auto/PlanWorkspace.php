<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Domain\Rule;

/**
 * The rule list while a plan is being built: existing rules plus the planner's changes.
 *
 * @internal
 */
final class PlanWorkspace
{
    /** @var array<string, Rule> current state by id, insertion ordered */
    private array $rules = [];

    /** @var array<string, Rule> */
    private array $original = [];

    /** @var array<string, true> */
    private array $createdIds = [];

    /** @var list<PlanNote> */
    private array $notes = [];

    /**
     * @param list<Rule> $existing
     */
    public function __construct(array $existing)
    {
        foreach ($existing as $rule) {
            $this->rules[$rule->id] = $rule;
            $this->original[$rule->id] = $rule;
        }
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    public function add(Rule $rule): void
    {
        $this->rules[$rule->id] = $rule;
        $this->createdIds[$rule->id] = true;
    }

    public function replace(Rule $rule): void
    {
        if (isset($this->rules[$rule->id])) {
            $this->rules[$rule->id] = $rule;
        }
    }

    public function remove(string $id): void
    {
        unset($this->rules[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    public function note(PlanNote $note): void
    {
        $this->notes[] = $note;
    }

    public function toPlan(?PageSnapshot $pending = null): AutoPlan
    {
        $create = [];
        $update = [];
        foreach ($this->rules as $id => $rule) {
            if (isset($this->createdIds[$id])) {
                $create[] = $rule;
                continue;
            }
            $before = $this->original[$id] ?? null;
            if ($before !== null && $before->toArray() !== $rule->toArray()) {
                $update[$id] = $rule;
            }
        }
        $delete = [];
        foreach (array_keys($this->original) as $id) {
            if (!isset($this->rules[$id])) {
                $delete[] = $id;
            }
        }

        return new AutoPlan($create, $update, $delete, $this->notes, $pending);
    }
}
