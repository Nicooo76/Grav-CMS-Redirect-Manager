<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Jobs;

use Closure;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLogger;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\NotFound\SqliteLogStore;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Notify\WebhookEvents;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Util\Clock;
use Throwable;

/**
 * The hourly housekeeping job. Every step runs on its own: a failing step is recorded and the next one still runs.
 *
 *   stats        folds the hit logs into stats.json
 *   log          deletes 404 entries older than the retention, enforces the size cap (JSONL) or maintains the database (SQLite)
 *   suggestions  stores suggestions for 404 paths with several hits
 *   webhook      reports 404 paths that reached notifications.not_found_threshold (once per cooldown)
 *   expired      disables expired rules (only when redirects.disable_expired is on)
 */
final class MaintenanceJob
{
    private const WEBHOOK_LIMIT = 20;

    /**
     * @param (Closure(array<string, mixed>): void)|null $onSuggestion called with each created or improved suggestion record
     * @param (Closure(Rule, Rule): void)|null $onRuleDisabled called with each rule that was disabled (after, before)
     */
    public function __construct(
        private readonly StatsStore $stats,
        private readonly LogStore $log,
        private readonly NotFoundLogger $logger,
        private readonly int $retentionDays,
        private readonly Clock $clock,
        private readonly ?SuggestionRefresher $suggestions = null,
        private readonly ?WebhookNotifier $webhook = null,
        private readonly ?ThresholdTracker $tracker = null,
        private readonly int $threshold = 0,
        private readonly ?RuleRepository $rules = null,
        private readonly bool $disableExpired = false,
        private readonly ?Closure $onSuggestion = null,
        private readonly ?Closure $onRuleDisabled = null,
    ) {
    }

    /**
     * @return array<string, mixed> what each step did; failed steps have an "error" entry
     */
    public function run(): array
    {
        $result = [];
        $result['stats'] = $this->step(fn (): array => ['merged' => $this->stats->aggregate()]);
        $result['log'] = $this->step($this->maintainLog(...));
        $result['suggestions'] = $this->suggestions === null
            ? ['skipped' => true]
            : $this->step(fn (): array => ['stored' => $this->refreshSuggestions($this->suggestions)]);
        $result['webhook'] = $this->webhook === null || $this->tracker === null || $this->threshold < 1
            ? ['skipped' => true]
            : $this->step(fn (): array => ['sent' => $this->notifyThreshold($this->webhook, $this->tracker)]);
        $result['expired'] = $this->disableExpired && $this->rules !== null
            ? $this->step(fn (): array => ['disabled' => $this->disableExpiredRules($this->rules)])
            : ['skipped' => true];

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function maintainLog(): array
    {
        if ($this->log instanceof SqliteLogStore) {
            return ['purged' => $this->log->maintain($this->retentionDays), 'rotated' => 0];
        }
        $purged = $this->logger->purgeOlderThan($this->retentionDays);
        $rotated = $this->log instanceof JsonlLogStore ? $this->log->rotate() : 0;

        return ['purged' => $purged, 'rotated' => $rotated];
    }

    private function refreshSuggestions(SuggestionRefresher $refresher): int
    {
        $records = $refresher->refresh();
        foreach ($records as $record) {
            if ($this->onSuggestion !== null) {
                try {
                    ($this->onSuggestion)($record);
                } catch (Throwable) {
                    // a failing listener must not stop the job
                }
            }
        }

        return count($records);
    }

    private function notifyThreshold(WebhookNotifier $webhook, ThresholdTracker $tracker): int
    {
        $now = $this->clock->now();
        $query = new GroupQuery(
            from: $now->modify('-24 hours'),
            to: $now,
            includeBots: false,
            sort: GroupSort::Hits,
            direction: SortDirection::Desc,
            page: 1,
            perPage: 200,
        );
        $rows = [];
        $hits = [];
        foreach ($this->log->groups($query)->rows as $row) {
            if ($row->hits < $this->threshold) {
                break;
            }
            $rows[$row->path] = $row;
            $hits[$row->path] = $row->hits;
        }
        if ($hits === []) {
            return 0;
        }

        $sent = [];
        foreach (array_slice($tracker->due($hits, $this->threshold), 0, self::WEBHOOK_LIMIT) as $path) {
            $row = $rows[$path];
            $referer = null;
            foreach (array_keys($row->topReferers) as $candidate) {
                $referer = (string) $candidate;
                break;
            }
            $payload = WebhookEvents::notFoundThreshold($path, $row->hits, '24h', $row->firstSeen->format(DATE_ATOM), $referer);
            if ($webhook->send(WebhookEvents::NOT_FOUND_THRESHOLD, $payload)->ok) {
                $sent[] = $path;
            }
        }
        $tracker->markNotified($sent, $hits);

        return count($sent);
    }

    private function disableExpiredRules(RuleRepository $rules): int
    {
        $now = $this->clock->now();
        $disabled = [];
        $rules->transaction(static function (array $current) use ($now, &$disabled): array {
            $disabled = [];
            $out = [];
            foreach ($current as $rule) {
                if ($rule->enabled && $rule->isExpired($now)) {
                    $disabled[] = [$rule, $rule->with(['enabled' => false])];
                    $out[] = $rule->with(['enabled' => false]);
                } else {
                    $out[] = $rule;
                }
            }

            return $out;
        });
        foreach ($disabled as [$before, $after]) {
            if ($this->onRuleDisabled !== null) {
                try {
                    ($this->onRuleDisabled)($after, $before);
                } catch (Throwable) {
                    // ignore
                }
            }
        }

        return count($disabled);
    }

    /**
     * @param callable(): array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private function step(callable $step): array
    {
        try {
            return $step();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
