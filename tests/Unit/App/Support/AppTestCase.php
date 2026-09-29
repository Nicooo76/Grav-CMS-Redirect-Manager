<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App\Support;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\App\RedirectService;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for tests of the application services: a temp data and cache dir, a fixed clock (2026-09-29 10:00 UTC),
 * a RedirectService without Grav, and a recorder for onRedirectRuleSaved and friends.
 */
abstract class AppTestCase extends TestCase
{
    use TempDirTrait;

    protected FixedClock $clock;
    protected RedirectService $app;
    protected InMemoryConfigWriter $configWriter;

    /** @var list<array{name: string, payload: array<string, mixed>}> */
    protected array $events = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->events = [];
        $this->configWriter = new InMemoryConfigWriter();
        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed> $config plugin config (plugins.redirect-manager)
     * @param list<PageInfo>|null  $pages  pages for the page index (null = empty index)
     */
    protected function makeApp(array $config = [], ?array $pages = null, ?SiteContext $site = null, ?HttpClientInterface $http = null): RedirectService
    {
        $site ??= new SiteContext(baseUrl: 'http://localhost:8080', languages: [], redirectDefaultCode: 301);
        $index = new PageIndex($pages ?? []);
        $factory = new ServiceFactory(
            $config,
            ['languages' => $site->languages, 'default_language' => $site->defaultLanguage ?? ''],
            $this->tmp . '/data',
            $this->tmp . '/cache',
            null,
            $this->clock,
            static fn (): PageIndex => $index,
        );
        $events = new RuleEvents(function (string $name, array $payload): array {
            $this->events[] = ['name' => $name, 'payload' => $payload];

            return $payload;
        });

        return new RedirectService($factory, $site, $this->configWriter, $events, $http);
    }

    /**
     * Stores rules directly (serialized field names), bypassing validation.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function seedRules(array $rows): void
    {
        $this->app->services()->repository()->saveAll(array_map(static fn (array $row): Rule => Rule::fromArray($row), $rows));
    }

    /**
     * @return list<string> names of the events fired so far
     */
    protected function eventNames(): array
    {
        return array_map(static fn (array $e): string => $e['name'], $this->events);
    }
}
