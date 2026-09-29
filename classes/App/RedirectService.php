<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Closure;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The application service: one object that the REST controllers and the CLI talk to. It owns no logic itself, it hands
 * out the focused services (rules, tester, 404 monitor, suggestions, import/export, checks, stats) wired to the same
 * ServiceFactory, so every entry point validates, writes and fires events the same way.
 *
 * Nothing here needs Grav: the Grav specific inputs arrive as SiteContext, ConfigWriter, RuleEvents and an optional
 * HTTP client, which Grav\GravBootstrap builds from the running site and tests replace with fakes.
 */
final class RedirectService
{
    private ?RuleService $rules = null;
    private ?TesterService $tester = null;
    private ?NotFoundService $notFound = null;
    private ?SuggestionService $suggestions = null;
    private ?ImportExportService $importExport = null;
    private ?CheckService $checks = null;
    private ?StatsService $stats = null;
    private ?StatsAccess $statsAccess = null;

    /**
     * @param Closure(): int|null $pendingDeletes number of deleted pages waiting for a decision (auto redirects); null = 0
     */
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site = new SiteContext(),
        private readonly ?ConfigWriter $config = null,
        private readonly ?RuleEvents $events = null,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?Closure $pendingDeletes = null,
    ) {
    }

    public function services(): ServiceFactory
    {
        return $this->services;
    }

    public function site(): SiteContext
    {
        return $this->site;
    }

    public function rules(): RuleService
    {
        return $this->rules ??= new RuleService($this->services, $this->site, $this->statsAccess(), $this->events());
    }

    public function tester(): TesterService
    {
        return $this->tester ??= new TesterService($this->services, $this->site);
    }

    public function notFound(): NotFoundService
    {
        return $this->notFound ??= new NotFoundService($this->services, $this->site, $this->rules(), $this->config);
    }

    public function suggestions(): SuggestionService
    {
        return $this->suggestions ??= new SuggestionService($this->services, $this->rules(), $this->events());
    }

    public function importExport(): ImportExportService
    {
        return $this->importExport ??= new ImportExportService($this->services, $this->site, $this->rules(), $this->suggestions());
    }

    public function checks(): CheckService
    {
        return $this->checks ??= new CheckService($this->services, $this->site, $this->rules(), $this->http);
    }

    public function stats(): StatsService
    {
        return $this->stats ??= new StatsService($this->services, $this->rules(), $this->statsAccess(), $this->pendingDeletes);
    }

    public function statsAccess(): StatsAccess
    {
        return $this->statsAccess ??= new StatsAccess($this->services->statsStore());
    }

    public function events(): RuleEvents
    {
        return $this->events ?? new RuleEvents(static fn (string $name, array $payload): array => $payload);
    }

    /**
     * Drops the compiled rule set and the analysis cache and compiles again. Returns the number of compiled rules.
     */
    public function rebuildCache(): int
    {
        $cache = $this->services->compiledCache();
        $cache->invalidate();
        foreach (glob($this->services->cacheDir() . '/analysis-*.php') ?: [] as $file) {
            @unlink($file);
        }

        return $cache->load()->count();
    }
}
