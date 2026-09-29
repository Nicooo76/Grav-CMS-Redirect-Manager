<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\AccountFactory;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiClient;
use PHPUnit\Framework\Attributes\Group;

/**
 * The context panel of the page editor: what GET /redirects/page-context and its badge say after pages were renamed
 * through the REST API (what Admin 2 does), who may read and mark, and that the panel is registered for the right users.
 */
#[Group('integration')]
#[Group('auto')]
final class PageContextTest extends AutoRedirectTestCase
{
    /** @var array<string, string> */
    private static array $passwords = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $site = self::$site;
        self::assertNotNull($site);
        self::$passwords = [
            'rmreader' => AccountFactory::create($site, 'rmreader', ['api' => ['access' => true, 'redirects' => ['read' => true]]]),
            'rmbasic' => AccountFactory::create($site, 'rmbasic', ['api' => ['access' => true]]),
        ];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function context(string $route, array $query = [], ?ApiClient $as = null): array
    {
        $response = ($as ?? $this->api)->get('/redirects/page-context', ['route' => $route] + $query);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createRule(array $fields): void
    {
        $response = $this->api->post('/redirects/rules', $fields);
        self::assertSame(201, $response->status, $response->describe());
    }

    private function badge(string $route, ?ApiClient $as = null): mixed
    {
        $response = ($as ?? $this->api)->get('/redirects/page-context/badge', ['route' => $route, 'lang' => 'en', 'type' => 'pages']);
        self::assertSame(200, $response->status, $response->describe());

        return $response->data()['count'];
    }

    public function testRenamingAPageShowsTheNewAutomaticRuleInItsPanelAndBadge(): void
    {
        $this->page('03.about', 'About');
        self::assertNull($this->badge('/about'));

        $this->setSlug('/about', 'about-us');

        $context = $this->context('/about-us');
        self::assertSame('/about-us', $context['route']);
        self::assertSame(1, $context['unseen']);
        self::assertCount(1, $context['created']);
        self::assertCount(1, $context['incoming']);
        self::assertSame($context['created'][0]['id'], $context['incoming'][0]['id']);
        self::assertEquals(['source' => '/about', 'target' => '/about-us', 'status' => 301, 'origin' => 'auto', 'unseen' => true, 'state' => 'active'], array_intersect_key($context['incoming'][0], array_flip(['source', 'target', 'status', 'origin', 'unseen', 'state'])));
        self::assertNull($context['outgoing']);
        self::assertSame(1, $this->badge('/about-us'));
        self::assertNull($this->badge('/about'), 'the old route has nothing to show');
        self::assertNull($this->badge('/somewhere-else'));

        // the page context and the sidebar badge count the same rule
        self::assertSame(1, $this->api->get('/redirects/badge')->data()['count']);

        // the old URL redirects, and it is what the page panel says
        $this->assertRedirect($this->get('/about'), 301, '/about-us');
        self::assertSame(['/about'], array_column($this->context('/about-us')['incoming'], 'source'));
    }

    public function testRenamingAPageWithChildrenAlsoCountsTheWildcardRule(): void
    {
        $this->page('03.blog', 'Blog');
        $this->page('03.blog/post', 'Post');

        $this->setSlug('/blog', 'news');

        $news = $this->context('/news');
        self::assertSame(2, $news['unseen'], '/blog and /blog/*');
        self::assertEqualsCanonicalizing(['/blog', '/blog/*'], array_column($news['incoming'], 'source'));
        self::assertSame(2, $this->badge('/news'));
        self::assertNull($this->badge('/news/post'), 'the wildcard rule covers the child, but names only the parent');
    }

    public function testMarkingAPageSeenClearsItsBadgeAndLeavesOtherPagesAlone(): void
    {
        $this->page('03.about', 'About');
        $this->page('04.team', 'Team');
        $this->setSlug('/about', 'about-us');
        $this->setSlug('/team', 'crew');
        self::assertSame(2, $this->api->get('/redirects/badge')->data()['count']);

        $seen = $this->api->post('/redirects/page-context/seen', ['route' => '/about-us', 'lang' => 'en']);

        self::assertSame(200, $seen->status, $seen->describe());
        self::assertSame(['cleared' => 1, 'count' => null, 'sidebar' => 1], $seen->data());
        self::assertNull($this->badge('/about-us'));
        self::assertSame(1, $this->badge('/crew'));
        self::assertSame(1, $this->api->get('/redirects/badge')->data()['count']);
        $after = $this->context('/about-us');
        self::assertSame(0, $after['unseen']);
        self::assertCount(1, $after['created'], 'still there for 24 hours, no longer unseen');
        self::assertFalse($after['created'][0]['unseen']);
        self::assertTrue($after['created'][0]['recent']);
    }

    public function testAPageThatIsRedirectedAwayIsReported(): void
    {
        $this->page('03.about', 'About');
        $this->createRule(['source' => '/about', 'target' => '/contact', 'match_type' => 'exact', 'status' => 302]);

        $outgoing = $this->context('/about')['outgoing'];

        self::assertIsArray($outgoing);
        self::assertSame(302, $outgoing['status']);
        self::assertSame('/contact', $outgoing['location']);
        self::assertSame('/about', $outgoing['source']);
    }

    public function testAnOldUrlAddedByHandLeadsToThePageAndShowsUp(): void
    {
        $this->page('03.about', 'About');
        $this->createRule(['source' => '/company', 'target' => '/about', 'target_type' => 'page', 'match_type' => 'exact', 'status' => 301]);

        $context = $this->context('/about');

        self::assertSame(['/company'], array_column($context['incoming'], 'source'));
        self::assertSame('manual', $context['incoming'][0]['origin']);
        self::assertSame([], $context['created'], 'a manual rule is not an automatic one');
        self::assertNull($this->badge('/about'));
        $this->assertRedirect($this->get('/company'), 301, '/about');
    }

    public function testDeletedDescendantsThatWaitForADecisionAreListed(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => 'ask']]);
        $this->deletePage('/docs/guide');

        $pending = $this->context('/docs')['pending'];

        self::assertCount(1, $pending);
        self::assertSame('/docs/guide', $pending[0]['route']);
        self::assertSame([], $this->context('/other')['pending']);
    }

    public function testMalformedRoutesAreRefused(): void
    {
        foreach (['', 'about', '/about?x=1'] as $route) {
            self::assertSame(422, $this->api->get('/redirects/page-context', ['route' => $route])->status, 'route ' . $route);
            self::assertSame(422, $this->api->get('/redirects/page-context/badge', ['route' => $route])->status);
        }
        self::assertSame(422, $this->api->post('/redirects/page-context/seen', [])->status);
    }

    public function testReadersCanReadButNotMark(): void
    {
        $this->page('03.about', 'About');
        $this->setSlug('/about', 'about-us');
        $reader = ApiClient::login($this->site(), 'rmreader', self::$passwords['rmreader']);

        $response = $reader->get('/redirects/page-context', ['route' => '/about-us']);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['read' => true, 'manage' => false], $response->meta()['permissions']);
        self::assertSame(1, $this->badge('/about-us', $reader));
        self::assertSame(403, $reader->post('/redirects/page-context/seen', ['route' => '/about-us'])->status);
        self::assertSame(1, $this->badge('/about-us'), 'a reader does not clear anything');

        self::assertTrue($this->context('/about-us')['created'][0]['unseen']);
        self::assertSame(['read' => true, 'manage' => true], $this->api->get('/redirects/page-context', ['route' => '/about-us'])->meta()['permissions']);
    }

    public function testWithoutThePermissionOrALoginThereIsNothing(): void
    {
        $basic = ApiClient::login($this->site(), 'rmbasic', self::$passwords['rmbasic']);

        self::assertSame(403, $basic->get('/redirects/page-context', ['route' => '/about'])->status);
        self::assertSame(403, $basic->get('/redirects/page-context/badge', ['route' => '/about'])->status);
        self::assertSame(403, $basic->post('/redirects/page-context/seen', ['route' => '/about'])->status);
        self::assertSame(401, $this->api->withoutAuth()->get('/redirects/page-context', ['route' => '/about'])->status);
        self::assertSame(401, $this->api->withoutAuth()->get('/redirects/page-context/badge', ['route' => '/about'])->status);
        self::assertSame(401, $this->api->withoutAuth()->post('/redirects/page-context/seen', ['route' => '/about'])->status);
    }

    public function testThePanelIsRegisteredForPagesAndOnlyForUsersWhoMayReadRedirects(): void
    {
        $find = static function (ApiClient $client): ?array {
            $response = $client->get('/context-panels');
            self::assertSame(200, $response->status, $response->describe());
            foreach ((array) $response->data() as $panel) {
                if (is_array($panel) && ($panel['id'] ?? null) === 'redirect-manager') {
                    return $panel;
                }
            }

            return null;
        };

        $panel = $find($this->api);
        self::assertIsArray($panel);
        self::assertSame('redirect-manager', $panel['plugin']);
        self::assertSame(['pages'], $panel['contexts']);
        self::assertSame('/redirects/page-context/badge', $panel['badgeEndpoint']);
        self::assertSame('route', $panel['icon']);
        self::assertSame('Redirects for this page', $panel['label']);
        self::assertArrayNotHasKey('authorize', $panel, 'the API plugin strips it');

        self::assertIsArray($find(ApiClient::login($this->site(), 'rmreader', self::$passwords['rmreader'])), 'the string form works for a user who is no super admin');
        self::assertNull($find(ApiClient::login($this->site(), 'rmbasic', self::$passwords['rmbasic'])));

        $script = $this->api->get('/gpm/plugins/redirect-manager/panel-script');
        self::assertSame(200, $script->status);
    }
}
