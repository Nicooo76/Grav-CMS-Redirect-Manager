<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** 410, 451 and pass-through pages, "only if not found", chains, rule windows and the open redirect guard. */
#[Group('integration')]
final class StatusResponsesTest extends IntegrationTestCase
{
    public function testGoneRendersTheTemplate(): void
    {
        $this->rules([['id' => 'g', 'source' => '/removed', 'target' => '', 'status' => 410]]);

        $r = $this->get('/removed');
        self::assertSame(410, $r->status);
        self::assertSame('Grav Redirect Manager', $r->header('x-redirect-by'));
        self::assertStringContainsString('text/html', (string) $r->header('content-type'));
        self::assertNull($r->location());
        self::assertSame('noindex', $r->header('x-robots-tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex">', $r->body);
        self::assertMatchesRegularExpression('/This page (has been|was) removed/', $r->body);
        self::assertStringContainsString('<p class="code">410</p>', $r->body);
        self::assertFalse($r->has('set-cookie'), 'no session cookie on a 410: ' . $r->header('set-cookie'));
        self::assertSame('public, max-age=3600', $r->header('cache-control'));

        $head = $this->get('/removed', ['method' => 'HEAD']);
        self::assertSame(410, $head->status);
        self::assertSame('', $head->body);
    }

    public function testUnavailableForLegalReasons(): void
    {
        $this->rules([['id' => 'l', 'source' => '/blocked', 'target' => '', 'status' => 451]]);

        $r = $this->get('/blocked');
        self::assertSame(451, $r->status);
        self::assertStringContainsString('Unavailable for legal reasons', $r->body);
        self::assertStringContainsString('<p class="code">451</p>', $r->body);
        self::assertSame('no-store', $r->header('cache-control'));
        self::assertNull($r->header('link'));
    }

    public function testGoneTemplateIsTranslatedAndUsesTheSiteTitle(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->rules([['id' => 'g', 'source' => '/removed', 'target' => '', 'status' => 410]]);

        $de = $this->get('/de/removed');
        self::assertSame(410, $de->status);
        self::assertStringContainsString('Diese Seite wurde entfernt', $de->body);
        self::assertStringContainsString('<html lang="de">', $de->body);
        self::assertStringContainsString(' | Grav</title>', $de->body, 'site title from the site config');
    }

    public function testThemeCanOverrideTheGoneTemplate(): void
    {
        $this->site()->writeFile('user/themes/quark2/templates/redirect-manager/gone.html.twig', '<h1>CUSTOM GONE {{ redirect_status }}</h1>');
        $this->rules([
            ['id' => 'g', 'source' => '/removed', 'target' => '', 'status' => 410],
            ['id' => 'l', 'source' => '/blocked', 'target' => '', 'status' => 451],
        ]);

        $r = $this->get('/removed');
        self::assertSame(410, $r->status);
        self::assertSame('<h1>CUSTOM GONE 410</h1>', trim($r->body));
        self::assertStringContainsString('Unavailable for legal reasons', $this->get('/blocked')->body, 'the other template keeps the default');
    }

    public function testPassThroughServesTheTargetPageUnderTheRequestedUrl(): void
    {
        $this->rules([['id' => 'p', 'source' => '/alias-page', 'target' => '/typography', 'status' => 200]]);

        $r = $this->get('/alias-page');
        self::assertSame(200, $r->status);
        self::assertNull($r->location());
        self::assertNull($r->header('x-redirect-by'), 'nothing was redirected');
        self::assertStringContainsString('<title>Typography', $r->body);
        self::assertSame(['p'], $this->site()->recordedHits());
    }

    public function testPassThroughToAMissingPageFallsThroughToTheNotFoundPage(): void
    {
        $this->rules([['id' => 'p', 'source' => '/alias-page', 'target' => '/does-not-exist', 'status' => 200]]);

        $r = $this->get('/alias-page');
        self::assertSame(404, $r->status);
        self::assertStringContainsString('does-not-exist', $this->site()->gravLog(), 'a warning names the target');
        self::assertSame([], $this->site()->recordedHits(), 'no hit for a rule that did not apply');
    }

    public function testOnlyIfNotFoundNeverOverridesAnExistingPage(): void
    {
        $this->rules([
            ['id' => 'exists', 'source' => '/typography', 'target' => '/home', 'only_if_not_found' => true],
            ['id' => 'missing', 'source' => '/nowhere', 'target' => '/typography', 'only_if_not_found' => true],
        ]);

        self::assertSame(200, $this->get('/typography')->status, 'the page wins');
        $this->assertRedirect($this->get('/nowhere'), 301, '/typography');
        self::assertSame(['missing'], $this->site()->recordedHits());
    }

    public function testOnlyIfNotFoundRedirectDoesNotCarryTheSessionCookie(): void
    {
        $this->rules([['id' => 'missing', 'source' => '/nowhere', 'target' => '/typography', 'only_if_not_found' => true]]);
        $r = $this->get('/nowhere');
        $this->assertRedirect($r, 301, '/typography');
        self::assertFalse($r->has('set-cookie'), 'session cookie leaked: ' . $r->header('set-cookie'));
        self::assertSame('public, max-age=3600', $r->header('cache-control'));
        self::assertNull($r->header('expires'));
    }

    public function testRuleWithoutTheFlagOverridesAnExistingPage(): void
    {
        $this->rules([['id' => 'override', 'source' => '/typography', 'target' => '/home']]);
        $this->assertRedirect($this->get('/typography'), 301, '/home');
    }

    public function testOnlyIfNotFoundGoneAndPassThrough(): void
    {
        $this->rules([
            ['id' => 'g', 'source' => '/old-news', 'target' => '', 'status' => 410, 'only_if_not_found' => true],
            ['id' => 'p', 'source' => '/soft-alias', 'target' => '/typography', 'status' => 200, 'only_if_not_found' => true],
        ]);
        $gone = $this->get('/old-news');
        self::assertSame(410, $gone->status);
        self::assertMatchesRegularExpression('/This page (has been|was) removed/', $gone->body);

        $alias = $this->get('/soft-alias');
        self::assertSame(200, $alias->status);
        self::assertStringContainsString('<title>Typography', $alias->body);
    }

    public function testContinueChainEndsOnTheLastRule(): void
    {
        $this->rules([
            ['id' => 'a', 'source' => '/chain-a', 'target' => '/chain-b', 'continue' => true],
            ['id' => 'b', 'source' => '/chain-b', 'target' => '/chain-c', 'continue' => true],
            ['id' => 'c', 'source' => '/chain-c', 'target' => '/typography', 'status' => 302],
        ]);
        $r = $this->get('/chain-a');
        $this->assertRedirect($r, 302, '/typography');
        self::assertSame('no-store', $r->header('cache-control'), 'the last rule decides the status');
    }

    public function testExpiredDisabledAndScheduledRulesAreIgnored(): void
    {
        $this->rules([
            ['id' => 'expired', 'source' => '/expired', 'target' => '/typography', 'expires_at' => '2020-01-01T00:00:00+00:00'],
            ['id' => 'disabled', 'source' => '/disabled', 'target' => '/typography', 'enabled' => false],
            ['id' => 'later', 'source' => '/later', 'target' => '/typography', 'active_from' => '2999-01-01T00:00:00+00:00'],
            ['id' => 'live', 'source' => '/live', 'target' => '/typography', 'active_from' => '2020-01-01T00:00:00+00:00', 'expires_at' => '2999-01-01T00:00:00+00:00'],
        ]);
        $this->assertNotRedirected($this->get('/expired'));
        $this->assertNotRedirected($this->get('/disabled'));
        $this->assertNotRedirected($this->get('/later'));
        $this->assertRedirect($this->get('/live'), 301, '/typography');
        self::assertSame(404, $this->get('/expired')->status);
    }

    /** @return iterable<string, array{string}> */
    public static function openRedirectAttempts(): iterable
    {
        yield 'double slash' => ['/go//evil.com'];
        yield 'encoded slashes' => ['/go/%2F%2Fevil.com'];
        yield 'encoded slash after slash' => ['/go//%2Fevil.com'];
        yield 'backslash' => ['/go/%5Cevil.com'];
        yield 'raw backslash' => ['/go/\\evil.com'];
        yield 'scheme' => ['/go/https://evil.com'];
        yield 'encoded scheme' => ['/go/https%3A%2F%2Fevil.com'];
        yield 'at sign' => ['/go/%2F%2Fuser@evil.com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openRedirectAttempts')]
    public function testOpenRedirectAttemptsNeverLeaveTheSite(string $path): void
    {
        $this->rules([['id' => 'go', 'source' => '/go/*', 'target' => '/$1', 'match_type' => 'wildcard']]);

        $r = $this->get($path);
        $location = $r->location();
        if ($location === null) {
            self::assertNotSame(301, $r->status, $path);

            return;
        }
        self::assertMatchesRegularExpression('#^/(?![/\\\\])#', $location, 'Location must stay on this host: ' . $location);
        self::assertStringNotContainsString('://', $location);
        self::assertStringStartsNotWith('//', $location);
    }

    public function testOpenRedirectThroughARegexCaptureIsBlocked(): void
    {
        $this->rules([['id' => 'rx', 'source' => '^/out/(.*)$', 'target' => '$1', 'match_type' => 'regex']]);
        foreach (['/out/https://evil.com', '/out//evil.com', '/out/%2F%2Fevil.com'] as $path) {
            $r = $this->get($path);
            $location = $r->location();
            self::assertTrue($location === null || preg_match('#^/(?![/\\\\])#', $location) === 1, $path . ' -> ' . (string) $location);
        }
    }
}
