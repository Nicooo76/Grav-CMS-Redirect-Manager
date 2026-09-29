<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use Grav\Plugin\RedirectManager\ImportExport\Support\SafeYaml;

enum Format: string
{
    case Csv = 'csv';
    case Json = 'json';
    case Yaml = 'yaml';
    case GravSite = 'grav_site';
    case Htaccess = 'htaccess';
    case Nginx = 'nginx';
    case WordpressJson = 'wordpress_json';
    case WordpressCsv = 'wordpress_csv';
    case CrawlerCsv = 'crawler_csv';
    case CloudflareCsv = 'cloudflare_csv';
    case Netlify = 'netlify';

    public function canImport(): bool
    {
        return $this !== self::CloudflareCsv;
    }

    public function canExport(): bool
    {
        return $this !== self::CrawlerCsv;
    }

    public function extension(): string
    {
        return match ($this) {
            self::Csv, self::WordpressCsv, self::CrawlerCsv, self::CloudflareCsv => 'csv',
            self::Json, self::WordpressJson => 'json',
            self::Yaml, self::GravSite => 'yaml',
            self::Htaccess => 'htaccess',
            self::Nginx => 'conf',
            self::Netlify => 'txt',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv, self::WordpressCsv, self::CrawlerCsv, self::CloudflareCsv => 'text/csv',
            self::Json, self::WordpressJson => 'application/json',
            self::Yaml, self::GravSite => 'application/yaml',
            self::Htaccess, self::Nginx, self::Netlify => 'text/plain',
        };
    }

    /** Suggested download name. */
    public function filename(): string
    {
        return match ($this) {
            self::Htaccess => 'redirects.htaccess',
            self::Nginx => 'redirects.nginx.conf',
            self::Netlify => '_redirects',
            self::GravSite => 'site-redirects.yaml',
            self::WordpressJson => 'redirects-wordpress.json',
            self::WordpressCsv => 'redirects-wordpress.csv',
            self::CloudflareCsv => 'redirects-cloudflare.csv',
            self::CrawlerCsv => 'crawler.csv',
            default => 'redirects.' . $this->extension(),
        };
    }

    /**
     * Guesses the format from the file name and, when the name is not enough, from the content.
     */
    public static function detect(string $filename, string $content): ?self
    {
        $base = strtolower(basename($filename));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

        if ($base === '_redirects') {
            return self::Netlify;
        }
        if ($base === '.htaccess' || $ext === 'htaccess') {
            return self::Htaccess;
        }
        if (str_contains($base, 'nginx')) {
            return self::Nginx;
        }

        return match ($ext) {
            'csv', 'tsv' => self::detectCsv($content),
            'json' => self::detectJson($content) ?? self::Json,
            'yaml', 'yml' => self::detectYaml($content),
            'conf' => self::sniff($content) ?? self::Nginx,
            default => self::sniff($content),
        };
    }

    private static function detectCsv(string $content): self
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $delimiter = Csv::detectDelimiter($text);
        $records = Csv::parse(substr($text, 0, 32768), $delimiter, 6, true);
        // Crawler exports sometimes start with a title line; look at the first few records.
        foreach ($records as $record) {
            $cells = array_map(static fn (string $c): string => str_replace([' ', '-'], '_', strtolower(trim($c))), $record['cells']);
            if (in_array('source_url', $cells, true) && in_array('target_url', $cells, true)) {
                return self::CloudflareCsv;
            }
            $hasUrl = array_intersect($cells, ['address', 'url', 'destination']) !== [];
            $hasStatus = array_intersect($cells, ['status_code', 'http_status_code', 'response_code', 'status_code_http']) !== [];
            if ($hasUrl && $hasStatus && !in_array('target', $cells, true)) {
                return self::CrawlerCsv;
            }
            if (in_array('source', $cells, true) && in_array('target', $cells, true) && in_array('regex', $cells, true) && in_array('code', $cells, true)) {
                return self::WordpressCsv;
            }
        }

        return self::Csv;
    }

    private static function detectJson(string $content): ?self
    {
        if (preg_match('/^\s*[\[{]/', $content) !== 1) {
            return null;
        }
        $data = json_decode($content, true, 32);
        if (is_array($data) && !array_is_list($data) && isset($data['redirects']) && is_array($data['redirects']) && !isset($data['rules'])) {
            return self::WordpressJson;
        }

        return self::Json;
    }

    private static function detectYaml(string $content): self
    {
        try {
            $data = SafeYaml::parse($content);
        } catch (ImportException) {
            return self::Yaml;
        }
        if (is_array($data) && !array_is_list($data) && !isset($data['rules']) && (isset($data['redirects']) || isset($data['routes']))) {
            return self::GravSite;
        }

        return self::Yaml;
    }

    /** Content sniffing for files without a helpful extension. */
    private static function sniff(string $content): ?self
    {
        $head = substr($content, 0, 65536);
        if (preg_match('/^\s*[\[{]/', $head) === 1) {
            return self::detectJson($content);
        }
        if (preg_match('/^\s*(RewriteEngine|RewriteRule|RewriteCond|RedirectMatch|Redirect(?:Permanent|Temp)?\s|<IfModule)/mi', $head) === 1) {
            return self::Htaccess;
        }
        if (preg_match('/^\s*(location\s+[^\n{]*\{|rewrite\s+\S+\s+\S+|server\s*\{|return\s+30\d)/mi', $head) === 1) {
            return self::Nginx;
        }
        if (preg_match('/^\s*(rules|redirects|routes)\s*:/m', $head) === 1) {
            return self::detectYaml($content);
        }
        if (preg_match('~^\s*/\S*\s+\S+(\s+\d{3}!?)?\s*$~m', $head) === 1 && preg_match('/^\s*\S+[,;\t]\S/m', $head) !== 1) {
            return self::Netlify;
        }

        return null;
    }
}
