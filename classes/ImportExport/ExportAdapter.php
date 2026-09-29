<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;

interface ExportAdapter
{
    /**
     * @param list<Rule> $rules already filtered and sorted (evaluation order) by the Exporter
     */
    public function export(array $rules, ExportOptions $options): ExportResult;
}
