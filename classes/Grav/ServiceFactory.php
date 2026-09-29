<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectConfig;
use Grav\Plugin\RedirectManager\Auto\AutoState;
use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\NotFound\IgnoreList;
use Grav\Plugin\RedirectManager\NotFound\IpAnonymizer;
use Grav\Plugin\RedirectManager\NotFound\IpMode;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLogger;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLoggerOptions;
use Grav\Plugin\RedirectManager\NotFound\ResolvedPaths;
use Grav\Plugin\RedirectManager\NotFound\SqliteLogStore;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClassifier;
use Grav\Plugin\RedirectManager\Notify\ThresholdTracker;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\CompiledRuleCache;
use Grav\Plugin\RedirectManager\Storage\DataDirProtection;
use Grav\Plugin\RedirectManager\Storage\ParsedRulesCache;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates the plugin's services on demand from plain arrays (no Grav objects), so the hot path only
 * builds what a request needs and everything stays unit-testable.
 *
 * $config is the plugin config (plugins.redirect-manager). $site carries the few Grav settings the plugin
 * reads: languages (list), default_language, custom_base_url, api_route, admin_route.
 *
 * @phpstan-import-type Settings from RequestContextFactory
 */
final class ServiceFactory
{
    /** @var array<string, object> */
    private array $instances = [];

    private bool $dataDirProtected = false;

    /**
     * @param array<string, mixed>                                                                                 $config
     * @param array{languages?: list<string>, default_language?: string, custom_base_url?: string, api_route?: string, admin_route?: string, base_url?: string, site_title?: string} $site
     * @param Closure(): PageIndex|null                                                                            $pageIndexProvider
     */
    public function __construct(
        private readonly array $config,
        private readonly array $site,
        private readonly string $dataDir,
        private readonly string $cacheDir,
        private readonly ?LoggerInterface $logger = null,
        private ?Clock $clock = null,
        private readonly ?Closure $pageIndexProvider = null,
    ) {
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    public function clock(): Clock
    {
        return $this->clock ??= new SystemClock();
    }

    /** Dotted lookup in the plugin config, e.g. "redirects.enabled". */
    public function config(string $path, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value ?? $default;
    }

    public function bool(string $path, bool $default): bool
    {
        $value = $this->config($path, $default);
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    public function int(string $path, int $default): int
    {
        $value = $this->config($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $path, string $default): string
    {
        $value = $this->config($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $path): array
    {
        $value = $this->config($path, []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    public function enabled(): bool
    {
        return $this->bool('enabled', true) && $this->bool('redirects.enabled', true);
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_values(array_map(strtolower(...), $this->site['languages'] ?? []));
    }

    public function requestFactory(): RequestContextFactory
    {
        /** @var RequestContextFactory */
        return $this->instances[__FUNCTION__] ??= new RequestContextFactory([
            'custom_base_url' => $this->site['custom_base_url'] ?? '',
            'languages' => $this->languages(),
            'trust_proxy_headers' => $this->bool('security.trust_proxy_headers', false),
            'excluded_paths' => $this->stringList('redirects.excluded_paths'),
            'api_route' => $this->site['api_route'] ?? '/api',
            'admin_route' => $this->site['admin_route'] ?? '/admin',
        ]);
    }

    public function repository(): RuleRepository
    {
        $this->protectDataDir();
        /** @var RuleRepository */
        return $this->instances[__FUNCTION__] ??= new RuleRepository($this->dataDir, $this->clock(), $this->parsedRules());
    }

    /** Parsed rows of rules.yaml, keyed by content hash (see ParsedRulesCache). */
    public function parsedRules(): ParsedRulesCache
    {
        /** @var ParsedRulesCache */
        return $this->instances[__FUNCTION__] ??= new ParsedRulesCache($this->cacheDir, $this->dataDir . '/' . RuleRepository::FILE);
    }

    public function compiledCache(): CompiledRuleCache
    {
        /** @var CompiledRuleCache */
        return $this->instances[__FUNCTION__] ??= new CompiledRuleCache(
            $this->cacheDir,
            // Not repository(): the read-only hot path must not touch the data directory.
            $this->dataDir . '/' . RuleRepository::FILE,
            new RuleCompiler(),
            $this->logger,
            $this->parsedRules(),
        );
    }

    public function targetGuard(): TargetGuard
    {
        /** @var TargetGuard */
        return $this->instances[__FUNCTION__] ??= new TargetGuard(
            $this->stringList('security.allowed_hosts'),
            $this->bool('security.allow_any_external', false),
        );
    }

    public function matcherOptions(): MatcherOptions
    {
        /** @var MatcherOptions */
        return $this->instances[__FUNCTION__] ??= new MatcherOptions(
            maxChainDepth: max(1, $this->int('redirects.max_chain_depth', 10)),
            backtrackLimit: max(1000, $this->int('security.regex_backtrack_limit', 100000)),
            guard: $this->targetGuard(),
            defaultLanguage: ($this->site['default_language'] ?? '') !== '' ? $this->site['default_language'] : null,
            globalQueryIgnore: $this->config('redirects.query_ignore') === null
                ? ['utm_*', 'fbclid', 'gclid', 'msclkid']
                : $this->stringList('redirects.query_ignore'),
        );
    }

    /** Loads the compiled rules (one stat and one include when warm) and returns a matcher over them. */
    public function matcher(): Matcher
    {
        /** @var Matcher */
        return $this->instances[__FUNCTION__] ??= new Matcher(
            $this->compiledCache()->load(),
            $this->clock(),
            $this->matcherOptions(),
        );
    }

    public function responder(): RedirectResponder
    {
        /** @var RedirectResponder */
        return $this->instances[__FUNCTION__] ??= new RedirectResponder(new RedirectResponderOptions(
            keepLanguagePrefix: $this->bool('redirects.keep_language_prefix', true),
            cacheControlPermanent: $this->string('redirects.cache_control_permanent', 'public, max-age=3600'),
            cacheControlTemporary: $this->string('redirects.cache_control_temporary', 'no-store'),
            languages: $this->languages(),
        ));
    }

    public function hitRecorder(): HitRecorder
    {
        $this->protectDataDir();
        /** @var HitRecorder */
        return $this->instances[__FUNCTION__] ??= new HitRecorder($this->dataDir . '/hits', $this->clock());
    }

    public function statsStore(): StatsStore
    {
        $this->protectDataDir();
        /** @var StatsStore */
        return $this->instances[__FUNCTION__] ??= new StatsStore(
            $this->dataDir . '/stats.json',
            $this->dataDir . '/hits',
            $this->clock(),
            max(1, $this->int('stats.keep_days', 90)),
        );
    }

    public function logStore(): LogStore
    {
        $this->protectDataDir();
        /** @var LogStore */
        return $this->instances[__FUNCTION__] ??= $this->createLogStore();
    }

    public function notFoundLogger(): NotFoundLogger
    {
        /** @var NotFoundLogger */
        return $this->instances[__FUNCTION__] ??= new NotFoundLogger(
            $this->logStore(),
            $this->ignoreList(),
            new IpAnonymizer($this->ipMode()),
            new UserAgentClassifier(),
            $this->clock(),
            new NotFoundLoggerOptions(
                enabled: $this->bool('log.enabled', true),
                logBots: $this->bool('log.log_bots', true),
                storeIp: $this->ipMode() !== IpMode::None,
            ),
        );
    }

    public function ignoreList(): IgnoreList
    {
        /** @var IgnoreList */
        return $this->instances[__FUNCTION__] ??= new IgnoreList(array_merge(
            $this->bool('log.use_default_ignores', true) ? IgnoreList::defaultPatterns() : [],
            $this->stringList('log.ignore_patterns'),
        ));
    }

    public function resolvedPaths(): ResolvedPaths
    {
        $this->protectDataDir();
        /** @var ResolvedPaths */
        return $this->instances[__FUNCTION__] ??= new ResolvedPaths($this->dataDir . '/404-state.json');
    }

    public function suggestionStore(): SuggestionStore
    {
        $this->protectDataDir();
        /** @var SuggestionStore */
        return $this->instances[__FUNCTION__] ??= new SuggestionStore($this->dataDir . '/suggestions.json', $this->clock());
    }

    public function checkResultStore(): CheckResultStore
    {
        $this->protectDataDir();
        /** @var CheckResultStore */
        return $this->instances[__FUNCTION__] ??= new CheckResultStore($this->dataDir . '/target-checks.json');
    }

    /** Remembers which 404 paths and dead targets were already reported by webhook (notify-state.json). */
    public function thresholdTracker(): ThresholdTracker
    {
        $this->protectDataDir();
        /** @var ThresholdTracker */
        return $this->instances[__FUNCTION__] ??= new ThresholdTracker($this->dataDir . '/notify-state.json', $this->clock());
    }

    /**
     * The webhook sender, or null when notifications.webhook_url is empty. The secret comes from the environment
     * variable REDIRECT_MANAGER_WEBHOOK_SECRET, else from notifications.webhook_secret.
     */
    public function webhookNotifier(?HttpClientInterface $client = null): ?WebhookNotifier
    {
        $url = trim($this->string('notifications.webhook_url', ''));
        if ($url === '') {
            return null;
        }
        $secret = getenv('REDIRECT_MANAGER_WEBHOOK_SECRET');
        if (!is_string($secret) || $secret === '') {
            $secret = $this->string('notifications.webhook_secret', '');
        }

        return new WebhookNotifier($client ?? HttpClient::create(), $this->clock(), $url, $secret, 5, $this->siteTitle());
    }

    /** Public root URL for runs without a request (scheduler, CLI): plugin config base_url, then system.custom_base_url. */
    public function siteUrl(): string
    {
        return rtrim($this->site['base_url'] ?? '', '/');
    }

    public function siteTitle(): string
    {
        return $this->site['site_title'] ?? '';
    }

    /** Pending deleted pages, unseen auto rules (sidebar badge): auto-state.json. */
    public function autoState(): AutoState
    {
        $this->protectDataDir();
        /** @var AutoState */
        return $this->instances[__FUNCTION__] ??= new AutoState($this->dataDir . '/auto-state.json', $this->clock());
    }

    /** The auto_redirect.* settings. */
    public function autoRedirectConfig(): AutoRedirectConfig
    {
        $section = $this->config('auto_redirect', []);

        return AutoRedirectConfig::fromArray(is_array($section) ? $section : []);
    }

    /** Page index for suggestions and the target picker. Only available inside Grav (provider set by the plugin). */
    public function pageIndex(): PageIndex
    {
        /** @var PageIndex */
        return $this->instances[__FUNCTION__] ??= $this->pageIndexProvider !== null
            ? ($this->pageIndexProvider)()
            : new PageIndex();
    }

    public function ipMode(): IpMode
    {
        return IpMode::fromConfig($this->config('log.ip_mode', 'anonymize'));
    }

    /** Once per factory: .htaccess and index.html in the data directory (see DataDirProtection). */
    private function protectDataDir(): void
    {
        if ($this->dataDirProtected) {
            return;
        }
        $this->dataDirProtected = true;
        if (!DataDirProtection::ensure($this->dataDir)) {
            $this->logger?->warning('Redirect Manager: cannot protect ' . $this->dataDir . ' (.htaccess, index.html): check the directory permissions.');
        }
    }

    private function createLogStore(): LogStore
    {
        if ($this->string('log.backend', 'jsonl') === 'sqlite') {
            if (SqliteLogStore::isAvailable()) {
                return new SqliteLogStore($this->dataDir . '/404.sqlite', $this->clock());
            }
            $this->logger?->warning('Redirect Manager: log.backend is "sqlite" but pdo_sqlite is missing, using JSONL.');
        }

        return new JsonlLogStore(
            $this->dataDir . '/404',
            $this->clock(),
            max(1, $this->int('log.max_size_mb', 50)) * 1_048_576,
        );
    }
}
