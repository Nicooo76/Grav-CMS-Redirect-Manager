<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

/** Implemented by adapters whose input describes broken URLs instead of rules (crawler exports). */
interface NotFoundPathProvider
{
    /**
     * @return list<string> unique paths that answered 404 or 410
     */
    public function notFoundPaths(string $content, ImportOptions $options): array;
}
