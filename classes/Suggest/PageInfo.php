<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/**
 * Framework-independent description of one page, as the suggestion engine needs it.
 * The Grav adapter (GravPageIndexBuilder) creates one PageInfo per page and language.
 */
final readonly class PageInfo
{
    /**
     * @param string                      $route        route without language prefix, e.g. "/blog/my-post"
     * @param string                      $rawRoute     route with ordering prefixes as on disk (informational)
     * @param string                      $slug         last route segment as Grav reports it (may differ from the route)
     * @param string|null                 $language     language code, null on single-language sites
     * @param string|null                 $parentRoute  route of the parent page
     * @param array<string, list<string>> $taxonomy     taxonomy type => values, e.g. ['tag' => ['php']]
     * @param array<string, string>       $translations language => route of the same page in other languages
     * @param int|null                    $modified     Unix timestamp of the last modification
     */
    public function __construct(
        public string $route,
        public string $rawRoute = '',
        public string $slug = '',
        public string $title = '',
        public ?string $language = null,
        public ?string $parentRoute = null,
        public bool $routable = true,
        public bool $published = true,
        public array $taxonomy = [],
        public array $translations = [],
        public ?int $modified = null,
    ) {
    }

    /** Only routable, published pages can be redirect targets. */
    public function isTarget(): bool
    {
        return $this->routable && $this->published;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'raw_route' => $this->rawRoute,
            'slug' => $this->slug,
            'title' => $this->title,
            'language' => $this->language,
            'parent_route' => $this->parentRoute,
            'routable' => $this->routable,
            'published' => $this->published,
            'taxonomy' => $this->taxonomy,
            'translations' => $this->translations,
            'modified' => $this->modified,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $taxonomy = [];
        if (is_array($data['taxonomy'] ?? null)) {
            foreach ($data['taxonomy'] as $type => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $list = [];
                foreach ($values as $value) {
                    if (is_string($value) || is_int($value)) {
                        $list[] = (string) $value;
                    }
                }
                $taxonomy[(string) $type] = $list;
            }
        }

        $translations = [];
        if (is_array($data['translations'] ?? null)) {
            foreach ($data['translations'] as $lang => $route) {
                if (is_string($route)) {
                    $translations[(string) $lang] = $route;
                }
            }
        }

        return new self(
            route: is_string($data['route'] ?? null) ? $data['route'] : '/',
            rawRoute: is_string($data['raw_route'] ?? null) ? $data['raw_route'] : '',
            slug: is_string($data['slug'] ?? null) ? $data['slug'] : '',
            title: is_string($data['title'] ?? null) ? $data['title'] : '',
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            parentRoute: is_string($data['parent_route'] ?? null) ? $data['parent_route'] : null,
            routable: ($data['routable'] ?? true) !== false,
            published: ($data['published'] ?? true) !== false,
            taxonomy: $taxonomy,
            translations: $translations,
            modified: is_int($data['modified'] ?? null) ? $data['modified'] : null,
        );
    }
}
