<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\Check\RunGuard;
use Grav\Plugin\RedirectManager\Check\TargetChecker;
use Grav\Plugin\RedirectManager\Check\TargetCheckerOptions;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Live check of rule targets: stored results and a manual run.
 *
 * A manual run is rate limited (checker.manual_interval seconds between two runs) through RunGuard; the scheduled
 * run passes $enforceInterval = false. Results are stored per rule by CheckResultStore, which also keeps last_run.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class CheckService
{
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site,
        private readonly RuleService $rules,
        private readonly ?HttpClientInterface $http,
    ) {
    }

    /**
     * Stored results of rules that still exist: dead targets first, then by rule id.
     *
     * @return array{last_run: string|null, results: list<Row>}
     */
    public function list(): array
    {
        $store = $this->services->checkResultStore();
        $byId = self::byId($this->rules->all());

        $entries = [];
        foreach ($store->all() as $id => $result) {
            $rule = $byId[$id] ?? null;
            if ($rule !== null) {
                $entries[] = ['dead' => $result->isDead(), 'id' => (string) $id, 'row' => $this->row($result, $rule)];
            }
        }
        usort($entries, static fn (array $a, array $b): int => ($b['dead'] <=> $a['dead']) ?: strcmp($a['id'], $b['id']));

        return [
            'last_run' => $store->lastRun()?->format(DATE_ATOM),
            'results' => array_map(static fn (array $e): array => $e['row'], $entries),
        ];
    }

    /**
     * Checks the targets of enabled redirect rules (all, or only $ids) and stores the results.
     *
     * @param list<string>|null $ids
     *
     * @return array{last_run: string|null, checked: int, dead: int, results: list<Row>}
     *
     * @throws ResourceNotFoundException when an id is not a rule
     * @throws RateLimitedException      when a run started less than checker.manual_interval seconds ago
     * @throws UnavailableException      when no HTTP client is available
     */
    public function run(?array $ids = null, bool $enforceInterval = true): array
    {
        $all = $this->rules->all();
        $byId = self::byId($all);

        $wanted = null;
        if ($ids !== null) {
            $wanted = [];
            foreach ($ids as $id) {
                if (!isset($byId[$id])) {
                    throw new ResourceNotFoundException('rule', (string) $id);
                }
                $wanted[$id] = true;
            }
        }

        $targets = [];
        foreach ($all as $rule) {
            if ($wanted !== null && !isset($wanted[$rule->id])) {
                continue;
            }
            if ($rule->enabled && $rule->status->needsTarget() && trim($rule->target) !== '') {
                $targets[] = ['id' => $rule->id, 'target' => $rule->target];
            }
        }

        $client = $this->client();
        $guard = null;
        if ($enforceInterval) {
            $guard = new RunGuard($this->services->dataDir() . '/check-run.lock', $this->services->clock(), max(0, $this->services->int('checker.manual_interval', 300)));
            if (!$guard->tryAcquire()) {
                throw new RateLimitedException(max(1, $guard->retryAfter()));
            }
        }

        try {
            $results = $targets === [] ? [] : (new TargetChecker($client, $this->services->clock(), $this->options()))->check($targets);
            $this->services->checkResultStore()->save($results);
        } finally {
            $guard?->release();
        }

        $checked = 0;
        $dead = 0;
        $rows = [];
        foreach ($results as $result) {
            $checked += $result->isSkipped() ? 0 : 1;
            $dead += $result->isDead() ? 1 : 0;
            $rows[] = $this->row($result, $byId[$result->ruleId] ?? null);
        }

        return [
            'last_run' => $this->services->checkResultStore()->lastRun()?->format(DATE_ATOM),
            'checked' => $checked,
            'dead' => $dead,
            'results' => $rows,
        ];
    }

    // ---------------------------------------------------------------- internals

    private function options(): TargetCheckerOptions
    {
        $timeout = $this->services->config('checker.timeout', 5);

        return new TargetCheckerOptions(
            baseUrl: $this->site->baseUrl,
            timeoutSeconds: is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 5.0,
            maxPerRun: $this->services->int('checker.max_per_run', 500),
            checkExternal: $this->services->bool('checker.check_external', true),
        );
    }

    /**
     * @throws UnavailableException
     */
    private function client(): HttpClientInterface
    {
        if ($this->http !== null) {
            return $this->http;
        }
        if (!class_exists(HttpClient::class)) {
            throw new UnavailableException('The live check needs symfony/http-client, which is not installed.');
        }

        return HttpClient::create();
    }

    /**
     * @return Row
     */
    private function row(TargetCheckResult $result, ?Rule $rule): array
    {
        return $result->toArray() + ['source' => $rule?->source, 'target' => $rule?->target];
    }

    /**
     * @param list<Rule> $rules
     *
     * @return array<string, Rule>
     */
    private static function byId(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->id] = $rule;
        }

        return $out;
    }
}
