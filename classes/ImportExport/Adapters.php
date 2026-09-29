<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\CloudflareCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\CrawlerCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\CsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\GravSiteAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\HtaccessAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\JsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\NetlifyAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\NginxAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\WordpressCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\WordpressJsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\YamlAdapter;
use Grav\Plugin\RedirectManager\Util\Clock;

/** Format to adapter mapping. */
final class Adapters
{
    public static function importer(Format $format, Clock $clock): ?ImportAdapter
    {
        return match ($format) {
            Format::Csv => new CsvAdapter($clock),
            Format::Json => new JsonAdapter($clock),
            Format::Yaml => new YamlAdapter($clock),
            Format::GravSite => new GravSiteAdapter($clock),
            Format::Htaccess => new HtaccessAdapter($clock),
            Format::Nginx => new NginxAdapter($clock),
            Format::WordpressJson => new WordpressJsonAdapter($clock),
            Format::WordpressCsv => new WordpressCsvAdapter($clock),
            Format::CrawlerCsv => new CrawlerCsvAdapter($clock),
            Format::Netlify => new NetlifyAdapter($clock),
            Format::CloudflareCsv => null,
        };
    }

    public static function exporter(Format $format, Clock $clock): ?ExportAdapter
    {
        return match ($format) {
            Format::Csv => new CsvAdapter($clock),
            Format::Json => new JsonAdapter($clock),
            Format::Yaml => new YamlAdapter($clock),
            Format::GravSite => new GravSiteAdapter($clock),
            Format::Htaccess => new HtaccessAdapter($clock),
            Format::Nginx => new NginxAdapter($clock),
            Format::WordpressJson => new WordpressJsonAdapter($clock),
            Format::WordpressCsv => new WordpressCsvAdapter($clock),
            Format::CloudflareCsv => new CloudflareCsvAdapter(),
            Format::Netlify => new NetlifyAdapter($clock),
            Format::CrawlerCsv => null,
        };
    }
}
