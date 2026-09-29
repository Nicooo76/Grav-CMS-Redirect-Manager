<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Jobs;

use Closure;
use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\Notify\Digest;
use Grav\Plugin\RedirectManager\Notify\DigestBuilder;
use Grav\Plugin\RedirectManager\Notify\DigestData;
use Grav\Plugin\RedirectManager\Notify\DigestPeriod;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Collects the numbers for the daily or weekly report mail and hands the finished mail to a sender.
 *
 * The sender is a closure (recipient, Digest) => bool so the job needs neither Grav nor the email plugin;
 * SchedulerJobs passes one that uses $grav['Email'].
 */
final class DigestJob
{
    private const TOP = 10;

    /**
     * @param Closure(string, Digest): bool $send
     */
    public function __construct(
        private readonly LogStore $log,
        private readonly StatsStore $stats,
        private readonly RuleRepository $rules,
        private readonly SuggestionStore $suggestions,
        private readonly CheckResultStore $checks,
        private readonly Clock $clock,
        private readonly DigestBuilder $builder,
        private readonly Closure $send,
        private readonly string $recipient,
        private readonly string $locale,
        private readonly string $siteName,
        private readonly string $siteUrl,
        private readonly string $adminUrl,
        private readonly int $retentionDays = 30,
        private readonly float $minScore = 0.5,
    ) {
    }

    /**
     * @return array{sent: bool, reason: string|null, subject: string|null}
     */
    public function run(DigestPeriod $period): array
    {
        if (trim($this->recipient) === '') {
            return ['sent' => false, 'reason' => 'no_recipient', 'subject' => null];
        }
        $data = $this->collect($period);
        $digest = $this->builder->build($data, $this->locale);
        $ok = ($this->send)(trim($this->recipient), $digest);

        return ['sent' => $ok, 'reason' => $ok ? null : 'send_failed', 'subject' => $digest->subject];
    }

    public function collect(DigestPeriod $period): DigestData
    {
        $to = $this->clock->now();
        $from = $to->modify($period === DigestPeriod::Daily ? '-1 day' : '-7 days');

        $window = $this->log->groups(new GroupQuery(
            from: $from,
            to: $to,
            includeBots: false,
            sort: GroupSort::Hits,
            direction: SortDirection::Desc,
            page: 1,
            perPage: self::TOP,
        ));
        $top = [];
        foreach ($window->rows as $row) {
            $top[$row->path] = $row->hits;
        }

        $newPaths = 0;
        $lookback = $this->log->groups(new GroupQuery(
            from: $to->modify('-' . max(1, $this->retentionDays) . ' days'),
            to: $to,
            includeBots: false,
            sort: GroupSort::First,
            direction: SortDirection::Desc,
            page: 1,
            perPage: GroupQuery::MAX_PER_PAGE,
        ));
        foreach ($lookback->rows as $row) {
            if ($row->firstSeen < $from) {
                break;
            }
            ++$newPaths;
        }

        $this->stats->aggregate();
        $rules = [];
        foreach ($this->rules->all() as $rule) {
            $rules[$rule->id] = $rule;
        }
        $redirectHits = array_sum($this->stats->totalsByDay($from, $to));
        $topRules = [];
        foreach ($this->stats->all() as $id => $stats) {
            $hits = $stats->hitsBetween($from, $to);
            if ($hits > 0 && isset($rules[$id])) {
                $topRules[] = ['id' => (string) $id, 'source' => $rules[$id]->source, 'hits' => $hits];
            }
        }
        usort($topRules, static fn (array $a, array $b): int => [$b['hits'], $a['id']] <=> [$a['hits'], $b['id']]);

        $dead = [];
        foreach ($this->checks->dead() as $result) {
            $rule = $rules[$result->ruleId] ?? null;
            if ($rule !== null) {
                $dead[] = ['source' => $rule->source, 'target' => $rule->target, 'status' => $result->status, 'error' => $result->error];
            }
        }

        return new DigestData(
            $period,
            $from,
            $to,
            $this->siteName,
            $this->siteUrl,
            $window->totals->hits,
            $top,
            $newPaths,
            $redirectHits,
            array_slice($topRules, 0, self::TOP),
            count($this->suggestions->open($this->minScore)),
            $dead,
            $this->adminUrl,
        );
    }
}
