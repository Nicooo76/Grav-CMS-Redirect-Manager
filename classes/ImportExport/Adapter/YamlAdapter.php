<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\SafeYaml;
use Grav\Plugin\RedirectManager\ImportExport\Support\StructuredRules;
use Grav\Plugin\RedirectManager\Util\Clock;
use Symfony\Component\Yaml\Yaml;

/** Same structure as rules.yaml: `{version: 1, rules: [...]}` or a bare list. */
final class YamlAdapter implements ImportAdapter, ExportAdapter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $data = SafeYaml::parse($content);

        return StructuredRules::rows($data, new RowFactory($options, $this->clock), $options->maxRows);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $data = ['version' => StructuredRules::VERSION, 'rules' => array_map(static fn ($r): array => $r->toArray(), $rules)];

        return new ExportResult(Yaml::dump($data, 4, 2), exported: count($rules));
    }
}
