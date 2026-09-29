<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Closure;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a plan against rules.yaml: plans inside RuleRepository::transaction() (so a concurrent edit is never
 * overwritten), stores the result, then tells the world about it. No Grav classes; the Grav adapter passes
 * closures for the event dispatch and the cache invalidation.
 *
 * After a successful write: the compiled cache is invalidated, onRedirectRuleSaved fires per created or updated
 * rule (action "auto") and per deleted rule (action "delete"), created and re-targeted rule ids go to the
 * "unseen" list, deleted ids leave it, a pending decision is recorded, and one line goes to the log.
 */
final class AutoRedirectApplier
{
    /**
     * @param (Closure(Rule, ?Rule, string): void)|null $onSaved    receives (rule, previous, action)
     * @param (Closure(): void)|null                    $invalidate drops the compiled rule cache
     */
    public function __construct(
        private readonly RuleRepository $repository,
        private readonly AutoState $state,
        private readonly AutoRedirectConfig $config,
        private readonly ?Closure $onSaved = null,
        private readonly ?Closure $invalidate = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A page moved or was renamed.
     *
     * @param (callable(string, string): bool)|null $isLive whether another page serves the route in the language now
     */
    public function move(PageSnapshot $before, PageSnapshot $after, ?callable $isLive = null): AutoPlan
    {
        $planner = new AutoRedirectPlanner($this->config);
        $probe = $planner->planMove($before, $after, [], $isLive);
        if ($probe->create === [] && $probe->notes === []) {
            return $probe; // no route changed (or nothing can be created): rules.yaml stays untouched
        }

        return $this->commit($before->title, static fn (array $rules): AutoPlan => $planner->planMove($before, $after, $rules, $isLive));
    }

    /**
     * A page was deleted; acts on the configured policy.
     *
     * @param (callable(string, string): bool)|null $routeExists
     */
    public function delete(PageSnapshot $before, ?callable $routeExists = null): AutoPlan
    {
        $planner = new AutoRedirectPlanner($this->config);
        if ($this->config->onDelete === DeletePolicy::Ask || $this->config->onDelete === DeletePolicy::Never) {
            $plan = $planner->planDelete($before, [], null, $routeExists);
            $this->record($before->title, $plan, []);

            return $plan;
        }

        return $this->commit($before->title, fn (array $rules): AutoPlan => $planner->planDelete($before, $rules, null, $routeExists));
    }

    /**
     * The editor decided about a pending deleted page.
     *
     * @param (callable(string, string): bool)|null $routeExists
     */
    public function resolve(PageSnapshot $before, DeleteAction $action, ?string $target = null, ?callable $routeExists = null): AutoPlan
    {
        $planner = new AutoRedirectPlanner($this->config);

        return $this->commit($before->title, static fn (array $rules): AutoPlan => $planner->planDeleteAction($before, $rules, $action, $target, $routeExists));
    }

    /**
     * @param callable(list<Rule>): AutoPlan $planFor
     */
    private function commit(string $title, callable $planFor): AutoPlan
    {
        $plan = new AutoPlan();
        $original = [];
        $stored = $this->repository->transaction(static function (array $current) use ($planFor, &$plan, &$original): array {
            $original = [];
            foreach ($current as $rule) {
                $original[$rule->id] = $rule;
            }
            $plan = $planFor($current);

            return self::merge($current, $plan);
        });

        $this->record($title, $plan, $stored, $original);

        return $plan;
    }

    /**
     * @param list<Rule> $current
     *
     * @return list<Rule>
     */
    private static function merge(array $current, AutoPlan $plan): array
    {
        $delete = array_flip($plan->delete);
        $out = [];
        foreach ($current as $rule) {
            if (isset($delete[$rule->id])) {
                continue;
            }
            $out[] = $plan->update[$rule->id] ?? $rule;
        }
        foreach ($plan->create as $rule) {
            $out[] = $rule;
        }

        return $out;
    }

    /**
     * @param list<Rule>           $stored   the rules as written
     * @param array<string, Rule>  $original the rules before the change, by id
     */
    private function record(string $title, AutoPlan $plan, array $stored, array $original = []): void
    {
        if ($plan->pending !== null) {
            try {
                $this->state->addPending($plan->pending);
            } catch (Throwable $e) {
                $this->logger?->error('Redirect Manager: cannot record the deleted page: ' . $e->getMessage());
            }
        }
        if ($plan->create === [] && $plan->update === [] && $plan->delete === []) {
            $this->logSummary($title, $plan);

            return;
        }

        try {
            if ($this->invalidate !== null) {
                ($this->invalidate)();
            }
        } catch (Throwable) {
            // The cache notices the changed file on its own (mtime and size).
        }

        $byId = [];
        foreach ($stored as $rule) {
            $byId[$rule->id] = $rule;
        }
        $touched = [];
        foreach ($plan->create as $rule) {
            $touched[] = $rule->id;
            $this->fire($byId[$rule->id] ?? $rule, null, RuleEvents::ACTION_AUTO);
        }
        foreach (array_keys($plan->update) as $id) {
            $touched[] = $id;
            $rule = $byId[$id] ?? null;
            if ($rule !== null) {
                $this->fire($rule, $original[$id] ?? null, RuleEvents::ACTION_AUTO);
            }
        }
        foreach ($plan->delete as $id) {
            $rule = $original[$id] ?? null;
            if ($rule !== null) {
                $this->fire($rule, $rule, RuleEvents::ACTION_DELETE);
            }
        }

        try {
            $this->state->forgetUnseen($plan->delete);
            $this->state->addUnseen(array_values(array_filter($touched, static fn (string $id): bool => !in_array($id, $plan->delete, true))));
        } catch (Throwable $e) {
            $this->logger?->warning('Redirect Manager: cannot update the auto-redirect state: ' . $e->getMessage());
        }
        $this->logSummary($title, $plan);
    }

    private function logSummary(string $title, AutoPlan $plan): void
    {
        if ($this->logger === null) {
            return;
        }
        $c = $plan->counts();
        if ($plan->isEmpty() && $plan->notes === []) {
            return;
        }
        $notes = [];
        foreach ($plan->notes as $note) {
            $notes[] = $note->kind . ' ' . $note->source;
        }
        $this->logger->info(sprintf(
            'Redirect Manager: auto-redirect for "%s": %d created, %d updated, %d removed, %d skipped%s%s',
            $title,
            $c['created'],
            $c['updated'],
            $c['deleted'],
            $c['skipped'],
            $c['pending'] > 0 ? ', decision pending' : '',
            $notes === [] ? '' : ' (' . implode('; ', $notes) . ')',
        ));
    }

    private function fire(Rule $rule, ?Rule $previous, string $action): void
    {
        if ($this->onSaved === null) {
            return;
        }
        try {
            ($this->onSaved)($rule, $previous, $action);
        } catch (Throwable $e) {
            $this->logger?->warning('Redirect Manager: a listener of onRedirectRuleSaved failed: ' . $e->getMessage());
        }
    }
}
