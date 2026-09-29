<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

use InvalidArgumentException;

/**
 * In-memory index of all redirect targets (routable, published pages) with precomputed
 * normalized routes, slugs, title/taxonomy tokens and inverted trigram/token indexes.
 *
 * Routes never carry a language prefix. A page with language null (single-language site)
 * matches every requested language. When several pages share (route, language) the first wins.
 *
 * The index can be cached: toArray() stores pages and derived data, fromArray() rebuilds only
 * the inverted indexes (no regex or transliteration work).
 */
final class PageIndex
{
    private const FORMAT_VERSION = 1;

    /** Posting lists longer than this are skipped when better (rarer) evidence exists. */
    private const MIN_POSTING_CAP = 300;

    private readonly TextNormalizer $text;

    /** @var list<IndexEntry> */
    private array $entries = [];

    /** @var array<string, array<string, int>> exact route => language key => entry id */
    private array $byExactRoute = [];

    /** @var array<string, array<string, int>> normalized route => language key => entry id */
    private array $byLooseRoute = [];

    /** @var array<int|string, list<int>> normalized slug => entry ids */
    private array $bySlug = [];

    /** @var array<int|string, list<int>> trigram of the normalized route => entry ids */
    private array $routeTrigrams = [];

    /** @var array<int|string, list<int>> trigram of the last route segment => entry ids */
    private array $slugTrigrams = [];

    /** @var array<int|string, list<int>> title token => entry ids */
    private array $titleTokens = [];

    /** @var array<int|string, list<int>> taxonomy value token => entry ids */
    private array $taxonomyTokens = [];

    /** @var array<string, array<string, string>> "lang|route" => other language => route */
    private array $translationLinks = [];

    /** @var array<string, string> lowercase language => language as given */
    private array $languages = [];

    /** @param list<PageInfo> $pages pages of all languages; non-targets are ignored */
    public function __construct(array $pages = [], ?TextNormalizer $text = null)
    {
        $this->text = $text ?? new TextNormalizer();

        $entries = [];
        foreach ($pages as $page) {
            if ($page->isTarget()) {
                $entries[] = $this->makeEntry($page);
            }
        }
        $this->register($entries);
    }

    public function text(): TextNormalizer
    {
        return $this->text;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Target pages of all languages, in insertion order.
     *
     * @return list<PageInfo>
     */
    public function all(): array
    {
        return array_map(static fn (IndexEntry $e): PageInfo => $e->page, $this->entries);
    }

    /**
     * Known language codes, sorted.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $languages = array_values($this->languages);
        sort($languages);

        return $languages;
    }

    /**
     * Unique routes of target pages in insertion order. A language limits the result to
     * pages of that language plus language-less pages.
     *
     * @return list<string>
     */
    public function routes(?string $language = null): array
    {
        $routes = [];
        foreach ($this->entries as $entry) {
            if ($this->languageMatches($entry, $language)) {
                $routes[$entry->page->route] = $entry->page->route;
            }
        }

        return array_values($routes);
    }

    /**
     * Finds a target page by route. Exact mode compares the route as given (trailing and
     * duplicate slashes ignored, case sensitive). Loose mode compares normalized routes, so
     * "/Über-uns" finds "/ueber-uns".
     */
    public function byRoute(string $route, ?string $language = null, bool $loose = false): ?PageInfo
    {
        $id = $this->lookup($route, $language, $loose);

        return $id === null ? null : $this->entries[$id]->page;
    }

    /**
     * All target pages with this route, across languages.
     *
     * @return list<PageInfo>
     */
    public function byRouteAllLanguages(string $route, bool $loose = false): array
    {
        $map = $loose ? $this->byLooseRoute : $this->byExactRoute;
        $key = $loose ? $this->text->route($route) : self::trimRoute($route);

        $pages = [];
        foreach ($map[$key] ?? [] as $id) {
            $pages[] = $this->entries[$id]->page;
        }

        return $pages;
    }

    /**
     * Target pages whose slug (or last route segment) equals the given slug after normalization.
     *
     * @return list<PageInfo>
     */
    public function bySlug(string $slug, ?string $language = null): array
    {
        $key = $this->text->slug($slug);
        if ($key === '') {
            return [];
        }

        $pages = [];
        foreach ($this->bySlug[$key] ?? [] as $id) {
            if ($this->languageMatches($this->entries[$id], $language)) {
                $pages[] = $this->entries[$id]->page;
            }
        }

        return $pages;
    }

    /**
     * The same page in another language, when both the link and the target exist.
     * Links are followed in both directions, so it is enough when one side declares them.
     */
    public function translation(PageInfo $page, string $language): ?PageInfo
    {
        $route = $this->translationLinks[self::linkKey($page->language, $page->route)][$language] ?? null;

        return $route === null ? null : $this->byRoute($route, $language);
    }

    /**
     * @internal
     */
    public function entry(int $id): IndexEntry
    {
        return $this->entries[$id];
    }

    /**
     * Entry ids that share trigrams with the request, most shared first.
     *
     * @internal
     *
     * @param array<int|string, true> $routeSet trigrams of the normalized request route
     * @param array<int|string, true> $slugSet  trigrams of the normalized last segment
     *
     * @return list<int>
     */
    public function candidatesByTrigrams(array $routeSet, array $slugSet, ?string $language, int $limit): array
    {
        $ids = array_keys($this->collect($this->routeTrigrams, array_keys($routeSet), $language, $limit));
        foreach (array_keys($this->collect($this->slugTrigrams, array_keys($slugSet), $language, $limit)) as $id) {
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @internal
     *
     * @param list<string> $tokens
     *
     * @return list<int>
     */
    public function candidatesByTitleTokens(array $tokens, ?string $language, int $limit): array
    {
        return array_keys($this->collect($this->titleTokens, $tokens, $language, $limit));
    }

    /**
     * @internal
     *
     * @param list<string> $tokens
     *
     * @return list<int>
     */
    public function candidatesByTaxonomyTokens(array $tokens, ?string $language, int $limit): array
    {
        return array_keys($this->collect($this->taxonomyTokens, $tokens, $language, $limit));
    }

    /** @internal */
    public function languageMatches(IndexEntry $entry, ?string $language): bool
    {
        return $language === null || $entry->page->language === null || $entry->page->language === $language;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            $entries[] = [
                'page' => $entry->page->toArray(),
                'norm_route' => $entry->normRoute,
                'norm_slug' => $entry->normSlug,
                'title_tokens' => $entry->titleTokens,
                'taxonomy_values' => $entry->taxonomyValues,
                'taxonomy_labels' => $entry->taxonomyLabels,
            ];
        }

        return ['version' => self::FORMAT_VERSION, 'entries' => $entries];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the data is not a valid index dump; callers rebuild then
     */
    public static function fromArray(array $data, ?TextNormalizer $text = null): self
    {
        if (($data['version'] ?? null) !== self::FORMAT_VERSION || !is_array($data['entries'] ?? null)) {
            throw new InvalidArgumentException('Unsupported page index data.');
        }

        $index = new self([], $text);
        $entries = [];
        foreach ($data['entries'] as $row) {
            if (
                !is_array($row)
                || !is_array($row['page'] ?? null)
                || !is_string($row['norm_route'] ?? null)
                || !is_string($row['norm_slug'] ?? null)
                || !is_array($row['title_tokens'] ?? null)
                || !is_array($row['taxonomy_values'] ?? null)
                || !is_array($row['taxonomy_labels'] ?? null)
            ) {
                throw new InvalidArgumentException('Malformed page index entry.');
            }

            $normRoute = $row['norm_route'];
            $slashPos = strrpos($normRoute, '/');
            $values = [];
            foreach ($row['taxonomy_values'] as $tokens) {
                $values[] = is_array($tokens) ? array_values(array_map('strval', $tokens)) : [];
            }

            $entries[] = new IndexEntry(
                page: PageInfo::fromArray($row['page']),
                normRoute: $normRoute,
                normSlug: $row['norm_slug'],
                lastSegment: $slashPos === false ? '' : substr($normRoute, $slashPos + 1),
                titleTokens: array_values(array_map('strval', $row['title_tokens'])),
                taxonomyValues: $values,
                taxonomyLabels: array_values(array_map('strval', $row['taxonomy_labels'])),
            );
        }
        $index->register($entries);

        return $index;
    }

    private function makeEntry(PageInfo $page): IndexEntry
    {
        $normRoute = $this->text->route($page->route);
        $slashPos = strrpos($normRoute, '/');
        $lastSegment = $slashPos === false ? '' : substr($normRoute, $slashPos + 1);
        $normSlug = $page->slug !== '' ? $this->text->slug($page->slug) : $lastSegment;

        $values = [];
        $labels = [];
        foreach ($page->taxonomy as $items) {
            foreach ($items as $item) {
                $tokens = $this->text->contentTokens($item);
                if ($tokens !== []) {
                    $values[] = $tokens;
                    $labels[] = $item;
                }
            }
        }

        return new IndexEntry(
            page: $page,
            normRoute: $normRoute,
            normSlug: $normSlug,
            lastSegment: $lastSegment,
            titleTokens: $this->text->contentTokens($page->title),
            taxonomyValues: $values,
            taxonomyLabels: $labels,
        );
    }

    /** @param list<IndexEntry> $entries */
    private function register(array $entries): void
    {
        foreach ($entries as $entry) {
            $page = $entry->page;
            $langKey = $page->language ?? '';
            $exactKey = self::trimRoute($page->route);
            if (isset($this->byExactRoute[$exactKey][$langKey])) {
                continue;
            }

            $id = count($this->entries);
            $this->entries[] = $entry;
            $this->byExactRoute[$exactKey][$langKey] = $id;
            $this->byLooseRoute[$entry->normRoute][$langKey] ??= $id;

            if ($page->language !== null) {
                $this->languages[strtolower($page->language)] = $page->language;
            }

            $this->bySlug[$entry->normSlug][] = $id;
            if ($entry->lastSegment !== '' && $entry->lastSegment !== $entry->normSlug) {
                $this->bySlug[$entry->lastSegment][] = $id;
            }

            foreach (array_keys($this->text->trigramSet($entry->normRoute)) as $trigram) {
                $this->routeTrigrams[$trigram][] = $id;
            }
            foreach (array_keys($this->text->trigramSet($entry->lastSegment)) as $trigram) {
                $this->slugTrigrams[$trigram][] = $id;
            }
            foreach ($entry->titleTokens as $token) {
                $this->titleTokens[$token][] = $id;
            }
            $seen = [];
            foreach ($entry->taxonomyValues as $tokens) {
                foreach ($tokens as $token) {
                    if (!isset($seen[$token])) {
                        $seen[$token] = true;
                        $this->taxonomyTokens[$token][] = $id;
                    }
                }
            }
        }

        $this->buildTranslationLinks();
    }

    private function buildTranslationLinks(): void
    {
        $this->translationLinks = [];
        foreach ($this->entries as $entry) {
            $page = $entry->page;
            foreach ($page->translations as $language => $route) {
                $language = (string) $language;
                if ($language === '' || $language === $page->language) {
                    continue;
                }
                $this->translationLinks[self::linkKey($page->language, $page->route)][$language] = $route;
                if ($page->language !== null) {
                    $this->translationLinks[self::linkKey($language, $route)][$page->language] ??= $page->route;
                }
            }
        }
    }

    private function lookup(string $route, ?string $language, bool $loose): ?int
    {
        $map = $loose ? $this->byLooseRoute : $this->byExactRoute;
        $key = $loose ? $this->text->route($route) : self::trimRoute($route);
        $ids = $map[$key] ?? null;
        if ($ids === null) {
            return null;
        }
        if ($language === null) {
            return $ids[array_key_first($ids)];
        }

        return $ids[$language] ?? $ids[''] ?? null;
    }

    /**
     * Counts, per entry, how many of the given keys it appears under. Rare keys are used first;
     * huge posting lists are skipped as soon as some evidence exists, which bounds the work.
     *
     * @param array<int|string, list<int>> $index
     * @param list<int|string>             $keys
     *
     * @return array<int, int> entry id => hits, best first
     */
    private function collect(array $index, array $keys, ?string $language, int $limit): array
    {
        $lists = [];
        foreach ($keys as $key) {
            if (isset($index[$key])) {
                $lists[] = $index[$key];
            }
        }
        usort($lists, static fn (array $a, array $b): int => count($a) <=> count($b));

        $cap = max(self::MIN_POSTING_CAP, intdiv(count($this->entries), 10));
        $hits = [];
        foreach ($lists as $list) {
            if ($hits !== [] && count($list) > $cap) {
                break;
            }
            foreach ($list as $id) {
                if ($language === null || $this->languageMatches($this->entries[$id], $language)) {
                    $hits[$id] = ($hits[$id] ?? 0) + 1;
                }
            }
        }

        arsort($hits);

        return array_slice($hits, 0, $limit, true);
    }

    private static function trimRoute(string $route): string
    {
        $route = preg_replace('#/+#', '/', '/' . trim($route)) ?? $route;

        return $route === '/' ? '/' : rtrim($route, '/');
    }

    private static function linkKey(?string $language, string $route): string
    {
        return ($language ?? '') . '|' . self::trimRoute($route);
    }
}
