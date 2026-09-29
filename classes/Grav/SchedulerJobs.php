<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
use Grav\Common\Grav;
use Grav\Common\Plugins;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\RedirectManager\Check\TargetChecker;
use Grav\Plugin\RedirectManager\Check\TargetCheckerOptions;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Jobs\CheckTargetsJob;
use Grav\Plugin\RedirectManager\Jobs\DigestJob;
use Grav\Plugin\RedirectManager\Jobs\MaintenanceJob;
use Grav\Plugin\RedirectManager\Jobs\SuggestionRefresher;
use Grav\Plugin\RedirectManager\Notify\Digest;
use Grav\Plugin\RedirectManager\Notify\DigestBuilder;
use Grav\Plugin\RedirectManager\Notify\DigestPeriod;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * Scheduler jobs of the plugin (Grav's scheduler: `bin/grav scheduler`, system cron or the admin).
 *
 *   redirect-manager-maintenance    hourly: fold hit logs into the statistics, purge and rotate the 404 log, refresh
 *                                   suggestions, 404 threshold webhook, disable expired rules (opt-in)
 *   redirect-manager-check-targets  checker.schedule (when checker.enabled): live check of the rule targets, dead-target webhook
 *   redirect-manager-digest         daily or weekly at 07:00 (notifications.email_digest): report mail through the email plugin
 *
 * The jobs are static "Class::method" callables, so the scheduler runs them from its own process, where Grav and the
 * plugin are loaded. Each returns a short text for the scheduler's job output and throws RuntimeException on failure,
 * which the scheduler records as a failed run. Run one job now:
 *
 *   bin/grav scheduler --run=redirect-manager-maintenance
 */
final class SchedulerJobs
{
    public const MAINTENANCE = 'redirect-manager-maintenance';
    public const CHECK_TARGETS = 'redirect-manager-check-targets';
    public const DIGEST = 'redirect-manager-digest';

    private const DEFAULT_CHECK_SCHEDULE = '30 3 * * 0';

    /**
     * Handler of onSchedulerInitialized: a failure is logged and never breaks the scheduler run.
     *
     * @param Closure(): ServiceFactory $services
     */
    public static function onInitialized(Grav $grav, Scheduler $scheduler, Closure $services): void
    {
        try {
            self::register($scheduler, $services());
        } catch (Throwable $e) {
            try {
                $grav['log']->error(sprintf('Redirect Manager: scheduler registration failed: %s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine()));
            } catch (Throwable) {
                // nothing left to do
            }
        }
    }

    public static function register(Scheduler $scheduler, ServiceFactory $services): void
    {
        $backlink = '/plugin/redirect-manager';

        $job = $scheduler->addFunction(self::class . '::maintenance', [], self::MAINTENANCE);
        $job->at('5 * * * *');
        $job->output('logs/' . self::MAINTENANCE . '.out');
        $job->backlink($backlink);
        $job->onlyOne();

        if ($services->bool('checker.enabled', true)) {
            $job = $scheduler->addFunction(self::class . '::checkTargets', [], self::CHECK_TARGETS);
            $job->at(self::cron($services->string('checker.schedule', self::DEFAULT_CHECK_SCHEDULE), self::DEFAULT_CHECK_SCHEDULE));
            $job->output('logs/' . self::CHECK_TARGETS . '.out');
            $job->backlink($backlink);
            $job->onlyOne();
        }

        $period = self::digestPeriod($services);
        if ($period !== null) {
            $job = $scheduler->addFunction(self::class . '::digest', [], self::DIGEST);
            $job->at($period === DigestPeriod::Daily ? '0 7 * * *' : '0 7 * * 1');
            $job->output('logs/' . self::DIGEST . '.out');
            $job->backlink($backlink);
            $job->onlyOne();
        }
    }

    /** Hourly housekeeping. */
    public static function maintenance(): string
    {
        return self::execute('maintenance', static function (Grav $grav, ServiceFactory $services, RuleEvents $events): array {
            $suggestions = new SuggestionRefresher(
                $services->logStore(),
                $services->suggestionStore(),
                static function () use ($grav, $services): Suggester {
                    self::preparePages($grav);

                    return new Suggester($services->pageIndex());
                },
                static function (string $path, ?string $language) use ($services): bool {
                    $context = new RequestContext($path, [], '', 'https', $language);
                    $matcher = $services->matcher();

                    return $matcher->match($context, MatchPhase::Early) !== null || $matcher->match($context, MatchPhase::NotFound) !== null;
                },
                $services->resolvedPaths(),
                $services->clock(),
                (float) $services->config('suggestions.min_score', 0.5),
            );

            $job = new MaintenanceJob(
                $services->statsStore(),
                $services->logStore(),
                $services->notFoundLogger(),
                max(0, $services->int('log.retention_days', 30)),
                $services->clock(),
                $suggestions,
                $services->webhookNotifier(),
                $services->thresholdTracker(),
                max(0, $services->int('notifications.not_found_threshold', 25)),
                $services->repository(),
                $services->bool('redirects.disable_expired', false),
                static fn (array $record) => $events->suggestionCreated($record),
                static fn (Rule $rule, Rule $previous) => $events->saved($rule, $previous, RuleEvents::ACTION_UPDATE),
            );

            return $job->run();
        });
    }

    /** Live check of the redirect targets. */
    public static function checkTargets(): string
    {
        return self::execute('check-targets', static function (Grav $grav, ServiceFactory $services): array {
            $timeout = $services->config('checker.timeout', 5);
            $checker = new TargetChecker(
                HttpClient::create(),
                $services->clock(),
                new TargetCheckerOptions(
                    baseUrl: $services->siteUrl() !== '' ? $services->siteUrl() : 'http://localhost',
                    timeoutSeconds: is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 5.0,
                    maxPerRun: max(1, $services->int('checker.max_per_run', 500)),
                    checkExternal: $services->bool('checker.check_external', true),
                ),
            );
            $job = new CheckTargetsJob(
                $services->repository(),
                $checker,
                $services->checkResultStore(),
                $services->webhookNotifier(),
                $services->thresholdTracker(),
                $services->bool('notifications.dead_targets', true),
            );

            return $job->run();
        });
    }

    /** Daily or weekly report mail. Skipped (and logged) when the email plugin is not available. */
    public static function digest(): string
    {
        return self::execute('digest', static function (Grav $grav, ServiceFactory $services): array {
            $period = self::digestPeriod($services);
            if ($period === null) {
                return ['sent' => false, 'reason' => 'digest_disabled'];
            }
            if (!isset($grav['Email'])) {
                self::log($grav, 'info', 'Redirect Manager: the report mail was skipped, the email plugin is not enabled or its mailer engine is "none".');

                return ['sent' => false, 'reason' => 'email_plugin_unavailable'];
            }

            $default = (string) $grav['config']->get('system.languages.default_lang', '');
            $base = $services->siteUrl() !== '' ? $services->siteUrl() : 'http://localhost';
            $adminRoute = '/' . trim((string) $grav['config']->get('plugins.admin2.route', '/admin'), '/');
            $job = new DigestJob(
                $services->logStore(),
                $services->statsStore(),
                $services->repository(),
                $services->suggestionStore(),
                $services->checkResultStore(),
                $services->clock(),
                new DigestBuilder(),
                self::mailer($grav),
                $services->string('notifications.email_to', ''),
                $default !== '' ? $default : 'en',
                $services->siteTitle(),
                $base,
                $base . $adminRoute . '/plugin/redirect-manager',
                max(1, $services->int('log.retention_days', 30)),
                (float) $services->config('suggestions.min_score', 0.5),
            );

            return $job->run($period);
        });
    }

    /**
     * @param Closure(Grav, ServiceFactory, RuleEvents): array<string, mixed> $work
     */
    private static function execute(string $name, Closure $work): string
    {
        $grav = Grav::instance();
        try {
            $plugin = Plugins::getPlugin('redirect-manager');
            if (!$plugin instanceof \Grav\Plugin\RedirectManagerPlugin) {
                throw new RuntimeException('the plugin is not loaded');
            }
            $result = $work($grav, $plugin->services(), $plugin->events());
        } catch (Throwable $e) {
            self::log($grav, 'error', sprintf('Redirect Manager: job %s failed: %s (%s:%d)', $name, $e->getMessage(), basename($e->getFile()), $e->getLine()));

            throw new RuntimeException(sprintf('Redirect Manager %s failed: %s', $name, $e->getMessage()), 0, $e);
        }
        $line = sprintf('Redirect Manager %s: %s', $name, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::log($grav, 'info', $line);

        return $line . "\n";
    }

    /**
     * @return Closure(string, Digest): bool
     */
    private static function mailer(Grav $grav): Closure
    {
        return static function (string $to, Digest $digest) use ($grav): bool {
            $email = $grav['Email'];
            $from = trim((string) $grav['config']->get('plugins.email.from', ''));
            $message = $email->message($digest->subject, $digest->text, 'text/plain');
            $message->from($from !== '' ? $from : $to)->to($to)->html($digest->html);

            return (int) $email->send($message) === 1;
        };
    }

    /** The Grav pages, enabled and ready for the page index (the scheduler runs without a page request). */
    private static function preparePages(Grav $grav): void
    {
        $grav['pages']->enablePages();
    }

    private static function digestPeriod(ServiceFactory $services): ?DigestPeriod
    {
        return match (strtolower($services->string('notifications.email_digest', 'none'))) {
            'daily' => DigestPeriod::Daily,
            'weekly' => DigestPeriod::Weekly,
            default => null,
        };
    }

    private static function cron(string $expression, string $fallback): string
    {
        $expression = trim($expression);

        return preg_match('/^\S+(?:\s+\S+){4}$/', $expression) === 1 ? $expression : $fallback;
    }

    private static function log(Grav $grav, string $level, string $message): void
    {
        try {
            $grav['log']->{$level}($message);
        } catch (Throwable) {
            // nothing left to do
        }
    }
}
