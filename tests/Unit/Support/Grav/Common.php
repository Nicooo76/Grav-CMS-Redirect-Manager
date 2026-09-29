<?php

declare(strict_types=1);

namespace RocketTheme\Toolbox\Event {
    if (!class_exists(Event::class, false)) {
        /** @implements \ArrayAccess<string, mixed> */
        class Event implements \ArrayAccess
        {
            private bool $stopped = false;

            /** @param array<string, mixed> $items */
            public function __construct(protected array $items = [])
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[(string) $offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[$offset]);
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return $this->items;
            }

            public function stopPropagation(): void
            {
                $this->stopped = true;
            }

            public function isPropagationStopped(): bool
            {
                return $this->stopped;
            }
        }
    }
}

namespace RocketTheme\Toolbox\ResourceLocator {
    if (!class_exists(UniformResourceLocator::class, false)) {
        /** Resolves the streams listed in $resources, everything else is "not found". */
        class UniformResourceLocator
        {
            /** @param array<string, string> $resources uri => absolute path */
            public function __construct(public array $resources = [])
            {
            }

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                return $this->resources[$uri] ?? false;
            }
        }
    }
}

namespace Grav\Common\Config {
    if (!class_exists(Config::class, false)) {
        class Config
        {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data = [])
            {
            }

            public function get(string $name, mixed $default = null): mixed
            {
                $value = $this->data;
                foreach (explode('.', $name) as $part) {
                    if (!is_array($value) || !array_key_exists($part, $value)) {
                        return $default;
                    }
                    $value = $value[$part];
                }

                return $value;
            }

            public function set(string $name, mixed $value): void
            {
                $node = &$this->data;
                foreach (explode('.', $name) as $part) {
                    if (!isset($node[$part]) || !is_array($node[$part])) {
                        $node[$part] = [];
                    }
                    $node = &$node[$part];
                }
                $node = $value;
            }
        }
    }
}

namespace Grav\Common\Language {
    if (!class_exists(Language::class, false)) {
        class Language
        {
            /** @param list<string> $supported */
            public function __construct(public array $supported = [], public string $default = '', public string $active = '')
            {
            }

            public function enabled(): bool
            {
                return count($this->supported) > 1;
            }

            public function getLanguage(): string|false
            {
                return $this->active !== '' ? $this->active : false;
            }

            public function getDefault(): string|false
            {
                return $this->default !== '' ? $this->default : false;
            }
        }
    }
}

namespace Grav\Common {
    use RocketTheme\Toolbox\Event\Event;

    if (!class_exists(Grav::class, false)) {
        /** @implements \ArrayAccess<string, mixed> */
        class Grav implements \ArrayAccess
        {
            private static ?self $instance = null;

            /** @var array<string, mixed> */
            public array $items = [];

            /** @var list<array{0: string, 1: Event|null}> events fired through fireEvent() */
            public array $fired = [];

            /** @var array<string, callable(Event|null): Event|null> */
            public array $listeners = [];

            public mixed $closed = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public static function setInstance(?self $grav): void
            {
                self::$instance = $grav;
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[(string) $offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[$offset]);
            }

            public function fireEvent(string $name, ?Event $event = null): Event
            {
                $event ??= new Event();
                $this->fired[] = [$name, $event];
                if (isset($this->listeners[$name])) {
                    ($this->listeners[$name])($event);
                }

                return $event;
            }

            public function close(mixed $response = null): void
            {
                $this->closed = $response;
            }
        }
    }
}

namespace Grav\Common\Page\Interfaces {
    if (!interface_exists(PageInterface::class, false)) {
        /** The part of Grav's page interface the plugin reads. */
        interface PageInterface
        {
            public function path(): string;

            public function title(): string;

            public function route(): ?string;

            public function rawRoute(): ?string;

            public function home(): bool;

            public function routable(): bool;

            public function published(): bool;

            public function modular(): bool;

            public function root(): bool;

            /** @return array<string, string|false> */
            public function translatedLanguages(bool $onlyPublished = true): array;

            /** @return iterable<PageInterface> */
            public function children(): iterable;

            public function parent(): ?PageInterface;

            public function header(): mixed;
        }
    }
}

namespace Grav\Common\Page {
    use Grav\Common\Page\Interfaces\PageInterface;

    if (!class_exists(Pages::class, false)) {
        class Pages
        {
            public bool $enabled = false;
            public int $resets = 0;

            /** @var array<string, PageInterface> route => page */
            public array $byRoute = [];

            /** @var array<string, PageInterface> folder path => page */
            public array $byPath = [];

            public function enablePages(): void
            {
                $this->enabled = true;
            }

            public function init(): void
            {
            }

            public function reset(): void
            {
                ++$this->resets;
            }

            public function find(string $route, bool $all = false): ?PageInterface
            {
                return $this->byRoute[$route] ?? null;
            }

            public function get(string $path): ?PageInterface
            {
                return $this->byPath[$path] ?? null;
            }

            /** @return array<string, PageInterface> */
            public function instances(): array
            {
                return $this->byPath;
            }
        }
    }
}
