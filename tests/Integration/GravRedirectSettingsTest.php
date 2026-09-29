<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * system.pages.redirect_default_route, redirect_default_code and redirect_trailing_slash belong to Grav. For a URL
 * without a matching rule the plugin must leave them alone; where a rule matches, the rule wins because it answers
 * before Grav's own redirects (E-10).
 */
#[Group('integration')]
final class GravRedirectSettingsTest extends IntegrationTestCase
{
    /**
     * @param array<string, mixed> $pages system.pages settings
     */
    private function pages(array $pages): void
    {
        $this->site()->writeSystemConfig(['pages' => $pages]);
    }

    private function assertGravRedirect(string $path, int $status, string $location): void
    {
        $r = $this->get($path);
        self::assertSame($status, $r->status, $path . ' ' . $r->describe());
        self::assertSame($location, $r->location(), $path);
        self::assertNull($r->header('x-redirect-by'), $path . ' is Grav\'s own redirect, not ours');
    }

    public function testDefaultRouteRedirectStripsHtmlWhenEnabledAndStaysGravsOwn(): void
    {
        $this->pages(['redirect_default_route' => 301, 'redirect_default_code' => 302]);
        $this->rules([['id' => 'other', 'source' => '/unrelated', 'target' => '/home']]);

        $this->assertGravRedirect('/typography.html', 301, '/typography');
        $this->assertGravRedirect('/typography.htm', 301, '/typography');
        self::assertSame(200, $this->get('/typography')->status, 'the default route itself is served');
        self::assertSame([], $this->site()->recordedHits(), 'no rule was involved');
        self::assertSame([], $this->site()->notFoundEntries(), 'a page that exists is no 404');
    }

    public function testWithTheSettingOffGravServesTheHtmlUrlAndWeDoNotAddARedirect(): void
    {
        $this->pages(['redirect_default_route' => 0]);
        $this->rules([['id' => 'other', 'source' => '/unrelated', 'target' => '/home']]);

        $r = $this->get('/typography.html');
        self::assertSame(200, $r->status, $r->describe());
        self::assertNull($r->header('x-redirect-by'));
        self::assertNull($r->location());
    }

    public function testTheDefaultRouteRedirectUsesTheConfiguredCode(): void
    {
        $this->pages(['redirect_default_route' => 302]);
        $this->assertGravRedirect('/typography.html', 302, '/typography');
    }

    public function testAPluginRuleBeatsGravsDefaultRouteRedirect(): void
    {
        $this->pages(['redirect_default_route' => 301]);
        $this->rules([
            ['id' => 'html', 'source' => '/typography.html', 'target' => '/home', 'status' => 302],
            ['id' => 'canonical', 'source' => '/typography', 'target' => '/home', 'status' => 308],
        ]);

        $this->assertRedirect($this->get('/typography.html'), 302, '/home', 'the rule answers before Grav would strip .html');
        $this->assertRedirect($this->get('/typography'), 308, '/home', 'a rule may override an existing page, as without the setting');
        $this->assertGravRedirect('/typography.htm', 301, '/typography');
    }

    public function testTheHomeAliasRouteFollowsGravWhenNoRuleMatches(): void
    {
        $this->pages(['redirect_default_route' => 301]);
        $this->rules([['id' => 'other', 'source' => '/unrelated', 'target' => '/typography']]);

        $home = $this->get('/home');
        $withoutPlugin = [$home->status, $home->location()];
        $this->rules([]);
        $plain = $this->get('/home');
        self::assertSame([$plain->status, $plain->location()], $withoutPlugin, 'the same answer with and without rules');
        self::assertNull($home->header('x-redirect-by'));
    }

    public function testTrailingSlashSettingIsRespectedForUrlsWithoutARule(): void
    {
        $this->rules([['id' => 'other', 'source' => '/unrelated', 'target' => '/home']]);

        $this->pages(['redirect_trailing_slash' => 301]);
        $this->assertGravRedirect('/typography/', 301, $this->site()->url('/typography'));

        $this->pages(['redirect_trailing_slash' => 302]);
        $this->assertGravRedirect('/typography/', 302, $this->site()->url('/typography'));

        // 1 = "use redirect_default_code"
        $this->pages(['redirect_trailing_slash' => 1, 'redirect_default_code' => 301]);
        $this->assertGravRedirect('/typography/', 301, $this->site()->url('/typography'));
        $this->pages(['redirect_trailing_slash' => 1, 'redirect_default_code' => 302]);
        $this->assertGravRedirect('/typography/', 302, $this->site()->url('/typography'));

        $this->pages(['redirect_trailing_slash' => 0]);
        $off = $this->get('/typography/');
        self::assertNull($off->header('x-redirect-by'));
        self::assertNotSame($this->site()->url('/typography'), $off->location(), 'Grav does not strip the slash when the setting is 0');
    }

    public function testARuleWithATrailingSlashSourceWinsOverTheTrailingSlashRedirect(): void
    {
        $this->pages(['redirect_trailing_slash' => 301]);
        $this->rules([['id' => 'slash', 'source' => '/old', 'target' => '/typography', 'status' => 308]]);

        $this->assertRedirect($this->get('/old/'), 308, '/typography', 'one hop instead of Grav\'s slash redirect first');
        $this->assertRedirect($this->get('/old'), 308, '/typography');
        $this->assertGravRedirect('/typography/', 301, $this->site()->url('/typography'));
    }

    public function testRuleWritesNeverTouchTheSystemConfiguration(): void
    {
        $this->pages(['redirect_default_route' => 301, 'redirect_default_code' => 301, 'redirect_trailing_slash' => 1]);
        $before = $this->site()->readFile('user/config/system.yaml');
        self::assertNotNull($before);

        $this->rules([['id' => 'a', 'source' => '/a', 'target' => '/typography']]);
        $this->get('/a');
        $this->get('/nothing-here');
        $this->assertRedirect($this->get('/a'), 301, '/typography');

        self::assertSame($before, $this->site()->readFile('user/config/system.yaml'), 'system.yaml is byte-identical');
    }
}
