<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Stats\RuleStats;
use Throwable;

/**
 * What the redirect rules say about one page, for the context panel in Admin 2's page editor (GET /redirects/page-context).
 *
 * A page is identified by its route (no language prefix, "/blog/news") and, on multi-language sites, a language.
 *
 *   incoming   rules that lead here: exact rules whose target is the route, wildcard rules "old/*" to "route/$1"
 *   created    automatic rules for this page or below it that nobody has looked at yet or that are under 24 hours old
 *   outgoing   the rule that redirects requests for this route somewhere else (the page is unreachable), or null
 *   pending    deleted descendants that wait for a decision
 *   not_found  404 hits of the last 30 days on the old URLs of the incoming rules
 *
 * "Unseen" is the plugin-wide list of AutoState; this service only narrows it to the page. The home page ("/") matches
 * itself only: everything is below it, and a badge that counts the whole site would say nothing.
 */
final class PageContextService
{
    public const MAX_ROWS = 50;
    public const MAX_NOT_FOUND = 20;
    public const RECENT_HOURS = 24;
    public const NOT_FOUND_DAYS = 30;
    public const MAX_ROUTE_LENGTH = 2048;

    private const HERE = 'here';
    private const BELOW = 'below';

    public function __construct(
        private readonly ServiceFactory $services,
        private readonly RuleService $rules,
        private readonly TesterService $tester,
        private readonly StatsAccess $stats,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidInputException
     */
    public function context(string $route, ?string $language): array
    {
        $route = self::normalizeRoute($route);
        $language = self::normalizeLanguage($language);
        $key = self::key($route);
        $now = $this->services->clock()->now();
        $recentSince = $now->modify('-' . self::RECENT_HOURS . ' hours');
        $state = $this->services->autoState();
        $unseenIds = array_flip($state->unseen());
        $stats = $this->stats->store()->all();

        $incoming = [];
        $created = [];
        $all = $this->rules->all();
        foreach ($all as $rule) {
            if (!self::forLanguage($rule, $language)) {
                continue;
            }
            $target = self::pointsAt($rule, $key);
            if ($target === self::HERE) {
                $incoming[] = $rule;
            }
            if ($rule->origin === RuleSource::Auto && $target !== null) {
                $unseen = isset($unseenIds[$rule->id]);
                if ($unseen || ($rule->createdAt !== null && $rule->createdAt >= $recentSince)) {
                    $created[] = $rule;
                }
            }
        }

        $newest = static fn (Rule $a, Rule $b): int => [$b->createdAt?->getTimestamp() ?? 0, $b->id] <=> [$a->createdAt?->getTimestamp() ?? 0, $a->id];
        usort($incoming, $newest);
        usort($created, $newest);
        $unseenCount = count(array_filter($created, static fn (Rule $r): bool => isset($unseenIds[$r->id])));

        $row = fn (Rule $rule): array => $this->row($rule, $stats[$rule->id] ?? new RuleStats(), isset($unseenIds[$rule->id]), $now, $recentSince);

        return [
            'route' => $route,
            'language' => $language,
            'incoming' => array_map($row, array_slice($incoming, 0, self::MAX_ROWS)),
            'incoming_total' => count($incoming),
            'created' => array_map($row, array_slice($created, 0, self::MAX_ROWS)),
            'created_total' => count($created),
            'unseen' => $unseenCount,
            'outgoing' => $this->outgoing($route, $language, $all, $stats, $now, $recentSince, $unseenIds),
            'pending' => $this->pending($route),
            'not_found' => $this->notFound($incoming, $now),
            'generated_at' => $now->format(Rule::DATE_FORMAT),
        ];
    }

    /**
     * Number of unseen automatic rules for the page (the toolbar badge). Reads no rules when nothing is unseen.
     *
     * @throws InvalidInputException
     */
    public function unseenCount(string $route, ?string $language): int
    {
        return count($this->unseenIds(self::normalizeRoute($route), self::normalizeLanguage($language)));
    }

    /**
     * Marks the unseen automatic rules of this page as seen and leaves the ones of other pages alone.
     *
     * @return array{cleared: int, count: int|null, sidebar: int|null} count = what is left for the page (null for none), sidebar = the number the sidebar badge shows now
     *
     * @throws InvalidInputException
     */
    public function markSeen(string $route, ?string $language): array
    {
        $ids = $this->unseenIds(self::normalizeRoute($route), self::normalizeLanguage($language));
        $state = $this->services->autoState();
        $state->forgetUnseen($ids);
        $sidebar = $state->badgeCount($this->existingIds());

        return ['cleared' => count($ids), 'count' => null, 'sidebar' => $sidebar > 0 ? $sidebar : null];
    }

    /**
     * @throws InvalidInputException
     */
    public static function normalizeRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            throw new InvalidInputException('"route" is required.', field: 'route', errorCode: 'required');
        }
        if ($route[0] !== '/' || strlen($route) > self::MAX_ROUTE_LENGTH || preg_match('/[\x00-\x1f\x7f?#]/', $route) === 1 || !mb_check_encoding($route, 'UTF-8')) {
            throw new InvalidInputException('"route" must be a page route such as /blog/news.', field: 'route', errorCode: 'invalid_route');
        }
        $route = preg_replace('#/{2,}#', '/', $route) ?? $route;

        return strlen($route) > 1 ? rtrim($route, '/') : $route;
    }

    private static function normalizeLanguage(?string $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return preg_match('/^[a-z]{2,3}([_-][a-z0-9]{2,8})?$/', $language) === 1 ? $language : null;
    }

    /**
     * How the target of a rule relates to the page: HERE (the page itself, or a wildcard "new/$1" for its subpages), BELOW
     * (a descendant), null (unrelated, external, or a rule without target).
     */
    private static function pointsAt(Rule $rule, string $key): ?string
    {
        if ($rule->targetType === TargetType::Url || !$rule->status->needsTarget() || $rule->target === '' || $rule->matchType === MatchType::Regex) {
            return null;
        }
        $target = $rule->target;
        if ($rule->matchType === MatchType::Wildcard) {
            if (preg_match('#^(.*?)/?\$1$#', $target, $m) !== 1) {
                return null;
            }
            $target = $m[1] === '' ? '/' : $m[1];
        }
        if (str_contains($target, '?') || $target[0] !== '/') {
            return null;
        }
        $target = self::key($target);
        if ($target === $key) {
            return self::HERE;
        }

        return $key !== '/' && str_starts_with($target, $key . '/') ? self::BELOW : null;
    }

    private static function forLanguage(Rule $rule, ?string $language): bool
    {
        $languages = $rule->conditions->languages;

        return $language === null || $languages === [] || in_array($language, $languages, true);
    }

    private static function key(string $path): string
    {
        $path = strlen($path) > 1 ? rtrim($path, '/') : $path;

        return mb_strtolower($path === '' ? '/' : $path, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function unseenIds(string $route, ?string $language): array
    {
        $unseen = $this->services->autoState()->unseen();
        if ($unseen === []) {
            return [];
        }
        $wanted = array_flip($unseen);
        $key = self::key($route);
        $ids = [];
        foreach ($this->rules->all() as $rule) {
            if (isset($wanted[$rule->id]) && $rule->origin === RuleSource::Auto && self::forLanguage($rule, $language) && self::pointsAt($rule, $key) !== null) {
                $ids[] = $rule->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function existingIds(): array
    {
        return array_map(static fn (Rule $r): string => $r->id, $this->rules->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Rule $rule, RuleStats $stats, bool $unseen, DateTimeImmutable $now, DateTimeImmutable $recentSince): array
    {
        return [
            'id' => $rule->id,
            'source' => $rule->source,
            'target' => $rule->target,
            'match_type' => $rule->matchType->value,
            'status' => $rule->status->value,
            'target_type' => $rule->targetType->value,
            'origin' => $rule->origin->value,
            'state' => match (true) {
                !$rule->enabled => 'disabled',
                $rule->isExpired($now) => 'expired',
                $rule->isScheduled($now) => 'scheduled',
                default => 'active',
            },
            'languages' => $rule->conditions->languages,
            'note' => $rule->note,
            'created_at' => $rule->createdAt?->format(Rule::DATE_FORMAT),
            'unseen' => $unseen,
            'recent' => $rule->createdAt !== null && $rule->createdAt >= $recentSince,
            'hits' => $stats->total,
            'last_hit' => $stats->lastHit?->format(Rule::DATE_FORMAT),
        ];
    }

    /**
     * The rule that answers a request for the page's route with a redirect or an error, before the page is looked up.
     *
     * @param list<Rule>               $all
     * @param array<string, RuleStats> $stats
     * @param array<string, int>       $unseenIds
     *
     * @return array<string, mixed>|null
     */
    private function outgoing(string $route, ?string $language, array $all, array $stats, DateTimeImmutable $now, DateTimeImmutable $recentSince, array $unseenIds): ?array
    {
        try {
            $answer = $this->tester->test(['url' => $route, 'language' => $language ?? '', 'phase' => 'early']);
        } catch (Throwable) {
            return null;
        }
        $result = $answer['result'] ?? null;
        if (!is_array($result) || !is_string($result['rule_id'] ?? null) || !is_int($result['status'] ?? null) || $result['status'] === 200) {
            return null;
        }
        foreach ($all as $rule) {
            if ($rule->id === $result['rule_id']) {
                $row = $this->row($rule, $stats[$rule->id] ?? new RuleStats(), isset($unseenIds[$rule->id]), $now, $recentSince);
                $row['location'] = is_string($result['location'] ?? null) ? $result['location'] : '';

                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pending(string $route): array
    {
        $key = self::key($route);
        $out = [];
        foreach ($this->services->autoState()->pending() as $entry) {
            foreach ($entry->routes() as $deleted) {
                $deletedKey = self::key($deleted);
                if ($deletedKey === $key || ($key !== '/' && str_starts_with($deletedKey, $key . '/'))) {
                    $out[] = [
                        'id' => $entry->id,
                        'title' => $entry->title(),
                        'route' => $entry->route(),
                        'children_count' => count($entry->children()),
                        'deleted_at' => $entry->deletedAt->format(Rule::DATE_FORMAT),
                    ];
                    break;
                }
            }
        }

        return array_slice($out, 0, self::MAX_ROWS);
    }

    /**
     * 404 hits (people, no bots) on the exact old URLs of the incoming rules, most hit first.
     *
     * @param list<Rule> $incoming
     *
     * @return list<array{path: string, hits: int, last_seen: string}>
     */
    private function notFound(array $incoming, DateTimeImmutable $now): array
    {
        $wanted = [];
        foreach ($incoming as $rule) {
            if ($rule->matchType === MatchType::Exact && $rule->source !== '' && $rule->source[0] === '/' && !str_contains($rule->source, '?')) {
                $wanted[self::key($rule->source)] = $rule->source;
            }
            if (count($wanted) >= self::MAX_NOT_FOUND) {
                break;
            }
        }
        if ($wanted === []) {
            return [];
        }

        /** @var array<string, array{path: string, hits: int, last: int}> $found */
        $found = [];
        try {
            foreach ($this->services->logStore()->entries($now->modify('-' . self::NOT_FOUND_DAYS . ' days'), $now) as $entry) {
                $key = self::key($entry->path);
                if (!isset($wanted[$key]) || $entry->uaClass === UserAgentClass::Bot) {
                    continue;
                }
                $found[$key] ??= ['path' => $wanted[$key], 'hits' => 0, 'last' => 0];
                ++$found[$key]['hits'];
                $found[$key]['last'] = max($found[$key]['last'], $entry->time->getTimestamp());
            }
        } catch (Throwable) {
            return [];
        }
        uasort($found, static fn (array $a, array $b): int => [$b['hits'], $b['last']] <=> [$a['hits'], $a['last']]);

        return array_values(array_map(
            static fn (array $f): array => ['path' => $f['path'], 'hits' => $f['hits'], 'last_seen' => $now->setTimestamp($f['last'])->format(Rule::DATE_FORMAT)],
            $found,
        ));
    }
}
