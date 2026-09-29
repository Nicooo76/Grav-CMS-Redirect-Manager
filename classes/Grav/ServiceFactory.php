<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
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
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\CompiledRuleCache;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use Psr\Log\LoggerInterface;

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

    /**
     * @param array<string, mixed>                                                                                 $config
     * @param array{languages?: list<string>, default_language?: string, custom_base_url?: string, api_route?: string, admin_route?: string} $site
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
        /** @var RuleRepository */
        return $this->instances[__FUNCTION__] ??= new RuleRepository($this->dataDir, $this->clock());
    }

    public function compiledCache(): CompiledRuleCache
    {
        /** @var CompiledRuleCache */
        return $this->instances[__FUNCTION__] ??= new CompiledRuleCache(
            $this->cacheDir,
            $this->repository()->file(),
            new RuleCompiler(),
            $this->logger,
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
        /** @var HitRecorder */
        return $this->instances[__FUNCTION__] ??= new HitRecorder($this->dataDir . '/hits', $this->clock());
    }

    public function statsStore(): StatsStore
    {
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
        /** @var ResolvedPaths */
        return $this->instances[__FUNCTION__] ??= new ResolvedPaths($this->dataDir . '/404-state.json');
    }

    public function suggestionStore(): SuggestionStore
    {
        /** @var SuggestionStore */
        return $this->instances[__FUNCTION__] ??= new SuggestionStore($this->dataDir . '/suggestions.json', $this->clock());
    }

    public function checkResultStore(): CheckResultStore
    {
        /** @var CheckResultStore */
        return $this->instances[__FUNCTION__] ??= new CheckResultStore($this->dataDir . '/target-checks.json');
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
