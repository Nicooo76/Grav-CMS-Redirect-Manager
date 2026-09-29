<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\App\StatsService;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(StatsService::class)]
#[Group('app')]
final class StatsServiceTest extends AppTestCase
{
    private function hit404(string $when, UserAgentClass $class = UserAgentClass::Browser, string $path = '/gone'): void
    {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify($when),
            $path,
            uaClass: $class,
            language: 'de',
            host: 'example.org',
        ));
    }

    /** Writes rule hits for a day ("YYYY-MM-DD") the way HitRecorder does; one id per hit. */
    private function ruleHits(string $day, string ...$ruleIds): void
    {
        $dir = $this->tmp . '/data/hits';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $day . '.log', implode("\n", $ruleIds) . "\n", FILE_APPEND);
    }

    private function check(string $ruleId, ?string $error, ?int $status = null): TargetCheckResult
    {
        return new TargetCheckResult(
            $ruleId,
            'https://example.org/target',
            $status,
            $error === null && $status !== null && $status < 400,
            $error,
            null,
            0,
            10,
            new DateTimeImmutable('2026-09-29T09:00:00+00:00'),
        );
    }

    // ---------------------------------------------------------------- dashboard

    public function testDashboardOfAnEmptySiteIsAllZero(): void
    {
        $dashboard = $this->app->stats()->dashboard();

        self::assertSame(
            [
                'not_found_today', 'not_found_7d', 'not_found_by_day', 'hits_today', 'hits_7d', 'hits_by_day',
                'rules_total', 'rules_active', 'open_suggestions', 'dead_targets', 'pending_deletes',
            ],
            array_keys($dashboard),
        );
        foreach ($dashboard as $key => $value) {
            if (is_array($value)) {
                self::assertCount(30, $value, $key);
                self::assertSame(0, array_sum($value), $key);
            } else {
                self::assertSame(0, $value, $key);
            }
        }
    }

    public function testByDayMapsCoverThirtyDaysOldestFirst(): void
    {
        $dashboard = $this->app->stats()->dashboard();

        foreach (['not_found_by_day', 'hits_by_day'] as $key) {
            $keys = array_keys($dashboard[$key]);
            self::assertSame('2026-08-31', $keys[0]);
            self::assertSame('2026-09-29', $keys[29]);
        }
    }

    public function testNotFoundNumbersCountTodayAndTheLastSevenDaysWithoutBots(): void
    {
        $this->hit404('-1 hour');
        $this->hit404('-2 hours');
        $this->hit404('-3 hours', UserAgentClass::Bot);
        $this->hit404('-6 days');           // 2026-09-23: still inside the 7 days
        $this->hit404('-7 days');           // 2026-09-22: outside
        $this->hit404('-40 days');          // outside the 30 days
        $this->hit404('-1 day', UserAgentClass::Monitoring);

        $dashboard = $this->app->stats()->dashboard();

        self::assertSame(2, $dashboard['not_found_today']);
        self::assertSame(4, $dashboard['not_found_7d']);
        self::assertSame(2, $dashboard['not_found_by_day']['2026-09-29']);
        self::assertSame(1, $dashboard['not_found_by_day']['2026-09-28']);
        self::assertSame(1, $dashboard['not_found_by_day']['2026-09-23']);
        self::assertSame(1, $dashboard['not_found_by_day']['2026-09-22']);
        self::assertSame(5, array_sum($dashboard['not_found_by_day']));
    }

    public function testHitNumbersComeFromTheRuleStatistics(): void
    {
        $this->ruleHits('2026-09-29', 'a', 'a', 'b');
        $this->ruleHits('2026-09-28', 'a');
        $this->ruleHits('2026-09-23', 'b');    // inside the 7 days
        $this->ruleHits('2026-09-20', 'b');    // outside

        $dashboard = $this->app->stats()->dashboard();

        self::assertSame(3, $dashboard['hits_today']);
        self::assertSame(5, $dashboard['hits_7d']);
        self::assertSame(3, $dashboard['hits_by_day']['2026-09-29']);
        self::assertSame(1, $dashboard['hits_by_day']['2026-09-28']);
        self::assertSame(0, $dashboard['hits_by_day']['2026-09-27']);
        self::assertSame(1, $dashboard['hits_by_day']['2026-09-20']);
    }

    public function testRulesTotalAndActive(): void
    {
        $this->seedRules([
            ['id' => 'on', 'source' => '/a', 'target' => '/x'],
            ['id' => 'off', 'source' => '/b', 'target' => '/x', 'enabled' => false],
            ['id' => 'expired', 'source' => '/c', 'target' => '/x', 'expires_at' => '2026-09-01T00:00:00+00:00'],
            ['id' => 'later', 'source' => '/d', 'target' => '/x', 'active_from' => '2026-10-01T00:00:00+00:00'],
            ['id' => 'ok2', 'source' => '/e', 'target' => '/x', 'expires_at' => '2026-12-01T00:00:00+00:00'],
        ]);

        $dashboard = $this->app->stats()->dashboard();

        self::assertSame(5, $dashboard['rules_total']);
        self::assertSame(2, $dashboard['rules_active']);
    }

    public function testOpenSuggestionsAreCounted(): void
    {
        $store = $this->app->services()->suggestionStore();
        $record = $store->upsertOpen('/a', new Suggestion('/x', 0.9, SuggestionReason::SameSlug));
        $store->upsertOpen('/b', new Suggestion('/y', 0.8, SuggestionReason::SimilarRoute));
        $store->upsertOpen('/c', new Suggestion('/z', 0.7, SuggestionReason::TitleMatch));
        self::assertNotNull($record);
        $store->reject($record['id']);

        self::assertSame(2, $this->app->stats()->dashboard()['open_suggestions']);
    }

    public function testDeadTargetsCountOnlyExistingEnabledRules(): void
    {
        $this->seedRules([
            ['id' => 'dead', 'source' => '/a', 'target' => 'https://example.org/1', 'target_type' => 'url'],
            ['id' => 'dead2', 'source' => '/b', 'target' => 'https://example.org/2', 'target_type' => 'url'],
            ['id' => 'alive', 'source' => '/c', 'target' => 'https://example.org/3', 'target_type' => 'url'],
            ['id' => 'off', 'source' => '/d', 'target' => 'https://example.org/4', 'target_type' => 'url', 'enabled' => false],
        ]);
        $this->app->services()->checkResultStore()->save([
            $this->check('dead', TargetCheckResult::ERROR_TIMEOUT),
            $this->check('dead2', null, 404),
            $this->check('alive', null, 200),
            $this->check('off', TargetCheckResult::ERROR_DNS),
            $this->check('deleted-rule', TargetCheckResult::ERROR_DNS),
        ]);

        self::assertSame(2, $this->app->stats()->dashboard()['dead_targets']);
    }

    public function testPendingDeletesComeFromTheClosure(): void
    {
        $service = new StatsService(
            $this->app->services(),
            $this->app->rules(),
            $this->app->statsAccess(),
            static fn (): int => 4,
        );

        self::assertSame(0, $this->app->stats()->dashboard()['pending_deletes'], 'no closure means 0');
        self::assertSame(4, $service->dashboard()['pending_deletes']);
    }

    // ---------------------------------------------------------------- pages

    /**
     * @return list<string>
     */
    private function routes(?string $q, ?string $language = null, int $limit = 20): array
    {
        return array_column($this->app->stats()->pages($q, $language, $limit), 'route');
    }

    private function withPages(): void
    {
        $this->app = $this->makeApp([], PageTreeFixture::pages());
    }

    public function testPagesWithoutQueryListTheFirstPagesByRoute(): void
    {
        $this->withPages();

        $pages = $this->app->stats()->pages(null, 'en', 5);

        self::assertSame(['/', '/about', '/blog', '/blog/grav-tips-and-tricks', '/blog/hello-world'], array_column($pages, 'route'));
        self::assertSame(['/', '/about', '/blog'], $this->routes('', 'en', 3));
    }

    public function testPageRowShape(): void
    {
        $this->withPages();

        $rows = $this->app->stats()->pages('about', 'en');

        self::assertSame(
            ['route' => '/about', 'title' => 'About us', 'language' => 'en', 'translations' => ['de']],
            $rows[0],
        );
    }

    public function testLimitIsClamped(): void
    {
        $this->app = $this->makeApp([], array_map(
            static fn (int $i): PageInfo => new PageInfo(route: '/page-' . $i, title: 'Page ' . $i, language: 'en'),
            range(1, 150),
        ));

        self::assertCount(1, $this->app->stats()->pages(null, null, 0));
        self::assertCount(1, $this->app->stats()->pages(null, null, -5));
        self::assertCount(100, $this->app->stats()->pages(null, null, 5000));
        self::assertCount(20, $this->app->stats()->pages(null, null));
    }

    public function testQueryRanksRouteStartBeforeTitleStartBeforeContains(): void
    {
        $this->app = $this->makeApp([], [
            new PageInfo(route: '/zz/guide', title: 'Contact guide', language: 'en'),
            new PageInfo(route: '/contact-us', title: 'Get in touch', language: 'en'),
            new PageInfo(route: '/a/b/contact', title: 'Nothing', language: 'en'),
            new PageInfo(route: '/contact', title: 'Contact', language: 'en'),
            new PageInfo(route: '/z', title: 'Contactless payment', language: 'en'),
            new PageInfo(route: '/unrelated', title: 'Other', language: 'en'),
        ]);

        // route starts with the query (shorter first), then title starts with it, then substring matches
        self::assertSame(
            ['/contact', '/contact-us', '/z', '/zz/guide', '/a/b/contact'],
            $this->routes('contact'),
        );
        self::assertSame(['/contact', '/contact-us', '/a/b/contact'], $this->routes('/contact'), 'a leading slash anchors on the route');
    }

    public function testQueryMatchesAsciiFoldedTitlesAndRoutes(): void
    {
        $this->withPages();

        self::assertContains('/team/jan-mueller', $this->routes('Müller'));
        self::assertContains('/team/jan-mueller', $this->routes('mueller'));
        self::assertContains('/ueber-uns', $this->routes('über'));
        self::assertContains('/ueber-uns', $this->routes('UEBER'));
        self::assertSame([], $this->routes('no-such-page-anywhere'));
    }

    public function testLanguageFilterKeepsThatLanguageAndLanguageLessPages(): void
    {
        $this->app = $this->makeApp([], [
            new PageInfo(route: '/en-page', title: 'English', language: 'en'),
            new PageInfo(route: '/de-seite', title: 'Deutsch', language: 'de'),
            new PageInfo(route: '/shared', title: 'Shared'),
        ]);

        self::assertSame(['/de-seite', '/en-page', '/shared'], $this->routes(null));
        self::assertSame(['/en-page', '/shared'], $this->routes(null, 'en'));
        self::assertSame(['/de-seite', '/shared'], $this->routes('', 'DE'));
        self::assertSame(['/shared'], $this->routes('shared', 'de'));
        self::assertSame([], $this->routes('en-page', 'de'));
        self::assertSame('', $this->app->stats()->pages('shared', null)[0]['language']);
    }

    public function testPagesOfAnEmptyIndexAreEmpty(): void
    {
        self::assertSame([], $this->app->stats()->pages('x', null));
        self::assertSame([], $this->app->stats()->pages(null, null));
    }

    public function testUnpublishedAndNonRoutablePagesAreNotOffered(): void
    {
        $this->withPages();

        self::assertNotContains('/blog/draft-post', $this->routes('draft'));
        self::assertNotContains('/blog/hidden', $this->routes('hidden'));
    }
}
