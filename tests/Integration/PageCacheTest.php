<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * Static caching (I-27): with Grav's page cache on and `pages.expires` / `pages.cache_control` set, a redirect still
 * happens before the cached page is served, carries the plugin's own Cache-Control, and a rule edit applies at once.
 * A cache that answers before PHP starts (a CDN, nginx fastcgi_cache) never reaches the plugin; that is documented.
 */
#[Group('integration')]
final class PageCacheTest extends IntegrationTestCase
{
    private const GRAV_CACHE_CONTROL = 'public, max-age=900';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeSystemConfig([
            'cache' => ['enabled' => true, 'check' => ['method' => 'file']],
            'pages' => ['expires' => 900, 'cache_control' => self::GRAV_CACHE_CONTROL, 'last_modified' => true, 'etag' => true],
        ]);
    }

    /** Requests the page until Grav answers it from its page cache (a second request at the latest). */
    private function warmPageCache(string $path): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
    }

    public function testThePageCacheIsReallyOnAndAddsItsHeadersToPages(): void
    {
        $this->warmPageCache('/typography');
        $page = $this->get('/typography');
        self::assertSame(200, $page->status);
        self::assertSame(self::GRAV_CACHE_CONTROL, $page->header('cache-control'), 'pages.cache_control is applied to pages');
        self::assertNotNull($page->header('expires'), 'pages.expires is applied to pages');
        self::assertNotEmpty(glob($this->site()->dir . '/cache/*/') ?: [], 'Grav wrote a cache');
    }

    public function testARedirectAnswersBeforeTheCachedPageAndCarriesOurCacheControl(): void
    {
        $this->warmPageCache('/typography');
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/home', 'status' => 301]]);

        $r = $this->get('/typography');
        $this->assertRedirect($r, 301, '/home');
        self::assertSame('public, max-age=3600', $r->header('cache-control'), 'the plugin\'s value, not pages.cache_control');
        self::assertNull($r->header('expires'), 'no pages.expires on a redirect');
        self::assertNull($r->header('etag'));
        self::assertNull($r->header('last-modified'));
        self::assertFalse($r->has('set-cookie'));
        self::assertSame('', $r->body, 'not a byte of the cached page');
    }

    public function testATemporaryRedirectStaysUncacheableWhateverThePageCacheSays(): void
    {
        $this->warmPageCache('/typography');
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/home', 'status' => 302]]);
        $r = $this->get('/typography');
        $this->assertRedirect($r, 302, '/home');
        self::assertSame('no-store', $r->header('cache-control'));
        self::assertNull($r->header('expires'));
    }

    public function testARuleEditAppliesImmediatelyEvenThoughThePageIsCached(): void
    {
        $this->warmPageCache('/typography');
        $this->warmPageCache('/');

        // add
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/home']]);
        $this->assertRedirect($this->get('/typography'), 301, '/home');
        // change the target
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/typo2']]);
        $this->assertRedirect($this->get('/typography'), 301, '/typo2');
        // change the status
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/typo2', 'status' => 307]]);
        $this->assertRedirect($this->get('/typography'), 307, '/typo2');
        // switch it off: the cached page is served again
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/typo2', 'enabled' => false]]);
        $page = $this->get('/typography');
        self::assertSame(200, $page->status);
        self::assertNull($page->header('x-redirect-by'));
        self::assertSame(self::GRAV_CACHE_CONTROL, $page->header('cache-control'));
        // delete it, add a rule for another page
        $this->rules([['id' => 'b', 'source' => '/', 'target' => '/typography', 'status' => 302]]);
        $this->assertRedirect($this->get('/'), 302, '/typography');
        $this->rules([]);
        self::assertSame(200, $this->get('/')->status);
    }

    public function testEditsThroughTheApiApplyImmediatelyWithThePageCacheOn(): void
    {
        $this->warmPageCache('/typography');
        // A rule written through the plugin's own repository (what the API and the CLI use) is live at once.
        $repository = $this->site()->repository();
        $repository->saveAll([\Grav\Plugin\RedirectManager\Domain\Rule::fromArray(['id' => 'a', 'source' => '/typography', 'target' => '/home'])]);
        $this->assertRedirect($this->get('/typography'), 301, '/home');
        $repository->saveAll([]);
        self::assertSame(200, $this->get('/typography')->status);
    }

    public function testGoneAndLegalReasonsPagesCarryOurHeadersNotTheCachedPageOnes(): void
    {
        $this->warmPageCache('/typography');
        $this->rules([
            ['id' => 'g', 'source' => '/typography', 'target' => '', 'status' => 410],
            ['id' => 'l', 'source' => '/blocked', 'target' => '', 'status' => 451],
        ]);

        $gone = $this->get('/typography');
        self::assertSame(410, $gone->status);
        self::assertSame('public, max-age=3600', $gone->header('cache-control'));
        self::assertSame('noindex', $gone->header('x-robots-tag'));
        self::assertNull($gone->header('expires'));
        self::assertFalse($gone->has('set-cookie'));
        self::assertStringNotContainsString('<title>Typography', $gone->body);

        $legal = $this->get('/blocked');
        self::assertSame(451, $legal->status);
        self::assertSame('no-store', $legal->header('cache-control'), '451 stays uncacheable');
    }

    public function testOnlyIfNotFoundRulesAndTheLogWorkWithThePageCacheOn(): void
    {
        $this->rules([['id' => 'n', 'source' => '/gone-page', 'target' => '/typography', 'only_if_not_found' => true]]);
        foreach ([1, 2, 3] as $i) {
            $r = $this->get('/gone-page');
            $this->assertRedirect($r, 301, '/typography', 'request ' . $i);
            self::assertSame('public, max-age=3600', $r->header('cache-control'));
            self::assertNull($r->header('expires'));
        }
    }

    public function testNotFoundRequestsAreStillLoggedAndAnsweredWithThePageCacheOn(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(404, $this->get('/never-existed')->status);
        }
        $logged = array_filter($this->site()->notFoundEntries(), static fn (array $e): bool => ($e['p'] ?? null) === '/never-existed');
        self::assertCount(3, $logged, 'every 404 counts, the page cache does not swallow them');
    }

    public function testHeadRequestsGetTheSameRedirectFromTheCachedPage(): void
    {
        $this->warmPageCache('/typography');
        $this->rules([['id' => 'a', 'source' => '/typography', 'target' => '/home']]);
        $this->assertRedirect($this->get('/typography', ['method' => 'HEAD']), 301, '/home');
    }
}
