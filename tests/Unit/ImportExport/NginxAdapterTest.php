<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\NginxAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use Grav\Plugin\RedirectManager\ImportExport\ImportPreview;
use Grav\Plugin\RedirectManager\ImportExport\Support\Args;
use Grav\Plugin\RedirectManager\ImportExport\Support\NginxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(NginxAdapter::class)]
#[CoversClass(NginxConfig::class)]
#[CoversClass(Args::class)]
final class NginxAdapterTest extends ImportExportTestCase
{
    private function nginx(string $content): ImportPreview
    {
        return $this->preview($content, Format::Nginx);
    }

    public function testRealisticFile(): void
    {
        $preview = $this->nginx($this->fixture('sample-nginx.conf'));
        $sigs = self::sigs($preview);

        self::assertSame([], $preview->errors);
        self::assertSame([
            'e:/alt=>/neu#301 cs strict',
            'e:/entfernt=>#410 cs strict',
            'e:/kurz=>https://www.example.com/lang#302 cs strict',
            'r:^/produkte/(\d+)$=>/shop/artikel-$1#301 cs',
            'r:^/News/(?<slug>[^/]+)$=>/aktuelles/{slug}#302',
            'w:/docs/*=>/dokumentation/#301 cs',
            'e:/suche=>/finden#301 cs strict q=pass',
            'e:/form=>/bot-form#301 cs strict header:User-Agentregex((?i)bot|spider)',
            'e:/promo=>/shop-promo#302 cs strict hosts=shop.example.com',
            'e:/marke=>/marken/acme#301 cs strict q=params{"brand":"acme"}',
            'r:^/(\d{4})/(\d{2})/$=>/archiv/$1-$2#301 cs q=pass',
            'e:/weiterleitung=>/ziel#302 cs strict q=pass',
            'w:/*=>https://neu.example.org/$1#301 cs q=pass hosts=alt.example.org',
        ], $sigs);

        $codes = [];
        foreach ($preview->rows as $row) {
            if ($row->rule === null) {
                $codes[] = self::codes($row)[0];
            }
        }
        self::assertSame([
            'directive_ignored',      // map
            'unsupported_status',     // return 405
            'unsupported_variable',   // https://$host/new$request_uri
            'internal_rewrite_skipped',
            'directive_ignored',      // try_files
            'directive_ignored',      // fastcgi_pass
            'unconditional_redirect', // default_server
        ], $codes);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function locations(): iterable
    {
        yield 'exact' => ['location = /a { return 301 /b; }', ['e:/a=>/b#301 cs strict']];
        yield 'regex' => ['location ~ ^/p/(\d+)$ { return 301 /q/$1; }', ['r:^/p/(\d+)$=>/q/$1#301 cs']];
        yield 'regex insensitive' => ['location ~* ^/p/(\d+)$ { return 301 /q/$1; }', ['r:^/p/(\d+)$=>/q/$1#301']];
        yield 'regex literal is exact' => ['location ~ ^/a$ { return 301 /b; }', ['e:/a=>/b#301 cs strict']];
        yield 'regex literal with slash' => ['location ~* ^/a/?$ { return 301 /b; }', ['e:/a=>/b#301']];
        yield 'regex wildcard' => ['location ~* ^/blog/(.*)$ { return 301 /news/$1; }', ['w:/blog/*=>/news/$1#301']];
        yield 'caret tilde prefix' => ['location ^~ /docs { return 301 /d; }', ['w:/docs*=>/d#301 cs']];
        yield 'prefix with slash' => ['location /docs/ { return 301 /d/; }', ['w:/docs/*=>/d/#301 cs']];
        yield 'quoted regex with braces' => ['location ~ "^/y/(\d{4})$" { return 301 /z/$1; }', ['r:^/y/(\d{4})$=>/z/$1#301 cs']];
        yield 'single quoted' => ["location ~ '^/y/(\\d+)\$' { return 301 /z/\$1; }", ['r:^/y/(\d+)$=>/z/$1#301 cs']];
        yield 'named capture' => ['location ~ ^/(?<a>\w+)/(?<b>\w+)$ { return 301 /$b/$a; }', ['r:^/(?<a>\w+)/(?<b>\w+)$=>/{b}/{a}#301 cs']];
        yield 'braced variable' => ['location ~ ^/(?<a>\w+)$ { return 301 /${a}/x; }', ['r:^/(?<a>\w+)$=>/{a}/x#301 cs']];
        yield 'numbered braced' => ['location ~ ^/(\w+)$ { return 301 /${1}/x; }', ['r:^/(\w+)$=>/$1/x#301 cs']];
        yield 'nested in server and http' => ['http { server { location = /a { return 308 /b; } } }', ['e:/a=>/b#308 cs strict']];
        yield 'comments' => ["# top\nlocation = /a { # trailing\n  return 301 /b; # here\n}", ['e:/a=>/b#301 cs strict']];
        yield 'multiple rules in one location' => ['location = /a { return 301 /b; return 302 /c; }', ['e:/a=>/b#301 cs strict', 'e:/a=>/c#302 cs strict']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('locations')]
    public function testLocations(string $config, array $expected): void
    {
        $preview = $this->nginx($config);

        self::assertSame([], $preview->errors);
        self::assertSame($expected, self::sigs($preview));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function returns(): iterable
    {
        yield 'gone' => ['location = /a { return 410; }', ['e:/a=>#410 cs strict']];
        yield 'legal' => ['location = /a { return 451; }', ['e:/a=>#451 cs strict']];
        yield 'url only is 302' => ['location = /a { return https://x.test/y; }', ['e:/a=>https://x.test/y#302 cs strict']];
        yield 'path only is 302' => ['location = /a { return /y; }', ['e:/a=>/y#302 cs strict']];
        yield '303 maps to 302' => ['location = /a { return 303 /y; }', ['e:/a=>/y#302 cs strict']];
        yield 'is_args args' => ['location = /a { return 301 /y$is_args$args; }', ['e:/a=>/y#301 cs strict q=pass']];
        yield 'question args' => ['location = /a { return 301 /y?$args; }', ['e:/a=>/y#301 cs strict q=pass']];
        yield 'query in target' => ['location = /a { return 301 /y?x=1; }', ['e:/a=>/y?x=1#301 cs strict']];
        yield 'request_uri catch all' => ['server { server_name old.test; location / { return 301 https://new.test$request_uri; } }', ['w:/*=>https://new.test/$1#301 cs q=pass hosts=old.test']];
        yield 'server level' => ['server { server_name old.test *.old.test _; return 301 https://new.test$request_uri; }', ['w:/*=>https://new.test/$1#301 cs q=pass hosts=old.test|*.old.test']];
        yield 'host if at server level' => ['server { if ($host = old.test) { return 301 https://new.test$request_uri; } }', ['w:/*=>https://new.test/$1#301 cs q=pass hosts=old.test']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('returns')]
    public function testReturnForms(string $config, array $expected): void
    {
        self::assertSame($expected, self::sigs($this->nginx($config)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skippedReturns(): iterable
    {
        yield 'ok body' => ['location = /a { return 200 "ok"; }', 'unsupported_status'];
        yield '404' => ['location = /a { return 404; }', 'unsupported_status'];
        yield 'scheme variable' => ['location = /a { return 301 $scheme://example.com/; }', 'unsupported_variable'];
        yield 'host variable' => ['location = /a { return 301 https://$host/b; }', 'unsupported_variable'];
        yield 'request uri outside catch all' => ['location = /a { return 301 /b$request_uri; }', 'unsupported_variable'];
        yield 'unknown capture' => ['location ~ ^/(x)$ { return 301 /$unknown; }', 'unsupported_variable'];
        yield 'server return without host' => ['server { return 301 https://x.test$request_uri; }', 'unconditional_redirect'];
        yield 'location slash without host' => ['location / { return 301 https://x.test$request_uri; }', 'unconditional_redirect'];
        yield 'named location' => ['location @fallback { return 301 /b; }', 'location_ignored'];
        yield 'unsupported if' => ['location = /a { if ($request_method = POST) { return 301 /b; } }', 'unsupported_condition'];
        yield 'negated host' => ['location = /a { if ($host != x.test) { return 301 /b; } }', 'unsupported_condition'];
        yield 'internal rewrite last' => ['rewrite ^/a$ /b last;', 'internal_rewrite_skipped'];
        yield 'internal rewrite break' => ['rewrite ^/a$ /b break;', 'internal_rewrite_skipped'];
        yield 'internal rewrite no flag' => ['rewrite ^/a$ /b;', 'internal_rewrite_skipped'];
        yield 'try_files' => ['location / { try_files $uri /index.php; }', 'directive_ignored'];
        yield 'proxy_pass' => ['location /api { proxy_pass http://backend; }', 'directive_ignored'];
        yield 'map block' => ['map $uri $x { /a /b; }', 'directive_ignored'];
        yield 'include' => ['include /etc/nginx/redirects.conf;', 'directive_ignored'];
    }

    #[DataProvider('skippedReturns')]
    public function testSkippedConstructsAreReported(string $config, string $code): void
    {
        $preview = $this->nginx($config);

        self::assertSame([], $preview->rules());
        self::assertSame(0, $preview->counts()['errors']);
        self::assertSame($code, self::codes($preview->rows[0])[0]);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rewrites(): iterable
    {
        yield 'permanent' => ['rewrite ^/a$ /b permanent;', ['e:/a=>/b#301 cs strict q=pass']];
        yield 'redirect' => ['rewrite ^/a$ /b redirect;', ['e:/a=>/b#302 cs strict q=pass']];
        yield 'question mark drops the query' => ['rewrite ^/a$ /b? permanent;', ['e:/a=>/b#301 cs strict']];
        yield 'absolute url without flag' => ['rewrite ^/a$ https://x.test/b;', ['e:/a=>https://x.test/b#302 cs strict q=pass']];
        yield 'captures' => ['rewrite ^/p/(\d+)/(\w+)$ /q/$2/$1 permanent;', ['r:^/p/(\d+)/(\w+)$=>/q/$2/$1#301 cs q=pass']];
        yield 'insensitive flag' => ['rewrite (?i)^/a$ /b permanent;', ['e:/a=>/b#301 strict q=pass']];
        yield 'quoted regex' => ['rewrite "^/y/(\d{4})$" /z/$1 permanent;', ['r:^/y/(\d{4})$=>/z/$1#301 cs q=pass']];
        yield 'inside location' => ['location /x { rewrite ^/x/(.*)$ /y/$1 permanent; }', ['w:/x/*=>/y/$1#301 cs q=pass']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('rewrites')]
    public function testRewrite(string $config, array $expected): void
    {
        self::assertSame($expected, self::sigs($this->nginx($config)));
    }

    public function testInvalidDirectives(): void
    {
        $preview = $this->nginx("location = /a { return; }\nrewrite ^/b\$;\n");

        self::assertSame(['invalid_line', 'invalid_line'], array_map(static fn ($r) => $r->errors[0]->code ?? '', $preview->rows));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditions(): iterable
    {
        yield 'host equals' => ['$host = a.test', 'hosts=a.test'];
        yield 'http_host' => ['$http_host = a.test', 'hosts=a.test'];
        yield 'host regex' => ['$host ~* ^(a\.test|b\.test)$', 'hosts=a.test|b.test'];
        yield 'host optional www' => ['$host ~ ^(www\.)?a\.test$', 'hosts=a.test|www.a.test'];
        yield 'scheme' => ['$scheme = https', 'schemes=https'];
        yield 'header exists' => ['$http_x_flag', 'header:X-Flagexists()'];
        yield 'header empty' => ['$http_x_flag = ""', 'header:X-Flag!exists()'];
        yield 'header equals' => ['$http_x_flag = on', 'header:X-Flagequals(on)'];
        yield 'header not equals' => ['$http_x_flag != on', 'header:X-Flag!equals(on)'];
        yield 'header regex' => ['$http_user_agent ~ Googlebot', 'header:User-Agentcontains(Googlebot)'];
        yield 'header regex insensitive' => ['$http_user_agent ~* googlebot', 'header:User-Agentregex((?i)googlebot)'];
        yield 'header negated regex' => ['$http_referer !~ ^https://a\.test', 'header:Referer!starts_with(https://a.test)'];
        yield 'header negated insensitive' => ['$http_referer !~* bot', 'header:Referer!regex((?i)bot)'];
        yield 'multiword header' => ['$http_accept_language ~ ^de', 'header:Accept-Languagestarts_with(de)'];
        yield 'cookie equals' => ['$cookie_beta = 1', 'cookie:betaequals(1)'];
        yield 'cookie exists' => ['$cookie_beta', 'cookie:betaexists()'];
        yield 'arg' => ['$arg_id = 5', 'q=params{"id":"5"}'];
        yield 'arg exists' => ['$arg_id', 'q=params{"id":null}'];
        yield 'args exact' => ['$args = "a=b&c=d"', 'q=exact'];
        yield 'query_string exact' => ['$query_string = a=b', 'q=exact'];
    }

    #[DataProvider('conditions')]
    public function testIfConditions(string $cond, string $expected): void
    {
        $preview = $this->nginx("location = /a {\n if ({$cond}) {\n return 301 /b;\n }\n}\n");

        self::assertSame([], $preview->errors);
        $sigs = self::sigs($preview);
        self::assertCount(1, $sigs, $cond);
        self::assertStringContainsString($expected, $sigs[0], $cond);
    }

    public function testQueryConditionOnRegexRuleIsReported(): void
    {
        $preview = $this->nginx('location ~ ^/a/(\d+)$ { if ($args = "x=1") { return 301 /b/$1; } }');

        self::assertSame(['unsupported_condition'], self::codes($preview->rows[0]));
        self::assertSame('ignore', $preview->rules()[0]->queryMode->value);
    }

    public function testStructuralProblemsAreFileErrors(): void
    {
        self::assertSame(['invalid_nginx'], array_map(static fn ($i) => $i->code, $this->nginx('location = /a { return 301 /b;')->errors));
        self::assertSame(['invalid_nginx'], array_map(static fn ($i) => $i->code, $this->nginx("return 301 /b;\n}\n")->errors));
    }

    public function testTokenizer(): void
    {
        $nodes = NginxConfig::parse("a b \"c d\" 'e f';\nblock x {\n  inner \${var} \$other;\n  deep { leaf; }\n}\n# only a comment\nregex ~ a{2};\n");

        self::assertSame('a', $nodes[0]->name);
        self::assertSame(['b', 'c d', 'e f'], $nodes[0]->args);
        self::assertSame(1, $nodes[0]->line);
        self::assertNull($nodes[0]->children);
        self::assertSame('block', $nodes[1]->name);
        self::assertSame(2, $nodes[1]->line);
        self::assertSame(['${var}', '$other'], $nodes[1]->children[0]->args ?? []);
        self::assertSame('leaf', $nodes[1]->children[1]->children[0]->name ?? '');
        self::assertSame('a b "c d" "e f"', $nodes[0]->text());
    }

    public function testArgsQuoteAndSplitAreInverse(): void
    {
        foreach (['plain', 'with space', 'a"b', 'back\\slash', 'semi;colon', '{brace}', "it's"] as $arg) {
            self::assertSame([$arg], Args::split(Args::quote($arg)), $arg);
        }
        self::assertSame('""', Args::quote(''));
        self::assertSame(['a', '', 'b'], Args::split('a "" b'));
        self::assertSame(['^/x\.y$'], Args::split('^/x\.y$'), 'backslashes outside quotes are regex escapes');
    }

    // ---------------------------------------------------------------- export

    public function testExportForms(): void
    {
        $rules = FixtureRules::only(['cs_noslash', 'cs_slash', 'exact_301', 'gone_410', 'legal_451', 'wild_blog', 'regex_named', 'query_pass', 'space']);
        $out = (new Exporter())->export($rules, Format::Nginx, new ExportOptions(includeHeader: false))->content;

        foreach ([
            "location = /Case/Sensitive {\n    return 301 /case-target;\n}\n",
            "location ~ ^/case-slash/?\$ {\n    return 301 /t;\n}\n",
            "location ~* ^/old-page/?\$ {\n    return 301 /new-page;\n}\n",
            "location ~* ^/gone-page/?\$ {\n    return 410;\n}\n",
            "location ~* ^/legal/?\$ {\n    return 451;\n}\n",
            "location ~* ^/blog/(.*)\$ {\n    return 301 /news/\$1;\n}\n",
            "location ~ \"^/(?<year>\\\\d{4})/(?<slug>[^/]+)\$\" {\n    return 301 /archive/\$year/\$slug;\n}\n",
            "location ~* ^/pass-query/?\$ {\n    return 301 /target\$is_args\$args;\n}\n",
            "location ~* \"^/my page/?\$\" {\n    return 301 /my-page;\n}\n",
        ] as $block) {
            self::assertStringContainsString($block, $out);
        }
    }

    public function testExportConditions(): void
    {
        $rules = FixtureRules::only(['host', 'scheme', 'header_ua', 'header_ref', 'cookie', 'query_exact', 'host_wild']);
        $out = (new Exporter())->export($rules, Format::Nginx, new ExportOptions(includeHeader: false))->content;

        foreach ([
            'if ($host ~* ^(example\.com|www\.example\.com)$) {',
            'if ($host ~* ^[^.]+\.example\.org$) {',
            'if ($scheme = https) {',
            'if ($http_user_agent ~ Googlebot) {',
            'if ($http_referer ~ ^https://partner\.example) {',
            'if ($cookie_beta = 1) {',
            'if ($args = q=1) {',
        ] as $line) {
            self::assertStringContainsString($line, $out);
        }
        $single = (new Exporter())->export([FixtureRules::all()['host']->with(['conditions' => ['hosts' => ['one.test']]])], Format::Nginx, new ExportOptions(includeHeader: false))->content;
        self::assertStringContainsString('if ($host = one.test) {', $single);
    }

    public function testExportConditionOperators(): void
    {
        $base = FixtureRules::all()['header_ua'];
        $forms = [
            ['exists', '', false, 'if ($http_user_agent) {'],
            ['exists', '', true, 'if ($http_user_agent = "") {'],
            ['equals', 'Bot', false, 'if ($http_user_agent = Bot) {'],
            ['equals', 'Bot', true, 'if ($http_user_agent != Bot) {'],
            ['starts_with', 'Bot', true, 'if ($http_user_agent !~ ^Bot) {'],
            ['regex', '(?i)bot|spider', false, 'if ($http_user_agent ~* bot|spider) {'],
        ];
        foreach ($forms as [$operator, $value, $negate, $expected]) {
            $rule = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => $operator, 'value' => $value, 'negate' => $negate]]]]);
            self::assertStringContainsString($expected, (new Exporter())->export([$rule], Format::Nginx, new ExportOptions())->content, $operator);
        }
        $header = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Real-Ip', 'operator' => 'exists']]]]);
        self::assertStringContainsString('if ($http_x_real_ip) {', (new Exporter())->export([$header], Format::Nginx, new ExportOptions())->content);
    }

    public function testExportSkips(): void
    {
        $rules = FixtureRules::only(['language', 'alias_200', 'soft', 'query_params']);
        $result = (new Exporter())->export($rules, Format::Nginx, new ExportOptions());
        $codes = [];
        foreach ($result->skipped as $note) {
            $codes[substr($note->ruleId, 5)] = $note->code;
        }
        ksort($codes);

        self::assertSame(['alias_200' => 'pass_through_not_supported', 'language' => 'language_not_supported', 'query_params' => 'query_not_supported', 'soft' => 'only_if_not_found_not_supported'], $codes);
        self::assertSame(0, $result->exported);

        $two = FixtureRules::all()['host']->with(['conditions' => ['hosts' => ['a.test'], 'schemes' => ['https']]]);
        self::assertSame(['multiple_conditions_not_supported'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$two], Format::Nginx, new ExportOptions())->skipped));

        $one = FixtureRules::all()['query_params']->with(['query_params' => ['weird name' => 'x']]);
        self::assertSame(['query_not_supported'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$one], Format::Nginx, new ExportOptions())->skipped));

        $named = FixtureRules::all()['regex_named']->with(['target' => '/x/{nope}']);
        self::assertSame(['named_group_unresolved'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$named], Format::Nginx, new ExportOptions())->skipped));
    }

    public function testExportSingleQueryParam(): void
    {
        $rule = FixtureRules::all()['query_params']->with(['query_params' => ['brand' => 'acme']]);
        $out = (new Exporter())->export([$rule], Format::Nginx, new ExportOptions(includeHeader: false))->content;
        self::assertStringContainsString('if ($arg_brand = acme) {', $out);

        $exists = FixtureRules::all()['query_params']->with(['query_params' => ['brand' => null]]);
        self::assertStringContainsString('if ($arg_brand) {', (new Exporter())->export([$exists], Format::Nginx, new ExportOptions())->content);
    }
}
