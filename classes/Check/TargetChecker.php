<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

use Grav\Plugin\RedirectManager\Util\Clock;
use SplObjectStorage;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Checks whether rule targets still answer, without Grav.
 *
 * Sends HEAD (GET with "Range: bytes=0-0" after a 405/501), follows redirects by hand to report
 * the final URL, runs requests concurrently through HttpClientInterface::stream() and keeps a
 * minimum gap between requests to the same host. Network problems become result codes, never
 * exceptions.
 *
 * SSRF protection: external hosts are resolved first and refused (blocked_private) when any
 * address is private, loopback, link-local or reserved. The first address is then pinned for the
 * request, so a second DNS answer cannot redirect it. The host of the site's own baseUrl is
 * exempt, so a local development site can be checked. Every redirect hop is checked again.
 * A redirect that leaves the site while checkExternal is off ends the check at that redirect
 * (status of the redirect, no error, finalUrl = its Location).
 */
final class TargetChecker
{
    private const PLACEHOLDER = '/\$\d|\$\{\d+\}|\{[A-Za-z_][A-Za-z0-9_]*\}/';
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var callable(string): list<string> */
    private $resolver;

    /** @var callable(int): void */
    private $sleeper;

    private string $baseUrl;
    private string $baseHost;

    /** @var array<string, float> host => clock time (ms) of the last request start */
    private array $lastStart = [];

    /** @var array<string, list<string>> */
    private array $dnsCache = [];

    /** @var array<int, TargetCheckResult> */
    private array $out = [];

    /** @var array<int, string> input index => rule id */
    private array $ids = [];

    /**
     * @param (callable(string): list<string>)|null $resolver host => list of IP addresses (default: A and AAAA lookup)
     * @param (callable(int): void)|null            $sleeper  waits the given milliseconds (default: usleep)
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Clock $clock,
        private readonly TargetCheckerOptions $opts,
        ?callable $resolver = null,
        ?callable $sleeper = null,
    ) {
        $this->resolver = $resolver ?? self::defaultResolver(...);
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->baseUrl = rtrim($opts->baseUrl, '/');
        $this->baseHost = UrlTools::host($opts->baseUrl);
    }

    /**
     * @param list<array{id: string, target: string}> $targets
     *
     * @return list<TargetCheckResult> one result per input target, same order
     */
    public function check(array $targets): array
    {
        $this->lastStart = [];
        $this->dnsCache = [];
        $this->out = [];
        $this->ids = [];

        /** @var array<string, CheckJob> $jobs */
        $jobs = [];
        /** @var array<string, string> $rejected url => error code */
        $rejected = [];

        foreach (array_values($targets) as $i => $entry) {
            $id = $entry['id'];
            $target = trim($entry['target']);
            $this->ids[$i] = $id;

            if (preg_match(self::PLACEHOLDER, $target) === 1) {
                $this->out[$i] = $this->failure($id, $target, TargetCheckResult::ERROR_SKIPPED_DYNAMIC);
                continue;
            }
            $url = $this->absolute($target);
            if ($url === null) {
                $this->out[$i] = $this->failure($id, $target, TargetCheckResult::ERROR_INVALID_URL);
                continue;
            }
            if (isset($jobs[$url])) {
                $jobs[$url]->members[] = $i;
                continue;
            }
            if (isset($rejected[$url])) {
                $this->out[$i] = $this->failure($id, $url, $rejected[$url]);
                continue;
            }

            [$error, $pin] = $this->assess($url);
            if ($error !== null) {
                $rejected[$url] = $error;
                $this->out[$i] = $this->failure($id, $url, $error);
                continue;
            }
            $job = new CheckJob($url, [$i]);
            $job->pinnedIp = $pin;
            $jobs[$url] = $job;
        }

        $queue = array_values($jobs);
        foreach (array_slice($queue, max(0, $this->opts->maxPerRun)) as $job) {
            foreach ($job->members as $i) {
                $this->out[$i] = $this->failure($this->ids[$i], $job->url, TargetCheckResult::ERROR_RATE_LIMITED);
            }
        }
        $queue = array_slice($queue, 0, max(0, $this->opts->maxPerRun));

        $this->run($queue);

        ksort($this->out);
        $results = array_values($this->out);
        $this->out = [];

        return $results;
    }

    /**
     * @param list<CheckJob> $pending
     */
    private function run(array $pending): void
    {
        /** @var SplObjectStorage<ResponseInterface, CheckJob> $active */
        $active = new SplObjectStorage();
        $concurrency = max(1, $this->opts->concurrency);

        while ($pending !== [] || count($active) > 0) {
            $this->fill($pending, $active, $concurrency);

            if (count($active) === 0) {
                if ($pending === []) {
                    break;
                }
                $this->waitForSlot($pending);
                continue;
            }

            $poll = $this->pollTimeout($pending, count($active) >= $concurrency);
            $full = $poll === null;
            $timeout = $poll ?? $this->opts->timeoutSeconds;

            $responses = [];
            foreach ($active as $response) {
                $responses[] = $response;
            }

            foreach ($this->client->stream($responses, $timeout) as $response => $chunk) {
                if (!isset($active[$response])) {
                    continue;
                }
                $job = $active[$response];
                if ($this->onChunk($job, $response, $chunk, $full)) {
                    unset($active[$response]);
                    if (!$this->advanceOrFinish($job)) {
                        continue;
                    }
                    array_unshift($pending, $job);
                    break;
                }
            }
        }
    }

    /**
     * Starts every pending job that may start now.
     *
     * @param list<CheckJob>                                 $pending
     * @param SplObjectStorage<ResponseInterface, CheckJob> $active
     */
    private function fill(array &$pending, SplObjectStorage $active, int $concurrency): void
    {
        $remaining = [];
        foreach ($pending as $job) {
            if (count($active) >= $concurrency || $this->spacingRemainingMs($job) > 0) {
                $remaining[] = $job;
                continue;
            }
            $host = $job->host();
            $this->lastStart[$host] = $this->nowMs();
            $response = $this->start($job);
            if ($response !== null) {
                $active[$response] = $job;
            }
        }
        $pending = $remaining;
    }

    private function spacingRemainingMs(CheckJob $job): float
    {
        $last = $this->lastStart[$job->host()] ?? null;
        if ($last === null) {
            return 0.0;
        }

        return max(0.0, $this->opts->minIntervalMs - ($this->nowMs() - $last));
    }

    /**
     * Sleeps until the earliest pending job may start.
     *
     * @param list<CheckJob> $pending
     */
    private function waitForSlot(array $pending): void
    {
        $min = null;
        foreach ($pending as $job) {
            $r = $this->spacingRemainingMs($job);
            $min = $min === null ? $r : min($min, $r);
        }
        $ms = max(1, (int) ceil($min ?? 1.0));
        ($this->sleeper)($ms);
        // Progress must not depend on the clock moving (a test clock may stand still).
        foreach ($pending as $job) {
            if ($this->spacingRemainingMs($job) <= $ms) {
                unset($this->lastStart[$job->host()]);
            }
        }
    }

    /**
     * Timeout for the next stream() call. Null means "use the full request timeout".
     *
     * @param list<CheckJob> $pending
     */
    private function pollTimeout(array $pending, bool $concurrencyFull): ?float
    {
        if ($pending === [] || $concurrencyFull) {
            return null;
        }
        $min = null;
        foreach ($pending as $job) {
            $r = $this->spacingRemainingMs($job);
            $min = $min === null ? $r : min($min, $r);
        }
        if ($min === null) {
            return null;
        }

        return min($this->opts->timeoutSeconds, max(0.01, $min / 1000));
    }

    private function start(CheckJob $job): ?ResponseInterface
    {
        if ($job->startedNs === 0) {
            $job->startedNs = hrtime(true);
        }
        $job->deadlineNs = hrtime(true) + (int) ($this->opts->timeoutSeconds * 1_000_000_000);

        $headers = ['User-Agent' => $this->opts->userAgent, 'Accept' => '*/*'];
        $job->rangeSent = $job->method === 'GET';
        if ($job->rangeSent) {
            $headers['Range'] = 'bytes=0-0';
        }
        $options = [
            'headers' => $headers,
            'max_redirects' => 0,
            'timeout' => $this->opts->timeoutSeconds,
            'max_duration' => $this->opts->timeoutSeconds,
            'buffer' => false,
        ];
        if ($job->pinnedIp !== null) {
            $options['resolve'] = [$job->host() => $job->pinnedIp];
        }

        try {
            return $this->client->request($job->method, $job->currentUrl, $options);
        } catch (\Exception $e) {
            // Transport errors from the client, or an option/URL it refuses (InvalidArgumentException).
            $this->finish($job, null, $e instanceof ExceptionInterface ? $this->errorCode($e) : TargetCheckResult::ERROR_INVALID_URL);
        }

        return null;
    }

    /**
     * Handles one stream chunk. Returns true once the response is settled (headers seen or failed).
     */
    private function onChunk(CheckJob $job, ResponseInterface $response, ChunkInterface $chunk, bool $fullTimeout): bool
    {
        try {
            if ($chunk->isTimeout()) {
                if ($fullTimeout || hrtime(true) >= $job->deadlineNs) {
                    $response->cancel();
                    $job->pendingStatus = null;
                    $job->pendingError = TargetCheckResult::ERROR_TIMEOUT;

                    return true;
                }

                return false;
            }
            if (!$chunk->isFirst()) {
                return false;
            }
            $job->pendingStatus = $response->getStatusCode();
            $job->pendingHeaders = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            $job->pendingStatus = null;
            $job->pendingError = $this->errorCode($e);

            return true;
        }
        // Headers are all we need; do not download the body.
        $response->cancel();

        return true;
    }

    /**
     * Decides what to do with a settled response. Returns true when the job continues with
     * another request (redirect or GET fallback), false when it finished.
     */
    private function advanceOrFinish(CheckJob $job): bool
    {
        $status = $job->pendingStatus;
        $headers = $job->pendingHeaders;
        $error = $job->pendingError;
        $job->pendingStatus = null;
        $job->pendingHeaders = [];
        $job->pendingError = null;

        if ($status === null) {
            $this->finish($job, null, $error ?? TargetCheckResult::ERROR_CONNECTION);

            return false;
        }

        if ($job->method === 'HEAD' && ($status === 405 || $status === 501)) {
            $job->method = 'GET';

            return true;
        }

        $location = $headers['location'][0] ?? null;
        if (in_array($status, self::REDIRECT_STATUSES, true) && is_string($location) && $location !== '') {
            return $this->follow($job, $status, $location);
        }

        if ($job->rangeSent && ($status === 206 || $status === 416)) {
            $status = 200;
        }
        $this->finish($job, $status, null);

        return false;
    }

    private function follow(CheckJob $job, int $status, string $location): bool
    {
        $next = UrlTools::normalize(UrlTools::resolve($job->currentUrl, $location));
        if ($next === null) {
            $this->finish($job, $status, TargetCheckResult::ERROR_INVALID_URL);

            return false;
        }
        ++$job->redirects;
        if ($job->redirects > $this->opts->maxRedirects || in_array($next, $job->visited, true)) {
            $this->finish($job, $status, TargetCheckResult::ERROR_TOO_MANY_REDIRECTS);

            return false;
        }

        [$error, $pin] = $this->assess($next);
        if ($error === TargetCheckResult::ERROR_SKIPPED_EXTERNAL) {
            $job->currentUrl = $next;
            $this->finish($job, $status, null);

            return false;
        }
        if ($error !== null) {
            $this->finish($job, $status, $error);

            return false;
        }

        $job->currentUrl = $next;
        $job->visited[] = $next;
        $job->pinnedIp = $pin;

        return true;
    }

    private function finish(CheckJob $job, ?int $status, ?string $error): void
    {
        $ok = $error === null && $status !== null && $status < 400;
        $finalUrl = ($status !== null || $job->redirects > 0) ? $job->currentUrl : null;
        $duration = $job->startedNs === 0 ? 0 : (int) round((hrtime(true) - $job->startedNs) / 1_000_000);
        $now = $this->clock->now();

        foreach ($job->members as $i) {
            $this->out[$i] = new TargetCheckResult(
                $this->ids[$i],
                $job->url,
                $status,
                $ok,
                $error,
                $finalUrl,
                $job->redirects,
                $duration,
                $now,
            );
        }
    }

    private function failure(string $id, string $url, string $error): TargetCheckResult
    {
        return new TargetCheckResult($id, $url, null, false, $error, null, 0, 0, $this->clock->now());
    }

    /** Turns a rule target into an absolute, normalized URL. Null when it cannot be one. */
    private function absolute(string $target): ?string
    {
        if ($target === '') {
            return null;
        }
        if (str_starts_with($target, '//')) {
            $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);

            return UrlTools::normalize((is_string($scheme) ? $scheme : 'https') . ':' . $target);
        }
        if ($target[0] === '/') {
            return UrlTools::normalize($this->baseUrl . $target);
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) === 1) {
            return UrlTools::normalize($target);
        }

        return null;
    }

    /**
     * Applies the host rules to a normalized URL.
     *
     * @return array{0: ?string, 1: ?string} error code (or null) and the IP to pin the request to
     */
    private function assess(string $url): array
    {
        $host = UrlTools::host($url);
        if ($host === $this->baseHost) {
            return [null, null];
        }
        if (!$this->opts->checkExternal) {
            return [TargetCheckResult::ERROR_SKIPPED_EXTERNAL, null];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return IpGuard::isPublic($host) ? [null, null] : [TargetCheckResult::ERROR_BLOCKED_PRIVATE, null];
        }

        $ips = $this->dnsCache[$host] ??= array_values(array_filter(
            ($this->resolver)($host),
            static fn (mixed $ip): bool => is_string($ip) && $ip !== '',
        ));
        if ($ips === []) {
            return [TargetCheckResult::ERROR_DNS, null];
        }
        foreach ($ips as $ip) {
            if (!IpGuard::isPublic($ip)) {
                return [TargetCheckResult::ERROR_BLOCKED_PRIVATE, null];
            }
        }

        return [null, $ips[0]];
    }

    private function errorCode(\Throwable $e): string
    {
        if ($e instanceof TimeoutExceptionInterface) {
            return TargetCheckResult::ERROR_TIMEOUT;
        }
        $m = strtolower($e->getMessage());
        if (preg_match('/curl error 6\b/', $m) === 1) {
            return TargetCheckResult::ERROR_DNS;
        }
        if (preg_match('/curl error 28\b|timed out|timeout/', $m) === 1) {
            return TargetCheckResult::ERROR_TIMEOUT;
        }
        if (preg_match('/curl error (35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)\b|\bssl\b|\btls\b|certificate|handshake/', $m) === 1) {
            return TargetCheckResult::ERROR_TLS;
        }
        if (preg_match('/resolve host|getaddrinfo|name or service not known|no address associated|nodename nor servname|dns/', $m) === 1) {
            return TargetCheckResult::ERROR_DNS;
        }
        if (preg_match('/redirect/', $m) === 1) {
            return TargetCheckResult::ERROR_TOO_MANY_REDIRECTS;
        }

        return TargetCheckResult::ERROR_CONNECTION;
    }

    private function nowMs(): float
    {
        return (float) $this->clock->now()->format('U.u') * 1000.0;
    }

    /**
     * @return list<string>
     */
    private static function defaultResolver(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
