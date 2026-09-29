<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use DateTimeImmutable;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Pages;
use Grav\Common\Plugins;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\Email\Email;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\SchedulerJobs;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support\ArrayLogger;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use Grav\Plugin\RedirectManagerPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use stdClass;

/**
 * SchedulerJobs::register() against a recording scheduler, and the three jobs end to end over a real ServiceFactory
 * on temp directories. The one thing not exercised is checkTargets() against live HTTP: HttpClient::create() is not
 * injectable, so the tests use only rules the checker skips without a request (targets with placeholders).
 */
#[CoversClass(SchedulerJobs::class)]
#[Group('grav')]
final class SchedulerJobsTest extends GravTestCase
{
    use TempDirTrait;

    private FixedClock $clock;
    private Grav $grav;
    private ArrayLogger $logger;

    /** @var list<array{string, array<string, mixed>}> events the plugin's RuleEvents dispatched */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T12:00:00+00:00'));
        $this->logger = new ArrayLogger();
        $this->dispatched = [];
        $this->grav = new Grav();
        $this->grav['log'] = $this->logger;
        $this->grav['pages'] = new Pages();
        $this->grav['config'] = new Config([
            'system' => ['languages' => ['default_lang' => 'en']],
            'plugins' => ['admin2' => ['route' => '/backend/'], 'email' => ['from' => 'noreply@example.org']],
        ]);
        Grav::setInstance($this->grav);
        Plugins::$registry = [];
    }

    protected function tearDown(): void
    {
        Grav::setInstance(null);
        Plugins::$registry = [];
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $site
     */
    private function factory(array $config = [], array $site = []): ServiceFactory
    {
        /** @var array{languages?: list<string>, default_language?: string, custom_base_url?: string, api_route?: string, admin_route?: string, base_url?: string, site_title?: string} $site */
        return new ServiceFactory(
            $config,
            $site + ['base_url' => 'https://site.example', 'site_title' => 'Demo Shop'],
            $this->tmp . '/data',
            $this->tmp . '/cache',
            $this->logger,
            $this->clock,
            static fn () => PageTreeFixture::index(),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadPlugin(array $config = []): ServiceFactory
    {
        $services = $this->factory($config);
        $events = new RuleEvents(function (string $name, array $payload): array {
            $this->dispatched[] = [$name, $payload];

            return $payload;
        });
        Plugins::$registry['redirect-manager'] = new RedirectManagerPlugin($services, $events);

        return $services;
    }

    private function hits(ServiceFactory $services, string $path, int $count, string $language = 'en'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $services->logStore()->append(new NotFoundEntry(
                new DateTimeImmutable('2026-09-29T11:00:00+00:00'),
                $path,
                '',
                'https://ref.example/page',
                'Mozilla/5.0',
                UserAgentClass::Browser,
                null,
                $language,
                'site.example',
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $output): array
    {
        self::assertStringEndsWith("\n", $output);
        $pos = strpos($output, '{');
        self::assertNotFalse($pos);
        $data = json_decode(substr($output, $pos), true);
        self::assertIsArray($data, $output);

        return $data;
    }

    // ---- register() -------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, string|null, string|null}>
     */
    public static function registrations(): iterable
    {
        yield 'defaults: maintenance and the weekly check' => [[], ['maintenance', 'check'], '30 3 * * 0', null];
        yield 'checker off' => [['checker' => ['enabled' => false]], ['maintenance'], null, null];
        yield 'custom schedule' => [['checker' => ['schedule' => ' 15 4 * * 1 ']], ['maintenance', 'check'], '15 4 * * 1', null];
        yield 'invalid schedule falls back' => [['checker' => ['schedule' => 'every night']], ['maintenance', 'check'], '30 3 * * 0', null];
        yield 'four fields fall back' => [['checker' => ['schedule' => '1 2 3 4']], ['maintenance', 'check'], '30 3 * * 0', null];
        yield 'daily digest' => [['checker' => ['enabled' => false], 'notifications' => ['email_digest' => 'Daily']], ['maintenance', 'digest'], null, '0 7 * * *'];
        yield 'weekly digest' => [['checker' => ['enabled' => false], 'notifications' => ['email_digest' => 'weekly']], ['maintenance', 'digest'], null, '0 7 * * 1'];
        yield 'unknown digest value' => [['checker' => ['enabled' => false], 'notifications' => ['email_digest' => 'monthly']], ['maintenance'], null, null];
        yield 'everything' => [['notifications' => ['email_digest' => 'daily']], ['maintenance', 'check', 'digest'], '30 3 * * 0', '0 7 * * *'];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $jobs
     */
    #[DataProvider('registrations')]
    public function testRegisterAddsTheEnabledJobs(array $config, array $jobs, ?string $checkCron, ?string $digestCron): void
    {
        $scheduler = new Scheduler();

        SchedulerJobs::register($scheduler, $this->factory($config));

        $ids = ['maintenance' => SchedulerJobs::MAINTENANCE, 'check' => SchedulerJobs::CHECK_TARGETS, 'digest' => SchedulerJobs::DIGEST];
        self::assertSame(array_map(static fn (string $j): string => $ids[$j], $jobs), array_keys($scheduler->jobs));

        $maintenance = $scheduler->jobs[SchedulerJobs::MAINTENANCE];
        self::assertSame(SchedulerJobs::class . '::maintenance', $maintenance->command);
        self::assertSame([], $maintenance->args);
        self::assertSame('5 * * * *', $maintenance->arg('at'));

        foreach ($scheduler->jobs as $id => $job) {
            self::assertSame(['at', 'output', 'backlink', 'onlyOne'], array_column($job->calls, 0), $id);
            self::assertSame('logs/' . $id . '.out', $job->arg('output'));
            self::assertSame('/plugin/redirect-manager', $job->arg('backlink'));
        }
        if ($checkCron !== null) {
            self::assertSame($checkCron, $scheduler->jobs[SchedulerJobs::CHECK_TARGETS]->arg('at'));
            self::assertSame(SchedulerJobs::class . '::checkTargets', $scheduler->jobs[SchedulerJobs::CHECK_TARGETS]->command);
        }
        if ($digestCron !== null) {
            self::assertSame($digestCron, $scheduler->jobs[SchedulerJobs::DIGEST]->arg('at'));
            self::assertSame(SchedulerJobs::class . '::digest', $scheduler->jobs[SchedulerJobs::DIGEST]->command);
        }
    }

    // ---- maintenance() ----------------------------------------------------------------------------

    public function testMaintenanceRunsAllStepsAndReportsThem(): void
    {
        $services = $this->loadPlugin([
            'log' => ['retention_days' => 30],
            'notifications' => ['not_found_threshold' => 2, 'webhook_url' => 'ftp://not-allowed.example/hook'],
            'redirects' => ['disable_expired' => true],
        ]);

        $services->repository()->saveAll([
            new Rule('exp', '/expired', '/somewhere', expiresAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00')),
            new Rule('early', '/handled-early', '/x'),
            new Rule('late', '/handled-late', '/y', onlyIfNotFound: true),
        ]);
        $this->hits($services, '/aboutt', 3);              // a typo of /about: gets a suggestion
        $this->hits($services, '/handled-early', 3);       // a rule already handles it
        $this->hits($services, '/handled-late', 3);        // an only-if-not-found rule handles it
        $this->hits($services, '/zzzz-nothing-like-it', 1); // below the minimum of hits
        $services->logStore()->append(new NotFoundEntry(new DateTimeImmutable('2026-05-01T00:00:00+00:00'), '/ancient', '', '', 'Mozilla/5.0', UserAgentClass::Browser, null, 'en', 'site.example'));

        $output = SchedulerJobs::maintenance();

        self::assertStringStartsWith('Redirect Manager maintenance: {', $output);
        $result = self::decode($output);
        self::assertSame(['merged' => 0], $result['stats']);
        self::assertSame(1, $result['log']['purged'], 'the entry older than the retention is gone');
        self::assertSame(1, $result['suggestions']['stored']);
        self::assertSame(['sent' => 0], $result['webhook'], 'the webhook URL is not allowed, so nothing is sent (and nothing hits the network)');
        self::assertSame(['disabled' => 1], $result['expired']);

        self::assertTrue($this->grav['pages']->enabled, 'the page tree is enabled for the suggester');
        $stored = $services->suggestionStore()->open(0.0);
        self::assertCount(1, $stored);
        self::assertSame('/aboutt', $stored[0]['path']);
        self::assertSame('/about', $stored[0]['target']);

        $names = array_column($this->dispatched, 0);
        self::assertSame(['onSuggestionCreated', 'onRedirectRuleSaved'], $names);
        self::assertSame('/aboutt', $this->dispatched[0][1]['suggestion']['path']);
        self::assertSame(RuleEvents::ACTION_UPDATE, $this->dispatched[1][1]['action']);
        self::assertSame('exp', $this->dispatched[1][1]['rule']->id);
        self::assertFalse($this->dispatched[1][1]['rule']->enabled);
        self::assertTrue($this->dispatched[1][1]['previous']->enabled);

        self::assertSame([rtrim($output)], $this->logger->messages('info'));
    }

    public function testMaintenanceWithDefaultsSkipsWhatIsNotConfigured(): void
    {
        $this->loadPlugin();

        $result = self::decode(SchedulerJobs::maintenance());

        self::assertSame(['merged' => 0], $result['stats']);
        self::assertSame(['purged' => 0, 'rotated' => 0], $result['log']);
        self::assertSame(['stored' => 0], $result['suggestions']);
        self::assertSame(['skipped' => true], $result['webhook']);
        self::assertSame(['skipped' => true], $result['expired']);
        self::assertSame([], $this->dispatched);
    }

    // ---- checkTargets() ---------------------------------------------------------------------------

    public function testCheckTargetsWithoutRulesMakesNoRequest(): void
    {
        $this->loadPlugin(['checker' => ['timeout' => 'soon', 'max_per_run' => 0]]);

        $output = SchedulerJobs::checkTargets();

        self::assertSame('Redirect Manager check-targets: {"checked":0,"dead":0,"notified":0}' . "\n", $output);
        self::assertSame([rtrim($output)], $this->logger->messages('info'));
    }

    public function testCheckTargetsSkipsDynamicTargetsAndStoresTheResult(): void
    {
        $services = $this->loadPlugin(['checker' => ['timeout' => '2.5', 'check_external' => false]]);
        $services->repository()->saveAll([
            new Rule('dyn', '/blog/*', '/news/$1', MatchType::Wildcard),
            new Rule('named', '/(?<slug>.+)', '/x/{slug}', MatchType::Regex),
            new Rule('off', '/disabled', '/somewhere', enabled: false),
        ]);

        $result = self::decode(SchedulerJobs::checkTargets());

        self::assertSame(['checked' => 0, 'dead' => 0, 'notified' => 0], $result);
        $stored = $services->checkResultStore()->all();
        self::assertSame(['dyn', 'named'], array_keys($stored), 'the disabled rule is not checked');
        self::assertTrue($stored['dyn']->isSkipped());
    }

    // ---- digest() ---------------------------------------------------------------------------------

    public function testDigestIsSkippedWhenDisabled(): void
    {
        $this->loadPlugin();

        self::assertSame('Redirect Manager digest: {"sent":false,"reason":"digest_disabled"}' . "\n", SchedulerJobs::digest());
    }

    public function testDigestIsSkippedAndLoggedWhenTheEmailPluginIsMissing(): void
    {
        $this->loadPlugin(['notifications' => ['email_digest' => 'daily', 'email_to' => 'ops@example.org']]);

        $output = SchedulerJobs::digest();

        self::assertSame('Redirect Manager digest: {"sent":false,"reason":"email_plugin_unavailable"}' . "\n", $output);
        $info = $this->logger->messages('info');
        self::assertCount(2, $info);
        self::assertStringContainsString('the email plugin is not enabled', $info[0]);
    }

    public function testDigestSendsTheMailThroughTheEmailService(): void
    {
        $email = new Email();
        $this->grav['Email'] = $email;
        $services = $this->loadPlugin(['notifications' => ['email_digest' => 'daily', 'email_to' => ' ops@example.org ']]);
        $this->hits($services, '/missing', 4);

        $output = SchedulerJobs::digest();

        $result = self::decode($output);
        self::assertTrue($result['sent']);
        self::assertNull($result['reason']);
        self::assertStringContainsString('Demo Shop', $result['subject']);
        self::assertStringContainsString('Daily redirect report', $result['subject']);

        self::assertCount(1, $email->sent);
        $message = $email->sent[0];
        self::assertSame($result['subject'], $message->subject);
        self::assertSame('text/plain', $message->contentType);
        self::assertSame('noreply@example.org', $message->from);
        self::assertSame('ops@example.org', $message->to);
        self::assertStringContainsString('/missing', $message->body);
        self::assertStringContainsString('<html', $message->html);
        self::assertStringContainsString('href="https://site.example/backend/plugin/redirect-manager"', $message->html);
    }

    public function testDigestUsesTheRecipientAsSenderWhenNoFromAddressIsConfigured(): void
    {
        $email = new Email();
        $this->grav['Email'] = $email;
        $this->grav['config'] = new Config(['system' => ['languages' => ['default_lang' => 'de']]]);
        $this->loadPlugin(['notifications' => ['email_digest' => 'weekly', 'email_to' => 'ops@example.org']]);

        $result = self::decode(SchedulerJobs::digest());

        self::assertTrue($result['sent']);
        self::assertStringContainsString('Wochenbericht', $result['subject'], 'the default language picks the German texts');
        self::assertSame('ops@example.org', $email->sent[0]->from);
        self::assertStringContainsString('href="https://site.example/admin/plugin/redirect-manager"', $email->sent[0]->html, 'default admin route');
    }

    public function testDigestReportsAFailedSend(): void
    {
        $email = new Email(0);
        $this->grav['Email'] = $email;
        $this->loadPlugin(['notifications' => ['email_digest' => 'daily', 'email_to' => 'ops@example.org']]);

        $result = self::decode(SchedulerJobs::digest());

        self::assertFalse($result['sent']);
        self::assertSame('send_failed', $result['reason']);
        self::assertCount(1, $email->sent);
    }

    public function testDigestWithoutARecipientSendsNothing(): void
    {
        $email = new Email();
        $this->grav['Email'] = $email;
        $this->loadPlugin(['notifications' => ['email_digest' => 'daily']]);

        $result = self::decode(SchedulerJobs::digest());

        self::assertFalse($result['sent']);
        self::assertSame('no_recipient', $result['reason']);
        self::assertSame([], $email->sent);
    }

    // ---- failures ---------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function jobMethods(): iterable
    {
        yield 'maintenance' => ['maintenance'];
        yield 'checkTargets' => ['checkTargets'];
        yield 'digest' => ['digest'];
    }

    #[DataProvider('jobMethods')]
    public function testAJobFailsWhenThePluginIsNotLoaded(string $method): void
    {
        try {
            SchedulerJobs::$method();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringEndsWith('failed: the plugin is not loaded', $e->getMessage());
            self::assertStringStartsWith('Redirect Manager ', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        $errors = $this->logger->messages('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('failed: the plugin is not loaded', $errors[0]);
        self::assertStringContainsString('SchedulerJobs.php:', $errors[0], 'the log line names file and line');
        self::assertSame([], $this->logger->messages('info'));
    }

    public function testAnObjectThatIsNotThePluginIsRejected(): void
    {
        Plugins::$registry['redirect-manager'] = new stdClass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redirect Manager maintenance failed: the plugin is not loaded');

        SchedulerJobs::maintenance();
    }

    public function testAnErrorInsideTheJobIsWrappedAndLogged(): void
    {
        $services = $this->loadPlugin();
        mkdir($services->dataDir(), 0775, true);
        file_put_contents($services->repository()->file(), "rules: [unclosed\n  - : :\n");

        try {
            SchedulerJobs::checkTargets();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('Redirect Manager check-targets failed: ', $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertStringEndsWith($e->getPrevious()->getMessage(), $e->getMessage());
        }

        $errors = $this->logger->messages('error');
        self::assertCount(1, $errors);
        self::assertStringStartsWith('Redirect Manager: job check-targets failed: ', $errors[0]);
    }

    public function testLoggingProblemsNeverBreakAJob(): void
    {
        $this->loadPlugin();
        unset($this->grav['log']);

        self::assertStringStartsWith('Redirect Manager digest: ', SchedulerJobs::digest());
    }
}
