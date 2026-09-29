<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Closure;
use DateTimeZone;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\DayRange;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;

/**
 * Numbers for the dashboard and the page search of the target picker.
 *
 * All day figures are UTC days. "Today" is the current UTC day, "7d" is today plus the six days before it, the by-day
 * maps cover 30 days, oldest first, zero filled. 404 numbers exclude bots (as the monitor does by default).
 */
final class StatsService
{
    public const DASHBOARD_DAYS = 30;
    public const PAGE_LIMIT_MAX = 100;

    /**
     * @param Closure(): int|null $pendingDeletes number of deleted pages waiting for a decision; null = 0
     */
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly RuleService $rules,
        private readonly StatsAccess $stats,
        private readonly ?Closure $pendingDeletes,
    ) {
    }

    /**
     * @return array{not_found_today: int, not_found_7d: int, not_found_by_day: array<string, int>, hits_today: int, hits_7d: int, hits_by_day: array<string, int>, rules_total: int, rules_active: int, open_suggestions: int, dead_targets: int, pending_deletes: int}
     */
    public function dashboard(): array
    {
        $now = $this->services->clock()->now()->setTimezone(new DateTimeZone('UTC'));
        $from = $now->setTime(0, 0)->modify('-' . (self::DASHBOARD_DAYS - 1) . ' days');

        $notFound = $this->services->logStore()->countsByDay($from, $now, false);
        $hits = $this->stats->store()->totalsByDay($from, $now);
        $today = DayRange::key($now->getTimestamp());

        $rules = $this->rules->summary();

        return [
            'not_found_today' => $notFound[$today] ?? 0,
            'not_found_7d' => $this->lastDays($notFound, 7),
            'not_found_by_day' => $notFound,
            'hits_today' => $hits[$today] ?? 0,
            'hits_7d' => $this->lastDays($hits, 7),
            'hits_by_day' => $hits,
            'rules_total' => $rules['total'],
            'rules_active' => $rules['active'],
            'open_suggestions' => count($this->services->suggestionStore()->open()),
            'dead_targets' => $this->deadTargets($rules['enabled']),
            'pending_deletes' => $this->pendingDeletes === null ? 0 : ($this->pendingDeletes)(),
        ];
    }

    /**
     * Page search for the target picker.
     *
     * @return list<array{route: string, title: string, language: string, translations: list<string>}>
     */
    public function pages(?string $q, ?string $language, int $limit = 20): array
    {
        $limit = max(1, min(self::PAGE_LIMIT_MAX, $limit));
        $index = $this->services->pageIndex();
        $text = $index->text();
        $needle = $text->ascii(trim((string) $q));
        $language = $language === null || trim($language) === '' ? null : strtolower(trim($language));

        $ranked = [];
        foreach ($index->all() as $page) {
            if ($language !== null && $page->language !== null && strtolower($page->language) !== $language) {
                continue;
            }
            $rank = 0;
            if ($needle !== '') {
                $route = $text->ascii($page->route);
                $title = $text->ascii($page->title);
                if (str_starts_with($needle[0] === '/' ? $route : ltrim($route, '/'), $needle)) {
                    $rank = 0;
                } elseif (str_starts_with($title, $needle)) {
                    $rank = 1;
                } elseif (str_contains($route, $needle) || str_contains($title, $needle)) {
                    $rank = 2;
                } else {
                    continue;
                }
            }
            $ranked[] = [$rank, strlen($page->route), $page->route, $page];
        }

        usort($ranked, static fn (array $a, array $b): int => $needle === ''
            ? $a[2] <=> $b[2]
            : [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(
            static fn (array $item): array => self::pageRow($item[3]),
            array_slice($ranked, 0, $limit),
        );
    }

    /**
     * @return array{route: string, title: string, language: string, translations: list<string>}
     */
    private static function pageRow(PageInfo $page): array
    {
        return [
            'route' => $page->route,
            'title' => $page->title,
            'language' => $page->language ?? '',
            'translations' => array_map('strval', array_keys($page->translations)),
        ];
    }

    /**
     * @param array<string, int> $byDay oldest first
     */
    private function lastDays(array $byDay, int $days): int
    {
        return array_sum(array_slice($byDay, -$days));
    }

    /**
     * Rules whose last live check found the target dead, as long as the rule still exists and is enabled.
     *
     * @param array<string, true> $enabled ids of the enabled rules
     */
    private function deadTargets(array $enabled): int
    {
        $dead = 0;
        foreach ($this->services->checkResultStore()->all() as $result) {
            if ($result->isDead() && isset($enabled[$result->ruleId])) {
                ++$dead;
            }
        }

        return $dead;
    }
}
