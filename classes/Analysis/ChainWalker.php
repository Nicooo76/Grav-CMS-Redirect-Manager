<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Matching\LocationEncoder;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Follows the redirect of one rule hop by hop through a rule set and reports loops, self references,
 * too long chains and chains worth shortening.
 *
 * A hop is one HTTP response: the Matcher result for a concrete path (a "continue" sequence of rules counts as
 * one hop). Analysis works on concrete paths: exact rules use their source, wildcard and regex rules a sample
 * path (SampleGenerator) whose captures flow through the targets, so the real Matcher resolves every later hop.
 *
 * Pass-through (status 200) rules serve the target under the requested URL and are no redirect: they end a
 * chain and only count for the self reference check. Rules with 410 or 451 end a chain and count as a hop,
 * because a rule pointing at a gone page can be gone itself.
 */
final class ChainWalker
{
    private readonly RuleCompiler $compiler;

    public function __construct(
        private readonly MatcherOptions $options,
        private readonly Clock $clock,
    ) {
        $this->compiler = new RuleCompiler();
    }

    /**
     * One outcome per language variant that has something to report; empty for plain single-hop redirects.
     *
     * @param callable(string): Matcher $matcherFor matcher that knows every rule relevant for the given path
     * @return list<ChainOutcome>
     */
    public function walk(Rule $rule, callable $matcherFor): array
    {
        if (!$rule->status->needsTarget() || trim($rule->target) === '' || !$rule->isActive($this->clock->now())) {
            return [];
        }
        $languages = $rule->conditions->languages === [] ? [null] : $rule->conditions->languages;
        $out = [];
        foreach ($languages as $language) {
            $outcome = $this->walkVariant($rule, $language, $matcherFor);
            if ($outcome !== null) {
                $out[] = $outcome;
            }
        }

        return $out;
    }

    /**
     * @param callable(string): Matcher $matcherFor
     */
    private function walkVariant(Rule $rule, ?string $language, callable $matcherFor): ?ChainOutcome
    {
        $sample = SampleGenerator::generate($rule);
        if ($sample === null) {
            return $rule->matchType === MatchType::Regex
                ? new ChainOutcome(ChainOutcome::SKIPPED, $rule->id, $language, 0, [$rule->id], [])
                : null;
        }
        $ctx = SampleGenerator::context($rule, $sample, $language);
        if ($ctx === null) {
            return null;
        }
        $current = $this->firstHop($rule, $ctx, $matcherFor);
        if ($current === null) {
            return null;
        }

        $max = max(1, $this->options->maxChainDepth);
        $paths = [$ctx->path];
        /** @var list<array{rules: list<string>, location: string, status: StatusCode}> $hops */
        $hops = [];
        /** @var array<string, int> $seen rule id => index of the first hop that applied it */
        $seen = [];
        $loopStart = null;
        $end = 'end';

        while (true) {
            $index = count($hops);
            $repeat = null;
            foreach ($current['rules'] as $id) {
                if (isset($seen[$id])) {
                    $repeat = min($repeat ?? PHP_INT_MAX, $seen[$id]);
                } else {
                    $seen[$id] = $index;
                }
            }
            if ($current['status'] === StatusCode::PassThrough && $index > 0) {
                $end = 'served';
                break;
            }
            $hops[] = $current;
            if ($repeat !== null) {
                $loopStart = $repeat;
                $end = 'loop';
                break;
            }
            if ($current['status']->isError()) {
                $end = 'gone';
                break;
            }
            $location = $current['location'];
            if ($location === '' || $location[0] !== '/') {
                $end = 'external';
                break;
            }
            $next = PathKey::normalized(PathKey::pathOf($location));
            if ($next === null) {
                break;
            }
            $known = array_search($next, $paths, true);
            if ($known !== false) {
                $loopStart = $known;
                $end = 'loop';
                break;
            }
            if ($current['status'] === StatusCode::PassThrough) {
                break; // the start rule serves a page, nothing follows
            }
            $paths[] = $next;
            $ctx = $ctx->withPath($next)->withQuery(PathKey::queryOf($location));
            $result = $matcherFor($next)->match($ctx, MatchPhase::Any);
            if ($result === null) {
                break;
            }
            $current = self::hop($result);
            if (count($hops) >= $max) {
                $hops[] = $current; // one hop more than allowed is enough to report it
                $end = 'deep';
                break;
            }
        }

        return $this->outcome($rule, $language, $sample, $hops, $end, $loopStart);
    }

    /**
     * @param list<array{rules: list<string>, location: string, status: StatusCode}> $hops
     */
    private function outcome(Rule $rule, ?string $language, Sample $sample, array $hops, string $end, ?int $loopStart): ?ChainOutcome
    {
        $ids = [];
        $locations = [self::display($rule, $sample)];
        foreach ($hops as $hop) {
            foreach ($hop['rules'] as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if ($hop['location'] !== '') {
                $locations[] = $hop['location'];
            }
        }

        if ($end === 'loop' && $loopStart !== null) {
            $cycle = [];
            foreach (array_slice($hops, $loopStart) as $hop) {
                foreach ($hop['rules'] as $id) {
                    if (!in_array($id, $cycle, true)) {
                        $cycle[] = $id;
                    }
                }
            }
            $self = $loopStart === 0 && $cycle === [$rule->id];

            return new ChainOutcome($self ? ChainOutcome::SELF : ChainOutcome::LOOP, $rule->id, $language, count($hops), $ids, $locations, $cycle);
        }
        if ($end === 'deep') {
            return new ChainOutcome(ChainOutcome::TOO_DEEP, $rule->id, $language, count($hops), $ids, $locations);
        }
        if (count($hops) < 2 || $hops[0]['status'] === StatusCode::PassThrough) {
            return null;
        }

        $last = $hops[count($hops) - 1];
        $shortcut = null;
        $status = null;
        if ($end === 'gone') {
            $status = $last['status']->value;
        } elseif ($sample->templatable) {
            $shortcut = $sample->templatize($last['location']);
        }

        return new ChainOutcome(ChainOutcome::CHAIN, $rule->id, $language, count($hops), $ids, $locations, [], $shortcut, $status);
    }

    /**
     * The rule's own redirect for the sample request, without asking the rest of the set.
     *
     * @param callable(string): Matcher $matcherFor
     * @return array{rules: list<string>, location: string, status: StatusCode}|null
     */
    private function firstHop(Rule $rule, RequestContext $ctx, callable $matcherFor): ?array
    {
        if ($rule->continueMatching) {
            $result = $matcherFor($ctx->path)->match($ctx, MatchPhase::Any);

            return $result !== null && self::firstId($result) === $rule->id ? self::hop($result) : null;
        }

        $target = $rule->target;
        if (strpbrk($target, '${') === false && ($rule->queryMode !== QueryMode::Pass || $ctx->query === [])) {
            if ($target[0] === '/') {
                $location = self::collapse($target);
            } elseif ($rule->targetType === TargetType::Url && $rule->status !== StatusCode::PassThrough) {
                $location = $target;
            } else {
                return null;
            }
            if (!$this->options->guard->isSafeLocation($location, $ctx->host)) {
                return null;
            }

            return ['rules' => [$rule->id], 'location' => LocationEncoder::encode($location), 'status' => $rule->status];
        }

        // Placeholders (or a query to pass on): let the Matcher build the location from a set with just this rule.
        $single = new Matcher($this->compiler->compile([$rule]), $this->clock, $this->options);
        $result = $single->match($ctx, MatchPhase::Any);

        return $result !== null && self::firstId($result) === $rule->id ? self::hop($result) : null;
    }

    private static function firstId(MatchResult $result): ?string
    {
        $first = $result->rules[0] ?? null;

        return $first?->id;
    }

    /**
     * @return array{rules: list<string>, location: string, status: StatusCode}
     */
    private static function hop(MatchResult $result): array
    {
        return [
            'rules' => array_map(static fn (Rule $r): string => $r->id, $result->rules),
            'location' => $result->location,
            'status' => $result->status,
        ];
    }

    /** Collapses "//" inside an internal path; a leading "//" stays so the guard rejects it. */
    private static function collapse(string $location): string
    {
        $end = strcspn($location, '?#');
        $path = substr($location, 0, $end);
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $location;
        }

        return preg_replace('#/{2,}#', '/', $path) . substr($location, $end);
    }

    private static function display(Rule $rule, Sample $sample): string
    {
        $query = '';
        if ($sample->query !== [] && ($rule->queryMode === QueryMode::Exact || $rule->queryMode === QueryMode::Params)) {
            $query = '?' . http_build_query($sample->query, '', '&', PHP_QUERY_RFC3986);
        }

        return $sample->path . $query;
    }
}
