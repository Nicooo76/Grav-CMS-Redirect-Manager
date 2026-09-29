<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Domain\Conditions;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Util\Ids;

/**
 * Decides which rules a page change needs. Pure logic: snapshots and rules in, plan out, no Grav, no I/O.
 *
 * Moves and renames (planMove): one exact rule old -> new per language, plus /old/* -> /new/$1 for descendants
 * (children mode "wildcard") or one exact rule per descendant ("each"). Languages whose routes are identical share
 * one rule without a language condition; otherwise the rule is limited to the languages it applies to.
 *   - Moving back: an auto rule that points from the new route to the old one is removed and nothing is created.
 *   - Chains: auto rules and page-target rules that point at the old route (or below it) get the new target, so
 *     A -> B followed by B -> C leaves A -> C and B -> C.
 *   - Auto rules whose source is the new route (a live page now) are removed.
 *   - An enabled manual rule with the same source wins: the auto rule is skipped and noted.
 *   - Routes that another page serves now are never used as a source ($isLive).
 *
 * Deletes (planDelete / planDeleteAction): 410 for the route and its descendants, or 301 to the nearest existing
 * parent, or a pending decision (policy "ask"), or nothing. Auto rules that pointed at the deleted page follow the
 * decision (410 or the parent). The home page and the root route never get a rule.
 *
 * $isLive is fn(string $route, string $language): bool; $routeExists the same for ancestors.
 */
final class AutoRedirectPlanner
{
    /** More descendants than this fall back to one wildcard rule even in children mode "each". */
    public const EACH_LIMIT = 1000;

    /** @var callable(): string */
    private $ids;

    /**
     * @param (callable(): string)|null $ids rule id generator (default: Ids::rule)
     */
    public function __construct(private readonly AutoRedirectConfig $config, ?callable $ids = null)
    {
        $this->ids = $ids ?? Ids::rule(...);
    }

    /**
     * @param list<Rule>                            $existing
     * @param (callable(string, string): bool)|null $isLive   whether another page serves the route in the language now
     */
    public function planMove(PageSnapshot $before, PageSnapshot $after, array $existing, ?callable $isLive = null): AutoPlan
    {
        $ws = new PlanWorkspace($existing);
        if ($before->home || $after->home) {
            return $ws->toPlan();
        }
        $triples = self::triples($before->routes, $after->routes);
        if ($triples === []) {
            return $ws->toPlan();
        }
        $all = array_values(array_unique([...$before->languages(), ...$after->languages()]));
        $specs = $this->moveSpecs($before, $after, $triples, $all, $ws);

        $open = [];
        foreach ($specs as $spec) {
            if (!$this->undo($ws, $spec)) {
                $open[] = $spec;
            }
        }
        $this->followMoves($ws, $triples, $isLive);
        foreach ($open as $spec) {
            $this->create($ws, $spec, $isLive);
        }

        return $ws->toPlan();
    }

    /**
     * Plan for a deleted page under the configured policy (or $policy).
     *
     * @param list<Rule>                            $existing
     * @param (callable(string, string): bool)|null $routeExists whether an ancestor route still exists (default: yes)
     */
    public function planDelete(PageSnapshot $before, array $existing, ?DeletePolicy $policy = null, ?callable $routeExists = null): AutoPlan
    {
        return match ($policy ?? $this->config->onDelete) {
            DeletePolicy::Never => new AutoPlan(),
            DeletePolicy::Ask => $before->home || self::usableRoutes($before) === [] ? new AutoPlan() : new AutoPlan(pending: $before),
            DeletePolicy::Gone => $this->planDeleteAction($before, $existing, DeleteAction::Gone, null, $routeExists),
            DeletePolicy::Parent => $this->planDeleteAction($before, $existing, DeleteAction::Parent, null, $routeExists),
        };
    }

    /**
     * Rules for a deleted page once the decision is made (also used to resolve a pending decision).
     *
     * @param list<Rule>                            $existing
     * @param string|null                           $target      for DeleteAction::Redirect: a route (no language prefix) or an absolute URL
     * @param (callable(string, string): bool)|null $routeExists
     */
    public function planDeleteAction(PageSnapshot $before, array $existing, DeleteAction $action, ?string $target = null, ?callable $routeExists = null): AutoPlan
    {
        $ws = new PlanWorkspace($existing);
        if ($before->home) {
            return $ws->toPlan();
        }

        $triples = [];
        foreach (self::usableRoutes($before) as $language => $route) {
            $to = match ($action) {
                DeleteAction::Gone => '',
                DeleteAction::Parent => $this->parentTarget($before, $language, $routeExists),
                DeleteAction::Redirect => trim((string) $target),
            };
            if ($action !== DeleteAction::Gone && ($to === '' || strcasecmp(self::trim($to), self::trim($route)) === 0)) {
                continue;
            }
            $triples[] = ['lang' => $language, 'old' => $route, 'new' => $to];
        }
        if ($triples === []) {
            return $ws->toPlan();
        }

        $all = $before->languages();
        $specs = $this->deleteSpecs($before, $triples, $all, $action);
        $this->followDeletes($ws, $triples, $action);
        foreach ($specs as $spec) {
            $this->create($ws, $spec, null);
        }

        return $ws->toPlan();
    }

    /**
     * @param list<array{lang: string, old: string, new: string}> $triples
     * @param list<string>                                        $all
     *
     * @return list<RuleSpec>
     */
    private function moveSpecs(PageSnapshot $before, PageSnapshot $after, array $triples, array $all, PlanWorkspace $ws): array
    {
        $note = sprintf('Automatic redirect: page "%s" moved', $before->title !== '' ? $before->title : $before->primaryRoute());
        $each = $this->config->children === ChildrenMode::Each;
        if ($each && count($before->descendants) > self::EACH_LIMIT) {
            $each = false;
            $ws->note(new PlanNote(PlanNote::SKIPPED, $before->primaryRoute(), 'Too many descendants for one rule each, a wildcard rule is used.'));
        }

        $specs = [];
        foreach (self::group($triples) as $group) {
            $specs[] = new RuleSpec($group['old'], $group['new'], MatchType::Exact, $this->config->status, TargetType::Page, $group['langs'], $all, $note);
            if (!$each && self::hasDescendantsUnder($before, $group['old'], $group['langs'])) {
                $specs[] = new RuleSpec($group['old'] . '/*', $group['new'] . '/$1', MatchType::Wildcard, $this->config->status, TargetType::Route, $group['langs'], $all, $note);
            }
        }

        if ($each) {
            $afterNodes = [];
            foreach ($after->descendants as $node) {
                $afterNodes[$node->key] = $node;
            }
            foreach ($before->descendants as $node) {
                $moved = $afterNodes[$node->key] ?? null;
                if ($moved === null) {
                    continue;
                }
                $nodeAll = array_values(array_unique([...array_keys($node->routes), ...array_keys($moved->routes)]));
                foreach (self::group(self::triples($node->routes, $moved->routes)) as $group) {
                    $specs[] = new RuleSpec($group['old'], $group['new'], MatchType::Exact, $this->config->status, TargetType::Page, $group['langs'], $nodeAll, $note);
                }
            }
        }

        return $specs;
    }

    /**
     * @param list<array{lang: string, old: string, new: string}> $triples
     * @param list<string>                                        $all
     *
     * @return list<RuleSpec>
     */
    private function deleteSpecs(PageSnapshot $before, array $triples, array $all, DeleteAction $action): array
    {
        $note = sprintf('Automatic redirect: page "%s" deleted', $before->title !== '' ? $before->title : $before->primaryRoute());
        $status = $action === DeleteAction::Gone ? StatusCode::Gone : $this->config->status;
        $each = $this->config->children === ChildrenMode::Each && count($before->descendants) <= self::EACH_LIMIT;

        $specs = [];
        foreach (self::group($triples) as $group) {
            $targetType = self::targetType($group['new']);
            $specs[] = new RuleSpec($group['old'], $group['new'], MatchType::Exact, $status, $targetType, $group['langs'], $all, $note, false);
            if (!$each && self::hasDescendantsUnder($before, $group['old'], $group['langs'])) {
                $specs[] = new RuleSpec($group['old'] . '/*', $group['new'], MatchType::Wildcard, $status, $targetType, $group['langs'], $all, $note, false);
            }
        }
        if ($each) {
            $targets = [];
            foreach ($triples as $triple) {
                $targets[$triple['lang']] = $triple['new'];
            }
            foreach ($before->descendants as $node) {
                $nodeTriples = [];
                foreach ($node->routes as $language => $route) {
                    if (isset($targets[$language]) && !self::isRoot($route)) {
                        $nodeTriples[] = ['lang' => $language, 'old' => $route, 'new' => $targets[$language]];
                    }
                }
                $nodeAll = array_keys($node->routes);
                foreach (self::group($nodeTriples) as $group) {
                    $specs[] = new RuleSpec($group['old'], $group['new'], MatchType::Exact, $status, self::targetType($group['new']), $group['langs'], $nodeAll, $note, false);
                }
            }
        }

        return $specs;
    }

    /**
     * Removes auto rules that this spec makes obsolete: rules from the new route (a live page now) and the exact
     * reverse of the spec. Returns true when the reverse existed, i.e. the page moved back and nothing is to be created.
     */
    private function undo(PlanWorkspace $ws, RuleSpec $spec): bool
    {
        $reversed = false;
        $sourceKey = self::key($spec->reverseSource());
        $targetKey = self::key($spec->reverseTarget());
        foreach ($ws->rules() as $rule) {
            if ($rule->origin !== RuleSource::Auto || $rule->matchType !== $spec->match || !$rule->status->isRedirect()) {
                continue;
            }
            if (self::key($rule->source) !== $sourceKey || !self::overlap($rule->conditions->languages, $spec->languages)) {
                continue;
            }
            if (self::key($rule->target) === $targetKey) {
                $reversed = true;
            }
            $this->removeLanguages($ws, $rule, $spec);
        }

        return $reversed;
    }

    private function removeLanguages(PlanWorkspace $ws, Rule $rule, RuleSpec $spec): void
    {
        $have = $rule->conditions->languages;
        if ($have === []) {
            $have = array_values(array_diff($spec->allLanguages, [PageSnapshot::ANY]));
        }
        $remaining = $spec->languages === [PageSnapshot::ANY] ? [] : array_values(array_diff($have, $spec->languages));
        if ($remaining === []) {
            $ws->remove($rule->id);

            return;
        }
        $conditions = $rule->conditions->toArray();
        $conditions['languages'] = $remaining;
        $ws->replace($rule->with(['conditions' => $conditions]));
    }

    /**
     * Rules that point at the moved page follow it: auto rules and rules with target type "page".
     *
     * @param list<array{lang: string, old: string, new: string}> $triples
     * @param (callable(string, string): bool)|null               $isLive
     */
    private function followMoves(PlanWorkspace $ws, array $triples, ?callable $isLive): void
    {
        foreach ($ws->rules() as $rule) {
            if (!self::follows($rule)) {
                continue;
            }
            [$path, $suffix] = self::splitTarget($rule->target);
            $mapped = [];
            foreach ($triples as $triple) {
                if (!self::langMatches($rule->conditions->languages, $triple['lang'])) {
                    continue;
                }
                $new = self::mapPrefix($path, $triple['old'], $triple['new']);
                if ($new === null) {
                    continue;
                }
                if ($isLive !== null && $isLive($path, $triple['lang'])) {
                    continue; // another page took the old route: the rule still points at a live page
                }
                $mapped[$new] = true;
            }
            if ($mapped === []) {
                continue;
            }
            if (count($mapped) > 1) {
                $ws->note(new PlanNote(PlanNote::AMBIGUOUS, $rule->source, 'The rule points at a page that moved differently per language; it was not changed.'));
                continue;
            }
            $new = (string) array_key_first($mapped);
            if (!str_contains($rule->source, '*') && self::key($rule->source) === self::key($new)) {
                if ($rule->origin === RuleSource::Auto) {
                    $ws->remove($rule->id);
                } else {
                    $ws->note(new PlanNote(PlanNote::LOOP, $rule->source, 'The rule would redirect to itself after the move; it was not changed.'));
                }
                continue;
            }
            $ws->replace($rule->with(['target' => $new . $suffix]));
        }
    }

    /**
     * Auto rules that pointed at the deleted page follow the decision: 410 for "gone", the new target otherwise.
     *
     * @param list<array{lang: string, old: string, new: string}> $triples
     */
    private function followDeletes(PlanWorkspace $ws, array $triples, DeleteAction $action): void
    {
        foreach ($ws->rules() as $rule) {
            if ($rule->origin !== RuleSource::Auto || !self::follows($rule)) {
                continue;
            }
            [$path] = self::splitTarget($rule->target);
            $outcomes = [];
            foreach ($triples as $triple) {
                if (self::langMatches($rule->conditions->languages, $triple['lang']) && self::mapPrefix($path, $triple['old'], $triple['new']) !== null) {
                    $outcomes[$triple['new']] = true;
                }
            }
            if ($outcomes === []) {
                continue;
            }
            if (count($outcomes) > 1) {
                $ws->note(new PlanNote(PlanNote::AMBIGUOUS, $rule->source, 'The rule points at a page that was deleted; the decision differs per language, the rule was not changed.'));
                continue;
            }
            if ($action === DeleteAction::Gone) {
                $ws->replace($rule->with(['status' => StatusCode::Gone->value, 'target' => '', 'target_type' => TargetType::Route->value]));
                continue;
            }
            $new = (string) array_key_first($outcomes);
            if (!str_contains($rule->source, '*') && self::key($rule->source) === self::key($new)) {
                $ws->remove($rule->id);
                continue;
            }
            $ws->replace($rule->with(['target' => $new, 'target_type' => self::targetType($new)->value]));
        }
    }

    /**
     * @param (callable(string, string): bool)|null $isLive
     */
    private function create(PlanWorkspace $ws, RuleSpec $spec, ?callable $isLive): void
    {
        $sourceKey = self::key($spec->source);
        $targetKey = self::key($spec->target);

        if ($isLive !== null && !$spec->isWildcard()) {
            foreach ($spec->languages as $language) {
                if ($isLive($spec->source, $language)) {
                    $ws->note(new PlanNote(PlanNote::OCCUPIED, $spec->source, 'Another page serves this route now, no redirect was created.'));

                    return;
                }
            }
        }

        $sameSource = [];
        foreach ($ws->rules() as $rule) {
            if (!$rule->enabled || $rule->matchType !== $spec->match || !self::overlap($rule->conditions->languages, $spec->languages)) {
                continue;
            }
            $isSource = self::key($rule->source) === $sourceKey;
            if ($rule->origin === RuleSource::Auto) {
                if ($isSource) {
                    $sameSource[] = $rule;
                }
                continue;
            }
            if ($isSource) {
                $ws->note(new PlanNote(PlanNote::CONFLICT, $spec->source, 'A manual rule with this source exists; no automatic redirect was created.'));

                return;
            }
            if ($spec->targetType !== TargetType::Url && $spec->status->isRedirect() && self::key($rule->source) === self::key($spec->reverseSource()) && self::key($rule->target) === self::key($spec->reverseTarget())) {
                $ws->note(new PlanNote(PlanNote::LOOP, $spec->source, 'A manual rule redirects back from the target; no automatic redirect was created.'));

                return;
            }
            if (!$spec->isWildcard() && $spec->status->isRedirect() && self::key($rule->source) === $targetKey) {
                $ws->note(new PlanNote(PlanNote::SHADOWED, $rule->source, 'A manual rule redirects the new route of the page.'));
            }
        }

        $conditionLanguages = $spec->conditionLanguages();
        foreach ($sameSource as $rule) {
            if (!$spec->replaceAuto) {
                $ws->note(new PlanNote(PlanNote::EXISTS, $spec->source, 'A rule with this source exists.'));

                return;
            }
            if (self::sameSet($rule->conditions->languages, $conditionLanguages)) {
                if (self::key($rule->target) === $targetKey && $rule->status === $spec->status) {
                    $ws->note(new PlanNote(PlanNote::EXISTS, $spec->source, 'The redirect exists already.'));
                } else {
                    $ws->replace($rule->with(['target' => $spec->target, 'status' => $spec->status->value, 'target_type' => $spec->targetType->value]));
                }

                return;
            }
        }

        $ws->add(new Rule(
            id: ($this->ids)(),
            source: $spec->source,
            target: $spec->target,
            matchType: $spec->match,
            status: $spec->status,
            targetType: $spec->targetType,
            note: $spec->note,
            origin: RuleSource::Auto,
            conditions: new Conditions(languages: $conditionLanguages),
        ));
    }

    /**
     * @param (callable(string, string): bool)|null $routeExists
     */
    private function parentTarget(PageSnapshot $before, string $language, ?callable $routeExists): string
    {
        foreach ($before->ancestors[$language] ?? [] as $route) {
            if (!self::isRoot($route) && ($routeExists === null || $routeExists($route, $language))) {
                return $route;
            }
        }

        return '/';
    }

    /**
     * @param array<string, string> $from
     * @param array<string, string> $to
     *
     * @return list<array{lang: string, old: string, new: string}>
     */
    private static function triples(array $from, array $to): array
    {
        $out = [];
        foreach ($from as $language => $old) {
            $new = $to[$language] ?? null;
            if ($new === null || self::isRoot($old) || self::isRoot($new) || strcasecmp(self::trim($old), self::trim($new)) === 0) {
                continue;
            }
            $out[] = ['lang' => (string) $language, 'old' => self::trim($old), 'new' => self::trim($new)];
        }

        return $out;
    }

    /**
     * Languages with the same old and new route share a group.
     *
     * @param list<array{lang: string, old: string, new: string}> $triples
     *
     * @return list<array{old: string, new: string, langs: list<string>}>
     */
    private static function group(array $triples): array
    {
        $groups = [];
        foreach ($triples as $triple) {
            $key = $triple['old'] . "\0" . $triple['new'];
            $groups[$key] ??= ['old' => $triple['old'], 'new' => $triple['new'], 'langs' => []];
            $groups[$key]['langs'][] = $triple['lang'];
        }

        return array_values($groups);
    }

    /**
     * @param list<string> $languages
     */
    private static function hasDescendantsUnder(PageSnapshot $before, string $route, array $languages): bool
    {
        $prefix = rtrim($route, '/') . '/';
        foreach ($before->descendants as $node) {
            foreach ($languages as $language) {
                $candidate = $node->routes[$language] ?? null;
                if ($candidate !== null && stripos($candidate, $prefix) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, string> language => route, without the root route
     */
    private static function usableRoutes(PageSnapshot $snapshot): array
    {
        return array_filter($snapshot->routes, static fn (string $route): bool => !self::isRoot($route));
    }

    private static function follows(Rule $rule): bool
    {
        if (!$rule->status->needsTarget() || $rule->targetType === TargetType::Url || $rule->target === '') {
            return false;
        }

        return $rule->origin === RuleSource::Auto || $rule->targetType === TargetType::Page;
    }

    /**
     * @return array{0: string, 1: string} target path and its "?query#fragment" suffix
     */
    private static function splitTarget(string $target): array
    {
        $pos = strcspn($target, '?#');

        return [substr($target, 0, $pos), substr($target, $pos)];
    }

    /** $path is $old or below it: returns the same location under $new. */
    private static function mapPrefix(string $path, string $old, string $new): ?string
    {
        $path = self::trim($path);
        if (strcasecmp($path, $old) === 0) {
            return $new;
        }
        if (strncasecmp($path, $old . '/', strlen($old) + 1) === 0) {
            return rtrim($new, '/') . substr($path, strlen($old));
        }

        return null;
    }

    private static function targetType(string $target): TargetType
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://~i', $target) === 1 ? TargetType::Url : ($target === '' ? TargetType::Route : TargetType::Page);
    }

    private static function isRoot(string $route): bool
    {
        return trim($route, '/') === '';
    }

    private static function trim(string $route): string
    {
        return strlen($route) > 1 ? rtrim($route, '/') : $route;
    }

    private static function key(string $path): string
    {
        return mb_strtolower(self::trim($path), 'UTF-8');
    }

    /**
     * @param list<string> $ruleLanguages
     * @param list<string> $languages
     */
    private static function overlap(array $ruleLanguages, array $languages): bool
    {
        return $ruleLanguages === [] || in_array(PageSnapshot::ANY, $languages, true) || array_intersect($ruleLanguages, $languages) !== [];
    }

    /**
     * @param list<string> $ruleLanguages
     */
    private static function langMatches(array $ruleLanguages, string $language): bool
    {
        return $ruleLanguages === [] || $language === PageSnapshot::ANY || in_array($language, $ruleLanguages, true);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
