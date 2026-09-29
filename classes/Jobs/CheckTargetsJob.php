<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Jobs;

use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\Check\TargetChecker;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Notify\WebhookEvents;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;

/**
 * The scheduled live check: HEAD requests to the targets of all enabled redirect rules, results into
 * target-checks.json, and one webhook per target that newly died (dead_target event).
 */
final class CheckTargetsJob
{
    private const WEBHOOK_LIMIT = 50;

    public function __construct(
        private readonly RuleRepository $rules,
        private readonly TargetChecker $checker,
        private readonly CheckResultStore $results,
        private readonly ?WebhookNotifier $webhook = null,
        private readonly ?ThresholdTracker $tracker = null,
        private readonly bool $notifyDead = true,
    ) {
    }

    /**
     * @return array{checked: int, dead: int, notified: int}
     */
    public function run(): array
    {
        $all = $this->rules->all();
        $byId = [];
        $targets = [];
        foreach ($all as $rule) {
            $byId[$rule->id] = $rule;
            if ($rule->enabled && $rule->status->needsTarget() && trim($rule->target) !== '') {
                $targets[] = ['id' => $rule->id, 'target' => $rule->target];
            }
        }

        $results = $targets === [] ? [] : $this->checker->check($targets);
        $this->results->save($results);
        $this->results->forget(array_values(array_diff(array_keys($this->results->all()), array_keys($byId))));

        $checked = 0;
        foreach ($results as $result) {
            $checked += $result->isSkipped() ? 0 : 1;
        }
        $dead = $this->results->dead();

        return ['checked' => $checked, 'dead' => count($dead), 'notified' => $this->notify($dead, $byId)];
    }

    /**
     * @param list<\Grav\Plugin\RedirectManager\Check\TargetCheckResult> $dead
     * @param array<string, Rule>                                        $byId
     */
    private function notify(array $dead, array $byId): int
    {
        if (!$this->notifyDead || $this->webhook === null || $this->tracker === null) {
            return 0;
        }
        $deadById = [];
        foreach ($dead as $result) {
            if (isset($byId[$result->ruleId])) {
                $deadById[$result->ruleId] = $result;
            }
        }
        $sent = [];
        foreach (array_slice($this->tracker->dueDead(array_keys($deadById)), 0, self::WEBHOOK_LIMIT) as $id) {
            $result = $deadById[$id];
            $rule = $byId[$id];
            $payload = WebhookEvents::deadTarget($id, $rule->source, $rule->target, $result->status, $result->error);
            if ($this->webhook->send(WebhookEvents::DEAD_TARGET, $payload)->ok) {
                $sent[] = $id;
            }
        }
        $this->tracker->markDeadNotified($sent);

        return count($sent);
    }
}
