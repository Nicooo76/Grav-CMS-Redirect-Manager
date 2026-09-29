<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** The 404 monitor: what is logged, what is not, and what is stored about the visitor. */
#[Group('integration')]
final class NotFoundLoggingTest extends IntegrationTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesFor(string $path): array
    {
        return array_values(array_filter($this->site()->notFoundEntries(), static fn (array $e): bool => ($e['p'] ?? null) === $path));
    }

    public function testA404IsLoggedWithAnonymizedIpAndUserAgentClass(): void
    {
        $r = $this->get('/some/missing-page?ref=newsletter', ['headers' => ['User-Agent' => self::BROWSER, 'Referer' => 'https://example.org/blog?x=1#top']]);
        self::assertSame(404, $r->status);

        $entries = $this->entriesFor('/some/missing-page');
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('127.0.0.0', $entry['ip'], 'the last octet is zeroed');
        self::assertSame('browser', $entry['c']);
        self::assertSame('ref=newsletter', $entry['q']);
        self::assertSame('GET', $entry['m']);
        self::assertSame('https://example.org/blog', $entry['r'], 'referer without query and fragment');
        self::assertStringStartsWith('Mozilla/5.0', (string) $entry['ua']);
        self::assertSame('127.0.0.1', $entry['h'], 'host');
    }

    public function testBotsAreClassified(): void
    {
        $this->get('/bot-visit', ['headers' => ['User-Agent' => self::BOT]]);
        $entries = $this->entriesFor('/bot-visit');
        self::assertCount(1, $entries);
        self::assertSame('bot', $entries[0]['c']);
    }

    public function testBotsCanBeLeftOut(): void
    {
        $this->site()->writePluginConfig(['log' => ['log_bots' => false]]);
        $this->get('/bot-visit', ['headers' => ['User-Agent' => self::BOT]]);
        $this->get('/human-visit', ['headers' => ['User-Agent' => self::BROWSER]]);
        self::assertSame([], $this->entriesFor('/bot-visit'));
        self::assertCount(1, $this->entriesFor('/human-visit'));
    }

    public function testNoIpIsStoredWhenTheModeIsNone(): void
    {
        $this->site()->writePluginConfig(['log' => ['ip_mode' => 'none']]);
        $this->get('/no-ip');
        $entries = $this->entriesFor('/no-ip');
        self::assertCount(1, $entries);
        self::assertArrayNotHasKey('ip', $entries[0]);
    }

    public function testIgnoredPathsAreNotLogged(): void
    {
        $this->site()->writePluginConfig(['log' => ['ignore_patterns' => ['/private-*']]]);
        foreach (['/wp-login.php', '/favicon.ico', '/wp-admin/setup.php', '/private-area/x', '/kept-page'] as $path) {
            $this->get($path);
        }
        $logged = array_map(static fn (array $e): mixed => $e['p'], $this->site()->notFoundEntries());
        self::assertSame(['/kept-page'], $logged);
    }

    public function testLoggingCanBeSwitchedOff(): void
    {
        $this->site()->writePluginConfig(['log' => ['enabled' => false]]);
        self::assertSame(404, $this->get('/quiet')->status);
        self::assertSame([], $this->site()->notFoundEntries());
    }

    public function testOnlyGetAndHeadAreLogged(): void
    {
        $this->get('/by-post', ['method' => 'POST']);
        $this->get('/by-head', ['method' => 'HEAD']);
        self::assertSame([], $this->entriesFor('/by-post'));
        self::assertCount(1, $this->entriesFor('/by-head'));
    }

    public function testPagesThatExistAreNotLogged(): void
    {
        $this->get('/typography');
        $this->get('/');
        self::assertSame([], $this->site()->notFoundEntries());
    }

    public function testARedirectedRequestThatFoundNoPageIsLoggedBeforeItRedirects(): void
    {
        $this->rules([['id' => 'nf', 'source' => '/late', 'target' => '/typography', 'only_if_not_found' => true]]);
        $this->assertRedirect($this->get('/late'), 301, '/typography');
        self::assertCount(1, $this->entriesFor('/late'), 'the monitor still sees what visitors ran into');
    }

    public function testEarlyRedirectsAreNotLogged(): void
    {
        $this->rules([['id' => 'e', 'source' => '/early', 'target' => '/typography']]);
        $this->assertRedirect($this->get('/early'), 301, '/typography');
        self::assertSame([], $this->site()->notFoundEntries());
    }

    public function testPathIsStoredDecoded(): void
    {
        $this->get('/%C3%BCber-uns-x');
        self::assertCount(1, $this->entriesFor('/über-uns-x'));
    }

    public function testSqliteBackendWorksOrFallsBackToJsonl(): void
    {
        $this->site()->writePluginConfig(['log' => ['backend' => 'sqlite']]);
        self::assertSame(404, $this->get('/sqlite-check')->status);
        if (extension_loaded('pdo_sqlite')) {
            self::assertFileExists($this->site()->dataDir() . '/404.sqlite');
        } else {
            self::assertCount(1, $this->entriesFor('/sqlite-check'));
        }
    }
}
