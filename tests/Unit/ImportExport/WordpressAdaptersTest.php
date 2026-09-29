<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\Adapter\WordpressCsvAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Adapter\WordpressJsonAdapter;
use Grav\Plugin\RedirectManager\ImportExport\Exporter;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\Format;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(WordpressJsonAdapter::class)]
#[CoversClass(WordpressCsvAdapter::class)]
final class WordpressAdaptersTest extends ImportExportTestCase
{
    // ---------------------------------------------------------------- json

    public function testRealisticJsonExport(): void
    {
        $preview = $this->preview($this->fixture('wordpress-redirection.json'), Format::WordpressJson);

        self::assertSame([], $preview->errors);
        // Redirection evaluates by position; the file lists ids 12, 3, 4, ... out of order.
        self::assertSame('r:/blog/(\d{4})/(.*)=>/magazin/$1/$2#301 cs strict q=pass', self::sigs($preview)[0]);
        self::assertSame('e:/app=>https://example.org/app-desktop#302 header:User-Agent!regex(iPhone|Android)', self::sigs($preview)[5]);
        self::assertSame('e:/partner=>/partner-google#301 header:Referercontains(google.)', self::sigs($preview)[6]);

        $rules = $preview->rules();
        self::assertSame(['/blog/(\d{4})/(.*)', '/shop/altes-produkt', '/kontakt-alt', '/kampagne', '/app', '/app', '/partner', '/old-domain'], array_map(static fn ($r) => $r->source, $rules));

        $byTarget = [];
        foreach ($rules as $rule) {
            $byTarget[$rule->target] = $rule;
        }
        self::assertSame('Relaunch 2026', $byTarget['/magazin/$1/$2']->group);
        self::assertSame('', $byTarget['/kontakt']->group, 'the default group "Redirections" means no group');
        self::assertSame('Modified Posts', $byTarget['https://www.example.org/neu']->group);
        self::assertSame('Kontaktseite umgezogen', $byTarget['/kontakt']->note);
        self::assertFalse($byTarget['/']->enabled);
        self::assertTrue($byTarget['/kontakt']->enabled);
        self::assertSame('regex', $byTarget['/magazin/$1/$2']->matchType->value);
        self::assertTrue($byTarget['/magazin/$1/$2']->caseSensitive);
        self::assertFalse($byTarget['/magazin/$1/$2']->ignoreTrailingSlash);
        self::assertSame('pass', $byTarget['/magazin/$1/$2']->queryMode->value);
        self::assertSame('exact', $byTarget['/kontakt']->queryMode->value);
        self::assertSame('url', $byTarget['https://www.example.org/neu']->targetType->value);

        $skipped = [];
        foreach ($preview->rows as $row) {
            if ($row->rule === null) {
                $skipped[] = self::codes($row);
            }
        }
        self::assertSame([['unsupported_match_type'], ['unsupported_action']], $skipped);
        self::assertSame(['total' => 10, 'valid' => 8, 'errors' => 0, 'duplicates' => 0, 'warnings' => 2, 'skipped' => 2, 'not_found' => 0], $preview->counts());
    }

    public function testConditionalMatchTypes(): void
    {
        $item = static fn (string $type, array $data, array $extra = []): array => $extra + [
            'url' => '/x', 'action_code' => 301, 'action_type' => 'url', 'match_type' => $type, 'action_data' => $data, 'regex' => false, 'group_id' => 1,
        ];
        $json = static fn (array ...$items): string => (string) json_encode(['redirects' => $items]);

        $cases = [
            'agent contains' => [$item('agent', ['url_from' => '/m', 'agent' => 'Mobile', 'regex' => false]), ['e:/x=>/m#301 header:User-Agentcontains(Mobile)']],
            'agent regex' => [$item('agent', ['url_from' => '/m', 'agent' => '^Mobile', 'regex' => true]), ['e:/x=>/m#301 header:User-Agentstarts_with(Mobile)']],
            'referrer' => [$item('referrer', ['url_from' => '/m', 'referrer' => 'google', 'regex' => false]), ['e:/x=>/m#301 header:Referercontains(google)']],
            'header' => [$item('header', ['url_from' => '/m', 'name' => 'X-A', 'value' => '1', 'regex' => false]), ['e:/x=>/m#301 header:X-Aequals(1)']],
            'cookie' => [$item('cookie', ['url_from' => '/m', 'name' => 'beta', 'value' => '1', 'regex' => false]), ['e:/x=>/m#301 cookie:betaequals(1)']],
            'cookie regex' => [$item('cookie', ['url_from' => '/m', 'name' => 'beta', 'value' => '^1', 'regex' => true]), ['e:/x=>/m#301 cookie:betastarts_with(1)']],
            'server' => [$item('server', ['url_from' => '/m', 'server' => 'Shop.Test']), ['e:/x=>/m#301 hosts=shop.test']],
            'only notfrom' => [$item('agent', ['url_from' => '', 'url_notfrom' => '/n', 'agent' => 'Bot']), ['e:/x=>/n#301 header:User-Agent!contains(Bot)']],
            'gone by agent' => [$item('agent', ['agent' => 'Bot'], ['action_type' => 'error', 'action_code' => 410]), ['e:/x=>#410 header:User-Agentcontains(Bot)']],
        ];
        foreach ($cases as $name => [$data, $expected]) {
            $preview = $this->preview($json($data), Format::WordpressJson);
            self::assertSame($expected, self::sigs($preview), $name);
        }

        $skipped = [
            'unknown agent' => $item('agent', ['url_from' => '/m']),
            'server without host' => $item('server', ['url_from' => '/m']),
            'ip' => $item('ip', ['url_from' => '/m', 'ip' => ['1.2.3.4']]),
            'role' => $item('role', ['url_from' => '/m', 'role' => 'admin']),
            'language' => $item('language', ['url_from' => '/m', 'language' => 'de']),
            'no target' => $item('agent', ['agent' => 'Bot']),
        ];
        foreach ($skipped as $name => $data) {
            $preview = $this->preview($json($data), Format::WordpressJson);
            self::assertSame([], $preview->rules(), $name);
            self::assertSame(0, $preview->counts()['errors'], $name);
            self::assertSame(1, $preview->counts()['skipped'], $name);
        }
    }

    public function testActionTypesAndOddValues(): void
    {
        $json = static fn (array $item): string => (string) json_encode(['redirects' => [$item + ['url' => '/x', 'group_id' => 1]]]);

        $cases = [
            'pass' => [['action_type' => 'pass', 'action_code' => 0, 'action_data' => ['url' => '/real']], ['e:/x=>/real#200']],
            'old string action_data' => [['action_code' => 302, 'action_type' => 'url', 'action_data' => '/plain'], ['e:/x=>/plain#302']],
            'no code defaults to 301' => [['action_type' => 'url', 'action_data' => ['url' => '/t']], ['e:/x=>/t#301']],
            'error 410' => [['action_type' => 'error', 'action_code' => 410, 'action_data' => null], ['e:/x=>#410']],
            'regex url is match_url' => [['regex' => 1, 'url' => 'regex', 'match_url' => '/y/(.*)', 'action_data' => ['url' => '/z/$1']], ['r:/y/(.*)=>/z/$1#301']],
            'string flags' => [['regex' => 'true', 'url' => '/y/(\d+)', 'action_data' => ['url' => '/z/$1']], ['r:/y/(\d+)=>/z/$1#301']],
            'status word' => [['status' => 'disabled', 'action_data' => ['url' => '/t']], ['e:/x=>/t#301']],
        ];
        foreach ($cases as $name => [$item, $expected]) {
            $preview = $this->preview($json($item), Format::WordpressJson);
            self::assertSame($expected, self::sigs($preview), $name);
        }
        self::assertFalse($this->preview($json(['status' => 'disabled', 'action_data' => ['url' => '/t']]), Format::WordpressJson)->rules()[0]->enabled);

        foreach ([
            'error 404' => ['action_type' => 'error', 'action_code' => 404],
            'random' => ['action_type' => 'random'],
            'nothing' => ['action_type' => 'nothing'],
        ] as $name => $item) {
            $preview = $this->preview($json($item), Format::WordpressJson);
            self::assertSame([], $preview->rules(), $name);
            self::assertSame(1, $preview->counts()['skipped'], $name);
        }
    }

    public function testInvalidWordpressJson(): void
    {
        self::assertSame(['invalid_json'], array_map(static fn ($i) => $i->code, $this->preview('{"redirects": [', Format::WordpressJson)->errors));
        self::assertSame(['invalid_structure'], array_map(static fn ($i) => $i->code, $this->preview('{"groups": []}', Format::WordpressJson)->errors));
        self::assertSame(['invalid_structure'], array_map(static fn ($i) => $i->code, $this->preview('[1,2]', Format::WordpressJson)->errors));

        $preview = $this->preview('{"redirects": ["nope", {"url": "", "action_data": {"url": "/x"}}]}', Format::WordpressJson);
        self::assertSame([['invalid_row'], ['missing_source']], array_map(static fn ($r) => array_map(static fn ($i) => $i->code, $r->errors), $preview->rows));
    }

    public function testJsonExportStructure(): void
    {
        $rules = FixtureRules::only(['exact_301', 'grouped', 'gone_410', 'alias_200', 'header_ua', 'host', 'wild_blog', 'disabled', 'cs_noslash']);
        $rules[5] = $rules[5]->with(['conditions' => ['hosts' => ['shop.test']]]);
        $result = (new Exporter())->export($rules, Format::WordpressJson, new ExportOptions(onlyEnabled: false));
        $data = json_decode($result->content, true);

        self::assertIsArray($data);
        self::assertSame(['plugin', 'groups', 'redirects'], array_keys($data));
        self::assertSame(['Redirections', 'Kampagne'], array_column($data['groups'], 'name'));
        self::assertSame([1, 2], array_column($data['groups'], 'id'));
        self::assertSame([8, 1], array_column($data['groups'], 'redirects'));
        $byUrl = [];
        foreach ($data['redirects'] as $r) {
            $byUrl[$r['url']] = $r;
        }
        self::assertArrayHasKey('/old-page', $byUrl);
        self::assertSame(2, $byUrl['/grouped']['group_id']);
        self::assertSame(['error', 410, null], [$byUrl['/gone-page']['action_type'], $byUrl['/gone-page']['action_code'], $byUrl['/gone-page']['action_data']]);
        self::assertSame('pass', $byUrl['/alias']['action_type']);
        self::assertSame('agent', $byUrl['/bot']['match_type']);
        self::assertSame(['url_from' => '/bot-target', 'agent' => 'Googlebot', 'regex' => false], $byUrl['/bot']['action_data']);
        self::assertSame('server', $byUrl['/hosted']['match_type']);
        self::assertSame(['server' => 'shop.test', 'url_from' => '/h-target'], $byUrl['/hosted']['action_data']);
        self::assertSame('^/blog/(.*)$', $byUrl['^/blog/(.*)$']['url']);
        self::assertTrue($byUrl['^/blog/(.*)$']['regex']);
        self::assertFalse($byUrl['/disabled']['enabled']);
        self::assertSame('disabled', $byUrl['/disabled']['status']);
        self::assertTrue($byUrl['/Case/Sensitive']['match_data']['source']['flag_case']);
        self::assertFalse($byUrl['/Case/Sensitive']['match_data']['source']['flag_trailing']);
        self::assertSame(range(0, count($data['redirects']) - 1), array_column($data['redirects'], 'position'));
        self::assertSame(range(1, count($data['redirects'])), array_column($data['redirects'], 'id'));
    }

    public function testJsonExportOfNegatedAndOperatorConditions(): void
    {
        $base = FixtureRules::all()['header_ua'];
        $forms = [
            ['user-agent', 'contains', 'Bot', false, 'agent', ['url_from' => '/bot-target', 'agent' => 'Bot', 'regex' => false]],
            ['user-agent', 'contains', 'Bot', true, 'agent', ['url_notfrom' => '/bot-target', 'agent' => 'Bot', 'regex' => false]],
            ['user-agent', 'equals', 'Bot', false, 'agent', ['url_from' => '/bot-target', 'agent' => '^Bot$', 'regex' => true]],
            ['Referer', 'contains', 'google', false, 'referrer', ['url_from' => '/bot-target', 'referrer' => 'google', 'regex' => false]],
            ['Referer', 'starts_with', 'https://a', false, 'referrer', ['url_from' => '/bot-target', 'referrer' => '^https://a', 'regex' => true]],
            ['X-Test', 'equals', 'yes', false, 'header', ['url_from' => '/bot-target', 'name' => 'X-Test', 'value' => 'yes', 'regex' => false]],
            ['X-Test', 'regex', 'y.s', false, 'header', ['url_from' => '/bot-target', 'name' => 'X-Test', 'value' => 'y.s', 'regex' => true]],
        ];
        foreach ($forms as [$name, $operator, $value, $negate, $matchType, $data]) {
            $rule = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => $name, 'operator' => $operator, 'value' => $value, 'negate' => $negate]]]]);
            $decoded = json_decode((new Exporter())->export([$rule], Format::WordpressJson, new ExportOptions())->content, true);
            self::assertSame($matchType, $decoded['redirects'][0]['match_type'], $name . $operator);
            self::assertSame($data, $decoded['redirects'][0]['action_data'], $name . $operator);
        }
        $cookie = $base->with(['conditions' => ['rules' => [['kind' => 'cookie', 'name' => 'beta', 'operator' => 'equals', 'value' => '1']]]]);
        self::assertSame('cookie', json_decode((new Exporter())->export([$cookie], Format::WordpressJson, new ExportOptions())->content, true)['redirects'][0]['match_type']);

        $exists = $base->with(['conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'exists', 'negate' => true]]]]);
        self::assertSame(['condition_not_supported'], array_map(static fn ($n) => $n->code, (new Exporter())->export([$exists], Format::WordpressJson, new ExportOptions())->skipped));
    }

    public function testJsonExportSkips(): void
    {
        $cases = [
            'conditions_not_supported' => FixtureRules::all()['language'],
            'query_params_not_supported' => FixtureRules::all()['query_params'],
            'status_not_supported' => FixtureRules::all()['legal_451'],
            'multiple_conditions_not_supported' => FixtureRules::all()['host'],
            'condition_not_supported' => FixtureRules::all()['host_wild'],
            'named_group_unresolved' => FixtureRules::all()['regex_named']->with(['target' => '/x/{nope}']),
        ];
        foreach ($cases as $code => $rule) {
            $result = (new Exporter())->export([$rule], Format::WordpressJson, new ExportOptions());
            self::assertSame([$code], array_map(static fn ($n) => $n->code, $result->skipped), $code);
            self::assertNotSame('', $result->skipped[0]->reason);
            self::assertSame(0, $result->exported);
        }
    }

    // ---------------------------------------------------------------- csv

    public function testRealisticCsvExport(): void
    {
        $preview = $this->preview($this->fixture('wordpress-redirection.csv'), Format::WordpressCsv);

        self::assertSame([], $preview->errors);
        self::assertSame([
            'e:/kontakt-alt=>/kontakt#301',
            'r:/blog/(\d{4})/(.*)=>/magazin/$1/$2#301',
            'e:/shop/altes-produkt=>#410',
            'e:/kampagne=>/#302',
            'e:/alias-seite=>/echte-seite#200',
        ], self::sigs($preview));
        self::assertSame('Produkt eingestellt, endgültig', $preview->rules()[2]->note);
        self::assertFalse($preview->rules()[3]->enabled);
        self::assertSame(['unsupported_action'], self::codes($preview->rows[4]));
    }

    public function testCsvColumnOrderComesFromTheHeader(): void
    {
        $csv = "title,status,code,target,type,source,regex\nMy title,disabled,302,/n,url,/o,0\n";
        $rule = $this->rulesOf($this->preview($csv, Format::WordpressCsv))[0];

        self::assertSame(['/o', '/n', 302, 'My title', false], [$rule->source, $rule->target, $rule->status->value, $rule->note, $rule->enabled]);
    }

    public function testCsvWithoutHeaderUsesDocumentedOrder(): void
    {
        $rule = $this->rulesOf($this->preview("/o,/n,0,307,url,5,Title,enabled\n", Format::WordpressCsv))[0];

        self::assertSame(['/o', '/n', 307, 'Title'], [$rule->source, $rule->target, $rule->status->value, $rule->note]);
    }

    public function testCsvMatchColumnAndUnsupportedRows(): void
    {
        $csv = "source,target,regex,code,type,match,title,status\n/a,/b,0,301,url,url,,enabled\n/c,/d,0,301,url,login,,enabled\n/e,,0,404,error,url,,enabled\n/f,/g,0,301,nothing,url,,enabled\n";
        $preview = $this->preview($csv, Format::WordpressCsv);

        self::assertSame(['e:/a=>/b#301'], self::sigs($preview));
        self::assertSame([[], ['unsupported_match_type'], ['unsupported_status'], ['unsupported_action']], array_map(self::codes(...), $preview->rows));
    }

    public function testCsvWithoutSourceColumnIsAFileError(): void
    {
        $preview = $this->preview("target,code\n/a,301\n", Format::WordpressCsv);

        self::assertSame(['missing_column'], array_map(static fn ($i) => $i->code, $preview->errors));
    }

    public function testCsvExportFormat(): void
    {
        $rules = FixtureRules::only(['exact_301', 'gone_410', 'alias_200', 'wild_blog', 'grouped', 'disabled', 'host', 'query_params']);
        $result = (new Exporter())->export($rules, Format::WordpressCsv, new ExportOptions(onlyEnabled: false));
        $lines = explode("\n", trim($result->content));

        self::assertSame('source,target,regex,code,type,hits,title,status', $lines[0]);
        foreach ([
            '/old-page,/new-page,0,301,url,0,,enabled',
            '/gone-page,,0,410,error,0,,enabled',
            '/alias,/actual-page,0,0,pass,0,,enabled',
            '^/blog/(.*)$,/news/$1,1,301,url,0,,enabled',
            '/grouped,/g-target,0,301,url,0,"Herbst, mit ""Komma""",enabled',
            '/disabled,/d-target,0,301,url,0,,disabled',
        ] as $line) {
            self::assertContains($line, $lines);
        }
        $codes = [];
        foreach ($result->skipped as $note) {
            $codes[substr($note->ruleId, 5)] = $note->code;
        }
        ksort($codes);
        self::assertSame(['host' => 'conditions_not_supported', 'query_params' => 'query_params_not_supported'], $codes);
        $noHeader = (new Exporter())->export(FixtureRules::only(['exact_301']), Format::WordpressCsv, new ExportOptions(includeHeader: false));
        self::assertSame("/old-page,/new-page,0,301,url,0,,enabled\n", $noHeader->content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function csvInjection(): iterable
    {
        yield 'formula' => ['=1+1'];
        yield 'plus' => ['+SUM(A1)'];
    }

    #[DataProvider('csvInjection')]
    public function testCsvExportNeutralisesFormulas(string $title): void
    {
        $rule = FixtureRules::all()['exact_301']->with(['note' => $title]);
        $out = (new Exporter())->export([$rule], Format::WordpressCsv, new ExportOptions())->content;

        self::assertStringContainsString(",'" . $title . ",", $out);
        self::assertSame($title, $this->rulesOf($this->preview($out, Format::WordpressCsv))[0]->note);
    }
}
