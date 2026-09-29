<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reverse proxies: X-Forwarded-Host, -Proto and -For count only with `security.trust_proxy_headers`, and the scheme
 * also comes from the HTTPS server variable. `php -S` has no TLS, so the site of this class starts with a tiny
 * auto_prepend_file (Support/https-prepend.php) that sets HTTPS for requests carrying `X-Test-Https`.
 */
#[Group('integration')]
final class ProxyHeadersTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required for the integration tests.');
        }
        if (TestSite::baseDir() === null) {
            self::markTestSkipped('No Grav test site. Run scripts/setup-test-site.sh first.');
        }
        self::$site = TestSite::create(['auto_prepend_file' => __DIR__ . '/Support/https-prepend.php']);
    }

    private function trustProxy(bool $on = true): void
    {
        $this->site()->writePluginConfig(['security' => ['trust_proxy_headers' => $on]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logged(string $path): array
    {
        return array_values(array_filter($this->site()->notFoundEntries(), static fn (array $e): bool => ($e['p'] ?? null) === $path));
    }

    // ---- host -------------------------------------------------------------------------------------------

    public function testForwardedHostCountsOnlyWhenTheProxyIsTrusted(): void
    {
        $this->rules([['id' => 'shop', 'source' => '/hosted', 'target' => '/typography', 'conditions' => ['hosts' => ['shop.example.test']]]]);
        $headers = ['X-Forwarded-Host' => 'shop.example.test'];

        $this->assertNotRedirected($this->get('/hosted', ['headers' => $headers]), 'trust off: the header is ignored');
        self::assertSame(404, $this->get('/hosted', ['headers' => $headers])->status);

        $this->trustProxy();
        $this->assertRedirect($this->get('/hosted', ['headers' => $headers]), 301, '/typography');
        $this->assertRedirect($this->get('/hosted', ['headers' => ['X-Forwarded-Host' => 'Shop.Example.Test:8443']]), 301, '/typography', 'case and port are ignored');
        $this->assertRedirect($this->get('/hosted', ['headers' => ['X-Forwarded-Host' => 'shop.example.test, internal.local']]), 301, '/typography', 'the first hop counts');
        $this->assertNotRedirected($this->get('/hosted', ['headers' => ['X-Forwarded-Host' => 'other.example.test']]));
        $this->assertNotRedirected($this->get('/hosted'), 'no forwarded host: the Host header (127.0.0.1) does not match');
    }

    public function testTheRealHostStillWinsWithoutAForwardedHostEvenWhenTrusted(): void
    {
        $this->trustProxy();
        $this->rules([['id' => 'direct', 'source' => '/direct', 'target' => '/typography', 'conditions' => ['hosts' => ['direct.example.test']]]]);
        $this->assertRedirect($this->get('/direct', ['headers' => ['Host' => 'direct.example.test']]), 301, '/typography');
    }

    public function testTheLocationNeverTakesTheForwardedHostOrScheme(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);
        foreach ([false, true] as $trusted) {
            $this->trustProxy($trusted);
            $r = $this->get('/old', ['headers' => ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7']]);
            $this->assertRedirect($r, 301, '/typography', 'trusted: ' . var_export($trusted, true));
            self::assertStringNotContainsString('evil.example', (string) $r->location());
        }
    }

    public function testTheLoggedHostFollowsTheForwardedHostOnlyWhenTrusted(): void
    {
        $this->get('/lost-a', ['headers' => ['X-Forwarded-Host' => 'shop.example.test']]);
        self::assertSame('127.0.0.1', $this->logged('/lost-a')[0]['h'], 'trust off');

        $this->trustProxy();
        $this->get('/lost-b', ['headers' => ['X-Forwarded-Host' => 'shop.example.test:8443']]);
        self::assertSame('shop.example.test', $this->logged('/lost-b')[0]['h'], 'trust on');
    }

    // ---- client address ---------------------------------------------------------------------------------

    public function testTheLoggedIpFollowsXForwardedForOnlyWhenTrustedAndIsAnonymized(): void
    {
        $forwarded = ['X-Forwarded-For' => '198.51.100.77, 10.0.0.1'];

        $this->get('/ip-off', ['headers' => $forwarded]);
        self::assertSame('127.0.0.0', $this->logged('/ip-off')[0]['ip'], 'trust off: the connecting address, anonymized');

        $this->trustProxy();
        $this->get('/ip-on', ['headers' => $forwarded]);
        self::assertSame('198.51.100.0', $this->logged('/ip-on')[0]['ip'], 'trust on: the first forwarded address, anonymized');

        $this->get('/ip-v6', ['headers' => ['X-Forwarded-For' => '2001:db8:abcd:12::5']]);
        self::assertSame('2001:db8:abcd::', $this->logged('/ip-v6')[0]['ip']);

        $this->get('/ip-garbage', ['headers' => ['X-Forwarded-For' => 'unknown']]);
        self::assertArrayNotHasKey('ip', $this->logged('/ip-garbage')[0], 'a forwarded value that is no address is not stored');

        $raw = '';
        foreach (glob($this->site()->dataDir() . '/404/*.jsonl') ?: [] as $file) {
            $raw .= (string) file_get_contents($file);
        }
        self::assertStringNotContainsString('198.51.100.77', $raw, 'the full address never reaches the disk');
        self::assertStringNotContainsString('2001:db8:abcd:12', $raw);
    }

    public function testNoForwardedAddressIsStoredWhenTheIpModeIsNone(): void
    {
        $this->site()->writePluginConfig(['security' => ['trust_proxy_headers' => true], 'log' => ['ip_mode' => 'none']]);
        $this->get('/ip-none', ['headers' => ['X-Forwarded-For' => '198.51.100.77']]);
        self::assertArrayNotHasKey('ip', $this->logged('/ip-none')[0]);
    }

    // ---- scheme -----------------------------------------------------------------------------------------

    public function testTheHttpsServerVariableSetsTheScheme(): void
    {
        $this->rules([
            ['id' => 'tls', 'source' => '/secure-only', 'target' => '/typography', 'conditions' => ['schemes' => ['https']]],
            ['id' => 'plain', 'source' => '/plain-only', 'target' => '/typography', 'conditions' => ['schemes' => ['http']]],
        ]);
        $tls = ['X-Test-Https' => 'on'];

        $this->assertRedirect($this->get('/secure-only', ['headers' => $tls]), 301, '/typography');
        $this->assertNotRedirected($this->get('/plain-only', ['headers' => $tls]));
        $this->assertNotRedirected($this->get('/secure-only'), 'no TLS');
        $this->assertRedirect($this->get('/plain-only'), 301, '/typography');
        $this->assertNotRedirected($this->get('/secure-only', ['headers' => ['X-Test-Https' => 'off']]), 'HTTPS=off is plain http');
        $this->assertRedirect($this->get('/plain-only', ['headers' => ['X-Test-Https' => 'off']]), 301, '/typography');
    }

    public function testATrustedProxyHeaderOverridesTheServerVariable(): void
    {
        $this->rules([['id' => 'plain', 'source' => '/plain-only', 'target' => '/typography', 'conditions' => ['schemes' => ['http']]]]);
        $request = ['headers' => ['X-Test-Https' => 'on', 'X-Forwarded-Proto' => 'http']];

        $this->assertNotRedirected($this->get('/plain-only', $request), 'trust off: the server variable says https');
        $this->trustProxy();
        $this->assertRedirect($this->get('/plain-only', $request), 301, '/typography', 'trust on: the proxy says the client used http');
    }
}
