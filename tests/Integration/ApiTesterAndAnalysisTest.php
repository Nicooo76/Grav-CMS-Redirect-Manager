<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** REST API: rule tester (POST /redirects/test), live validation, chain fix, analysis and groups. */
#[Group('integration')]
final class ApiTesterAndAnalysisTest extends ApiTestCase
{
    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function test(array $body): array
    {
        $response = $this->api->post('/redirects/test', $body);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    // ------------------------------------------------------------------ tester

    public function testExactRuleShapeOfTheAnswer(): void
    {
        $this->rules([['id' => 'ex', 'source' => '/old', 'target' => '/typography', 'status' => 301]]);
        $data = $this->test(['url' => '/old']);

        self::assertSame(['input', 'context', 'result', 'chain', 'final', 'trace', 'page_exists'], array_keys($data));
        self::assertSame(['url' => '/old', 'method' => 'GET', 'phase' => 'any'], $data['input']);
        self::assertSame(['/old', '127.0.0.1', 'http', false], [$data['context']['path'], $data['context']['host'], $data['context']['scheme'], $data['context']['excluded']]);
        self::assertSame(['ex', 301, '/typography'], [$data['result']['rule_id'], $data['result']['status'], $data['result']['location']]);
        self::assertSame(
            [['url' => '/old', 'status' => 301, 'rule_id' => 'ex', 'location' => '/typography'], ['url' => '/typography', 'status' => 200, 'rule_id' => null, 'location' => null]],
            $data['chain'],
        );
        self::assertSame(['url' => '/typography', 'status' => 200], $data['final']);
        self::assertSame(['ex', 'exact', true, 'matched'], [$data['trace'][0]['rule_id'], $data['trace'][0]['match_type'], $data['trace'][0]['matched'], $data['trace'][0]['reason']]);
        self::assertFalse($data['page_exists'], 'page_exists describes the tested URL itself, and /old is no page');
    }

    public function testTestingMakesNoRealRequestAndNoHitIsCounted(): void
    {
        $this->rules([['id' => 'ex', 'source' => '/old', 'target' => '/typography']]);
        $this->test(['url' => '/old']);
        self::assertSame([], $this->site()->recordedHits());
        self::assertSame([], $this->site()->notFoundEntries());
        self::assertSame(0, $this->api->get('/redirects/rules')->data()[0]['stats']['total']);
    }

    public function testWildcardCapturesFlowIntoTheLocationAndTheQueryIsParsed(): void
    {
        $this->rules([['id' => 'wc', 'source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard', 'status' => 302]]);
        $data = $this->test(['url' => '/blog/hello?x=1&y=2']);
        self::assertSame(['/blog/hello', ['x' => '1', 'y' => '2']], [$data['context']['path'], $data['context']['query']]);
        self::assertSame(['wc', 302, '/news/hello', ['1' => 'hello']], [$data['result']['rule_id'], $data['result']['status'], $data['result']['location'], $data['result']['captures']]);
        self::assertSame('wildcard', $data['trace'][0]['match_type']);
        self::assertSame(['url' => '/news/hello', 'status' => 404], $data['final'], 'the target page does not exist');
    }

    public function testRegexCapturesAndNoMatchTrace(): void
    {
        $this->rules([['id' => 'rx', 'source' => '^/p/(\d+)/(\w+)$', 'target' => '/q/$2-$1', 'match_type' => 'regex', 'status' => 307]]);
        $data = $this->test(['url' => '/p/12/abc']);
        self::assertSame(['rx', 307, '/q/abc-12', ['1' => '12', '2' => 'abc']], [$data['result']['rule_id'], $data['result']['status'], $data['result']['location'], $data['result']['captures']]);
        self::assertSame('regex', $data['trace'][0]['match_type']);

        $miss = $this->test(['url' => '/p/x/abc']);
        self::assertNull($miss['result']);
        self::assertSame([['url' => '/p/x/abc', 'status' => 404, 'rule_id' => null, 'location' => null]], $miss['chain']);
        self::assertSame(['rx', false], [$miss['trace'][0]['rule_id'], $miss['trace'][0]['matched']]);
    }

    public function testThreeHopChainEndingIn404(): void
    {
        $this->rules([
            ['id' => 'c1', 'source' => '/c1', 'target' => '/c2'],
            ['id' => 'c2', 'source' => '/c2', 'target' => '/c3'],
            ['id' => 'c3', 'source' => '/c3', 'target' => '/nowhere'],
        ]);
        $data = $this->test(['url' => '/c1']);
        self::assertSame(['/c1', '/c2', '/c3', '/nowhere'], array_column($data['chain'], 'url'));
        self::assertSame([301, 301, 301, 404], array_column($data['chain'], 'status'));
        self::assertSame(['c1', 'c2', 'c3', null], array_column($data['chain'], 'rule_id'));
        self::assertSame(['url' => '/nowhere', 'status' => 404], $data['final']);
        self::assertSame('c1', $data['result']['rule_id'], 'result is the first hop only');
    }

    public function testChainEndingInARealPage(): void
    {
        $this->rules([
            ['id' => 'd1', 'source' => '/d1', 'target' => '/d2'],
            ['id' => 'd2', 'source' => '/d2', 'target' => '/typography'],
        ]);
        $data = $this->test(['url' => '/d1']);
        self::assertSame(['/d1', '/d2', '/typography'], array_column($data['chain'], 'url'));
        self::assertSame(['url' => '/typography', 'status' => 200], $data['final']);
        self::assertFalse($data['page_exists']);

        $page = $this->test(['url' => '/typography']);
        self::assertNull($page['result']);
        self::assertTrue($page['page_exists']);
        self::assertSame(['url' => '/typography', 'status' => 200], $page['final']);
        self::assertSame([], $page['trace']);
        self::assertTrue($this->test(['url' => '/typography/'])['page_exists'], 'a trailing slash is ignored');
    }

    public function testLoopInStoredRulesIsReportedInsteadOfLooping(): void
    {
        $this->rules([
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2'],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l1'],
        ]);
        $data = $this->test(['url' => '/l1']);
        self::assertTrue($data['final']['loop'] ?? false);
        self::assertCount(2, $data['chain']);
    }

    public function testExternalTargetEndsTheChain(): void
    {
        $this->site()->writePluginConfig(['security' => ['allowed_hosts' => ['example.com']]]);
        $this->rules([['id' => 'ext', 'source' => '/ext', 'target' => 'https://example.com/x', 'status' => 302, 'target_type' => 'url']]);
        $data = $this->test(['url' => '/ext']);
        self::assertSame(['ext', 302, 'https://example.com/x'], [$data['result']['rule_id'], $data['result']['status'], $data['result']['location']]);
        self::assertSame(['url' => 'https://example.com/x', 'status' => 200, 'external' => true], $data['final']);
    }

    public function testExternalTargetOfAHostThatIsNotAllowedDoesNotMatch(): void
    {
        $this->rules([['id' => 'ext', 'source' => '/ext', 'target' => 'https://example.com/x', 'status' => 302, 'target_type' => 'url']]);
        $data = $this->test(['url' => '/ext']);
        self::assertNull($data['result']);
        self::assertSame(['ext', false, 'unsafe_target'], [$data['trace'][0]['rule_id'], $data['trace'][0]['matched'], $data['trace'][0]['reason']]);
        self::assertSame(404, $data['final']['status']);
    }

    public function testGoneRuleEndsWith410(): void
    {
        $this->rules([['id' => 'gone', 'source' => '/gone', 'target' => '', 'status' => 410]]);
        $data = $this->test(['url' => '/gone']);
        self::assertSame(['gone', 410], [$data['result']['rule_id'], $data['result']['status']]);
        self::assertSame([['url' => '/gone', 'status' => 410, 'rule_id' => 'gone', 'location' => null]], $data['chain']);
        self::assertSame(['url' => '/gone', 'status' => 410], $data['final']);
    }

    public function testAbsoluteUrlsSetHostAndSchemeForConditions(): void
    {
        $this->rules([['id' => 'h', 'source' => '/only', 'target' => '/typography', 'conditions' => ['hosts' => ['shop.example.com'], 'schemes' => ['https']]]]);
        self::assertSame('h', $this->test(['url' => 'https://shop.example.com/only'])['result']['rule_id']);
        self::assertNull($this->test(['url' => 'http://shop.example.com/only'])['result'], 'wrong scheme');
        self::assertNull($this->test(['url' => 'https://other.example.com/only'])['result'], 'wrong host');
        $noMatch = $this->test(['url' => '/only']);
        self::assertSame('host', $noMatch['trace'][0]['reason']);
    }

    public function testCookieAndHeaderConditionsUseTheGivenValues(): void
    {
        $this->rules([
            ['id' => 'ck', 'source' => '/cookie', 'target' => '/typography', 'conditions' => ['rules' => [['kind' => 'cookie', 'name' => 'vip', 'operator' => 'equals', 'value' => '1']]]],
            ['id' => 'hd', 'source' => '/header', 'target' => '/typography', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Test', 'operator' => 'equals', 'value' => 'yes']]]],
        ]);
        self::assertSame('ck', $this->test(['url' => '/cookie', 'cookies' => ['vip' => '1']])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/cookie'])['result']);
        self::assertSame('hd', $this->test(['url' => '/header', 'headers' => ['X-Test' => 'yes']])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/header', 'headers' => ['X-Test' => 'no']])['result']);
    }

    public function testPhaseSelectsWhichRulesApply(): void
    {
        $this->rules([
            ['id' => 'early', 'source' => '/early', 'target' => '/typography'],
            ['id' => 'late', 'source' => '/late', 'target' => '/typography', 'only_if_not_found' => true],
        ]);
        self::assertSame('early', $this->test(['url' => '/early', 'phase' => 'early'])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/late', 'phase' => 'early'])['result']);
        self::assertSame('late', $this->test(['url' => '/late', 'phase' => 'not_found'])['result']['rule_id']);
        self::assertSame('late', $this->test(['url' => '/late'])['result']['rule_id']);
    }

    public function testLanguagePrefixIsKeptOnTheChain(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About', 'About us', 'en', '10');
        $this->site()->writePage('ueber', 'Ueber uns', 'Ueber uns', 'de', '10', "slug: ueber
");
        $this->rules([
            ['id' => 'a', 'source' => '/alt', 'target' => '/about'],
            ['id' => 'de-only', 'source' => '/nur-de', 'target' => '/about', 'conditions' => ['languages' => ['de']]],
        ]);

        $de = $this->test(['url' => '/de/alt']);
        self::assertSame(['/alt', 'de', '/de', '/about'], [$de['context']['path'], $de['context']['language'], $de['context']['language_prefix'], $de['result']['location']]);
        self::assertSame(['/de/alt', '/de/about'], array_column($de['chain'], 'url'), 'the target keeps the /de prefix');
        self::assertSame('/de/about', $de['chain'][0]['location']);
        self::assertSame(200, $de['final']['status']);

        $en = $this->test(['url' => '/en/alt']);
        self::assertSame(['/en/alt', '/en/about'], array_column($en['chain'], 'url'));

        $given = $this->test(['url' => '/alt', 'language' => 'de']);
        self::assertSame('de', $given['context']['language'], 'the "language" field stands in for a missing prefix');
        self::assertSame('', $given['context']['language_prefix']);

        self::assertSame('de-only', $this->test(['url' => '/de/nur-de'])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/en/nur-de'])['result']);
        self::assertSame('language', $this->test(['url' => '/en/nur-de'])['trace'][0]['reason']);
        self::assertTrue($this->test(['url' => '/en/about'])['page_exists']);
    }

    public function testLanguagePrefixMatchesWhatTheFrontendDoes(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->rules([['id' => 'a', 'source' => '/alt', 'target' => '/typography']]);
        $tested = $this->test(['url' => '/de/alt']);
        $real = $this->get('/de/alt');
        $this->assertRedirect($real, 301, $tested['chain'][0]['location']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function badTesterInput(): array
    {
        return [
            'url missing' => [[], 'url', 'required'],
            'url empty' => [['url' => ''], 'url', 'required'],
            'control characters' => [['url' => "/ab"], 'url', 'invalid_url'],
            'not http' => [['url' => 'ftp://files.example/a'], 'url', 'invalid_url'],
            'unknown phase' => [['url' => '/a', 'phase' => 'later'], 'phase', 'invalid_value'],
            'headers not a map' => [['url' => '/a', 'headers' => 'x'], 'headers', 'invalid_type'],
            'cookie value not a string' => [['url' => '/a', 'cookies' => ['c' => ['x']]], 'cookies', 'invalid_type'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('badTesterInput')]
    public function testBadInputIs422(array $body, string $field, string $code): void
    {
        $response = $this->api->post('/redirects/test', $body);
        $this->assertProblem($response, 422);
        self::assertSame([$field, $code, 'error'], [$response->errors()[0]['field'], $response->errors()[0]['code'], $response->errors()[0]['severity']]);
    }

    // ------------------------------------------------------------------ validate, shorten, analysis

    public function testValidateReturnsIssuesAndPreviewWithoutSaving(): void
    {
        $response = $this->api->post('/redirects/rules/validate', ['source' => '/blog/*', 'match_type' => 'wildcard', 'target' => '/news/$1', 'status' => 301, 'sample' => '/blog/hello']);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame([], $data['issues']);
        self::assertSame('/blog/hello', $data['preview']['sample']);
        $result = $data['preview']['result'];
        self::assertTrue($result['matched']);
        self::assertSame([301, '/news/hello', ['1' => 'hello']], [$result['status'], $result['location'], $result['captures']]);
        self::assertSame([], $this->site()->repository()->all());
    }

    public function testValidateReportsErrorsAsIssuesWith200(): void
    {
        $this->createRule(['source' => '/a', 'target' => '/b', 'status' => 301]);
        $loop = $this->api->post('/redirects/rules/validate', ['source' => '/b', 'target' => '/a', 'status' => 301]);
        self::assertSame(200, $loop->status);
        self::assertSame(['loop', 'error'], [$loop->data()['issues'][0]['code'], $loop->data()['issues'][0]['severity']]);
        self::assertNull($loop->data()['preview'], 'no sample, no preview');

        $bad = $this->api->post('/redirects/rules/validate', ['source' => '/x', 'target' => '/y', 'status' => 999, 'sample' => '/x']);
        self::assertSame(200, $bad->status);
        self::assertSame('status', $bad->data()['issues'][0]['field']);
        self::assertNull($bad->data()['preview']);

        $chain = $this->api->post('/redirects/rules/validate', ['source' => '/b', 'target' => '/c', 'status' => 301]);
        self::assertSame('warning', $chain->data()['issues'][0]['severity']);
    }

    public function testShortenChainPointsTheFirstRuleAtTheEnd(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/b', 'status' => 301]);
        $this->createRule(['source' => '/b', 'target' => '/c', 'status' => 301]);
        $before = $this->api->get('/redirects/rules/' . $a['id']);
        self::assertContains('chain', $before->data()['badges']);

        $response = $this->api->post('/redirects/rules/' . $a['id'] . '/shorten-chain');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame('/c', $response->data()['target']);
        self::assertNotContains('chain', $response->data()['badges']);
        self::assertNotNull($response->header('etag'));
        $this->assertRedirect($this->get('/a'), 301, '/c');
        self::assertSame(['chains' => [], 'loops' => [], 'conflicts' => [], 'expired' => [], 'unused' => []], $this->api->get('/redirects/analysis')->data());
    }

    public function testShortenChainRefusesARuleThatIsNotAChain(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $response = $this->api->post('/redirects/rules/' . $a['id'] . '/shorten-chain');
        $this->assertProblem($response, 422);
        self::assertSame(['id', 'no_chain'], [$response->errors()[0]['field'], $response->errors()[0]['code']]);
    }

    public function testAnalysisFindsChainsLoopsConflictsExpiredAndUnusedRules(): void
    {
        $old = '2026-01-01T00:00:00+00:00';
        $this->rules([
            ['id' => 'c1', 'source' => '/c1', 'target' => '/c2', 'created_at' => $old],
            ['id' => 'c2', 'source' => '/c2', 'target' => '/typography', 'created_at' => $old],
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2', 'created_at' => $old],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l1', 'created_at' => $old],
            ['id' => 'k1', 'source' => '/same', 'target' => '/typography', 'created_at' => $old],
            ['id' => 'k2', 'source' => '/same', 'target' => '/home', 'created_at' => $old],
            ['id' => 'ex', 'source' => '/expired', 'target' => '/typography', 'expires_at' => '2026-02-01T00:00:00+00:00', 'created_at' => $old],
            ['id' => 'new', 'source' => '/fresh', 'target' => '/typography', 'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM)],
        ]);
        $data = $this->api->get('/redirects/analysis')->data();

        self::assertSame(['c1', 'c2'], $data['chains'][0]['rule_ids']);
        self::assertSame(['/c1', '/c2', '/typography'], $data['chains'][0]['paths']);
        self::assertSame('/typography', $data['chains'][0]['shortcut']);
        self::assertNotEmpty($data['loops']);
        self::assertContains('l1', $data['loops'][0]['rule_ids'] ?? $data['loops'][0]);
        self::assertSame([['k1', 'k2']], array_map(static function (array|string $c): array {
            $c = array_values((array) $c);
            sort($c);

            return $c;
        }, $data['conflicts']));
        self::assertSame(['ex'], $data['expired']);
        self::assertContains('c1', $data['unused']);
        self::assertNotContains('new', $data['unused'], 'a rule created today is not unused yet');
        self::assertNotContains('ex', $data['unused'], 'an expired rule is not active, so not "unused"');
    }

    public function testGroupsListsGroupsAndTagsWithCounts(): void
    {
        $this->rules([
            ['id' => 'a', 'source' => '/a', 'target' => '/typography', 'group' => 'Blog', 'tags' => ['seo', 'x']],
            ['id' => 'b', 'source' => '/b', 'target' => '/typography', 'group' => 'Blog', 'tags' => ['seo']],
            ['id' => 'c', 'source' => '/c', 'target' => '/typography', 'group' => 'Shop'],
            ['id' => 'd', 'source' => '/d', 'target' => '/typography'],
        ]);
        self::assertSame(
            ['groups' => [['name' => 'Blog', 'count' => 2], ['name' => 'Shop', 'count' => 1]], 'tags' => [['name' => 'seo', 'count' => 2], ['name' => 'x', 'count' => 1]]],
            $this->api->get('/redirects/groups')->data(),
        );
    }
}
