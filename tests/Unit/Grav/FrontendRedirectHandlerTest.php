<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Closure;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Pages;
use Grav\Events\PageEvent;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\FrontendRedirectHandler;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\ArrayLogger;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\FakeFrontendRequest;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakePage;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The request flow that used to live in redirect-manager.php: early redirects, 410/451 after the session started,
 * pass-through to a page, the 404 log and "only if not found" rules, always against Grav stand-ins.
 */
#[CoversClass(FrontendRedirectHandler::class)]
#[Group('grav')]
final class FrontendRedirectHandlerTest extends GravTestCase
{
    use TempDirTrait;

    private Grav $grav;

    private ArrayLogger $log;

    /** @var list<array{string, array<string, mixed>}> events fired through RuleEvents */
    private array $events = [];

    /** @var Closure(array<string, mixed>): array<string, mixed>|null */
    private ?Closure $onMatched = null;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->events = [];
        $this->onMatched = null;
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed>       $config plugin config
     */
    private function handler(array $rules, FakeFrontendRequest $request, array $config = [], array $server = ['REMOTE_ADDR' => '203.0.113.9'], bool $isCli = false): FrontendRedirectHandler
    {
        $services = new ServiceFactory($config, [], $this->tmp . '/data', $this->tmp . '/cache');
        $services->repository()->saveAll(array_map(static fn (array $row): Rule => Rule::fromArray($row), $rules));

        $this->log = new ArrayLogger();
        $this->grav = new Grav();
        $this->grav['config'] = new Config(['plugins' => ['redirect-manager' => $config]]);
        $this->grav['log'] = $this->log;
        $this->grav['request'] = $request;
        $this->grav['pages'] = new Pages();
        $this->grav['twig'] = new class () {
            /** @var list<array{string, array<string, mixed>}> */
            public array $rendered = [];

            /** @param array<string, mixed> $vars */
            public function processTemplate(string $template, array $vars): string
            {
                $this->rendered[] = [$template, $vars];

                return '<p>' . $template . ' ' . $vars['redirect_path'] . '</p>';
            }
        };

        $events = new RuleEvents(function (string $name, array $payload): array {
            $this->events[] = [$name, $payload];

            return $name === 'onRedirectMatched' && $this->onMatched !== null ? ($this->onMatched)($payload) : $payload;
        });

        return new FrontendRedirectHandler($this->grav, static fn (): ServiceFactory => $services, static fn (): RuleEvents => $events, $server, $isCli);
    }

    private function sent(): Response
    {
        self::assertInstanceOf(Response::class, $this->grav->closed, 'no response was sent');

        return $this->grav->closed;
    }

    /**
     * @return list<string>
     */
    private function hits(): array
    {
        $hits = [];
        foreach (glob($this->tmp . '/data/hits/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    public function testAnEarlyRedirectIsSentAndCounted(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/old', 'target' => '/new']], new FakeFrontendRequest('/old'));
        $handler->onPluginsLoaded();

        $response = $this->sent();
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/new', $response->getHeaderLine('Location'));
        self::assertSame('Grav Redirect Manager', $response->getHeaderLine('X-Redirect-By'));
        self::assertSame(['a'], $this->hits());
        self::assertSame('onRedirectMatched', $this->events[0][0]);
    }

    public function testNothingHappensOnTheCommandLine(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/old', 'target' => '/new']], new FakeFrontendRequest('/old'), isCli: true);
        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed);
        self::assertSame([], $this->events);
    }

    public function testAUrlWithoutARuleIsLeftToGrav(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/old', 'target' => '/new']], new FakeFrontendRequest('/other'));
        $handler->onPluginsLoaded();
        $handler->onPagesInitialized();
        self::assertNull($this->grav->closed);
        self::assertSame([], $this->hits());
    }

    public function testADisabledPluginNeverMatchesOrLogs(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/old', 'target' => '/new']], new FakeFrontendRequest('/old'), ['enabled' => false]);
        $handler->onPluginsLoaded();
        $handler->onPageNotFound(new PageEvent());
        self::assertNull($this->grav->closed);
        self::assertSame([], $this->events);
        self::assertFileDoesNotExist($this->tmp . '/data/404');
    }

    public function testExcludedPathsAreNeverRedirected(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/api/x', 'target' => '/new']], new FakeFrontendRequest('/api/x'));
        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed);
    }

    public function testAListenerCanCancelTheRedirect(): void
    {
        $handler = $this->handler([['id' => 'a', 'source' => '/old', 'target' => '/new']], new FakeFrontendRequest('/old'));
        $this->onMatched = static fn (array $payload): array => ['cancel' => true] + $payload;
        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed);
        self::assertSame([], $this->hits());
    }

    public function testAGoneRuleWaitsForPagesInitializedAndUsesTheTemplate(): void
    {
        $handler = $this->handler([['id' => 'g', 'source' => '/gone', 'target' => '', 'status' => 410]], new FakeFrontendRequest('/gone'));
        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed, 'the error page is rendered once the site is ready');

        $handler->onPagesInitialized();
        $response = $this->sent();
        self::assertSame(410, $response->getStatusCode());
        self::assertSame('<p>redirect-manager/gone.html.twig /gone</p>', (string) $response->getBody());
        self::assertSame(['g'], $this->hits());
        self::assertSame($this->grav['twig']->rendered[0][1]['redirect_status'], 410);
    }

    public function testALegalReasonsRuleUsesItsOwnTemplate(): void
    {
        $handler = $this->handler([['id' => 'l', 'source' => '/blocked', 'target' => '', 'status' => 451]], new FakeFrontendRequest('/blocked'));
        $handler->onPluginsLoaded();
        $handler->onPagesInitialized();
        self::assertSame(451, $this->sent()->getStatusCode());
        self::assertSame('redirect-manager/unavailable.html.twig', $this->grav['twig']->rendered[0][0]);
    }

    public function testPagesInitializedWithoutAPendingMatchDoesNothing(): void
    {
        $handler = $this->handler([], new FakeFrontendRequest('/x'));
        $handler->onPagesInitialized();
        self::assertNull($this->grav->closed);
    }

    public function testAPassThroughRuleSwapsInTheTargetPage(): void
    {
        $handler = $this->handler([['id' => 'p', 'source' => '/alias', 'target' => '/real', 'status' => 200]], new FakeFrontendRequest('/alias'));
        $page = new FakePage('/pages/real', 'Real', '/real');
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->byRoute['/real'] = $page;
        $this->grav['page'] = new FakePage('/pages/404', 'Not found', '/404');

        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed, 'a pass-through rule serves a page, it does not send a response');
        $handler->onPagesInitialized();

        self::assertSame($page, $this->grav['page']);
        self::assertSame(['p'], $this->hits());
    }

    public function testAPassThroughToAnUnroutablePageFallsThroughWithAWarning(): void
    {
        $handler = $this->handler([['id' => 'p', 'source' => '/alias', 'target' => '/hidden', 'status' => 200]], new FakeFrontendRequest('/alias'));
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->byRoute['/hidden'] = new FakePage('/pages/hidden', 'Hidden', '/hidden', routable: false);
        $before = new FakePage('/pages/404', 'Not found', '/404');
        $this->grav['page'] = $before;

        $handler->onPluginsLoaded();
        $handler->onPagesInitialized();

        self::assertSame($before, $this->grav['page']);
        self::assertSame([], $this->hits());
        $warnings = $this->log->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('pass-through rule p points to "/hidden"', $warnings[0]);
        self::assertStringContainsString('Falling through.', $warnings[0]);
    }

    public function testANotFoundRequestIsLoggedAndAnOnlyIfNotFoundRuleRedirects(): void
    {
        $handler = $this->handler(
            [['id' => 'n', 'source' => '/missing', 'target' => '/found', 'only_if_not_found' => true]],
            new FakeFrontendRequest('/missing', 'a=1', ['Referer' => 'https://example.org/from', 'User-Agent' => 'Mozilla/5.0 Chrome/120', 'Host' => 'site.test']),
        );
        $handler->onPluginsLoaded();
        self::assertNull($this->grav->closed, 'not before Grav knows the page does not exist');

        $handler->onPageNotFound(new PageEvent());

        self::assertSame(301, $this->sent()->getStatusCode());
        self::assertSame('/found', $this->sent()->getHeaderLine('Location'));
        $names = array_column($this->events, 0);
        self::assertContains('onNotFoundLogged', $names);
        self::assertContains('onRedirectMatched', $names);
        $entry = $this->events[array_search('onNotFoundLogged', $names, true)][1]['entry'];
        self::assertSame('/missing', $entry->path);
    }

    public function testANotFoundRequestWithoutARuleOnlyLogsAndLeavesTheResponseToGrav(): void
    {
        $handler = $this->handler([], new FakeFrontendRequest('/nothing-here', '', ['User-Agent' => 'Mozilla/5.0 Chrome/120']));
        $handler->onPluginsLoaded();
        $handler->onPageNotFound(new PageEvent());
        self::assertNull($this->grav->closed);
        self::assertContains('onNotFoundLogged', array_column($this->events, 0));
    }

    public function testTheLoggedIpFollowsXForwardedForOnlyWhenTheProxyIsTrusted(): void
    {
        $headers = ['X-Forwarded-For' => '198.51.100.7, 10.0.0.1', 'User-Agent' => 'Mozilla/5.0 Chrome/120'];
        foreach ([true, false] as $trusted) {
            $this->removeTempDir();
            $this->makeTempDir();
            $this->events = [];
            $handler = $this->handler([], new FakeFrontendRequest('/lost', '', $headers), ['security' => ['trust_proxy_headers' => $trusted], 'log' => ['ip_mode' => 'anonymize']], ['REMOTE_ADDR' => '203.0.113.9']);
            $handler->onPluginsLoaded();
            $handler->onPageNotFound(new PageEvent());
            $entry = $this->events[array_search('onNotFoundLogged', array_column($this->events, 0), true)][1]['entry'];
            self::assertSame($trusted ? '198.51.100.0' : '203.0.113.0', $entry->ip, 'trusted: ' . var_export($trusted, true));
        }
    }

    public function testANotFoundPassThroughRuleSetsThePageAndStopsPropagation(): void
    {
        $handler = $this->handler(
            [['id' => 'p', 'source' => '/ghost', 'target' => '/real', 'status' => 200, 'only_if_not_found' => true]],
            new FakeFrontendRequest('/ghost', '', ['User-Agent' => 'Mozilla/5.0 Chrome/120']),
        );
        $page = new FakePage('/pages/real', 'Real', '/real');
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->byRoute['/real'] = $page;

        $event = new PageEvent();
        $handler->onPluginsLoaded();
        $handler->onPageNotFound($event);

        self::assertSame($page, $event->page);
        self::assertTrue($event->isPropagationStopped(), 'the error plugin must not turn it into a 404 page');
        self::assertNull($this->grav->closed);
        self::assertSame(['p'], $this->hits());
    }

    public function testANotFoundPassThroughToAMissingPageWarnsAndKeepsTheNotFound(): void
    {
        $handler = $this->handler(
            [['id' => 'p', 'source' => '/ghost', 'target' => '/nowhere', 'status' => 200, 'only_if_not_found' => true]],
            new FakeFrontendRequest('/ghost', '', ['User-Agent' => 'Mozilla/5.0 Chrome/120']),
        );
        $event = new PageEvent();
        $handler->onPluginsLoaded();
        $handler->onPageNotFound($event);

        self::assertNull($event->page);
        self::assertFalse($event->isPropagationStopped());
        self::assertStringContainsString('"/nowhere", which is not a routable page.', $this->log->messages('warning')[0]);
    }

    public function testAFailureIsLoggedAndNeverThrown(): void
    {
        $this->handler([], new FakeFrontendRequest('/x'));
        $grav = $this->grav;
        $broken = new FrontendRedirectHandler(
            $grav,
            static fn (): ServiceFactory => throw new RuntimeException('rules.yaml is unreadable'),
            static fn (): RuleEvents => new RuleEvents(static fn (string $n, array $p): array => $p),
            [],
            false,
        );

        $broken->onPluginsLoaded();
        $broken->onPageNotFound(new PageEvent());

        $errors = $this->log->messages('error');
        self::assertCount(2, $errors);
        self::assertStringContainsString('redirect matching failed: rules.yaml is unreadable', $errors[0]);
        self::assertStringContainsString('404 handling failed: rules.yaml is unreadable', $errors[1]);
        self::assertNull($grav->closed);
    }
}
