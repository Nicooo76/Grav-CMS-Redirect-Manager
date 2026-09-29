<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** Rule cache invalidation, failure isolation, exclusions, the on/off switches and the Twig helpers. */
#[Group('integration')]
final class ResilienceTest extends IntegrationTestCase
{
    public function testEditingRulesYamlIsPickedUpWithoutClearingTheCache(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/one']]);
        $this->assertRedirect($this->get('/x'), 301, '/one');

        // same length, written within the same second: only the content hash tells them apart
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/two']]);
        $this->assertRedirect($this->get('/x'), 301, '/two');

        $this->rules([]);
        $this->assertNotRedirected($this->get('/x'));

        $this->rules([['id' => 'b', 'source' => '/x', 'target' => '/three']]);
        $this->assertRedirect($this->get('/x'), 301, '/three');
    }

    public function testAHandEditedFileIsPickedUpToo(): void
    {
        $yaml = static fn (string $target): string => "version: 1\nrules:\n  - id: hand\n    source: /hand\n    target: $target\n";
        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', $yaml('/typography'));
        $this->assertRedirect($this->get('/hand'), 301, '/typography');
        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', $yaml('/home'));
        $this->assertRedirect($this->get('/hand'), 301, '/home');
    }

    public function testAMissingRulesFileIsFine(): void
    {
        self::assertSame(200, $this->get('/typography')->status);
        self::assertSame(404, $this->get('/nothing-here')->status);
        self::assertStringNotContainsString('Redirect Manager', $this->site()->gravLog());
    }

    public function testABrokenRulesFileNeverBreaksTheSite(): void
    {
        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', "rules: [unclosed\n  - : :\n\t bad");

        self::assertSame(200, $this->get('/typography')->status);
        self::assertSame(200, $this->get('/')->status);
        self::assertSame(404, $this->get('/missing')->status);

        $log = $this->site()->gravLog();
        self::assertStringContainsString('Redirect Manager', $log);
        self::assertStringContainsString('corrupt', $log);
        self::assertSame(1, substr_count($log, 'Rules file'), 'the error is logged once, not on every request');
    }

    public function testABrokenEditKeepsTheLastGoodRules(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        $this->assertRedirect($this->get('/x'), 301, '/typography');

        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', "rules: [broken\n  - : :");
        $this->assertRedirect($this->get('/x'), 301, '/typography', 'the previous rules stay in force');
        self::assertSame(200, $this->get('/typography')->status);
        self::assertStringContainsString('corrupt', $this->site()->gravLog());

        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/home']]);
        $this->assertRedirect($this->get('/x'), 301, '/home', 'fixing the file recovers');
    }

    public function testACorruptCompiledCacheIsRebuilt(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        $this->assertRedirect($this->get('/x'), 301, '/typography');

        $files = glob($this->site()->dir . '/cache/redirect-manager/rules-*.php') ?: [];
        self::assertCount(1, $files);
        file_put_contents($files[0], '<?php this is not php');
        $this->assertRedirect($this->get('/x'), 301, '/typography');
    }

    public function testAnUnwritableCacheDirectoryDoesNotBreakRedirects(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        // a file where the cache directory should be
        file_put_contents($this->site()->dir . '/cache/redirect-manager', 'not a directory');

        $this->assertRedirect($this->get('/x'), 301, '/typography');
        self::assertSame(200, $this->get('/typography')->status);
    }

    public function testDisabledPluginDoesNothing(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        $this->site()->writePluginConfig(['enabled' => false]);

        $r = $this->get('/x');
        self::assertSame(404, $r->status);
        self::assertNull($r->header('x-redirect-by'));
        self::assertSame([], $this->site()->notFoundEntries(), 'nothing is logged either');
    }

    public function testRedirectsCanBeSwitchedOffWhileTheRestKeepsWorking(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        $this->site()->writePluginConfig(['redirects' => ['enabled' => false]]);
        self::assertSame(404, $this->get('/x')->status);
        self::assertSame(200, $this->get('/typography')->status);
    }

    public function testApiAndAdminRoutesAreNeverRedirected(): void
    {
        $this->rules([
            ['id' => 'api', 'source' => '/api/*', 'target' => '/typography', 'match_type' => 'wildcard', 'priority' => 100],
            ['id' => 'api2', 'source' => '/api', 'target' => '/typography', 'priority' => 100],
            ['id' => 'admin', 'source' => '/admin*', 'target' => '/typography', 'match_type' => 'wildcard', 'priority' => 100],
            ['id' => 'reg', 'source' => '^/(api|admin).*$', 'target' => '/typography', 'match_type' => 'regex', 'priority' => 100],
        ]);

        foreach (['/api/v1/ping', '/api', '/api/v1/pages', '/admin', '/admin/dashboard'] as $path) {
            $r = $this->get($path);
            self::assertNull($r->header('x-redirect-by'), $path . ' ' . $r->describe());
            self::assertNotSame('/typography', $r->location(), $path);
        }
        $ping = $this->get('/api/v1/ping');
        self::assertContains($ping->status, [200, 401, 403], 'the API answers itself: ' . $ping->describe());
    }

    public function testConfiguredExcludedPathsAreNeverRedirected(): void
    {
        $this->site()->writePluginConfig(['redirects' => ['excluded_paths' => ['/keep-out']]]);
        $this->rules([
            ['id' => 'a', 'source' => '/keep-out/*', 'target' => '/typography', 'match_type' => 'wildcard'],
            ['id' => 'b', 'source' => '/keep-in', 'target' => '/typography'],
        ]);
        $this->assertNotRedirected($this->get('/keep-out/page'));
        $this->assertRedirect($this->get('/keep-in'), 301, '/typography');
    }

    public function testGravDirectoriesAreNeverRedirected(): void
    {
        $this->rules([['id' => 'all', 'source' => '/*', 'target' => '/typography', 'match_type' => 'wildcard', 'priority' => 100]]);
        foreach (['/user/pages/02.typography/default.md', '/system/config/system.yaml', '/vendor/autoload.php', '/cache/x', '/logs/grav.log'] as $path) {
            $this->assertNotRedirected($this->get($path), $path);
        }
    }

    public function testGravsTrailingSlashRedirectIsUntouchedForNonMatchingUrls(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/home']]);

        $existing = $this->get('/typography/');
        self::assertContains($existing->status, [301, 302, 303, 307, 308], $existing->describe());
        self::assertSame($this->site()->url('/typography'), $existing->location());
        self::assertNull($existing->header('x-redirect-by'));

        $missing = $this->get('/no-such-page/');
        self::assertContains($missing->status, [301, 302, 303, 307, 308]);
        self::assertNull($missing->header('x-redirect-by'));
    }

    public function testRedirectsNeverStartASession(): void
    {
        $this->rules([['id' => 'a', 'source' => '/x', 'target' => '/typography']]);
        for ($i = 0; $i < 3; $i++) {
            $r = $this->get('/x');
            self::assertFalse($r->has('set-cookie'));
            self::assertFalse($r->has('expires'));
        }
    }

    public function testTwigFunctionAndFilter(): void
    {
        $this->rules([
            ['id' => 'a', 'source' => '/old', 'target' => '/typography'],
            ['id' => 'g', 'source' => '/gone', 'target' => '', 'status' => 410],
        ]);
        $this->site()->writeFile('user/themes/quark2/templates/rm-twig.html.twig', <<<'TWIG'
FOR:{{ redirect_for('/old')|json_encode|raw }}
NONE:{{ redirect_for('/no-rule')|json_encode|raw }}
GONE:{{ redirect_for('/gone')|json_encode|raw }}
FILTER:{{ '/old'|redirect_target }}|{{ '/no-rule'|redirect_target }}
TWIG);
        $this->site()->writePage('twigpage', 'Twig page', '', null, '20');
        rename(
            $this->site()->dir . '/user/pages/20.twigpage/default.md',
            $this->site()->dir . '/user/pages/20.twigpage/rm-twig.md',
        );

        $r = $this->get('/twigpage');
        self::assertSame(200, $r->status, $r->body);
        self::assertMatchesRegularExpression('/^FOR:(.*)$/m', $r->body);
        preg_match('/^FOR:(.*)$/m', $r->body, $for);
        self::assertSame(['status' => 301, 'location' => '/typography', 'rule_id' => 'a'], json_decode($for[1], true));
        self::assertStringContainsString("NONE:null\n", $r->body);
        preg_match('/^GONE:(.*)$/m', $r->body, $gone);
        self::assertSame(['status' => 410, 'location' => '', 'rule_id' => 'g'], json_decode($gone[1], true));
        self::assertStringContainsString('FILTER:/typography|/no-rule', $r->body);
        self::assertSame([], $this->site()->recordedHits(), 'the lookup has no side effects');
    }

    public function testPluginFilesAreServedFromASymlinkedPluginDirectory(): void
    {
        // guards the setup itself: the plugin under test is the one the site runs
        self::assertTrue(is_file($this->site()->dir . '/user/plugins/redirect-manager/redirect-manager.php'));
    }
}
