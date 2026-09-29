<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\HtaccessAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportPreview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(HtaccessAdapter::class)]
final class HtaccessAdapterTest extends ImportExportTestCase
{
    private function htaccess(string $content, ?ImportOptions $options = null): ImportPreview
    {
        return $this->preview($content, Format::Htaccess, $options);
    }

    public function testRealisticFile(): void
    {
        $preview = $this->htaccess($this->fixture('sample.htaccess'));
        $sigs = self::sigs($preview);

        self::assertSame([], $preview->errors);
        self::assertContains('e:/alt/seite.html=>/neu/seite#301 cs strict', $sigs);
        self::assertContains('e:/firma=>https://www.example.com/unternehmen#301 cs strict', $sigs);
        self::assertContains('e:/aktion=>/angebote#302 cs strict', $sigs);
        self::assertContains('e:/formular=>/danke#302 cs strict', $sigs, 'seeother is mapped to 302');
        self::assertContains('e:/entfernt=>#410 cs strict', $sigs);
        self::assertContains('e:/veraltet=>#410 cs strict', $sigs);
        self::assertContains('e:/a=>/b#301 cs strict', $sigs);
        self::assertContains('e:/c=>/d#302 cs strict', $sigs);
        self::assertContains('r:^/produkte/(\d+)/?$=>/shop/artikel-$1#301 cs', $sigs);
        self::assertContains('w:/News/*=>/aktuelles/$1#302', $sigs, '(?i) turns case sensitivity off');
        self::assertContains('r:^/tmp/.*$=>#410 cs', $sigs);
        // RewriteBase /kunde
        self::assertContains('e:/kunde/alte-seite=>/kunde/neue-seite#301 q=pass', $sigs, 'continuation line, NC, QSA and base');
        self::assertContains('e:/kunde/shop=>/katalog#302 cs strict', $sigs, 'R without code is 302, trailing ? drops the query');
        self::assertContains('e:/kunde/weg=>#410 cs strict', $sigs);
        // after RewriteBase /
        self::assertContains('w:/*=>https://neuedomain.de/$1#301 cs hosts=altedomain.de|www.altedomain.de', $sigs);
        self::assertContains('e:/katalog=>/produkte#302 cs strict hosts=shop.example.com|store.example.com', $sigs, 'OR-ed host conditions merge');
        self::assertContains('e:/artikel.php?id=42=>/artikel/zweiundvierzig#301 cs strict q=exact', $sigs);
        self::assertContains('e:/angebot=>/partner-angebot#301 cs strict q=params{"ref":"partner"}', $sigs);
        self::assertContains('e:/intranet=>/wartung#302 cs strict', $sigs);
        self::assertContains('e:/fehlt=>/gefunden#301 cs strict notfound', $sigs);

        $codesOf = function (string $needle) use ($preview): array {
            foreach ($preview->rows as $row) {
                if (str_contains($row->raw, $needle)) {
                    return self::codes($row);
                }
            }
            self::fail('no row contains ' . $needle);
        };
        self::assertSame(['internal_rewrite_skipped'], $codesOf('RewriteRule ^index\.php$ - [L]'));
        self::assertSame(['internal_rewrite_skipped'], $codesOf('RewriteRule . /index.php [L]'));
        self::assertSame(['status_mapped'], $codesOf('Redirect seeother /formular'));
        self::assertSame(['forbidden_not_supported'], $codesOf('RewriteRule ^gesperrt$ - [F]'));
        self::assertSame(['invalid_target'], $codesOf('RewriteRule ^leer$'));
        self::assertSame(['internal_rewrite_skipped'], $codesOf('^intern$ /index.php?page=intern'));
        self::assertSame(['unsupported_variable'], $codesOf('/beitrag/%1?'));
        self::assertSame(['unsupported_variable'], $codesOf('https://%{HTTP_HOST}/$1'));
        self::assertSame(['unsupported_condition'], $codesOf('%{REMOTE_ADDR}'));
        self::assertSame(['block_ignored'], $codesOf('<FilesMatch'));
        self::assertNotContains('/bild.jpg', array_map(static fn ($r) => $r->source, $preview->rules()), 'directives inside ignored blocks are skipped');
        self::assertSame(['total' => 29, 'valid' => 21, 'errors' => 1, 'duplicates' => 0, 'warnings' => 9, 'skipped' => 7, 'not_found' => 0], $preview->counts());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function redirectDirectives(): iterable
    {
        yield 'status number' => ['Redirect 301 /a /b', ['e:/a=>/b#301 cs strict']];
        yield 'no status is temporary' => ['Redirect /a /b', ['e:/a=>/b#302 cs strict']];
        yield 'permanent word' => ['Redirect permanent /a /b', ['e:/a=>/b#301 cs strict']];
        yield 'temp word' => ['Redirect temp /a /b', ['e:/a=>/b#302 cs strict']];
        yield 'gone word' => ['Redirect gone /a', ['e:/a=>#410 cs strict']];
        yield 'gone number' => ['Redirect 410 /a', ['e:/a=>#410 cs strict']];
        yield 'gone with target ignored' => ['Redirect 410 /a /b', ['e:/a=>#410 cs strict']];
        yield 'case insensitive directive' => ['redirect 307 /a /b', ['e:/a=>/b#307 cs strict']];
        yield 'absolute target' => ['Redirect 301 /a https://other.example/x?y=1', ['e:/a=>https://other.example/x?y=1#301 cs strict']];
        yield 'relative target' => ['Redirect 301 /a b/c', ['e:/a=>/b/c#301 cs strict']];
        yield 'quoted' => ["Redirect 301 \"/my page\" '/new page'", []];
        yield 'quoted path only' => ['Redirect 301 "/my page" /new-page', ['e:/my page=>/new-page#301 cs strict']];
        yield 'RedirectPermanent' => ['RedirectPermanent /a /b', ['e:/a=>/b#301 cs strict']];
        yield 'RedirectTemp' => ['RedirectTemp /a /b', ['e:/a=>/b#302 cs strict']];
        yield 'RedirectMatch capture' => ['RedirectMatch 301 ^/p/(\d+)$ /q/$1', ['r:^/p/(\d+)$=>/q/$1#301 cs']];
        yield 'RedirectMatch default status' => ['RedirectMatch ^/p/(\d+)$ /q/$1', ['r:^/p/(\d+)$=>/q/$1#302 cs']];
        yield 'RedirectMatch gone' => ['RedirectMatch gone ^/old/', ['r:^/old/=>#410 cs']];
        yield 'RedirectMatch literal' => ['RedirectMatch 301 ^/a/?$ /b', ['e:/a=>/b#301 cs']];
        yield 'RedirectMatch wildcard' => ['RedirectMatch 301 ^/blog/(.*)$ /news/$1', ['w:/blog/*=>/news/$1#301 cs']];
        yield 'RedirectMatch case flag' => ['RedirectMatch 301 (?i)^/blog/(.*)$ /news/$1', ['w:/blog/*=>/news/$1#301']];
        yield 'RedirectMatch unanchored' => ['RedirectMatch 301 /old/(\d+) /new/$1', ['r:/old/(\d+)=>/new/$1#301 cs']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('redirectDirectives')]
    public function testRedirectDirectives(string $line, array $expected): void
    {
        $preview = $this->htaccess($line);

        self::assertSame([], $preview->errors);
        self::assertSame($expected, self::sigs($preview));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirectErrors(): iterable
    {
        yield 'no path' => ['Redirect', 'invalid_line'];
        yield 'path without slash' => ['Redirect 301 old /new', 'invalid_line'];
        yield 'missing target' => ['Redirect 301 /a', 'missing_target'];
        yield 'missing target of match' => ['RedirectMatch 301 ^/a$', 'missing_target'];
        yield 'bad regex' => ['RedirectMatch 301 ^/a(b$ /c', 'invalid_regex'];
        yield 'script target' => ['Redirect 301 /a javascript:alert(1)', 'unsafe_target'];
        yield 'rewrite without substitution' => ['RewriteRule ^a$', 'invalid_line'];
        yield 'unknown R status' => ['RewriteRule ^a$ /b [R=banana]', 'invalid_status'];
        yield 'R with non redirect status' => ['RewriteRule ^a$ /b [R=404]', 'invalid_status'];
    }

    #[DataProvider('redirectErrors')]
    public function testRedirectErrors(string $line, string $code): void
    {
        $preview = $this->htaccess($line);

        self::assertSame([], $preview->rules());
        self::assertSame([$code], self::codes($preview->rows[0]));
        self::assertSame(1, $preview->counts()['errors']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rewriteFlags(): iterable
    {
        yield 'all flags' => ['RewriteRule ^old$ /new [R=301,L,NC,QSA]', ['e:/old=>/new#301 strict q=pass']];
        yield 'R alone' => ['RewriteRule ^old$ /new [R]', ['e:/old=>/new#302 cs strict']];
        yield 'R word' => ['RewriteRule ^old$ /new [R=permanent]', ['e:/old=>/new#301 cs strict']];
        yield 'R 307 END NE' => ['RewriteRule ^old$ /new [R=307,END,NE]', ['e:/old=>/new#307 cs strict']];
        yield 'R 308 lowercase flags' => ['RewriteRule ^old$ /new [r=308,l,nc]', ['e:/old=>/new#308 strict']];
        yield 'G' => ['RewriteRule ^old$ - [G]', ['e:/old=>#410 cs strict']];
        yield 'G and L' => ['RewriteRule ^old$ - [G,L,NC]', ['e:/old=>#410 strict']];
        yield 'R=410' => ['RewriteRule ^old$ - [R=410,L]', ['e:/old=>#410 cs strict']];
        yield 'R=451' => ['RewriteRule ^old$ - [R=451,L]', ['e:/old=>#451 cs strict']];
        yield 'optional slash' => ['RewriteRule ^old/?$ /new [R=301,L]', ['e:/old=>/new#301 cs']];
        yield 'leading slash optional' => ['RewriteRule ^/?old$ /new [R=301,L]', ['e:/old=>/new#301 cs strict']];
        yield 'leading slash' => ['RewriteRule ^/old$ /new [R=301,L]', ['e:/old=>/new#301 cs strict']];
        yield 'capture' => ['RewriteRule ^blog/(.+)$ /news/$1 [R=301,L]', ['r:^/blog/(.+)$=>/news/$1#301 cs']];
        yield 'wildcard' => ['RewriteRule ^blog/(.*)$ /news/$1 [R=301,L]', ['w:/blog/*=>/news/$1#301 cs']];
        yield 'catch all' => ['RewriteRule ^(.*)$ https://new.example.com/$1 [R=301,L]', ['w:/*=>https://new.example.com/$1#301 cs']];
        yield 'query dropped by question mark' => ['RewriteRule ^old$ /new? [R=301,L]', ['e:/old=>/new#301 cs strict']];
        yield 'relative substitution' => ['RewriteRule ^old$ new [R=301,L]', ['e:/old=>/new#301 cs strict']];
        yield 'quoted pattern' => ['RewriteRule "^my page$" /new [R=301]', ['e:/my page=>/new#301 cs strict']];
        yield 'case flag in pattern' => ['RewriteRule (?i)^old$ /new [R=301]', ['e:/old=>/new#301 strict']];
        yield 'unanchored pattern' => ['RewriteRule old /new [R=301]', ['r:old=>/new#301 cs']];
        yield 'target with own query' => ['RewriteRule ^old$ /new?x=1 [R=301]', ['e:/old=>/new?x=1#301 cs strict']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('rewriteFlags')]
    public function testRewriteRules(string $line, array $expected): void
    {
        self::assertSame($expected, self::sigs($this->htaccess($line)));
    }

    public function testRewriteRuleThingsThatAreSkippedWithAWarning(): void
    {
        $cases = [
            'RewriteRule ^a$ - [F]' => 'forbidden_not_supported',
            'RewriteRule ^a$ /b [L]' => 'internal_rewrite_skipped',
            'RewriteRule ^a$ /b' => 'internal_rewrite_skipped',
            'RewriteRule ^a$ - [R=301]' => 'internal_rewrite_skipped',
            'RewriteRule ^a$ http://backend/x [P]' => 'proxy_not_supported',
            'RewriteRule !^a$ /b [R=301]' => 'negated_pattern',
            'RewriteRule ^a$ https://%{HTTP_HOST}/b [R=301]' => 'unsupported_variable',
            'RewriteRule ^a$ /b/%1 [R=301]' => 'unsupported_variable',
        ];
        foreach ($cases as $line => $code) {
            $preview = $this->htaccess($line);

            self::assertSame([], $preview->rules(), $line);
            self::assertSame([$code], self::codes($preview->rows[0]), $line);
            self::assertSame(1, $preview->counts()['skipped'], $line);
            self::assertSame(0, $preview->counts()['errors'], $line);
        }
    }

    public function testUnsupportedFlagsAreReportedButTheRuleIsImported(): void
    {
        $preview = $this->htaccess('RewriteRule ^a$ /b [R=301,L,E=foo:bar,C,S=2]');

        self::assertSame(['e:/a=>/b#301 cs strict'], self::sigs($preview));
        self::assertSame(['unsupported_flag', 'unsupported_flag', 'unsupported_flag'], self::codes($preview->rows[0]));
    }

    public function testCommentsBlankLinesAndUnknownDirectives(): void
    {
        $content = <<<'HTACCESS'

            # RewriteRule ^commented$ /out [R=301]
              # indented comment
            Options -Indexes
            ErrorDocument 404 /404.html
            RewriteEngine On
            Header set X-Test "1"
            RewriteRule ^real$ /in [R=301,L]
            HTACCESS;

        self::assertSame(['e:/real=>/in#301 cs strict'], self::sigs($this->htaccess($content)));
    }

    public function testLineContinuations(): void
    {
        $content = "RewriteCond %{HTTP_HOST} ^a\\.example\\.com\$ [NC,\\\nOR]\nRewriteCond %{HTTP_HOST} ^b\\.example\\.com\$\nRewriteRule ^x\$ \\\n  /y \\\n  [R=301,L]\nRedirect 301 /p \\\r\n /q\n";
        $preview = $this->htaccess($content);

        self::assertSame(['e:/x=>/y#301 cs strict hosts=a.example.com|b.example.com', 'e:/p=>/q#301 cs strict'], self::sigs($preview));
        self::assertSame(4, $preview->rows[0]->line, 'the row is numbered by its RewriteRule line');
        self::assertStringContainsString("RewriteCond", $preview->rows[0]->raw);
    }

    public function testRewriteBase(): void
    {
        $content = "RewriteBase /sub/\nRewriteRule ^a\$ b [R=301]\nRewriteBase /\nRewriteRule ^a\$ b [R=301]\nRewriteBase /x\nRedirect 301 /r /s\n";

        self::assertSame(['e:/sub/a=>/sub/b#301 cs strict', 'e:/a=>/b#301 cs strict', 'e:/r=>/s#301 cs strict'], self::sigs($this->htaccess($content)));
    }

    public function testIfModuleWrappersAreTransparentAndOtherBlocksAreSkipped(): void
    {
        $content = <<<'HTACCESS'
            <IfModule mod_alias.c>
              <IfModule mod_rewrite.c>
                Redirect 301 /inside /new
              </IfModule>
            </IfModule>
            <IfDefine SOMETHING>
              Redirect 301 /define /new
            </IfDefine>
            <VirtualHost *:80>
              Redirect 301 /vhost /new
            </VirtualHost>
            <Files "x.php">
              <Files "nested">
                Redirect 301 /nested /new
              </Files>
              Redirect 301 /files /new
            </Files>
            <Location /admin>
              Redirect 301 /location /new
            </Location>
            Redirect 301 /after /new
            HTACCESS;
        $preview = $this->htaccess($content);

        self::assertSame(
            ['/inside', '/define', '/vhost', '/after'],
            array_map(static fn ($r) => $r->source, $preview->rules()),
        );
        $blocks = [];
        foreach ($preview->rows as $row) {
            if ($row->rule === null) {
                $blocks[] = $row->warnings[0]->params['block'];
            }
        }
        self::assertSame(['files', 'location'], $blocks);
    }

    public function testStrayClosingTagIsIgnored(): void
    {
        self::assertSame(['e:/a=>/b#301 cs strict'], self::sigs($this->htaccess("</IfModule>\nRedirect 301 /a /b\n")));
    }

    // ---------------------------------------------------------------- conditions

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostConditions(): iterable
    {
        yield 'optional www' => ['%{HTTP_HOST} ^(www\.)?example\.com$ [NC]', 'hosts=example.com|www.example.com'];
        yield 'plain' => ['%{HTTP_HOST} ^example\.com$', 'hosts=example.com'];
        yield 'equals' => ['%{HTTP_HOST} =Example.com', 'hosts=example.com'];
        yield 'alternatives' => ['%{HTTP_HOST} ^(a\.com|b\.org)$', 'hosts=a.com|b.org'];
        yield 'subdomain wildcard' => ['%{HTTP_HOST} ^[^.]+\.example\.com$', 'hosts=*.example.com'];
        yield 'server name' => ['%{SERVER_NAME} ^example\.com$', 'hosts=example.com'];
        yield 'lowercase variable' => ['%{http_host} ^example\.com$', 'hosts=example.com'];
    }

    #[DataProvider('hostConditions')]
    public function testHostConditions(string $cond, string $expected): void
    {
        $preview = $this->htaccess("RewriteCond {$cond}\nRewriteRule ^a\$ /b [R=301]\n");

        self::assertSame(['e:/a=>/b#301 cs strict ' . $expected], self::sigs($preview));
        self::assertSame([], self::codes($preview->rows[0]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedConditions(): iterable
    {
        yield 'negated host' => ['%{HTTP_HOST} !^www\.'];
        yield 'host regex' => ['%{HTTP_HOST} ^shop\d+\.example\.com$'];
        yield 'remote addr' => ['%{REMOTE_ADDR} ^10\.'];
        yield 'time' => ['%{TIME_HOUR} >12'];
        yield 'file exists' => ['%{REQUEST_FILENAME} -f'];
        yield 'https with odd pattern' => ['%{HTTPS} maybe'];
        yield 'port' => ['%{SERVER_PORT} 8080'];
        yield 'query with capture' => ['%{QUERY_STRING} ^id=(\d+)$'];
        yield 'negated query' => ['%{QUERY_STRING} !^x=1$'];
        yield 'request scheme other' => ['%{REQUEST_SCHEME} ftp'];
    }

    #[DataProvider('unsupportedConditions')]
    public function testUnsupportedConditionsFlagTheRuleButKeepIt(string $cond): void
    {
        $preview = $this->htaccess("RewriteCond {$cond}\nRewriteRule ^a\$ /b [R=301]\n");

        self::assertSame(['e:/a=>/b#301 cs strict'], self::sigs($preview));
        self::assertSame(['unsupported_condition'], self::codes($preview->rows[0]));
        self::assertStringContainsString($cond, $preview->rows[0]->warnings[0]->params['condition']);
    }

    public function testSchemeConditions(): void
    {
        $cases = [
            '%{HTTPS} on' => 'schemes=https', '%{HTTPS} off' => 'schemes=http', '%{HTTPS} !=on' => 'schemes=http',
            '%{HTTPS} ^on$' => 'schemes=https', '%{SERVER_PORT} 80' => 'schemes=http', '%{SERVER_PORT} ^443$' => 'schemes=https',
            '%{REQUEST_SCHEME} https' => 'schemes=https', '%{REQUEST_SCHEME} http' => 'schemes=http',
        ];
        foreach ($cases as $cond => $expected) {
            self::assertSame(['e:/a=>/b#301 cs strict ' . $expected], self::sigs($this->htaccess("RewriteCond {$cond}\nRewriteRule ^a\$ /b [R=301]")), $cond);
        }
    }

    public function testQueryStringConditions(): void
    {
        $cases = [
            '^a=b&c=d$' => 'q=exact',
            '(^|&)x=1(&|$)' => 'q=params{"x":"1"}',
            '(?:^|&)x=1(?:&|$)' => 'q=params{"x":"1"}',
            '(^|&)x(=|&|$)' => 'q=params{"x":null}',
            'id=5' => 'q=params{"id":"5"}',
            'flag' => 'q=params{"flag":null}',
        ];
        foreach ($cases as $pattern => $expected) {
            $sigs = self::sigs($this->htaccess("RewriteCond %{QUERY_STRING} {$pattern}\nRewriteRule ^a\$ /b [R=301]"));
            self::assertCount(1, $sigs, $pattern);
            self::assertStringContainsString($expected, $sigs[0], $pattern);
        }

        $exact = $this->htaccess("RewriteCond %{QUERY_STRING} ^a=b&c=d\$\nRewriteRule ^p\$ /t [R=301]")->rules()[0];
        self::assertSame('/p?a=b&c=d', $exact->source);

        $merged = $this->htaccess("RewriteCond %{QUERY_STRING} (^|&)x=1(&|\$)\nRewriteCond %{QUERY_STRING} (^|&)y=2(&|\$)\nRewriteRule ^p\$ /t [R=301]")->rules()[0];
        self::assertSame(['x' => '1', 'y' => '2'], $merged->queryParams);

        $onRegex = $this->htaccess("RewriteCond %{QUERY_STRING} ^a=b\$\nRewriteRule ^p/(\\d+)\$ /t/\$1 [R=301]");
        self::assertSame(['unsupported_condition'], self::codes($onRegex->rows[0]));
        self::assertSame('ignore', $onRegex->rules()[0]->queryMode->value);
    }

    public function testHeaderConditions(): void
    {
        $cases = [
            '%{HTTP_USER_AGENT} Googlebot' => 'header:User-Agentcontains(Googlebot)',
            '%{HTTP_USER_AGENT} ^Googlebot$' => 'header:User-Agentequals(Googlebot)',
            '%{HTTP_USER_AGENT} ^Google' => 'header:User-Agentstarts_with(Google)',
            '%{HTTP_USER_AGENT} !bot' => 'header:User-Agent!contains(bot)',
            '%{HTTP_USER_AGENT} bot [NC]' => 'header:User-Agentregex((?i)bot)',
            '%{HTTP_USER_AGENT} (?i)bot|spider' => 'header:User-Agentregex((?i)bot|spider)',
            '%{HTTP_USER_AGENT} (bot|spider)' => 'header:User-Agentregex((bot|spider))',
            '%{HTTP_REFERER} ^https://partner\.example' => 'header:Refererstarts_with(https://partner.example)',
            '%{HTTP:X-Forwarded-For} .+' => 'header:X-Forwarded-Forexists()',
            '%{HTTP:Accept-Language} ^de' => 'header:Accept-Languagestarts_with(de)',
        ];
        foreach ($cases as $cond => $expected) {
            $sigs = self::sigs($this->htaccess("RewriteCond {$cond}\nRewriteRule ^a\$ /b [R=301]"));
            self::assertSame(['e:/a=>/b#301 cs strict ' . $expected], $sigs, $cond);
        }
    }

    public function testOrWithMixedConditionsIsReported(): void
    {
        $preview = $this->htaccess("RewriteCond %{HTTP_HOST} ^a\\.com\$ [OR]\nRewriteCond %{REMOTE_ADDR} ^10\\.\nRewriteRule ^a\$ /b [R=301]");

        self::assertSame(['e:/a=>/b#301 cs strict'], self::sigs($preview));
        self::assertSame(['unsupported_condition', 'unsupported_condition'], self::codes($preview->rows[0]));
    }

    public function testSecondHostGroupIsReportedInsteadOfIntersected(): void
    {
        $preview = $this->htaccess("RewriteCond %{HTTP_HOST} ^a\\.com\$\nRewriteCond %{HTTP_HOST} ^b\\.com\$\nRewriteRule ^a\$ /b [R=301]");

        self::assertSame(['e:/a=>/b#301 cs strict hosts=a.com'], self::sigs($preview));
        self::assertSame(['unsupported_condition'], self::codes($preview->rows[0]));
    }

    public function testConditionsStayPendingUntilTheNextRewriteRule(): void
    {
        $preview = $this->htaccess("RewriteCond %{HTTP_HOST} ^a\\.com\$\nRedirect 301 /a /b\nRewriteRule ^c\$ /d [R=301]\n");

        self::assertSame(['e:/a=>/b#301 cs strict', 'e:/c=>/d#301 cs strict hosts=a.com'], self::sigs($preview));
    }

    // ---------------------------------------------------------------- export

    public function testExportForms(): void
    {
        $rules = FixtureRules::only(['cs_noslash', 'cs_slash', 'gone_cs', 'exact_301', 'ci_noslash', 'gone_410', 'legal_451', 'wild_cs', 'wild_blog', 'regex_named']);
        $out = (new Exporter())->export($rules, Format::Htaccess, new ExportOptions())->content;
        $lines = explode("\n", trim($out));

        self::assertContains('Redirect 301 /Case/Sensitive /case-target', $lines);
        self::assertContains('RedirectMatch 301 ^/case-slash/?$ /t', $lines);
        self::assertContains('Redirect gone /gone-cs', $lines);
        self::assertContains('RewriteRule ^old-page/?$ /new-page [R=301,L,NC]', $lines);
        self::assertContains('RewriteRule ^nocase-noslash$ /t2 [R=301,L,NC]', $lines);
        self::assertContains('RewriteRule ^gone-page/?$ - [G,NC]', $lines);
        self::assertContains('RewriteRule ^legal/?$ - [R=451,L,NC]', $lines);
        self::assertContains('RedirectMatch 301 ^/docs/v1/(.*)$ /docs/$1', $lines);
        self::assertContains('RewriteRule ^blog/(.*)$ /news/$1 [R=301,L,NC]', $lines);
        self::assertContains('RedirectMatch 301 "^/(?<year>\\\\d{4})/(?<slug>[^/]+)$" /archive/$1/$2', $lines);
        self::assertSame('# Redirects exported by Redirect Manager.', $lines[0]);
        self::assertSame('RewriteEngine On', $lines[2]);
    }

    public function testExportOnlyDeclaresRewriteEngineWhenNeeded(): void
    {
        $plain = (new Exporter())->export(FixtureRules::only(['cs_noslash']), Format::Htaccess, new ExportOptions(includeHeader: false));

        self::assertSame("Redirect 301 /Case/Sensitive /case-target\n", $plain->content);
    }

    public function testExportConditionLines(): void
    {
        $rules = FixtureRules::only(['host', 'scheme', 'header_ua', 'query_exact', 'query_params', 'query_pass', 'soft', 'host_wild']);
        $out = (new Exporter())->export($rules, Format::Htaccess, new ExportOptions(includeHeader: false))->content;

        foreach ([
            'RewriteCond %{HTTP_HOST} ^(example\.com|www\.example\.com)$ [NC]',
            'RewriteCond %{HTTP_HOST} ^[^.]+\.example\.org$ [NC]',
            'RewriteCond %{HTTPS} on',
            'RewriteCond %{HTTP_USER_AGENT} Googlebot',
            'RewriteCond %{QUERY_STRING} ^q=1$',
            'RewriteCond %{QUERY_STRING} (^|&)id(=|&|$)',
            'RewriteCond %{QUERY_STRING} (^|&)cat=x(&|$)',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^search/?$ /found? [R=301,L,NC]',
            'RewriteRule ^pass-query/?$ /target [R=301,L,NC,QSA]',
        ] as $line) {
            self::assertStringContainsString($line . "\n", $out);
        }
    }

    public function testExportSkipsWhatApacheCannotDo(): void
    {
        $result = (new Exporter())->export(FixtureRules::only(['cookie', 'language', 'alias_200', 'regex_named']), Format::Htaccess, new ExportOptions());
        $reasons = [];
        foreach ($result->skipped as $note) {
            $reasons[substr($note->ruleId, 5)] = $note->code;
        }

        ksort($reasons);
        self::assertSame(['alias_200' => 'pass_through_not_supported', 'cookie' => 'cookie_condition_not_supported', 'language' => 'language_not_supported'], $reasons);
        self::assertStringContainsString('# skipped r030-language (language_not_supported): /de-only', $result->content);
    }

    public function testRegexRulesThatCannotBeRewritten(): void
    {
        $rule = FixtureRules::all()['regex_number']->with(['source' => '^(en|de)/product/(\d+)$', 'case_sensitive' => false]);
        $result = (new Exporter())->export([$rule], Format::Htaccess, new ExportOptions());
        self::assertSame(['regex_not_translatable'], array_map(static fn ($n) => $n->code, $result->skipped));

        $named = FixtureRules::all()['regex_named']->with(['target' => '/x/{unknown}']);
        self::assertSame(['named_group_unresolved'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$named], Format::Htaccess, new ExportOptions())->skipped));
    }

    public function testHeaderConditionExportForms(): void
    {
        $base = FixtureRules::all()['header_ua'];
        $forms = [
            ['exists', '', false, 'RewriteCond %{HTTP_USER_AGENT} .+'],
            ['equals', 'Bot', false, 'RewriteCond %{HTTP_USER_AGENT} ^Bot$'],
            ['starts_with', 'Bot', true, 'RewriteCond %{HTTP_USER_AGENT} !^Bot'],
            ['regex', '(?i)bot|spider', false, 'RewriteCond %{HTTP_USER_AGENT} bot|spider [NC]'],
        ];
        foreach ($forms as [$operator, $value, $negate, $expected]) {
            $rule = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => $operator, 'value' => $value, 'negate' => $negate]]]]);
            $out = (new Exporter())->export([$rule], Format::Htaccess, new ExportOptions())->content;
            self::assertStringContainsString($expected . "\n", $out, $operator);
        }
        $custom = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Test', 'operator' => 'contains', 'value' => 'a b']]]]);
        self::assertStringContainsString('RewriteCond %{HTTP:X-Test} "a b"' . "\n", (new Exporter())->export([$custom], Format::Htaccess, new ExportOptions())->content);
    }

    #[CoversNothing]
    public function testExportedFileIsDetectedAsHtaccess(): void
    {
        $out = (new Exporter())->export(FixtureRules::only(['exact_301']), Format::Htaccess, new ExportOptions())->content;

        self::assertSame(Format::Htaccess, Format::detect('upload.txt', $out));
    }
}
