<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\RedirectManager\Grav\AdminIntegration;
use Grav\Plugin\RedirectManager\Grav\RouteRegistrar;
use Grav\Plugin\RedirectManager\Grav\SchedulerJobs;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Grav\TwigIntegration;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\ArrayLogger;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\RouteCollector;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use RocketTheme\Toolbox\Event\Event;
use RuntimeException;
use Twig\Extension\AbstractExtension;

/**
 * The classes behind the plugin file's event methods (RouteRegistrar, AdminIntegration, TwigIntegration and the
 * guarded scheduler registration). redirect-manager.php itself only forwards to them, see PluginLayoutTest.
 */
#[CoversClass(RouteRegistrar::class)]
#[CoversClass(AdminIntegration::class)]
#[CoversClass(TwigIntegration::class)]
#[CoversClass(SchedulerJobs::class)]
#[Group('grav')]
final class PluginWiringTest extends GravTestCase
{
    use TempDirTrait;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function services(): ServiceFactory
    {
        return new ServiceFactory([], [], $this->tmp . '/data', $this->tmp . '/cache');
    }

    // ---- routes ----------------------------------------------------------------------------------------

    public function testTheRegistrarsRegisterEveryRouteOnceAndAutoRoutesGoLast(): void
    {
        $routes = new RouteCollector();
        RouteRegistrar::register($routes);
        RouteRegistrar::registerAuto($routes);

        $keys = array_keys($routes->routes);
        self::assertCount(43, $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no route twice');
        self::assertSame(
            [
                'GET /redirects/pending', 'POST /redirects/pending/{id}/resolve', 'GET /redirects/badge', 'POST /redirects/badge/seen',
                'GET /redirects/page-context', 'GET /redirects/page-context/badge', 'POST /redirects/page-context/seen',
            ],
            array_slice($keys, -7),
        );
        self::assertSame(['GET /redirects/rules', 'POST /redirects/rules'], array_slice($keys, 0, 2));
        foreach ($routes->routes as $key => [$class, $method]) {
            self::assertTrue(method_exists($class, $method), $key . ' points to ' . $class . '::' . $method);
        }
    }

    // ---- admin -----------------------------------------------------------------------------------------

    private function admin(?Language $language = null): AdminIntegration
    {
        $grav = new Grav();
        $grav['language'] = new class ($language) {
            public function __construct(private readonly ?Language $language)
            {
            }

            /**
             * @param list<string> $args
             *
             * @return string|list<string>
             */
            public function translate(array $args): string|array
            {
                return $this->language !== null ? 'Umleitungen' : $args[0];
            }
        };

        return new AdminIntegration($grav, dirname(__DIR__, 3));
    }

    public function testTheSidebarEntryIsAppendedAndKeepsWhatIsThere(): void
    {
        $event = new Event(['items' => [['id' => 'other']]]);
        $this->admin()->sidebarItems($event);

        $items = $event['items'];
        self::assertSame('other', $items[0]['id']);
        self::assertSame('redirect-manager', $items[1]['id']);
        self::assertSame('/plugin/redirect-manager', $items[1]['route']);
        self::assertSame('/redirects/badge', $items[1]['badgeEndpoint']);
        self::assertContains('api.redirects.read', $items[1]['authorize']);
    }

    public function testThePageDefinitionIsOnlyAnsweredForThisPlugin(): void
    {
        $other = new Event(['plugin' => 'other']);
        $this->admin()->pluginPageInfo($other);
        self::assertNull($other['definition']);

        $own = new Event(['plugin' => 'redirect-manager']);
        $this->admin(new Language())->pluginPageInfo($own);
        self::assertSame('Umleitungen', $own['definition']['title'], 'the translated title');
        self::assertSame('component', $own['definition']['page_type']);

        $untranslated = new Event(['plugin' => 'redirect-manager']);
        $this->admin()->pluginPageInfo($untranslated);
        self::assertSame('Redirects', $untranslated['definition']['title'], 'the language service returned the key itself');
    }

    public function testTheWidgetNeedsOnlyTheReadPermissionAsAString(): void
    {
        $event = new Event();
        $this->admin()->dashboardWidgets($event);
        $widget = $event['widgets'][0];
        self::assertSame('redirect-manager.overview', $widget['id']);
        self::assertSame('api.redirects.read', $widget['authorize'], 'a string: an array ends in a 500 for non-super admins');
        self::assertSame('/redirects/stats', $widget['dataEndpoint']);
        self::assertSame('Redirects overview', $widget['label']);
    }

    public function testTheContextPanelIsForPagesNeedsOnlyTheReadPermissionAsAStringAndHasABadge(): void
    {
        $event = new Event(['panels' => [['id' => 'other']]]);
        $this->admin(new Language())->contextPanels($event);

        self::assertSame('other', $event['panels'][0]['id']);
        $panel = $event['panels'][1];
        self::assertSame('redirect-manager', $panel['id']);
        self::assertSame('redirect-manager', $panel['plugin'], 'the script comes from admin-next/panels/<plugin>.js');
        self::assertSame(['pages'], $panel['contexts']);
        self::assertSame('api.redirects.read', $panel['authorize'], 'a string, like the widget');
        self::assertSame('/redirects/page-context/badge', $panel['badgeEndpoint']);
        self::assertSame('route', $panel['icon'], 'a Lucide name');
        self::assertSame('Umleitungen', $panel['label'], 'translated here, Admin 2 shows it verbatim');
        self::assertIsInt($panel['width']);

        $untranslated = new Event();
        $this->admin()->contextPanels($untranslated);
        self::assertSame('Redirects for this page', $untranslated['panels'][0]['label']);
    }

    public function testMcpToolsAreRegisteredFromTheManifestInConfig(): void
    {
        $collector = new class () {
            /** @var list<string> */
            public array $plugins = [];

            /** @var list<string> */
            public array $tools = [];

            /** @var list<string> */
            public array $warnings = [];

            public function registerPlugin(string $slug, ?string $prefix): void
            {
                $this->plugins[] = $slug . ':' . ($prefix ?? '');
            }

            /** @param array<string, mixed> $tool */
            public function add(string $slug, array $tool, int $version): void
            {
                $this->tools[] = (string) $tool['name'];
            }

            public function warn(string $slug, string $message): void
            {
                $this->warnings[] = $message;
            }
        };
        $this->admin()->registerMcpTools(new Event(['tools' => $collector]));

        self::assertSame([], $collector->warnings);
        self::assertCount(1, $collector->plugins);
        self::assertNotSame([], $collector->tools);
    }

    // ---- twig ------------------------------------------------------------------------------------------

    public function testTwigTemplatePathsGetThePluginTemplatesAppended(): void
    {
        $grav = new Grav();
        $twig = new class () {
            /** @var list<string> */
            public array $twig_paths = ['/theme/templates'];
        };
        $grav['twig'] = $twig;

        (new TwigIntegration($grav, $this->services(...), '/plugins/redirect-manager'))->templatePaths();

        self::assertSame(['/theme/templates', '/plugins/redirect-manager/templates'], $twig->twig_paths, 'after the theme, so a theme can override');
    }

    public function testTheTwigExtensionIsAddedAndAnswersFromTheRules(): void
    {
        $services = $this->services();
        $services->repository()->saveAll([\Grav\Plugin\RedirectManager\Domain\Rule::fromArray(['id' => 'a', 'source' => '/old', 'target' => '/new'])]);
        $env = new class () {
            public ?AbstractExtension $extension = null;

            public function addExtension(AbstractExtension $extension): void
            {
                $this->extension = $extension;
            }
        };
        $grav = new Grav();
        $grav['twig'] = new class ($env) {
            public function __construct(private readonly object $env)
            {
            }

            public function twig(): object
            {
                return $this->env;
            }
        };

        (new TwigIntegration($grav, static fn (): ServiceFactory => $services, '/p', ['HTTP_HOST' => 'site.test']))->initialized();

        self::assertNotNull($env->extension);
        $function = $env->extension->getFunctions()[0];
        self::assertSame('redirect_for', $function->getName());
        $result = ($function->getCallable())('/old');
        self::assertSame(301, $result['status']);
        self::assertSame('/new', $result['location']);
    }

    public function testAFailingTwigExtensionSetupIsLoggedNotThrown(): void
    {
        $grav = new Grav();
        $grav['log'] = $log = new ArrayLogger();
        $grav['twig'] = new class () {
            public function twig(): never
            {
                throw new RuntimeException('twig is not ready');
            }
        };

        (new TwigIntegration($grav, $this->services(...), '/p'))->initialized();

        self::assertCount(1, $log->messages('error'));
        self::assertStringContainsString('Twig extension failed: twig is not ready', $log->messages('error')[0]);
    }

    public function testTheSandboxAllowsTheFunctionAndTheFilter(): void
    {
        $event = new Event(['functions' => ['dump'], 'filters' => ['upper']]);
        (new TwigIntegration(new Grav(), $this->services(...), '/p'))->sandboxPolicy($event);

        self::assertSame(['dump', 'redirect_for'], $event['functions']);
        self::assertSame(['upper', 'redirect_target'], $event['filters']);
    }

    // ---- scheduler -------------------------------------------------------------------------------------

    public function testSchedulerRegistrationRunsThroughTheGuard(): void
    {
        $scheduler = new Scheduler();
        SchedulerJobs::onInitialized(new Grav(), $scheduler, $this->services(...));
        self::assertArrayHasKey(SchedulerJobs::MAINTENANCE, $scheduler->jobs);
    }

    public function testAFailureWhileRegisteringJobsIsLoggedAndTheSchedulerRunsOn(): void
    {
        $grav = new Grav();
        $grav['log'] = $log = new ArrayLogger();
        $scheduler = new Scheduler();

        SchedulerJobs::onInitialized($grav, $scheduler, static fn (): ServiceFactory => throw new RuntimeException('no config'));

        self::assertSame([], $scheduler->jobs);
        self::assertStringContainsString('scheduler registration failed: no config', $log->messages('error')[0]);
    }
}
