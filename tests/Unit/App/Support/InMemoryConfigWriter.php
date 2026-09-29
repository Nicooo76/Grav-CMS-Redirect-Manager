<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App\Support;

use Grav\Plugin\RedirectManager\App\ConfigWriter;

final class InMemoryConfigWriter implements ConfigWriter
{
    /** @var list<string> */
    public array $patterns = [];

    public function ignorePatterns(): array
    {
        return $this->patterns;
    }

    public function saveIgnorePatterns(array $patterns): void
    {
        $this->patterns = $patterns;
    }
}
