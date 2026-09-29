<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/**
 * Proposes existing pages as redirect targets for a 404 path.
 *
 * Input handling: query and fragment are dropped, percent-encoding decoded. A first segment that
 * is a known language code becomes the request language (unless the whole path is a page route).
 * The last segment loses a legacy extension (.html .htm .php ... ) and a trailing "index".
 * When the language is known, candidates come from that language (language-less pages always qualify).
 *
 * Strategies and score formulas (all scores are clamped to 0..1 and rounded to 3 decimals):
 *
 *  same_slug       last segment equals the slug of another page: 0.95 when exactly one route has that
 *                  slug, 0.85 for each candidate when several do.
 *  other_language  request language L is known and no page has this route in L, but a page with this route
 *                  exists in another language: 0.9 for its translation into L (link declared by either
 *                  side), 0.75 for the untranslated page itself.
 *  similar_route   normalized equality (case, umlauts/accents, separators, extension): 0.97, e.g.
 *                  "/Über-uns.html" vs "/ueber-uns". Otherwise
 *                    c(a, b)  = 0.6 * (1 - levenshtein(a, b) / max(|a|, |b|)) + 0.4 * jaccard(trigrams(a), trigrams(b))
 *                    sim      = 0.4 * c(fullRoute) + 0.6 * c(lastSegment)
 *                    score    = 0.85 * sim, only when c(lastSegment) >= 0.35 (siblings that merely share the parent
 *                               are not "similar")
 *  title_match     coverage = |Q ∩ T| / |Q| (Q = tokens of the last segment without stop words, T = title tokens),
 *                  jaccard = |Q ∩ T| / |Q ∪ T|, needs coverage >= 0.6:
 *                    score = 0.8 * (0.6 * coverage + 0.4 * jaccard)            (max 0.8)
 *  taxonomy_match  Dice = 2|Q ∩ V| / (|Q| + |V|) per taxonomy value V (tag, category, ...), needs >= 0.5;
 *                  each further matching value adds 0.05 (max +0.1):
 *                    score = 0.6 * min(1, bestDice + bonus)                    (max 0.6)
 *                  Ties go to the more recently modified page.
 *  parent_fallback nearest existing ancestor of the path (not the home page): 0.5 for the direct parent, else 0.4.
 *  home_fallback   "/" with 0.1, only when no other suggestion remains.
 *
 * Suggestions below 0.2 are dropped except fallbacks. Results are deduplicated by target (the best score wins,
 * a tie goes to the reason listed first above; the other reasons are kept in details["also"]) and sorted by
 * score descending, then reason order, then target.
 *
 * Speed: candidates come from inverted trigram/token indexes of PageIndex (rare trigrams first, huge posting
 * lists skipped), and only the best few are scored. Suggested targets are routes without language prefix;
 * details["language"] tells the language of a cross-language target.
 */
final class Suggester
{
    private const MIN_SCORE = 0.2;
    private const EXTENSION = '/\.(?:html?|xhtml|shtml|php[3-8]?|aspx?|jsp|cfm|cgi)$/i';

    /** @var array<string, string> lowercase language => language as the index knows it */
    private array $languageMap = [];

    public function __construct(
        private readonly PageIndex $index,
        private readonly int $candidateLimit = 30,
    ) {
        foreach ($index->languages() as $language) {
            $this->languageMap[strtolower($language)] = $language;
        }
    }

    /**
     * @return list<Suggestion> best first, at most $limit
     */
    public function suggest(string $path, ?string $language = null, int $limit = 5): array
    {
        if ($limit < 1) {
            return [];
        }

        $request = $this->parse($path, $language);
        if ($request->segments === []) {
            return [];
        }

        $candidates = array_merge(
            $this->sameSlug($request, $limit),
            $this->otherLanguage($request),
            $this->similarRoute($request, $limit),
            $this->titleMatch($request, $limit),
            $this->taxonomyMatch($request, $limit),
            $this->parentFallback($request),
        );

        /** @var array<string, Suggestion> $best */
        $best = [];
        /** @var array<string, list<string>> $also */
        $also = [];
        foreach ($candidates as $candidate) {
            if ($candidate->score < self::MIN_SCORE && !$candidate->reason->isFallback()) {
                continue;
            }
            $key = $candidate->target;
            if (!isset($best[$key])) {
                $best[$key] = $candidate;
                continue;
            }
            if ($candidate->score > $best[$key]->score) {
                $also[$key][] = $best[$key]->reason->value;
                $best[$key] = $candidate;
            } elseif ($candidate->reason !== $best[$key]->reason) {
                $also[$key][] = $candidate->reason->value;
            }
        }

        $result = [];
        foreach ($best as $key => $suggestion) {
            $extra = array_values(array_unique($also[$key] ?? []));
            $result[] = $extra === []
                ? $suggestion
                : new Suggestion(
                    $suggestion->target,
                    $suggestion->score,
                    $suggestion->reason,
                    $suggestion->pageTitle,
                    $suggestion->details + ['also' => $extra],
                );
        }

        if ($result === []) {
            $home = $this->index->byRoute('/', $request->language);
            $result[] = new Suggestion('/', 0.1, SuggestionReason::HomeFallback, $home->title ?? '');
        }

        usort($result, static function (Suggestion $a, Suggestion $b): int {
            return [$b->score, $a->reason->rank(), $a->target] <=> [$a->score, $b->reason->rank(), $b->target];
        });

        return array_slice($result, 0, $limit);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, list<Suggestion>> keyed by the given path
     */
    public function suggestMany(array $paths, ?string $language = null, int $limit = 5): array
    {
        $result = [];
        foreach ($paths as $path) {
            $result[$path] ??= $this->suggest($path, $language, $limit);
        }

        return $result;
    }

    private function parse(string $path, ?string $language): ParsedPath
    {
        $text = $this->index->text();

        $path = preg_replace('/[?#].*$/s', '', $path) ?? $path;
        if (str_contains($path, '%')) {
            $path = rawurldecode($path);
        }
        $path = $text->scrub($path);

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments !== []) {
            $last = preg_replace(self::EXTENSION, '', (string) array_pop($segments)) ?? '';
            if ($last !== '' && strtolower($last) !== 'index') {
                $segments[] = $last;
            }
        }

        $resolved = null;
        if (
            $segments !== []
            && isset($this->languageMap[strtolower($segments[0])])
            && $this->index->byRoute('/' . implode('/', $segments)) === null
        ) {
            $resolved = $this->languageMap[strtolower($segments[0])];
            array_shift($segments);
        }
        $resolved ??= $language !== null ? ($this->languageMap[strtolower($language)] ?? null) : null;

        $normalized = [];
        $lastRaw = '';
        foreach ($segments as $segment) {
            $slug = $text->slug($segment);
            if ($slug !== '') {
                $normalized[] = $slug;
                $lastRaw = $segment;
            }
        }

        return new ParsedPath(
            segments: $normalized,
            route: '/' . implode('/', $normalized),
            slug: $normalized === [] ? '' : $normalized[count($normalized) - 1],
            contentTokens: $lastRaw === '' ? [] : $text->contentTokens($lastRaw),
            language: $resolved,
        );
    }

    /** @return list<Suggestion> */
    private function sameSlug(ParsedPath $request, int $limit): array
    {
        $byRoute = [];
        foreach ($this->index->bySlug($request->slug, $request->language) as $page) {
            $byRoute[$page->route] ??= $page;
        }
        if ($byRoute === []) {
            return [];
        }

        $text = $this->index->text();
        $distance = [];
        foreach ($byRoute as $route => $_) {
            $distance[$route] = levenshtein($request->route, $text->route($route));
        }
        uksort($byRoute, static fn (string|int $a, string|int $b): int => [$distance[$a], $a] <=> [$distance[$b], $b]);

        $count = count($byRoute);
        $score = $count === 1 ? 0.95 : 0.85;
        $out = [];
        foreach (array_slice($byRoute, 0, $limit) as $page) {
            $out[] = new Suggestion(
                $page->route,
                $score,
                SuggestionReason::SameSlug,
                $page->title,
                ['slug' => $request->slug, 'candidates' => $count],
            );
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function otherLanguage(ParsedPath $request): array
    {
        $language = $request->language;
        if ($language === null || $this->index->byRoute($request->route, $language, true) !== null) {
            return [];
        }

        $out = [];
        foreach ($this->index->byRouteAllLanguages($request->route, true) as $page) {
            // Pages of the request language (or language-less ones) would have matched above.
            $translated = $this->index->translation($page, $language);
            if ($translated !== null) {
                $out[] = new Suggestion(
                    $translated->route,
                    0.9,
                    SuggestionReason::OtherLanguage,
                    $translated->title,
                    [
                        'language' => $language,
                        'via' => 'translation',
                        'source_route' => $page->route,
                        'source_language' => $page->language,
                    ],
                );
            } else {
                $out[] = new Suggestion(
                    $page->route,
                    0.75,
                    SuggestionReason::OtherLanguage,
                    $page->title,
                    ['language' => $page->language, 'via' => 'same_route', 'requested_language' => $language],
                );
            }
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function similarRoute(ParsedPath $request, int $limit): array
    {
        $text = $this->index->text();
        $out = [];

        $exact = $this->index->byRoute($request->route, $request->language, true);
        if ($exact !== null) {
            $out[] = new Suggestion(
                $exact->route,
                0.97,
                SuggestionReason::SimilarRoute,
                $exact->title,
                ['similarity' => 1.0, 'match' => 'normalized_equal'],
            );
        }

        $routeSet = $text->trigramSet($request->route);
        $slugSet = $text->trigramSet($request->slug);
        $scored = [];
        foreach ($this->index->candidatesByTrigrams($routeSet, $slugSet, $request->language, $this->candidateLimit) as $id) {
            $entry = $this->index->entry($id);
            if ($entry->normRoute === $request->route) {
                continue;
            }

            $lastSim = $this->similarity($request->slug, $entry->lastSegment, $slugSet, $text->trigramSet($entry->lastSegment));
            if ($lastSim < 0.35) {
                continue;
            }
            $fullSim = $this->similarity($request->route, $entry->normRoute, $routeSet, $text->trigramSet($entry->normRoute));
            $similarity = 0.4 * $fullSim + 0.6 * $lastSim;

            $scored[] = new Suggestion(
                $entry->page->route,
                0.85 * $similarity,
                SuggestionReason::SimilarRoute,
                $entry->page->title,
                ['similarity' => round($similarity, 3), 'last_segment' => round($lastSim, 3), 'full_route' => round($fullSim, 3)],
            );
        }

        usort($scored, static fn (Suggestion $a, Suggestion $b): int => [$b->score, $a->target] <=> [$a->score, $b->target]);

        return array_merge($out, array_slice($scored, 0, $limit));
    }

    /** @return list<Suggestion> */
    private function titleMatch(ParsedPath $request, int $limit): array
    {
        $query = $request->contentTokens;
        if ($query === []) {
            return [];
        }

        $out = [];
        foreach ($this->index->candidatesByTitleTokens($query, $request->language, $this->candidateLimit) as $id) {
            $entry = $this->index->entry($id);
            $shared = count(array_intersect($query, $entry->titleTokens));
            $coverage = $shared / count($query);
            if ($coverage < 0.6) {
                continue;
            }
            $jaccard = $shared / (count($query) + count($entry->titleTokens) - $shared);

            $out[] = new Suggestion(
                $entry->page->route,
                0.8 * (0.6 * $coverage + 0.4 * $jaccard),
                SuggestionReason::TitleMatch,
                $entry->page->title,
                ['coverage' => round($coverage, 3), 'jaccard' => round($jaccard, 3), 'tokens' => array_values(array_intersect($query, $entry->titleTokens))],
            );
        }

        usort($out, static fn (Suggestion $a, Suggestion $b): int => [$b->score, $a->target] <=> [$a->score, $b->target]);

        return array_slice($out, 0, $limit);
    }

    /** @return list<Suggestion> */
    private function taxonomyMatch(ParsedPath $request, int $limit): array
    {
        $query = $request->contentTokens;
        if ($query === []) {
            return [];
        }

        $rows = [];
        foreach ($this->index->candidatesByTaxonomyTokens($query, $request->language, $this->candidateLimit) as $id) {
            $entry = $this->index->entry($id);
            $best = 0.0;
            $matched = [];
            foreach ($entry->taxonomyValues as $i => $valueTokens) {
                $shared = count(array_intersect($query, $valueTokens));
                if ($shared === 0) {
                    continue;
                }
                $dice = 2 * $shared / (count($query) + count($valueTokens));
                if ($dice >= 0.5) {
                    $matched[] = $entry->taxonomyLabels[$i];
                    $best = max($best, $dice);
                }
            }
            if ($matched === []) {
                continue;
            }

            $bonus = min(0.1, 0.05 * (count($matched) - 1));
            $rows[] = [
                'modified' => $entry->page->modified ?? 0,
                'suggestion' => new Suggestion(
                    $entry->page->route,
                    0.6 * min(1.0, $best + $bonus),
                    SuggestionReason::TaxonomyMatch,
                    $entry->page->title,
                    ['matched' => $matched],
                ),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['suggestion']->score, $b['modified'], $a['suggestion']->target]
            <=> [$a['suggestion']->score, $a['modified'], $b['suggestion']->target]);

        return array_map(
            static fn (array $row): Suggestion => $row['suggestion'],
            array_slice($rows, 0, $limit),
        );
    }

    /** @return list<Suggestion> */
    private function parentFallback(ParsedPath $request): array
    {
        $count = count($request->segments);
        for ($depth = $count - 1; $depth >= 1; --$depth) {
            $route = '/' . implode('/', array_slice($request->segments, 0, $depth));
            $page = $this->index->byRoute($route, $request->language, true);
            if ($page !== null) {
                return [new Suggestion(
                    $page->route,
                    $depth === $count - 1 ? 0.5 : 0.4,
                    SuggestionReason::ParentFallback,
                    $page->title,
                    ['distance' => $count - $depth],
                )];
            }
        }

        return [];
    }

    /**
     * @param array<int|string, true> $setA
     * @param array<int|string, true> $setB
     */
    private function similarity(string $a, string $b, array $setA, array $setB): float
    {
        $longest = max(strlen($a), strlen($b));
        $levenshtein = $longest === 0 ? 1.0 : 1.0 - levenshtein($a, $b) / $longest;

        $shared = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $shared;
        $jaccard = $union === 0 ? 0.0 : $shared / $union;

        return 0.6 * $levenshtein + 0.4 * $jaccard;
    }
}
