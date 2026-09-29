<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, Format|null}>
     */
    public static function detection(): iterable
    {
        $csv = "source,target,status\n/a,/b,301\n";
        yield '_redirects' => ['_redirects', "/a /b 301\n", Format::Netlify];
        yield '.htaccess' => ['.htaccess', 'Redirect 301 /a /b', Format::Htaccess];
        yield 'htaccess extension' => ['old.htaccess', 'anything', Format::Htaccess];
        yield 'nginx in name' => ['redirects.nginx', 'anything', Format::Nginx];
        yield 'csv' => ['redirects.csv', $csv, Format::Csv];
        yield 'tsv' => ['redirects.tsv', "source\ttarget\n/a\t/b\n", Format::Csv];
        yield 'csv with bom' => ['r.csv', "\xEF\xBB\xBF" . $csv, Format::Csv];
        yield 'cloudflare csv' => ['bulk.csv', "source_url,target_url,status_code\nexample.com/a,https://example.com/b,301\n", Format::CloudflareCsv];
        yield 'wordpress csv' => ['r.csv', "source,target,regex,code,type,hits,title,status\n/a,/b,0,301,url,0,,enabled\n", Format::WordpressCsv];
        yield 'screaming frog' => ['r.csv', "Address,Status Code,Status\nhttps://x.test/a,404,Not Found\n", Format::CrawlerCsv];
        yield 'sitebulb' => ['r.csv', "URL,HTTP Status Code\nhttps://x.test/a,404\n", Format::CrawlerCsv];
        yield 'crawler with title line' => ['r.csv', "\"Response Codes: Client Error (4xx)\"\nAddress,Status Code\nhttps://x.test/a,404\n", Format::CrawlerCsv];
        yield 'json' => ['r.json', '{"version":1,"rules":[]}', Format::Json];
        yield 'bare json array' => ['r.json', '[]', Format::Json];
        yield 'wordpress json' => ['r.json', '{"plugin":{},"redirects":[]}', Format::WordpressJson];
        yield 'json without json' => ['r.json', 'not json', Format::Json];
        yield 'yaml' => ['rules.yaml', "version: 1\nrules: []\n", Format::Yaml];
        yield 'yml with redirects only' => ['site.yml', "redirects:\n  /a: /b\n", Format::GravSite];
        yield 'routes only' => ['site.yaml', "routes:\n  /a: /b\n", Format::GravSite];
        yield 'yaml with both' => ['x.yaml', "rules: []\nredirects: {}\n", Format::Yaml];
        yield 'broken yaml' => ['x.yaml', "a: [unclosed\n", Format::Yaml];
        yield 'conf' => ['site.conf', "server { location = /a { return 301 /b; } }", Format::Nginx];
        yield 'conf with apache syntax' => ['x.conf', "RewriteEngine On\nRewriteRule ^a$ /b [R=301]\n", Format::Htaccess];
        yield 'sniff json' => ['upload', '{"redirects": []}', Format::WordpressJson];
        yield 'sniff htaccess' => ['redirects.txt', "# c\nRedirectMatch 301 ^/a /b\n", Format::Htaccess];
        yield 'sniff nginx' => ['redirects.txt', "location = /a {\n return 301 /b;\n}\n", Format::Nginx];
        yield 'sniff nginx rewrite' => ['redirects.txt', "rewrite ^/a$ /b permanent;\n", Format::Nginx];
        yield 'sniff yaml' => ['redirects.txt', "rules:\n  - source: /a\n", Format::Yaml];
        yield 'sniff netlify' => ['redirects.txt', "/a /b 301\n/c /d\n", Format::Netlify];
        yield 'unknown' => ['notes.txt', "hello world\n", null];
        yield 'unknown csv-ish text' => ['notes.txt', "a,b\nc,d\n", null];
    }

    #[DataProvider('detection')]
    public function testDetect(string $filename, string $content, ?Format $expected): void
    {
        self::assertSame($expected, Format::detect($filename, $content));
    }

    public function testCapabilities(): void
    {
        foreach (Format::cases() as $format) {
            self::assertSame($format !== Format::CloudflareCsv, $format->canImport(), $format->value);
            self::assertSame($format !== Format::CrawlerCsv, $format->canExport(), $format->value);
            self::assertNotSame('', $format->extension());
            self::assertNotSame('', $format->mimeType());
            self::assertNotSame('', $format->filename());
            self::assertSame($format, Format::from($format->value));
        }
        self::assertSame('_redirects', Format::Netlify->filename());
        self::assertSame('redirects.csv', Format::Csv->filename());
        self::assertSame('text/csv', Format::CloudflareCsv->mimeType());
        self::assertSame('application/json', Format::WordpressJson->mimeType());
        self::assertSame('conf', Format::Nginx->extension());
    }
}
