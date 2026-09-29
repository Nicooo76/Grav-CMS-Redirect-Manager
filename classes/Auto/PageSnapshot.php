<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/**
 * A page and its subtree as the auto-redirect planner sees it: routes per language, nothing else.
 * Captured before and after a change (or before a delete) by the Grav adapter, compared by the planner.
 */
final readonly class PageSnapshot
{
    /** Language key on single-language sites. */
    public const ANY = '*';

    /**
     * @param string                          $title       page title, for notes and the "pending" list
     * @param string                          $rawRoute    structural route (folder names without order prefixes)
     * @param array<string, string>           $routes      language => public route of the page itself
     * @param list<PageNode>                  $descendants routable pages below it
     * @param array<string, list<string>>     $ancestors   language => routes of the ancestors, nearest first, root excluded
     * @param bool                            $home        the page is the home page: it never gets a rule
     */
    public function __construct(
        public string $title,
        public string $rawRoute,
        public array $routes,
        public array $descendants = [],
        public array $ancestors = [],
        public bool $home = false,
    ) {
    }

    /**
     * @return list<string> languages the page exists in (PageSnapshot::ANY on single-language sites)
     */
    public function languages(): array
    {
        return array_keys($this->routes);
    }

    /** First route: the default language's when the map is ordered that way. */
    public function primaryRoute(): string
    {
        foreach ($this->routes as $route) {
            return $route;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'raw_route' => $this->rawRoute,
            'routes' => $this->routes,
            'descendants' => array_map(static fn (PageNode $n): array => $n->toArray(), $this->descendants),
            'ancestors' => $this->ancestors,
            'home' => $this->home,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $descendants = [];
        foreach (is_array($data['descendants'] ?? null) ? $data['descendants'] : [] as $row) {
            if (is_array($row)) {
                $descendants[] = PageNode::fromArray($row);
            }
        }
        $ancestors = [];
        foreach (is_array($data['ancestors'] ?? null) ? $data['ancestors'] : [] as $language => $routes) {
            $list = [];
            foreach (is_array($routes) ? $routes : [] as $route) {
                if (is_string($route) && $route !== '') {
                    $list[] = $route;
                }
            }
            $ancestors[(string) $language] = $list;
        }

        return new self(
            is_scalar($data['title'] ?? null) ? (string) $data['title'] : '',
            is_scalar($data['raw_route'] ?? null) ? (string) $data['raw_route'] : '',
            self::stringMap($data['routes'] ?? []),
            $descendants,
            $ancestors,
            (bool) ($data['home'] ?? false),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        $out = [];
        if (!is_array($value)) {
            return $out;
        }
        foreach ($value as $key => $item) {
            if (is_string($item) && $item !== '') {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }
}
