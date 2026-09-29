<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\RedirectService;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(RedirectService::class)]
#[Group('app')]
final class RedirectServiceTest extends AppTestCase
{
    public function testAccessorsHandOutTheSameInstanceEveryTime(): void
    {
        self::assertSame($this->app->rules(), $this->app->rules());
        self::assertSame($this->app->tester(), $this->app->tester());
        self::assertSame($this->app->notFound(), $this->app->notFound());
        self::assertSame($this->app->suggestions(), $this->app->suggestions());
        self::assertSame($this->app->importExport(), $this->app->importExport());
        self::assertSame($this->app->checks(), $this->app->checks());
        self::assertSame($this->app->stats(), $this->app->stats());
        self::assertSame($this->app->statsAccess(), $this->app->statsAccess());
        self::assertSame($this->app->services(), $this->app->services());
    }

    public function testSiteContextIsTheOneGivenAtConstruction(): void
    {
        $site = new SiteContext(baseUrl: 'https://example.org');
        $app = $this->makeApp([], null, $site);

        self::assertSame($site, $app->site());
        self::assertSame('example.org', $app->site()->host());
    }

    public function testRuleServiceWorksOnTheSharedStorage(): void
    {
        $this->app->rules()->create(['source' => '/a', 'target' => '/b']);

        self::assertCount(1, $this->app->services()->repository()->all());
        self::assertSame('/a', $this->app->rules()->all()[0]->source);
    }

    public function testEventsDefaultToANoOpDispatcher(): void
    {
        $service = new RedirectService($this->app->services());
        $events = $service->events();

        $events->saved(Rule::fromArray(['source' => '/a', 'target' => '/b']), null, 'create');
        $events->suggestionCreated(['id' => 's1']);
        $events->notFoundLogged(['path' => '/x']);
        $payload = $events->matched(['result' => 'r']);

        self::assertSame(['result' => 'r', 'cancel' => false], $payload);
        self::assertSame([], $this->events);
    }

    public function testConfiguredEventsAreReturned(): void
    {
        self::assertSame($this->app->events(), $this->app->events());
        $this->app->rules()->create(['source' => '/a', 'target' => '/b']);

        self::assertSame(['onRedirectRuleSaved'], $this->eventNames());
    }

    public function testRebuildCacheReturnsTheRuleCount(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/b'],
            ['id' => 'r2', 'source' => '/c', 'target' => '/d'],
            ['id' => 'r3', 'source' => '/e', 'status' => 410],
        ]);

        self::assertSame(3, $this->app->rebuildCache());
        self::assertFileExists($this->app->services()->compiledCache()->cacheFile());
    }

    public function testRebuildCacheOfAnEmptyRuleSetIsZero(): void
    {
        self::assertSame(0, $this->app->rebuildCache());
    }

    public function testRebuildCachePicksUpRulesWrittenBehindTheServicesBack(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/b']]);
        self::assertSame(1, $this->app->rebuildCache());

        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/b'], ['id' => 'r2', 'source' => '/c', 'target' => '/d']]);

        self::assertSame(2, $this->app->rebuildCache());
    }

    public function testRebuildCacheDropsAnalysisFiles(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/b']]);
        $this->app->rules()->analysis(); // writes analysis-<revision>.php
        $dir = $this->app->services()->cacheDir();
        file_put_contents($dir . '/analysis-stale.php', '<?php return [];');
        self::assertNotSame([], glob($dir . '/analysis-*.php'));

        $this->app->rebuildCache();

        self::assertSame([], glob($dir . '/analysis-*.php'));
    }
}
