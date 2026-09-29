<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

interface ImportAdapter
{
    /**
     * Parses normalised text (UTF-8, LF line ends, no BOM) into rows. Fatal file problems are
     * reported by throwing ImportException; everything else becomes row errors or warnings.
     *
     * @return list<ImportRow>
     */
    public function parse(string $content, ImportOptions $options): array;
}
