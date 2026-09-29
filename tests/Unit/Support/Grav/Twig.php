<?php

declare(strict_types=1);

namespace Twig {
    if (!class_exists(TwigFunction::class, false)) {
        class TwigFunction
        {
            /** @var callable */
            private $callable;

            public function __construct(private readonly string $name, callable $callable)
            {
                $this->callable = $callable;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getCallable(): callable
            {
                return $this->callable;
            }
        }
    }

    if (!class_exists(TwigFilter::class, false)) {
        class TwigFilter
        {
            /** @var callable */
            private $callable;

            public function __construct(private readonly string $name, callable $callable)
            {
                $this->callable = $callable;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getCallable(): callable
            {
                return $this->callable;
            }
        }
    }
}

namespace Twig\Extension {
    if (!class_exists(AbstractExtension::class, false)) {
        abstract class AbstractExtension
        {
            /** @return list<\Twig\TwigFunction> */
            public function getFunctions(): array
            {
                return [];
            }

            /** @return list<\Twig\TwigFilter> */
            public function getFilters(): array
            {
                return [];
            }
        }
    }
}
