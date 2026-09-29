<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Jobs;

use Grav\Plugin\RedirectManager\Jobs\SuggestionRefresher;
use Grav\Plugin\RedirectManager\NotFound\ResolvedPaths;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SuggestionRefresher::class)]
#[Group('jobs')]
final class SuggestionRefresherTest extends JobsTestCase
{
    private SuggestionStore $store;
    private ResolvedPaths $resolved;
    private int $built = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SuggestionStore($this->tmp . '/suggestions.json', $this->clock);
        $this->resolved = new ResolvedPaths($this->tmp . '/404-state.json');
        $this->built = 0;
    }

    private function refresher(?\Closure $hasRule = null, float $minScore = 0.5): SuggestionRefresher
    {
        return new SuggestionRefresher(
            $this->log,
            $this->store,
            function (): Suggester {
                ++$this->built;

                return new Suggester(PageTreeFixture::index());
            },
            $hasRule ?? static fn (string $path, ?string $language): bool => false,
            $this->resolved,
            $this->clock,
            $minScore,
        );
    }

    public function testStoresASuggestionForAPathWithEnoughHits(): void
    {
        $this->hits('/about-us', 3);

        $changed = $this->refresher()->refresh();

        self::assertCount(1, $changed);
        self::assertSame('/about-us', $changed[0]['path']);
        self::assertSame('/about', $changed[0]['target']);
        self::assertSame(SuggestionStore::SOURCE_NOT_FOUND, $changed[0]['source']);
        self::assertCount(1, $this->store->open());
    }

    public function testPathsBelowThreeHitsAreIgnoredAndTheIndexIsNotBuilt(): void
    {
        $this->hits('/about-us', 2);

        self::assertSame([], $this->refresher()->refresh());
        self::assertSame(0, $this->built, 'the page index is only built when there is work');
    }

    public function testHitsOlderThanSevenDaysDoNotCount(): void
    {
        $this->hits('/about-us', 5, '2026-09-10T11:00:00+00:00');

        self::assertSame([], $this->refresher()->refresh());
    }

    public function testPathsThatARuleHandlesAreSkipped(): void
    {
        $this->hits('/about-us', 4);
        $this->hits('/contact-us', 4);

        $changed = $this->refresher(static fn (string $path, ?string $language): bool => $path === '/about-us')->refresh();

        self::assertSame(['/contact-us'], array_column($changed, 'path'));
    }

    public function testResolvedPathsAreSkipped(): void
    {
        $this->hits('/about-us', 4);
        $this->resolved->markResolved('/about-us', new \DateTimeImmutable('2026-09-29T11:30:00+00:00'));

        self::assertSame([], $this->refresher()->refresh());
    }

    public function testWeakSuggestionsAreNotStored(): void
    {
        $this->hits('/abut', 4); // 0.52 similar_route

        self::assertSame([], $this->refresher(minScore: 0.9)->refresh());
    }

    public function testASecondRunReportsNothingNew(): void
    {
        $this->hits('/about-us', 4);
        $refresher = $this->refresher();

        self::assertCount(1, $refresher->refresh());
        self::assertSame([], $refresher->refresh());
    }

    public function testRejectedSuggestionsStayRejected(): void
    {
        $this->hits('/about-us', 4);
        $record = $this->refresher()->refresh()[0];
        $this->store->reject($record['id']);

        self::assertSame([], $this->refresher()->refresh());
    }

    public function testBotsAreIgnored(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->log->append(new \Grav\Plugin\RedirectManager\NotFound\NotFoundEntry(new \DateTimeImmutable('2026-09-29T11:00:00+00:00'), '/about-us', '', '', 'Googlebot', \Grav\Plugin\RedirectManager\NotFound\UserAgentClass::Bot, null, 'en', 'example.org'));
        }

        self::assertSame([], $this->refresher()->refresh());
    }
}
