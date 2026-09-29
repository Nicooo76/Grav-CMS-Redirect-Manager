<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Pages;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\ArrayLogger;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use RuntimeException;

#[CoversClass(GravBootstrap::class)]
#[Group('grav')]
final class GravBootstrapTest extends GravTestCase
{
    use TempDirTrait;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function grav(array $config = [], ?object $uri = null): Grav
    {
        $grav = new Grav();
        $grav['config'] = new Config($config);
        $grav['locator'] = new UniformResourceLocator([
            'cache://' => $this->tmp . '/cache/',
            'user://data' => $this->tmp . '/user/data/',
        ]);
        $grav['log'] = new ArrayLogger();
        $grav['pages'] = new Pages();
        $grav['language'] = new Language(['en'], 'en', 'en');
        if ($uri !== null) {
            $grav['uri'] = $uri;
        }

        return $grav;
    }

    private static function uri(string|false $root): object
    {
        return new class ($root) {
            public function __construct(private readonly string|false $root)
            {
            }

            public function rootUrl(bool $include_host = false): string|false
            {
                return $this->root;
            }
        };
    }

    public function testFactoryReadsPluginConfigAndGravSettings(): void
    {
        $grav = $this->grav([
            'plugins' => [
                'redirect-manager' => ['redirects' => ['max_chain_depth' => 3], 'base_url' => 'https://plugin.example/'],
                'api' => ['route' => '/rest'],
                'admin2' => ['route' => '/backend'],
            ],
            'system' => [
                'custom_base_url' => 'https://custom.example',
                'languages' => ['supported' => ['en', ' de ', '', 5, ['x']], 'default_lang' => 'de'],
            ],
            'site' => ['title' => 'Demo'],
        ]);

        $factory = GravBootstrap::factory($grav);

        self::assertSame(3, $factory->int('redirects.max_chain_depth', 10));
        self::assertSame($this->tmp . '/user/data/redirect-manager', $factory->dataDir());
        self::assertSame($this->tmp . '/cache/redirect-manager', $factory->cacheDir());
        self::assertSame(['en', 'de', '5'], $factory->languages());
        self::assertSame('https://plugin.example', $factory->siteUrl());
        self::assertSame('Demo', $factory->siteTitle());
        self::assertSame('de', $factory->matcherOptions()->defaultLanguage);
        self::assertSame($grav['log'], $this->loggerOf($factory));
    }

    private function loggerOf(ServiceFactory $factory): mixed
    {
        // the logger is only observable through its effect: an unavailable sqlite backend logs a warning
        return (new \ReflectionProperty($factory, 'logger'))->getValue($factory);
    }

    public function testFactoryDerivesTheDefaultLanguageFromTheFirstSupportedOne(): void
    {
        $factory = GravBootstrap::factory($this->grav(['system' => ['languages' => ['supported' => ['fr', 'en']]]]));
        self::assertSame('fr', $factory->matcherOptions()->defaultLanguage);

        $none = GravBootstrap::factory($this->grav());
        self::assertNull($none->matcherOptions()->defaultLanguage);
        self::assertSame([], $none->languages());
    }

    public function testFactoryPageIndexProviderPreparesThePagesBeforeBuildingTheIndex(): void
    {
        $grav = $this->grav();
        // Only the two calls the provider itself makes are under test; the index builder has its own tests. Any Grav
        // page-tree method the stub lacks ends the run with a sentinel, so this stays independent of the builder.
        $pages = new class () extends Pages {
            public int $inits = 0;

            public function init(): void
            {
                ++$this->inits;
            }

            /** @param array<mixed> $arguments */
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('builder reached: ' . $name);
            }
        };
        $grav['pages'] = $pages;
        $factory = GravBootstrap::factory($grav);
        self::assertFalse($pages->enabled);

        try {
            self::assertInstanceOf(PageIndex::class, $factory->pageIndex());
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('builder reached: ', $e->getMessage());
        }

        self::assertTrue($pages->enabled, 'API and CLI requests start without a page tree');
        self::assertSame(1, $pages->inits);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?object, string}>
     */
    public static function baseUrlCases(): iterable
    {
        yield 'plugin base_url wins' => [
            ['plugins' => ['redirect-manager' => ['base_url' => ' https://a.example// ']], 'system' => ['custom_base_url' => 'https://b.example']],
            self::uri('https://c.example'),
            'https://a.example',
        ];
        yield 'custom_base_url next' => [
            ['system' => ['custom_base_url' => 'https://b.example/']],
            self::uri('https://c.example'),
            'https://b.example',
        ];
        yield 'uri root url' => [[], self::uri('https://c.example/sub/'), 'https://c.example/sub'];
        yield 'empty root url' => [[], self::uri(''), 'http://localhost'];
        yield 'no uri service' => [[], null, 'http://localhost'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('baseUrlCases')]
    public function testBaseUrlFallbackChain(array $config, ?object $uri, string $expected): void
    {
        $grav = $this->grav($config, $uri);

        self::assertSame($expected, GravBootstrap::siteContext($grav)->baseUrl);
        self::assertSame($expected, GravBootstrap::factory($grav)->siteUrl());
    }

    public function testBaseUrlFallsBackToLocalhostWhenTheUriServiceThrows(): void
    {
        $uri = new class () {
            public function rootUrl(bool $include_host = false): string
            {
                throw new RuntimeException('no request');
            }
        };

        self::assertSame('http://localhost', GravBootstrap::siteContext($this->grav([], $uri))->baseUrl);
    }

    public function testSiteContext(): void
    {
        $_SERVER = ['HTTP_HOST' => 'example.org'];
        $grav = $this->grav([
            'system' => [
                'languages' => ['supported' => ['de', 'en'], 'default_lang' => 'en'],
                'pages' => ['redirect_default_code' => '302', 'redirect_trailing_slash' => 1, 'other' => 'x', 7 => 'y'],
            ],
            'site' => [
                'redirects' => ['/a' => '/b', '/n' => 5, '/skip' => ['nested']],
                'routes' => ['/x' => '/y'],
            ],
        ], self::uri('https://example.org'));

        $site = GravBootstrap::siteContext($grav);

        self::assertInstanceOf(SiteContext::class, $site);
        self::assertSame(['HTTP_HOST' => 'example.org'], $site->server);
        self::assertSame('https://example.org', $site->baseUrl);
        self::assertSame(['/a' => '/b', '/n' => '5'], $site->siteRedirects);
        self::assertSame(['/x' => '/y'], $site->siteRoutes);
        self::assertSame(['redirect_default_code' => '302', 'redirect_trailing_slash' => 1], $site->pageSettings);
        self::assertSame(302, $site->redirectDefaultCode);
        self::assertSame(['de', 'en'], $site->languages);
        self::assertSame('en', $site->defaultLanguage);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function defaultCodes(): iterable
    {
        yield 'unset' => [null, 301];
        yield 'numeric string' => ['307', 307];
        yield 'int' => [302, 302];
        yield 'garbage' => ['permanent', 301];
    }

    #[DataProvider('defaultCodes')]
    public function testSiteContextDefaultCode(mixed $value, int $expected): void
    {
        $pages = $value === null ? [] : ['redirect_default_code' => $value];
        $site = GravBootstrap::siteContext($this->grav(['system' => ['pages' => $pages]]));
        self::assertSame($expected, $site->redirectDefaultCode);
    }

    public function testSiteContextDefaultLanguageFallsBackToTheFirstSupportedOne(): void
    {
        $site = GravBootstrap::siteContext($this->grav(['system' => ['languages' => ['supported' => ['fr', 'en']]]]));
        self::assertSame('fr', $site->defaultLanguage);

        $none = GravBootstrap::siteContext($this->grav());
        self::assertNull($none->defaultLanguage);
        self::assertSame([], $none->languages);
        self::assertSame([], $none->siteRedirects, 'a missing map is empty');
    }

    public function testSiteContextIgnoresANonArrayMap(): void
    {
        $site = GravBootstrap::siteContext($this->grav(['site' => ['redirects' => 'oops', 'routes' => null]]));
        self::assertSame([], $site->siteRedirects);
        self::assertSame([], $site->siteRoutes);
    }

    public function testServiceBuildsTheApplicationServiceOverASharedFactory(): void
    {
        $grav = $this->grav(['plugins' => ['redirect-manager' => ['base_url' => 'https://plugin.example']]]);
        $factory = GravBootstrap::factory($grav);

        $service = GravBootstrap::service($grav, $factory);

        self::assertSame($factory, $service->services());
        self::assertSame('https://plugin.example', $service->site()->baseUrl);
        self::assertSame(0, $service->stats()->dashboard()['pending_deletes']);

        $factory->repository()->saveAll([new Rule('a', '/old', '/new')]);
        self::assertSame(1, count($service->rules()->all()));
    }

    public function testServiceBuildsItsOwnFactoryAndAppliesAnExplicitBaseUrl(): void
    {
        $grav = $this->grav(['plugins' => ['redirect-manager' => ['base_url' => 'https://plugin.example']]]);

        $own = GravBootstrap::service($grav);
        self::assertSame($this->tmp . '/user/data/redirect-manager', $own->services()->dataDir());
        self::assertSame('https://plugin.example', $own->site()->baseUrl);

        $site = GravBootstrap::service($grav, null, '  https://cli.example/  ')->site();
        self::assertSame('https://cli.example', $site->baseUrl);
        self::assertSame($_SERVER, $site->server, 'the rest of the site context is kept');

        self::assertSame('https://plugin.example', GravBootstrap::service($grav, null, '   ')->site()->baseUrl);
    }

    public function testServicePendingDeleteCounterIsZeroForAnUnreadableStateFile(): void
    {
        $grav = $this->grav();
        $factory = GravBootstrap::factory($grav);
        // AutoState already treats an unreadable file as empty; the counter's own catch block (a Throwable from
        // autoState()) cannot be provoked, because ServiceFactory is final and AutoState never throws
        mkdir($factory->dataDir() . '/auto-state.json', 0775, true);

        $service = GravBootstrap::service($grav, $factory);

        self::assertSame(0, $service->stats()->dashboard()['pending_deletes']);
    }
}
