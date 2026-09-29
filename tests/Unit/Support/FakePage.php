<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Support;

use Grav\Common\Page\Interfaces\PageInterface;

require_once __DIR__ . '/GravStubs.php';

/**
 * Configurable stand-in for a Grav page: every value the auto-redirect adapter reads is a public property.
 */
final class FakePage implements PageInterface
{
    /** @var list<PageInterface|object> */
    public array $children = [];
    public ?PageInterface $parent = null;

    /** @var array<string, string|false> language => translated route (translatedLanguages()) */
    public array $languages = [];
    public bool $languagesThrow = false;
    public bool $routeThrows = false;
    public mixed $header = null;

    public function __construct(
        public string $path = '',
        public string $title = '',
        public ?string $route = null,
        public ?string $rawRoute = null,
        public bool $routable = true,
        public bool $published = true,
        public bool $modular = false,
        public bool $root = false,
        public bool $home = false,
    ) {
        $this->rawRoute ??= $route;
    }

    /** Adds a child page and sets its parent to this page. */
    public function withChild(self $child): self
    {
        $child->parent = $this;
        $this->children[] = $child;

        return $this;
    }

    public static function rootPage(): self
    {
        return new self('/site/pages', 'root', '/', '/', root: true);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function route(): ?string
    {
        if ($this->routeThrows) {
            throw new \RuntimeException('route() failed');
        }

        return $this->route;
    }

    public function rawRoute(): ?string
    {
        return $this->rawRoute;
    }

    public function home(): bool
    {
        return $this->home;
    }

    public function routable(): bool
    {
        return $this->routable;
    }

    public function published(): bool
    {
        return $this->published;
    }

    public function modular(): bool
    {
        return $this->modular;
    }

    public function root(): bool
    {
        return $this->root;
    }

    public function translatedLanguages(bool $onlyPublished = true): array
    {
        if ($this->languagesThrow) {
            throw new \RuntimeException('translatedLanguages() failed');
        }

        return $this->languages;
    }

    public function children(): iterable
    {
        return $this->children;
    }

    public function parent(): ?PageInterface
    {
        return $this->parent;
    }

    public function header(): mixed
    {
        return $this->header;
    }
}
