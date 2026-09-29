<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\Group;

/** DELETE /pages/{route} under each policy (auto_redirect.on_delete), and the pending decisions of "ask". */
#[Group('integration')]
#[Group('auto')]
final class DeletePolicyTest extends AutoRedirectTestCase
{
    private function tree(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->page('03.docs/guide/step', 'Step');
    }

    /** @param array<string, mixed> $auto */
    private function policy(string $policy, array $auto = []): void
    {
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => $policy] + $auto]);
    }

    public function testGoneAnswers410ForThePageAndItsDescendants(): void
    {
        $this->tree();
        $this->policy('gone');

        $this->deletePage('/docs/guide');

        self::assertSame(['/docs/guide -> (none) 410', '/docs/guide/* -> (none) 410'], $this->autoRules());
        self::assertSame(410, $this->get('/docs/guide')->status);
        self::assertSame(410, $this->get('/docs/guide/step')->status);
        self::assertSame(200, $this->get('/docs')->status, 'the parent stays');
        self::assertStringContainsString('auto-redirect for "Guide": 2 created', $this->site()->gravLog());
    }

    public function testParentRedirectsToTheNearestParent(): void
    {
        $this->tree();
        $this->policy('parent');

        $this->deletePage('/docs/guide');

        self::assertSame(['/docs/guide -> /docs', '/docs/guide/* -> /docs'], $this->autoRules());
        $this->assertRedirect($this->get('/docs/guide'), 301, '/docs');
        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/docs');
    }

    public function testParentOfATopLevelPageIsTheHomePage(): void
    {
        $this->tree();
        $this->policy('parent');

        $this->deletePage('/docs');

        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/');
    }

    public function testNeverLeavesEverythingAlone(): void
    {
        $this->tree();
        $this->policy('never');

        $this->deletePage('/docs/guide');

        self::assertSame([], $this->autoRules());
        self::assertSame(404, $this->get('/docs/guide')->status);
        self::assertFileDoesNotExist($this->site()->dataDir() . '/auto-state.json');
    }

    public function testChildrenModeEachWritesARuleForEveryDescendant(): void
    {
        $this->tree();
        $this->policy('gone', ['children' => 'each']);

        $this->deletePage('/docs/guide');

        self::assertSame(['/docs/guide -> (none) 410', '/docs/guide/step -> (none) 410'], $this->autoRules());
    }

    public function testAutoRulesThatPointedAtTheDeletedPageBecome410(): void
    {
        $this->tree();
        $this->policy('gone');
        $this->setSlug('/docs/guide', 'handbook');
        self::assertContains('/docs/guide -> /docs/handbook', $this->autoRules());

        $this->deletePage('/docs/handbook');

        $this->assertNoRuleRedirectsTo('/docs/handbook');
        self::assertSame(410, $this->get('/docs/guide')->status);
        self::assertSame(410, $this->get('/docs/handbook')->status);
    }

    private function assertNoRuleRedirectsTo(string $target): void
    {
        foreach ($this->storedRules() as $rule) {
            self::assertStringStartsNotWith($target, $rule->target, $rule->source . ' still points at the deleted page');
        }
    }

    public function testAskKeepsAPendingDecisionAndCreatesNoRule(): void
    {
        $this->tree();
        $this->policy('ask');

        $this->deletePage('/docs/guide');

        self::assertSame([], $this->autoRules());
        self::assertSame(404, $this->get('/docs/guide')->status);

        $pending = $this->api->get('/redirects/pending');
        self::assertSame(200, $pending->status, $pending->describe());
        $rows = $pending->data();
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame('Guide', $rows[0]['title']);
        self::assertSame('/docs/guide', $rows[0]['route']);
        self::assertSame(['/docs/guide/step'], $rows[0]['children']);
        self::assertSame(1, $rows[0]['children_count']);
        self::assertSame('/docs', $rows[0]['suggested_parent']);
        self::assertSame([], $rows[0]['languages']);
        self::assertNotSame('', $rows[0]['deleted_at']);

        self::assertSame(1, $this->api->get('/redirects/badge')->data()['count'] ?? null);
    }

    public function testResolveWithGone(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');
        $id = $this->pendingIds()[0];

        $response = $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'gone']);

        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['/docs/guide -> (none) 410', '/docs/guide/* -> (none) 410'], $this->autoRules());
        self::assertSame(410, $this->get('/docs/guide/step')->status);
        self::assertSame([], $this->pendingIds());
        self::assertCount(2, $response->data()['created']);
    }

    public function testResolveWithParent(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');

        $response = $this->api->post('/redirects/pending/' . $this->pendingIds()[0] . '/resolve', ['action' => 'parent']);

        self::assertSame(200, $response->status, $response->describe());
        $this->assertRedirect($this->get('/docs/guide'), 301, '/docs');
        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/docs');
    }

    public function testParentSkipsAnAncestorThatIsDeletedToo(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');
        $this->deletePage('/docs');
        $ids = $this->pendingIds();
        self::assertCount(2, $ids);

        // Resolve the deeper page first: its parent (/docs) is gone, the nearest existing ancestor is the home page.
        $rows = $this->api->get('/redirects/pending')->data();
        $guide = null;
        foreach ($rows as $row) {
            if ($row['route'] === '/docs/guide') {
                $guide = $row;
            }
        }
        self::assertNotNull($guide);
        self::assertNull($guide['suggested_parent'], 'no ancestor of /docs/guide exists any more');

        $this->api->post('/redirects/pending/' . $guide['id'] . '/resolve', ['action' => 'parent']);

        $this->assertRedirect($this->get('/docs/guide'), 301, '/');
    }

    public function testResolveWithAChosenTarget(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');

        $response = $this->api->post('/redirects/pending/' . $this->pendingIds()[0] . '/resolve', ['action' => 'redirect', 'target' => '/typography']);

        self::assertSame(200, $response->status, $response->describe());
        $this->assertRedirect($this->get('/docs/guide'), 301, '/typography');
        $this->assertRedirect($this->get('/docs/guide/step'), 301, '/typography');
    }

    public function testResolveWithAnExternalTarget(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => 'ask'], 'security' => ['allowed_hosts' => ['example.org']]]);

        $response = $this->api->post('/redirects/pending/' . $this->pendingIds()[0] . '/resolve', ['action' => 'redirect', 'target' => 'https://example.org/guide']);

        self::assertSame(200, $response->status, $response->describe());
        $this->assertRedirect($this->get('/docs/guide'), 301, 'https://example.org/guide');
    }

    public function testResolveWithAnExternalTargetThatIsNotAllowedIsRefused(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');

        $response = $this->api->post('/redirects/pending/' . $this->pendingIds()[0] . '/resolve', ['action' => 'redirect', 'target' => 'https://evil.example/x']);

        self::assertSame(422, $response->status, $response->describe());
        self::assertSame([], $this->autoRules());
        self::assertCount(1, $this->pendingIds(), 'the decision stays pending');
    }

    public function testDismissKeepsNoRule(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');

        $response = $this->api->post('/redirects/pending/' . $this->pendingIds()[0] . '/resolve', ['action' => 'dismiss']);

        self::assertSame(200, $response->status, $response->describe());
        self::assertSame([], $this->pendingIds());
        self::assertSame([], $this->autoRules());
        self::assertSame(0, $this->api->get('/redirects/badge')->data()['count'] ?? null);
    }

    public function testResolveValidation(): void
    {
        $this->tree();
        $this->policy('ask');
        $this->deletePage('/docs/guide');
        $id = $this->pendingIds()[0];

        self::assertSame(422, $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'explode'])->status);
        self::assertSame(422, $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'redirect'])->status);
        self::assertSame(422, $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'redirect', 'target' => 'nope'])->status);
        self::assertSame(404, $this->api->post('/redirects/pending/nope/resolve', ['action' => 'gone'])->status);
        self::assertSame([$id], $this->pendingIds());

        self::assertSame(200, $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'gone'])->status);
        self::assertSame(404, $this->api->post('/redirects/pending/' . $id . '/resolve', ['action' => 'gone'])->status, 'resolving twice');
    }

    public function testBatchDeleteAppliesThePolicyToEachPage(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('04.one', 'One');
        $this->page('05.two', 'Two');
        $this->policy('gone');

        $response = $this->api->post('/pages/batch', ['operation' => 'delete', 'routes' => ['/one', '/two']]);

        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['/one -> (none) 410', '/two -> (none) 410'], $this->autoRules());
    }

    public function testBadgeCountsUnseenRulesUntilTheyAreMarkedSeen(): void
    {
        $this->tree();
        $this->page('06.blog', 'Blog');
        $this->setSlug('/blog', 'news');

        $badge = $this->api->get('/redirects/badge');
        self::assertSame(200, $badge->status, $badge->describe());
        self::assertSame(1, $badge->data()['count']);
        self::assertSame(1, $badge->data()['unseen']);
        self::assertSame(0, $badge->data()['pending']);

        $seen = $this->api->post('/redirects/badge/seen', []);
        self::assertSame(200, $seen->status, $seen->describe());
        self::assertSame(0, $this->api->get('/redirects/badge')->data()['count']);
    }

    public function testBadgeAddsPendingDecisionsAndIgnoresDeletedRules(): void
    {
        $this->tree();
        $this->page('06.blog', 'Blog');
        $this->policy('ask');
        $this->setSlug('/blog', 'news');
        $this->deletePage('/docs/guide');
        self::assertSame(2, $this->api->get('/redirects/badge')->data()['count'], '1 unseen rule + 1 pending');

        $this->site()->repository()->delete(array_map(static fn (Rule $r): string => $r->id, $this->storedRules()));

        self::assertSame(1, $this->api->get('/redirects/badge')->data()['count'], 'only the pending decision is left');
    }

    /**
     * @return list<string>
     */
    private function pendingIds(): array
    {
        $rows = $this->api->get('/redirects/pending')->data();

        return array_map(static fn (array $row): string => (string) $row['id'], is_array($rows) ? $rows : []);
    }
}
