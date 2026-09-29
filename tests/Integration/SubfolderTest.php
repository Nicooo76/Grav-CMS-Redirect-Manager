<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** Grav installed below a base path (system.custom_base_url = http://host/sub). */
#[Group('integration')]
final class SubfolderTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeSystemConfig(['custom_base_url' => 'http://127.0.0.1:' . $this->site()->port . '/sub']);
    }

    public function testBasePathIsStrippedFromTheRequestAndAddedToTheTarget(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);
        $this->assertRedirect($this->get('/sub/old'), 301, '/sub/typography');
    }

    public function testWildcardsSeeThePathBelowTheBase(): void
    {
        $this->rules([['id' => 'w', 'source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard']]);
        $this->assertRedirect($this->get('/sub/blog/2020/post'), 301, '/sub/news/2020/post');
    }

    public function testExternalTargetsAreLeftAlone(): void
    {
        $this->site()->writePluginConfig(['security' => ['allowed_hosts' => ['partner.example.org']]]);
        $this->rules([['id' => 'x', 'source' => '/away', 'target' => 'https://partner.example.org/x', 'target_type' => 'url']]);
        $this->assertRedirect($this->get('/sub/away'), 301, 'https://partner.example.org/x');
    }

    public function testNotFoundRulesAndTheLogUseTheBasePathToo(): void
    {
        $this->rules([['id' => 'nf', 'source' => '/weg', 'target' => '/typography', 'only_if_not_found' => true]]);
        $this->assertRedirect($this->get('/sub/weg'), 301, '/sub/typography');
        $entries = $this->site()->notFoundEntries();
        self::assertSame('/weg', $entries[0]['p'] ?? null);
    }

    public function testApiRouteIsExcludedBelowTheBaseToo(): void
    {
        $this->rules([['id' => 'api', 'source' => '/api/*', 'target' => '/typography', 'match_type' => 'wildcard']]);
        $this->assertNotRedirected($this->get('/sub/api/v1/ping'));
    }
}
