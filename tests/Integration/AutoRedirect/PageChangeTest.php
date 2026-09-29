<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pages changed through the REST API (what Admin 2 does): renames through header.slug, moves, reorganize.
 * Asserts rules.yaml and the real frontend answers.
 */
#[Group('integration')]
#[Group('auto')]
final class PageChangeTest extends AutoRedirectTestCase
{
    private function blogTree(): void
    {
        $this->page('03.blog', 'Blog');
        $this->page('03.blog/a-post', 'A post');
        $this->page('03.blog/a-post/deep', 'Deep');
    }

    public function testSlugChangeRedirectsPageAndDescendants(): void
    {
        $this->blogTree();
        self::assertSame(200, $this->get('/blog/a-post/deep')->status);

        $this->setSlug('/blog', 'news');

        self::assertSame(['/blog -> /news', '/blog/* -> /news/$1'], $this->autoRules());
        $this->assertRedirect($this->get('/blog'), 301, '/news');
        $this->assertRedirect($this->get('/blog/a-post'), 301, '/news/a-post');
        $this->assertRedirect($this->get('/blog/a-post/deep'), 301, '/news/a-post/deep');
        self::assertSame(200, $this->get('/news')->status);
        self::assertSame(200, $this->get('/news/a-post/deep')->status);

        $rule = $this->storedRules()[0];
        self::assertSame(RuleSource::Auto, $rule->origin);
        self::assertStringContainsString('Blog', $rule->note);
        self::assertStringContainsString('auto-redirect for "Blog": 2 created', $this->site()->gravLog());
    }

    public function testChildrenModeEachWritesOneRulePerDescendant(): void
    {
        $this->blogTree();
        $this->site()->writePluginConfig(['auto_redirect' => ['children' => 'each']]);

        $this->setSlug('/blog', 'news');

        self::assertSame(['/blog -> /news', '/blog/a-post -> /news/a-post', '/blog/a-post/deep -> /news/a-post/deep'], $this->autoRules());
        foreach ($this->storedRules() as $rule) {
            self::assertSame(MatchType::Exact, $rule->matchType);
        }
        $this->assertRedirect($this->get('/blog/a-post/deep'), 301, '/news/a-post/deep');
    }

    public function testConfiguredStatusIsUsed(): void
    {
        $this->blogTree();
        $this->site()->writePluginConfig(['auto_redirect' => ['status' => 308]]);

        $this->setSlug('/blog', 'news');

        $this->assertRedirect($this->get('/blog'), 308, '/news');
    }

    public function testRenameBackRemovesTheRuleAndBothUrlsBehave(): void
    {
        $this->blogTree();
        $this->setSlug('/blog', 'news');
        self::assertNotSame([], $this->autoRules());

        $this->setSlug('/blog', 'blog');

        self::assertSame([], $this->autoRules());
        self::assertSame(200, $this->get('/blog')->status);
        self::assertSame(200, $this->get('/blog/a-post')->status);
        self::assertSame(404, $this->get('/news')->status);
        self::assertNull($this->get('/blog')->header('x-redirect-by'));
    }

    public function testSlugChainsAreFlattened(): void
    {
        $this->blogTree();

        $this->setSlug('/blog', 'news');
        $this->setSlug('/blog', 'press');

        self::assertSame(['/blog -> /press', '/blog/* -> /press/$1', '/news -> /press', '/news/* -> /press/$1'], $this->autoRules());
        $this->assertRedirect($this->get('/blog'), 301, '/press');
        $this->assertRedirect($this->get('/news'), 301, '/press');
        $this->assertRedirect($this->get('/news/a-post'), 301, '/press/a-post');
        $this->assertRedirect($this->get('/blog/a-post/deep'), 301, '/press/a-post/deep');
        self::assertSame(200, $this->get('/press/a-post')->status);
    }

    public function testAnEditThatKeepsTheSlugCreatesNothing(): void
    {
        $this->blogTree();

        $this->patchPage('/blog', ['header' => ['title' => 'New title', 'slug' => 'blog']]);
        $this->patchPage('/blog', ['content' => 'Changed']);
        $this->patchPage('/blog', ['header' => ['title' => 'Again']]);

        self::assertFileDoesNotExist($this->site()->dataDir() . '/rules.yaml');
        self::assertFileDoesNotExist($this->site()->dataDir() . '/auto-state.json');
    }

    public function testDisabledFeatureDoesNothing(): void
    {
        $this->blogTree();
        $this->site()->writePluginConfig(['auto_redirect' => ['enabled' => false]]);

        $this->setSlug('/blog', 'news');

        self::assertNull($this->get('/blog')->header('x-redirect-by'));
        self::assertFileDoesNotExist($this->site()->dataDir() . '/rules.yaml');
    }

    public function testManualRuleForTheOldRouteWins(): void
    {
        $this->blogTree();
        $this->rules([['id' => 'manual', 'source' => '/blog', 'target' => '/typography']]);

        $this->setSlug('/blog', 'news');

        self::assertSame(['/blog/* -> /news/$1'], $this->autoRules());
        $this->assertRedirect($this->get('/blog'), 301, '/typography');
        self::assertStringContainsString('conflict /blog', $this->site()->gravLog());
    }

    public function testPageTargetRulesFollowThePage(): void
    {
        $this->blogTree();
        $this->rules([
            ['id' => 'promo', 'source' => '/promo', 'target' => '/blog', 'target_type' => 'page'],
            ['id' => 'plain', 'source' => '/plain', 'target' => '/blog', 'target_type' => 'route'],
        ]);

        $this->setSlug('/blog', 'news');

        $this->assertRedirect($this->get('/promo'), 301, '/news');
        $this->assertRedirect($this->get('/plain'), 301, '/blog', 'rules with a plain route target are not touched');
    }

    public function testMovingAPageUnderAnotherParent(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->page('03.docs/guide/step', 'Step');
        $this->page('04.archive', 'Archive');

        $this->movePage('/docs/guide', '/archive');

        self::assertSame(['/docs/guide -> /archive/guide', '/docs/guide/* -> /archive/guide/$1'], $this->autoRules());
        $this->assertRedirect($this->get('/docs/guide'), 301, '/archive/guide');
        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/archive/guide/step');
        self::assertSame(200, $this->get('/archive/guide/step')->status);
    }

    public function testMoveWithARename(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->page('04.archive', 'Archive');

        $this->movePage('/docs/guide', '/archive', 'handbook');

        self::assertSame(['/docs/guide -> /archive/handbook'], $this->autoRules());
        $this->assertRedirect($this->get('/docs/guide'), 301, '/archive/handbook');
    }

    public function testMovingBackRemovesTheRule(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->page('04.archive', 'Archive');
        $this->movePage('/docs/guide', '/archive');
        self::assertNotSame([], $this->autoRules());

        $this->movePage('/archive/guide', '/docs');

        self::assertSame([], $this->autoRules());
        self::assertSame(200, $this->get('/docs/guide')->status);
        self::assertSame(404, $this->get('/archive/guide')->status);
    }

    public function testReorganizeMovesAreCaptured(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->page('03.docs/guide/step', 'Step');
        $this->page('04.archive', 'Archive');

        $response = $this->api->post('/pages/reorganize', ['operations' => [['route' => '/docs/guide', 'parent' => '/archive']]]);
        self::assertSame(200, $response->status, $response->describe());

        self::assertSame(['/docs/guide -> /archive/guide', '/docs/guide/* -> /archive/guide/$1'], $this->autoRules());
        self::assertCount(2, $this->storedRules(), 'the per-page move event of the same request is not planned twice');
        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/archive/guide/step');
    }

    public function testReorganizePositionChangeAloneCreatesNoRules(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/one', 'One');
        $this->page('03.docs/two', 'Two');

        $response = $this->api->post('/pages/reorganize', ['operations' => [['route' => '/docs/one', 'position' => 9]]]);
        self::assertSame(200, $response->status, $response->describe());

        self::assertSame([], $this->autoRules());
    }

    public function testHomePageIsNeverRedirected(): void
    {
        $this->setSlug('/home', 'start');

        self::assertSame([], $this->autoRules());
    }

    public function testABrokenRulesFileNeverBreaksTheApiRequest(): void
    {
        $this->blogTree();
        $this->site()->writeFile('user/data/redirect-manager/rules.yaml', "rules: [ not: valid: yaml\n");

        $response = $this->api->request('PATCH', '/pages/blog', ['header' => ['slug' => 'news']]);

        self::assertSame(200, $response->status, $response->describe());
        self::assertStringContainsString('auto-redirect', $this->site()->gravLog());
        self::assertSame(200, $this->get('/news')->status, 'the page change itself went through');
    }
}
