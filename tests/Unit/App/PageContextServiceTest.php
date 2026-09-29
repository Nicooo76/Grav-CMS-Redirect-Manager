<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\PageContextService;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PageContextService::class)]
#[Group('auto')]
final class PageContextServiceTest extends AppTestCase
{
    private const NOW = '2026-09-29T10:00:00+00:00';

    private function service(): PageContextService
    {
        return $this->app->pageContext();
    }

    /**
     * An automatic rule "old to new" the way the planner writes it.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function auto(string $id, string $old, string $new, string $created = '2026-09-29T09:30:00+00:00', array $extra = []): array
    {
        return $extra + ['id' => $id, 'source' => $old, 'target' => $new, 'target_type' => 'page', 'origin' => 'auto', 'created_at' => $created];
    }

    /**
     * @param list<string> $unseen
     */
    private function unseen(array $unseen): void
    {
        $this->app->services()->autoState()->addUnseen($unseen);
    }

    public function testRulesThatLeadToThePageAreListedNewestFirstWithHits(): void
    {
        $this->seedRules([
            self::auto('a', '/blog', '/news', '2026-09-28T08:00:00+00:00'),
            ['id' => 'm', 'source' => '/old-news', 'target' => '/news', 'target_type' => 'route', 'created_at' => '2026-09-01T08:00:00+00:00'],
            self::auto('w', '/blog/*', '/news/$1', '2026-09-28T08:00:01+00:00', ['match_type' => 'wildcard', 'target_type' => 'route']),
            ['id' => 'x', 'source' => '/elsewhere', 'target' => '/other'],
            ['id' => 'ext', 'source' => '/ext', 'target' => 'https://example.org/news', 'target_type' => 'url'],
            ['id' => 'gone', 'source' => '/gone', 'target' => '', 'status' => 410],
        ]);

        $context = $this->service()->context('/news/', null);

        self::assertSame('/news', $context['route'], 'the trailing slash is dropped');
        self::assertSame(['w', 'a', 'm'], array_column($context['incoming'], 'id'));
        self::assertSame(3, $context['incoming_total']);
        self::assertSame(['/blog/*', '/blog', '/old-news'], array_column($context['incoming'], 'source'));
        $manual = $context['incoming'][2];
        self::assertSame('manual', $manual['origin']);
        self::assertSame(301, $manual['status']);
        self::assertSame('active', $manual['state']);
        self::assertSame(0, $manual['hits']);
        self::assertNull($manual['last_hit']);
        self::assertNull($context['outgoing']);
        self::assertSame([], $context['pending']);
    }

    public function testTargetsMatchIgnoringCaseAndTrailingSlash(): void
    {
        $this->seedRules([['id' => 'a', 'source' => '/a', 'target' => '/News/', 'target_type' => 'route']]);

        self::assertSame(['a'], array_column($this->service()->context('/news', null)['incoming'], 'id'));
    }

    public function testUnseenAutomaticRulesOfThePageAndBelowAreTheBadge(): void
    {
        $this->seedRules([
            self::auto('a', '/blog', '/news'),
            self::auto('c', '/blog/post', '/news/post'),
            self::auto('w', '/blog/*', '/news/$1', extra: ['match_type' => 'wildcard', 'target_type' => 'route']),
            self::auto('o', '/x', '/other'),
            ['id' => 'manual', 'source' => '/m', 'target' => '/news', 'created_at' => '2026-09-29T09:59:00+00:00'],
        ]);
        $this->unseen(['a', 'c', 'w', 'o', 'manual']);

        $context = $this->service()->context('/news', null);

        self::assertSame(3, $context['unseen']);
        self::assertEqualsCanonicalizing(['a', 'c', 'w'], array_column($context['created'], 'id'), 'the rules of the page and of its descendants, no manual rule, no other page');
        self::assertSame(['a', 'w', 'manual'], array_values(array_intersect(['a', 'w', 'manual'], array_column($context['incoming'], 'id'))), 'c points below the page, so it is created here but not incoming');
        self::assertSame(3, $this->service()->unseenCount('/news', null));
        self::assertSame(1, $this->service()->unseenCount('/other', null));
        self::assertSame(0, $this->service()->unseenCount('/nowhere', null));
        foreach ($context['created'] as $row) {
            self::assertTrue($row['unseen']);
        }
    }

    public function testASeenRuleStaysInThePanelForTwentyFourHoursButNotInTheBadge(): void
    {
        $this->seedRules([
            self::auto('fresh', '/a', '/news', '2026-09-28T10:30:00+00:00'),
            self::auto('old', '/b', '/news', '2026-09-28T09:59:00+00:00'),
        ]);

        $context = $this->service()->context('/news', null);

        self::assertSame(['fresh'], array_column($context['created'], 'id'));
        self::assertFalse($context['created'][0]['unseen']);
        self::assertTrue($context['created'][0]['recent']);
        self::assertSame(0, $context['unseen']);
        self::assertSame(0, $this->service()->unseenCount('/news', null));
        self::assertSame(['fresh', 'old'], array_column($context['incoming'], 'id'));
        self::assertFalse($context['incoming'][1]['recent']);
    }

    public function testAnUnseenRuleStaysInThePanelHoweverOldItIs(): void
    {
        $this->seedRules([self::auto('a', '/a', '/news', '2026-01-01T00:00:00+00:00')]);
        $this->unseen(['a']);

        self::assertSame(['a'], array_column($this->service()->context('/news', null)['created'], 'id'));
        self::assertSame(1, $this->service()->unseenCount('/news', null));
    }

    public function testTheLanguageNarrowsRulesToTheOnesThatApplyToIt(): void
    {
        $this->seedRules([
            self::auto('de', '/blog', '/news', extra: ['conditions' => ['languages' => ['de']]]),
            self::auto('en', '/blog-en', '/news', extra: ['conditions' => ['languages' => ['en']]]),
            self::auto('any', '/blog-any', '/news'),
        ]);
        $this->unseen(['de', 'en', 'any']);

        $service = $this->service();
        self::assertEqualsCanonicalizing(['de', 'any'], array_column($service->context('/news', 'DE')['incoming'], 'id'));
        self::assertSame('de', $service->context('/news', 'DE')['language']);
        self::assertEqualsCanonicalizing(['en', 'any'], array_column($service->context('/news', 'en')['incoming'], 'id'));
        self::assertEqualsCanonicalizing(['de', 'en', 'any'], array_column($service->context('/news', null)['incoming'], 'id'));
        self::assertNull($service->context('/news', 'not a language!')['language']);
        self::assertSame(2, $service->unseenCount('/news', 'de'));
    }

    public function testTheHomePageOnlyOwnsRulesThatPointAtIt(): void
    {
        $this->seedRules([self::auto('home', '/old-start', '/'), self::auto('deep', '/a', '/b/c')]);
        $this->unseen(['home', 'deep']);

        $context = $this->service()->context('/', null);

        self::assertSame(['home'], array_column($context['incoming'], 'id'));
        self::assertSame(1, $context['unseen'], 'not the whole site');
    }

    public function testARuleThatRedirectsThePageAwayIsReported(): void
    {
        $this->seedRules([
            ['id' => 'away', 'source' => '/news', 'target' => '/blog', 'target_type' => 'page'],
            ['id' => 'late', 'source' => '/late', 'target' => '/blog', 'only_if_not_found' => true],
            ['id' => 'off', 'source' => '/off', 'target' => '/blog', 'enabled' => false],
        ]);

        $outgoing = $this->service()->context('/news', null)['outgoing'];

        self::assertIsArray($outgoing);
        self::assertSame('away', $outgoing['id']);
        self::assertSame('/blog', $outgoing['location']);
        self::assertSame(301, $outgoing['status']);
        self::assertNull($this->service()->context('/late', null)['outgoing'], 'a rule for not-found requests leaves an existing page alone');
        self::assertNull($this->service()->context('/off', null)['outgoing']);
        self::assertNull($this->service()->context('/blog', null)['outgoing']);
    }

    public function testAGoneRuleOnAPageRouteIsReportedToo(): void
    {
        $this->seedRules([['id' => 'gone', 'source' => '/news', 'target' => '', 'status' => 410]]);

        $outgoing = $this->service()->context('/news', null)['outgoing'];

        self::assertIsArray($outgoing);
        self::assertSame(410, $outgoing['status']);
        self::assertSame('', $outgoing['location']);
    }

    public function testDeletedDescendantsThatWaitForADecisionAreListed(): void
    {
        $state = $this->app->services()->autoState();
        $state->addPending(new PageSnapshot('Old post', '/news/old', [PageSnapshot::ANY => '/news/old'], [new PageNode('c', [PageSnapshot::ANY => '/news/old/deep'])], [PageSnapshot::ANY => ['/news']]));
        $state->addPending(new PageSnapshot('Elsewhere', '/shop/x', [PageSnapshot::ANY => '/shop/x'], [], []));
        $state->addPending(new PageSnapshot('Lookalike', '/newsroom', [PageSnapshot::ANY => '/newsroom'], [], []));

        $pending = $this->service()->context('/news', null)['pending'];

        self::assertCount(1, $pending);
        self::assertSame('Old post', $pending[0]['title']);
        self::assertSame('/news/old', $pending[0]['route']);
        self::assertSame(1, $pending[0]['children_count']);
        self::assertSame([], $this->service()->context('/', null)['pending'], 'the home page does not own every deleted page');
    }

    public function testNotFoundHitsOnTheOldUrlsAreCountedWithoutBots(): void
    {
        $this->seedRules([
            self::auto('a', '/Blog/Old', '/news'),
            self::auto('w', '/blog/*', '/news/$1', extra: ['match_type' => 'wildcard', 'target_type' => 'route']),
            ['id' => 'q', 'source' => '/with?query=1', 'target' => '/news'],
        ]);
        $log = $this->app->services()->logStore();
        $hit = static fn (string $when, string $path, UserAgentClass $class = UserAgentClass::Browser) => new NotFoundEntry(new \DateTimeImmutable($when), $path, '', '', 'UA', $class, null, null, 'example.org', 'GET');
        $log->append($hit('2026-09-28T10:00:00+00:00', '/blog/old'));
        $log->append($hit('2026-09-29T08:00:00+00:00', '/blog/old/'));
        $log->append($hit('2026-09-29T08:30:00+00:00', '/blog/old', UserAgentClass::Bot));
        $log->append($hit('2026-07-01T08:30:00+00:00', '/blog/old'));
        $log->append($hit('2026-09-29T08:30:00+00:00', '/unrelated'));

        $found = $this->service()->context('/news', null)['not_found'];

        self::assertSame([['path' => '/Blog/Old', 'hits' => 2, 'last_seen' => '2026-09-29T08:00:00+00:00']], $found, 'case and a trailing slash do not matter (the rule ignores them), bots and hits older than 30 days are not counted');
    }

    public function testMarkingAsSeenLeavesTheRulesOfOtherPagesAlone(): void
    {
        $this->seedRules([
            self::auto('a', '/a', '/news'),
            self::auto('c', '/c', '/news/post'),
            self::auto('o', '/o', '/other'),
            self::auto('gone', '/g', '/news'),
        ]);
        $this->unseen(['a', 'c', 'o', 'gone', 'deleted-rule']);

        $done = $this->service()->markSeen('/news', null);

        self::assertSame(['cleared' => 3, 'count' => null, 'sidebar' => 1], $done);
        self::assertSame(['o', 'deleted-rule'], $this->app->services()->autoState()->unseen());
        self::assertSame(0, $this->service()->unseenCount('/news', null));
        self::assertSame(1, $this->service()->unseenCount('/other', null));
        self::assertSame(['cleared' => 0, 'count' => null, 'sidebar' => 1], $this->service()->markSeen('/news', null));
    }

    public function testTheSidebarCountAfterMarkingIsNullWhenNothingIsLeft(): void
    {
        $this->seedRules([self::auto('a', '/a', '/news')]);
        $this->unseen(['a']);

        self::assertSame(['cleared' => 1, 'count' => null, 'sidebar' => null], $this->service()->markSeen('/news', null));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badRoutes(): array
    {
        return [
            'empty' => ['', 'required'],
            'blank' => ['   ', 'required'],
            'no leading slash' => ['news', 'invalid_route'],
            'query' => ['/news?x=1', 'invalid_route'],
            'fragment' => ['/news#top', 'invalid_route'],
            'control character' => ["/ne\x01ws", 'invalid_route'],
            'invalid utf-8' => ["/ne\xC3\x28ws", 'invalid_route'],
            'too long' => ['/' . str_repeat('a', PageContextService::MAX_ROUTE_LENGTH), 'invalid_route'],
        ];
    }

    #[DataProvider('badRoutes')]
    public function testARouteThatIsNotAPageRouteIsRefused(string $route, string $code): void
    {
        try {
            $this->service()->context($route, null);
            self::fail('Nothing was thrown.');
        } catch (InvalidInputException $e) {
            self::assertSame('route', $e->field);
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testRoutesAreNormalized(): void
    {
        self::assertSame('/a/b', PageContextService::normalizeRoute(' //a//b/ '));
        self::assertSame('/', PageContextService::normalizeRoute('/'));
        self::assertSame('/', PageContextService::normalizeRoute('///'));
        self::assertSame('/über uns', PageContextService::normalizeRoute('/über uns'));
    }

    public function testTheListsAreCapped(): void
    {
        $rows = [];
        for ($i = 0; $i < PageContextService::MAX_ROWS + 5; ++$i) {
            $rows[] = self::auto('r' . $i, '/old-' . $i, '/news');
        }
        $this->seedRules($rows);

        $context = $this->service()->context('/news', null);

        self::assertCount(PageContextService::MAX_ROWS, $context['incoming']);
        self::assertSame(PageContextService::MAX_ROWS + 5, $context['incoming_total']);
        self::assertCount(PageContextService::MAX_ROWS, $context['created']);
        self::assertSame(PageContextService::MAX_ROWS + 5, $context['created_total']);
    }

    public function testAnEmptySiteHasNothingToSay(): void
    {
        $context = $this->service()->context('/news', 'en');

        self::assertSame([], $context['incoming']);
        self::assertSame([], $context['created']);
        self::assertSame(0, $context['unseen']);
        self::assertNull($context['outgoing']);
        self::assertSame([], $context['pending']);
        self::assertSame([], $context['not_found']);
        self::assertSame(self::NOW, $context['generated_at']);
        self::assertSame(0, $this->service()->unseenCount('/news', 'en'));
    }
}
