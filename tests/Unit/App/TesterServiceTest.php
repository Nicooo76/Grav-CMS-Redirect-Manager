<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\App\TesterService;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[CoversClass(TesterService::class)]
#[Group('app')]
final class TesterServiceTest extends AppTestCase
{
    private const OLD = '2026-09-01T00:00:00+00:00';

    /**
     * Rebuilds the app over the page fixture (routes like /about, /blog, /products/faq exist) after seeding rules.
     *
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $rules
     */
    private function setUpSite(array $rules, array $config = [], ?SiteContext $site = null): void
    {
        $this->seedRules(array_map(static fn (array $row): array => $row + ['created_at' => self::OLD, 'updated_at' => self::OLD], $rules));
        $this->app = $this->makeApp($config, PageTreeFixture::pages(), $site);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function test(string|array $body): array
    {
        return $this->app->tester()->test(is_string($body) ? ['url' => $body] : $body);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string> the "url" of every chain entry
     */
    private static function chainUrls(array $result): array
    {
        return array_map(static fn (array $c): string => $c['url'], $result['chain']);
    }

    // ---------------------------------------------------------------- basics

    public function testAPathThatNoRuleMatchesEndsAtTheRequestedUrl(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/old', 'target' => '/about']]);

        $result = $this->test('/nothing-here');

        self::assertNull($result['result']);
        self::assertSame([['url' => '/nothing-here', 'status' => 404, 'rule_id' => null, 'location' => null]], $result['chain']);
        self::assertSame(['url' => '/nothing-here', 'status' => 404], $result['final']);
        self::assertFalse($result['page_exists']);
        self::assertSame(['url' => '/nothing-here', 'method' => 'GET', 'phase' => 'any'], $result['input']);
    }

    public function testAnExistingPageWithoutRuleIsA200(): void
    {
        $this->setUpSite([]);

        $result = $this->test('/about');

        self::assertSame(['url' => '/about', 'status' => 200], $result['final']);
        self::assertTrue($result['page_exists']);
        self::assertSame([], $result['trace']);
    }

    public function testAMatchingRuleReportsTheMatchAndTheLocation(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/old', 'target' => '/about', 'status' => 302]]);

        $result = $this->test('/old');

        self::assertSame('a', $result['result']['rule_id']);
        self::assertSame(302, $result['result']['status']);
        self::assertSame('/about', $result['result']['location']);
        self::assertSame(['a'], $result['result']['rules']);
        self::assertInstanceOf(stdClass::class, $result['result']['captures']);
        self::assertSame([['url' => '/old', 'status' => 302, 'rule_id' => 'a', 'location' => '/about'], ['url' => '/about', 'status' => 200, 'rule_id' => null, 'location' => null]], $result['chain']);
        self::assertSame(['url' => '/about', 'status' => 200], $result['final']);
        self::assertFalse($result['page_exists']); // /old itself is no page
    }

    public function testCapturesOfAWildcardRuleAreReported(): void
    {
        $this->setUpSite([['id' => 'w', 'source' => '/promo/*', 'target' => '/products/$1', 'match_type' => 'wildcard']]);

        $result = $this->test('/promo/faq');

        self::assertSame(['1' => 'faq'], (array) $result['result']['captures']);
        self::assertSame('/products/faq', $result['result']['location']);
        self::assertSame(['url' => '/products/faq', 'status' => 200], $result['final']);
    }

    public function testTheContextDescribesHowTheRequestWasRead(): void
    {
        $this->setUpSite([]);

        $context = $this->test('https://Example.ORG/old/page?x=1&y=2#frag')['context'];

        self::assertSame('/old/page', $context['path']);
        self::assertSame(['x' => '1', 'y' => '2'], $context['query']);
        self::assertSame('example.org', $context['host']);
        self::assertSame('https', $context['scheme']);
        self::assertNull($context['language']);
        self::assertSame('', $context['base_path']);
        self::assertSame('', $context['language_prefix']);
        self::assertFalse($context['excluded']);
    }

    public function testAnEmptyQueryIsAnEmptyObject(): void
    {
        $this->setUpSite([]);

        self::assertInstanceOf(stdClass::class, $this->test('/x')['context']['query']);
    }

    public function testAPathWithoutLeadingSlashAndAProtocolRelativeUrlAreUnderstood(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/old', 'target' => '/about']]);

        self::assertSame('a', $this->test('old')['result']['rule_id']);
        $protocolRelative = $this->test('//example.org/old');
        self::assertSame('a', $protocolRelative['result']['rule_id']);
        self::assertSame('https', $protocolRelative['context']['scheme']);
        self::assertSame('example.org', $protocolRelative['context']['host']);
    }

    public function testAbsoluteAndPathUrlsGiveTheSameResult(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/old', 'target' => '/about']]);

        $path = $this->test('/old?utm_source=x');
        $absolute = $this->test('http://localhost:8080/old?utm_source=x');

        self::assertEquals($path['result'], $absolute['result']);
        self::assertSame($path['chain'], $absolute['chain']);
        self::assertSame('localhost', $absolute['context']['host']);
    }

    public function testTheMethodIsUppercasedAndEchoed(): void
    {
        $this->setUpSite([]);

        self::assertSame('POST', $this->test(['url' => '/x', 'method' => 'post'])['input']['method']);
        self::assertSame('GET', $this->test(['url' => '/x', 'method' => ''])['input']['method']);
    }

    // ---------------------------------------------------------------- conditions

    public function testHostConditions(): void
    {
        $this->setUpSite([['id' => 'h', 'source' => '/old', 'target' => '/about', 'conditions' => ['hosts' => ['example.org']]]]);

        self::assertSame('h', $this->test('https://example.org/old')['result']['rule_id']);
        self::assertNull($this->test('https://other.org/old')['result']);
        self::assertNull($this->test('/old')['result']); // no host given, none can match
    }

    public function testAHostHeaderStandsInForTheUrlHost(): void
    {
        $this->setUpSite([['id' => 'h', 'source' => '/old', 'target' => '/about', 'conditions' => ['hosts' => ['example.org']]]]);

        $result = $this->test(['url' => '/old', 'headers' => ['Host' => 'example.org']]);

        self::assertSame('h', $result['result']['rule_id']);
        self::assertSame('example.org', $result['context']['host']);
    }

    public function testSchemeConditions(): void
    {
        $this->setUpSite([['id' => 's', 'source' => '/old', 'target' => '/about', 'conditions' => ['schemes' => ['https']]]]);

        self::assertSame('s', $this->test('https://example.org/old')['result']['rule_id']);
        self::assertNull($this->test('http://example.org/old')['result']);
    }

    public function testHeaderAndCookieConditions(): void
    {
        $this->setUpSite([
            ['id' => 'hd', 'source' => '/h', 'target' => '/about', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Beta', 'operator' => 'equals', 'value' => '1']]]],
            ['id' => 'ck', 'source' => '/c', 'target' => '/about', 'conditions' => ['rules' => [['kind' => 'cookie', 'name' => 'beta', 'operator' => 'exists']]]],
        ]);

        self::assertNull($this->test('/h')['result']);
        self::assertSame('hd', $this->test(['url' => '/h', 'headers' => ['x-beta' => '1']])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/h', 'headers' => ['X-Beta' => '2']])['result']);
        self::assertNull($this->test('/c')['result']);
        self::assertSame('ck', $this->test(['url' => '/c', 'cookies' => ['beta' => 'yes']])['result']['rule_id']);
    }

    public function testNumbersInHeadersAndCookiesAreAccepted(): void
    {
        $this->setUpSite([['id' => 'hd', 'source' => '/h', 'target' => '/about', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Beta', 'operator' => 'equals', 'value' => '1']]]]]);

        self::assertSame('hd', $this->test(['url' => '/h', 'headers' => ['X-Beta' => 1]])['result']['rule_id']);
    }

    public function testLanguageConditionsUseTheLanguageParameter(): void
    {
        $this->setUpSite([['id' => 'l', 'source' => '/old', 'target' => '/about', 'conditions' => ['languages' => ['de']]]]);

        self::assertNull($this->test('/old')['result']);
        self::assertSame('l', $this->test(['url' => '/old', 'language' => ' DE '])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/old', 'language' => 'en'])['result']);
    }

    // ---------------------------------------------------------------- language prefix and base path

    private function multilingualSite(): SiteContext
    {
        return new SiteContext(baseUrl: 'http://localhost:8080', languages: ['en', 'de'], defaultLanguage: 'en');
    }

    public function testTheLanguagePrefixIsStrippedForMatchingAndKeptInTheLocation(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/alt', 'target' => '/neu']], site: $this->multilingualSite());

        $result = $this->test('/de/alt');

        self::assertSame('de', $result['context']['language']);
        self::assertSame('/de', $result['context']['language_prefix']);
        self::assertSame('/alt', $result['context']['path']);
        self::assertSame('a', $result['result']['rule_id']);
        self::assertSame('/neu', $result['result']['location']);
        self::assertSame('/de/neu', $result['chain'][0]['location']);
        self::assertSame('/de/alt', $result['chain'][0]['url']);
        self::assertSame('/de/neu', $result['chain'][1]['url']);
    }

    public function testWithoutAPrefixNoLanguageIsSet(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/alt', 'target' => '/neu']], site: $this->multilingualSite());

        $result = $this->test('/alt');

        self::assertNull($result['context']['language']);
        self::assertSame('/neu', $result['chain'][0]['location']);
    }

    public function testTheLanguagePrefixIsNotAddedTwice(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/alt', 'target' => '/en/neu']], site: $this->multilingualSite());

        self::assertSame('/en/neu', $this->test('/de/alt')['chain'][0]['location']);
    }

    public function testTheLanguagePrefixCanBeDroppedByConfiguration(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/alt', 'target' => '/neu']], ['redirects' => ['keep_language_prefix' => false]], $this->multilingualSite());

        self::assertSame('/neu', $this->test('/de/alt')['chain'][0]['location']);
    }

    public function testPageExistsLooksInTheLanguageOfTheRequest(): void
    {
        $this->setUpSite([], site: $this->multilingualSite());

        self::assertTrue($this->test('/de/kontakt')['page_exists']);
        self::assertSame(200, $this->test('/de/kontakt')['final']['status']);
        self::assertTrue($this->test('/en/contact')['page_exists']);
        self::assertTrue($this->test('/de')['page_exists']); // the home page
    }

    public function testASubfolderInstallationAddsTheBasePathToTheLocation(): void
    {
        $this->setUpSite([['id' => 'a', 'source' => '/old', 'target' => '/about']], site: new SiteContext(server: ['SCRIPT_NAME' => '/sub/index.php']));

        $result = $this->test('/sub/old');

        self::assertSame('/sub', $result['context']['base_path']);
        self::assertSame('/old', $result['context']['path']);
        self::assertSame('a', $result['result']['rule_id']);
        self::assertSame('/sub/old', $result['chain'][0]['url']);
        self::assertSame('/sub/about', $result['chain'][0]['location']);
        self::assertSame(['url' => '/sub/about', 'status' => 200], $result['final']);
    }

    public function testInASubfolderTheBasePathAndTheLanguagePrefixCombine(): void
    {
        $this->setUpSite(
            [['id' => 'a', 'source' => '/alt', 'target' => '/kontakt']],
            site: new SiteContext(server: ['SCRIPT_NAME' => '/sub/index.php'], languages: ['en', 'de'], defaultLanguage: 'en'),
        );

        $result = $this->test('/sub/de/alt');

        self::assertSame('/sub/de/kontakt', $result['chain'][0]['location']);
        self::assertSame(['url' => '/sub/de/kontakt', 'status' => 200], $result['final']);
    }

    // ---------------------------------------------------------------- chains

    public function testAChainOfThreeHopsEndsAtAPage(): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
            ['id' => 'r2', 'source' => '/x2', 'target' => '/x3', 'status' => 302],
            ['id' => 'r3', 'source' => '/x3', 'target' => '/about', 'status' => 308],
        ]);

        $result = $this->test('/x1');

        self::assertSame(['/x1', '/x2', '/x3', '/about'], self::chainUrls($result));
        self::assertSame([301, 302, 308, 200], array_column($result['chain'], 'status'));
        self::assertSame(['r1', 'r2', 'r3', null], array_column($result['chain'], 'rule_id'));
        self::assertSame(['/x2', '/x3', '/about', null], array_column($result['chain'], 'location'));
        self::assertSame(['url' => '/about', 'status' => 200], $result['final']);
        self::assertSame('r1', $result['result']['rule_id']); // "result" is the first hop
    }

    public function testAChainCanEndInA404(): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
            ['id' => 'r2', 'source' => '/x2', 'target' => '/x3'],
            ['id' => 'r3', 'source' => '/x3', 'target' => '/deleted-page'],
        ]);

        $result = $this->test('/x1');

        self::assertSame(['/x1', '/x2', '/x3', '/deleted-page'], self::chainUrls($result));
        self::assertSame(['url' => '/deleted-page', 'status' => 404], $result['final']);
        self::assertSame(404, $result['chain'][3]['status']);
    }

    public function testEveryRedirectEntryOfTheChainCarriesItsLocation(): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2?a=1'],
            ['id' => 'r2', 'source' => '/x2', 'target' => '/about#top'],
        ]);

        $result = $this->test('/x1');

        self::assertSame('/x2?a=1', $result['chain'][0]['location']);
        self::assertSame('/about#top', $result['chain'][1]['location']);
        // the fragment does not take part in the next request
        self::assertSame(['/x1', '/x2?a=1', '/about'], self::chainUrls($result));
    }

    public function testAnExternalTargetStopsTheChain(): void
    {
        $this->setUpSite(
            [
                ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
                ['id' => 'r2', 'source' => '/x2', 'target' => 'https://ext.example/landing', 'target_type' => 'url'],
                ['id' => 'never', 'source' => '/landing', 'target' => '/about'],
            ],
            ['security' => ['allowed_hosts' => ['ext.example']]],
        );

        $result = $this->test('/x1');

        self::assertSame(['/x1', '/x2'], self::chainUrls($result));
        self::assertSame('https://ext.example/landing', $result['chain'][1]['location']);
        self::assertSame(['url' => 'https://ext.example/landing', 'status' => 200, 'external' => true], $result['final']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        yield '410 gone' => [410];
        yield '451 unavailable for legal reasons' => [451];
    }

    #[DataProvider('errorStatuses')]
    public function testAnErrorStatusEndsTheChain(int $status): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
            ['id' => 'r2', 'source' => '/x2', 'status' => $status],
        ]);

        $result = $this->test('/x1');

        self::assertSame(['/x1', '/x2'], self::chainUrls($result));
        self::assertSame($status, $result['chain'][1]['status']);
        self::assertNull($result['chain'][1]['location']);
        self::assertSame(['url' => '/x2', 'status' => $status], $result['final']);
    }

    public function testAGoneRuleAsTheFirstHop(): void
    {
        $this->setUpSite([['id' => 'r1', 'source' => '/gone', 'status' => 410]]);

        $result = $this->test('/gone');

        self::assertSame(410, $result['result']['status']);
        self::assertSame([['url' => '/gone', 'status' => 410, 'rule_id' => 'r1', 'location' => null]], $result['chain']);
        self::assertSame(['url' => '/gone', 'status' => 410], $result['final']);
    }

    public function testAPassThroughRuleServesTheTargetPageUnderTheRequestedUrl(): void
    {
        $this->setUpSite([
            ['id' => 'pt', 'source' => '/shop', 'target' => '/products', 'status' => 200],
            ['id' => 'pt-missing', 'source' => '/ghost', 'target' => '/no-such-page', 'status' => 200],
        ]);

        $ok = $this->test('/shop');
        $missing = $this->test('/ghost');

        self::assertSame([['url' => '/shop', 'status' => 200, 'rule_id' => 'pt', 'location' => null]], $ok['chain']);
        self::assertSame(['url' => '/shop', 'status' => 200], $ok['final']);
        self::assertSame(['url' => '/ghost', 'status' => 404], $missing['final']);
    }

    public function testALoopIsDetected(): void
    {
        $this->setUpSite([
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2'],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l3'],
            ['id' => 'l3', 'source' => '/l3', 'target' => '/l1'],
        ]);

        $result = $this->test('/l1');

        self::assertSame(['/l1', '/l2', '/l3'], self::chainUrls($result));
        self::assertTrue($result['final']['loop']);
        self::assertSame('/l1', $result['final']['url']);
        self::assertArrayNotHasKey('truncated', $result['final']);
    }

    public function testASelfRedirectIsALoop(): void
    {
        $this->setUpSite([['id' => 'self', 'source' => '/x', 'target' => '/x']]);

        $result = $this->test('/x');

        self::assertCount(1, $result['chain']);
        self::assertTrue($result['final']['loop']);
    }

    public function testTheChainIsCutAtTheConfiguredDepth(): void
    {
        $this->setUpSite(
            [
                ['id' => 'h1', 'source' => '/h1', 'target' => '/h2'],
                ['id' => 'h2', 'source' => '/h2', 'target' => '/h3'],
                ['id' => 'h3', 'source' => '/h3', 'target' => '/h4'],
                ['id' => 'h4', 'source' => '/h4', 'target' => '/h5'],
                ['id' => 'h5', 'source' => '/h5', 'target' => '/about'],
            ],
            ['redirects' => ['max_chain_depth' => 3]],
        );

        $result = $this->test('/h1');

        self::assertSame(['/h1', '/h2', '/h3'], self::chainUrls($result));
        self::assertTrue($result['final']['truncated']);
        self::assertSame('/h4', $result['final']['url']);
        self::assertSame(404, $result['final']['status']);
    }

    public function testAChainThatFitsIsNotTruncated(): void
    {
        $this->setUpSite(
            [
                ['id' => 'h1', 'source' => '/h1', 'target' => '/h2'],
                ['id' => 'h2', 'source' => '/h2', 'target' => '/about'],
            ],
            ['redirects' => ['max_chain_depth' => 3]],
        );

        $result = $this->test('/h1');

        self::assertArrayNotHasKey('truncated', $result['final']);
        self::assertSame(['url' => '/about', 'status' => 200], $result['final']);
    }

    public function testEachHopOfAChainSeesTheSameHeadersAndSchemes(): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
            ['id' => 'r2', 'source' => '/x2', 'target' => '/about', 'conditions' => ['schemes' => ['https']]],
        ]);

        // the scheme of the request carries over to the following hops
        self::assertSame(['/x1', '/x2', '/about'], self::chainUrls($this->test('https://example.org/x1')));
        self::assertSame(['/x1', '/x2'], self::chainUrls($this->test('http://example.org/x1')));
    }

    // ---------------------------------------------------------------- phase

    public function testOnlyIfNotFoundRulesMatchInTheAnyAndNotFoundPhases(): void
    {
        $this->setUpSite([['id' => 'nf', 'source' => '/late', 'target' => '/about', 'only_if_not_found' => true]]);

        self::assertSame('nf', $this->test('/late')['result']['rule_id']);
        self::assertSame('nf', $this->test(['url' => '/late', 'phase' => 'any'])['result']['rule_id']);
        self::assertSame('nf', $this->test(['url' => '/late', 'phase' => 'not_found'])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/late', 'phase' => 'early'])['result']);
    }

    public function testEarlyRulesDoNotMatchInTheNotFoundPhase(): void
    {
        $this->setUpSite([['id' => 'early', 'source' => '/soon', 'target' => '/about']]);

        self::assertSame('early', $this->test(['url' => '/soon', 'phase' => 'early'])['result']['rule_id']);
        self::assertNull($this->test(['url' => '/soon', 'phase' => 'not_found'])['result']);
    }

    public function testThePhaseIsEchoedAndAppliesToEveryHop(): void
    {
        $this->setUpSite([
            ['id' => 'r1', 'source' => '/x1', 'target' => '/x2'],
            ['id' => 'r2', 'source' => '/x2', 'target' => '/about', 'only_if_not_found' => true],
        ]);

        $early = $this->test(['url' => '/x1', 'phase' => 'early']);
        $any = $this->test('/x1');

        self::assertSame('early', $early['input']['phase']);
        self::assertSame(['/x1', '/x2'], self::chainUrls($early));
        self::assertSame(['/x1', '/x2', '/about'], self::chainUrls($any));
    }

    // ---------------------------------------------------------------- excluded routes and trace

    public function testExcludedRoutesAreNeverRedirected(): void
    {
        $this->setUpSite([['id' => 'api', 'source' => '/api/thing', 'target' => '/about']]);

        $result = $this->test('/api/thing');

        self::assertTrue($result['context']['excluded']);
        self::assertNull($result['result']);
        self::assertSame([], $result['trace']);
        self::assertCount(1, $result['chain']);
        self::assertNull($result['chain'][0]['rule_id']);
        self::assertSame('/api/thing', $result['final']['url']);
    }

    public function testConfiguredExcludedPathsAreNeverRedirected(): void
    {
        $this->setUpSite([['id' => 'r', 'source' => '/private/x', 'target' => '/about']], ['redirects' => ['excluded_paths' => ['/private']]]);

        self::assertTrue($this->test('/private/x')['context']['excluded']);
        self::assertNull($this->test('/private/x')['result']);
    }

    public function testTheTraceExplainsWhyNothingMatched(): void
    {
        $this->setUpSite([
            ['id' => 'off', 'source' => '/old', 'target' => '/about', 'enabled' => false],
            ['id' => 'de-only', 'source' => '/old', 'target' => '/about', 'conditions' => ['languages' => ['de']]],
            ['id' => 'other', 'source' => '/other', 'target' => '/about'],
        ]);

        $result = $this->test('/old');

        self::assertNull($result['result']);
        self::assertNotSame([], $result['trace']);
        $reasons = [];
        foreach ($result['trace'] as $step) {
            self::assertFalse($step['matched']);
            self::assertSame('/old', $step['path']);
            $reasons[$step['rule_id']] = $step['reason'];
        }
        self::assertSame('disabled', $reasons['off']);
        self::assertSame('language', $reasons['de-only']);
        self::assertArrayNotHasKey('other', $reasons);
    }

    public function testTheTraceOfAMatchEndsWithTheMatchedRule(): void
    {
        $this->setUpSite([
            ['id' => 'skip', 'source' => '/old', 'target' => '/about', 'priority' => 5, 'conditions' => ['languages' => ['de']]],
            ['id' => 'hit', 'source' => '/old', 'target' => '/contact'],
        ]);

        $result = $this->test('/old');

        self::assertSame('hit', $result['result']['rule_id']);
        $last = $result['trace'][array_key_last($result['trace'])];
        self::assertSame('hit', $last['rule_id']);
        self::assertTrue($last['matched']);
        self::assertSame('matched', $last['reason']);
    }

    // ---------------------------------------------------------------- invalid input

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no url' => [[], 'url', 'required'];
        yield 'blank url' => [['url' => '   '], 'url', 'required'];
        yield 'url is not a string' => [['url' => ['/x']], 'url', 'required'];
        yield 'unknown phase' => [['url' => '/x', 'phase' => 'late'], 'phase', 'invalid_value'];
        yield 'headers is a string' => [['url' => '/x', 'headers' => 'X-A: 1'], 'headers', 'invalid_type'];
        yield 'header value is an array' => [['url' => '/x', 'headers' => ['X-A' => ['1']]], 'headers', 'invalid_type'];
        yield 'cookies is a string' => [['url' => '/x', 'cookies' => 'a=1'], 'cookies', 'invalid_type'];
        yield 'cookie value is an object' => [['url' => '/x', 'cookies' => ['a' => ['b']]], 'cookies', 'invalid_type'];
        yield 'control characters' => [['url' => "/x\r\nHost: evil"], 'url', 'invalid_url'];
        yield 'other scheme with host' => [['url' => 'ftp://example.org/x'], 'url', 'invalid_url'];
        yield 'javascript scheme with host' => [['url' => 'javascript://example.org/%0aalert(1)'], 'url', 'invalid_url'];
        yield 'unparsable url' => [['url' => 'http:///x'], 'url', 'invalid_url'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidInput(array $body, string $field, string $code): void
    {
        $this->setUpSite([]);

        try {
            $this->app->tester()->test($body);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame($field, $e->field);
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testAPathThatNormalizesToNothingIsInvalid(): void
    {
        $this->setUpSite([]);

        $this->expectException(InvalidInputException::class);
        $this->test('/a/%00/b');
    }
}
