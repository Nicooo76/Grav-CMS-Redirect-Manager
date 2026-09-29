<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Match types, query modes, conditions and status codes against a real Grav 2 request pipeline. */
#[Group('integration')]
final class RedirectMatchingTest extends IntegrationTestCase
{
    public function testExactRedirectHasCleanCacheableHeaders(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);

        $r = $this->get('/old');
        $this->assertRedirect($r, 301, '/typography');
        self::assertSame('public, max-age=3600', $r->header('cache-control'));
        self::assertFalse($r->has('set-cookie'), 'a 301 must not start a session: ' . $r->header('set-cookie'));
        self::assertNull($r->header('expires'), 'no session cache headers');
        self::assertNull($r->header('pragma'));
        self::assertSame('', $r->body);
        self::assertSame(200, $this->get('/typography')->status, 'the target exists');
    }

    public function testHeadGetsTheSameRedirect(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);
        $r = $this->get('/old', ['method' => 'HEAD']);
        $this->assertRedirect($r, 301, '/typography');
        self::assertFalse($r->has('set-cookie'));
    }

    public function testCaseInsensitiveByDefaultAndCaseSensitiveOnRequest(): void
    {
        $this->rules([
            ['id' => 'ci', 'source' => '/Mixed/Case', 'target' => '/typography'],
            ['id' => 'cs', 'source' => '/Strict', 'target' => '/typography', 'case_sensitive' => true],
        ]);
        $this->assertRedirect($this->get('/mixed/case'), 301, '/typography');
        $this->assertRedirect($this->get('/MIXED/CASE'), 301, '/typography');
        $this->assertRedirect($this->get('/Strict'), 301, '/typography');
        $this->assertNotRedirected($this->get('/strict'));
    }

    public function testTrailingSlashOnTheRequestMatchesInOneHop(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);
        // Grav would first bounce /old/ to /old; our rule answers before that redirect.
        $this->assertRedirect($this->get('/old/'), 301, '/typography');
    }

    public function testEncodedUmlautsInSourceAndTarget(): void
    {
        $this->rules([
            ['id' => 'u1', 'source' => '/über-uns', 'target' => '/typography'],
            ['id' => 'u2', 'source' => '/alt', 'target' => '/über-neu/größe'],
        ]);
        $this->assertRedirect($this->get('/%C3%BCber-uns'), 301, '/typography');
        $this->assertRedirect($this->get('/%c3%9cber-uns'), 301, '/typography', 'case-insensitive by default');
        $this->assertRedirect($this->get('/alt'), 301, '/%C3%BCber-neu/gr%C3%B6%C3%9Fe');
    }

    public function testWildcardWithCapture(): void
    {
        $this->rules([['id' => 'w', 'source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard']]);
        $this->assertRedirect($this->get('/blog/2020/my-post'), 301, '/news/2020/my-post');
        $this->assertRedirect($this->get('/blog/x%20y'), 301, '/news/x%20y');
        $this->assertNotRedirected($this->get('/blogger/x'));
    }

    public function testRegexWithNamedGroup(): void
    {
        $this->rules([['id' => 'r', 'source' => '^/product/(?<id>\d+)(?:/.*)?$', 'target' => '/shop/item-{id}', 'match_type' => 'regex', 'status' => 308]]);
        $this->assertRedirect($this->get('/product/42/some-slug'), 308, '/shop/item-42');
        $this->assertNotRedirected($this->get('/product/abc'));
    }

    public function testQueryModes(): void
    {
        $this->rules([
            ['id' => 'ignore', 'source' => '/q-ignore', 'target' => '/typography', 'query_mode' => 'ignore'],
            ['id' => 'pass', 'source' => '/q-pass', 'target' => '/typography', 'query_mode' => 'pass'],
            ['id' => 'exact', 'source' => '/q-exact?id=5', 'target' => '/typography?found=1', 'query_mode' => 'exact'],
            ['id' => 'params', 'source' => '/q-params', 'target' => '/typography', 'query_mode' => 'params', 'query_params' => ['id' => null, 'ref' => 'mail']],
        ]);

        $this->assertRedirect($this->get('/q-ignore?a=1&b=2'), 301, '/typography');

        $pass = $this->get('/q-pass?a=1&b=two');
        self::assertSame(301, $pass->status);
        self::assertSame('/typography?a=1&b=two', $pass->location());

        $this->assertRedirect($this->get('/q-exact?id=5'), 301, '/typography?found=1');
        $this->assertRedirect($this->get('/q-exact?id=5&utm_source=x'), 301, '/typography?found=1', 'ignored tracking parameters do not count');
        $this->assertNotRedirected($this->get('/q-exact?id=6'));
        $this->assertNotRedirected($this->get('/q-exact'));

        $this->assertRedirect($this->get('/q-params?id=1&ref=mail&x=y'), 301, '/typography');
        $this->assertNotRedirected($this->get('/q-params?id=1&ref=other'));
        $this->assertNotRedirected($this->get('/q-params?ref=mail'));
    }

    public function testHostCondition(): void
    {
        $this->rules([['id' => 'h', 'source' => '/hosted', 'target' => '/typography', 'conditions' => ['hosts' => ['alt.example.test']]]]);

        $this->assertRedirect($this->get('/hosted', ['headers' => ['Host' => 'alt.example.test']]), 301, '/typography');
        $this->assertRedirect($this->get('/hosted', ['headers' => ['Host' => 'ALT.example.test:8080']]), 301, '/typography');
        $this->assertNotRedirected($this->get('/hosted'));
        $this->assertNotRedirected($this->get('/hosted', ['headers' => ['Host' => 'other.example.test']]));
    }

    public function testHeaderConditionUserAgent(): void
    {
        $this->rules([['id' => 'ua', 'source' => '/for-bots', 'target' => '/typography', 'conditions' => ['rules' => [
            ['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'Googlebot'],
        ]]]]);

        $bot = $this->get('/for-bots', ['headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']]);
        $this->assertRedirect($bot, 301, '/typography');
        self::assertSame('User-Agent', $bot->header('vary'), 'conditional redirects must not be shared between visitors');
        $this->assertNotRedirected($this->get('/for-bots', ['headers' => ['User-Agent' => 'Mozilla/5.0 Firefox']]));
    }

    public function testCookieCondition(): void
    {
        $this->rules([['id' => 'ck', 'source' => '/beta', 'target' => '/typography', 'conditions' => ['rules' => [
            ['kind' => 'cookie', 'name' => 'beta', 'operator' => 'equals', 'value' => '1'],
        ]]]]);

        $with = $this->get('/beta', ['cookies' => ['beta' => '1']]);
        $this->assertRedirect($with, 301, '/typography');
        self::assertSame('Cookie', $with->header('vary'));
        $this->assertNotRedirected($this->get('/beta', ['cookies' => ['beta' => '0']]));
        $this->assertNotRedirected($this->get('/beta'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function redirectStatuses(): iterable
    {
        yield '301' => [301, 'public, max-age=3600'];
        yield '302' => [302, 'no-store'];
        yield '307' => [307, 'no-store'];
        yield '308' => [308, 'public, max-age=3600'];
    }

    #[DataProvider('redirectStatuses')]
    public function testRedirectStatusesAndCacheControl(int $status, string $cacheControl): void
    {
        $this->rules([['id' => 's', 'source' => '/st', 'target' => '/typography', 'status' => $status]]);

        $r = $this->get('/st');
        $this->assertRedirect($r, $status, '/typography');
        self::assertSame($cacheControl, $r->header('cache-control'));
        self::assertFalse($r->has('set-cookie'));
    }

    public function testConfiguredCacheControlIsUsed(): void
    {
        $this->site()->writePluginConfig(['redirects' => ['cache_control_permanent' => 'public, max-age=86400, immutable', 'cache_control_temporary' => 'private, max-age=60']]);
        $this->rules([
            ['id' => 'p', 'source' => '/perm', 'target' => '/typography'],
            ['id' => 't', 'source' => '/temp', 'target' => '/typography', 'status' => 302],
        ]);
        self::assertSame('public, max-age=86400, immutable', $this->get('/perm')->header('cache-control'));
        self::assertSame('private, max-age=60', $this->get('/temp')->header('cache-control'));
    }

    public function testExternalTargetsNeedAnAllowedHost(): void
    {
        $this->rules([['id' => 'x', 'source' => '/away', 'target' => 'https://partner.example.org/landing?a=b', 'target_type' => 'url']]);
        $this->assertNotRedirected($this->get('/away'), 'external hosts are blocked until they are allowed');

        $this->site()->writePluginConfig(['security' => ['allowed_hosts' => ['partner.example.org']]]);
        $this->assertRedirect($this->get('/away'), 301, 'https://partner.example.org/landing?a=b');
    }

    public function testPriorityDecidesBetweenOverlappingRules(): void
    {
        $this->rules([
            ['id' => 'low', 'source' => '/p/*', 'target' => '/typography', 'match_type' => 'wildcard', 'priority' => 0],
            ['id' => 'high', 'source' => '/p/special', 'target' => '/home', 'priority' => 10],
        ]);
        $this->assertRedirect($this->get('/p/special'), 301, '/home');
        $this->assertRedirect($this->get('/p/other'), 301, '/typography');
    }

    public function testHitsAreRecordedPerAppliedRule(): void
    {
        $this->rules([
            ['id' => 'first', 'source' => '/h1', 'target' => '/h2', 'continue' => true],
            ['id' => 'second', 'source' => '/h2', 'target' => '/typography'],
        ]);
        $this->assertRedirect($this->get('/h1'), 301, '/typography');
        $hits = $this->site()->recordedHits();
        sort($hits);
        self::assertSame(['first', 'second'], $hits);
    }
}
