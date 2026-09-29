<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

final readonly class TargetCheckerOptions
{
    /**
     * @param string $baseUrl          Site root URL. Internal targets ("/path") are made absolute with it.
     *                                 Requests to this host skip the private-address protection.
     * @param float  $timeoutSeconds   Idle and total timeout per request.
     * @param int    $maxRedirects     Redirects followed per target before "too_many_redirects".
     * @param int    $concurrency      Requests in flight at the same time.
     * @param int    $maxPerRun        Distinct URLs requested per run. The rest gets "rate_limited".
     * @param int    $minIntervalMs    Minimum gap between two requests to the same host.
     * @param bool   $checkExternal    When false, targets on other hosts get "skipped_external".
     */
    public function __construct(
        public string $baseUrl,
        public float $timeoutSeconds = 5.0,
        public int $maxRedirects = 5,
        public int $concurrency = 5,
        public int $maxPerRun = 500,
        public int $minIntervalMs = 200,
        public bool $checkExternal = true,
        public string $userAgent = 'GravRedirectManager/1.0 (+link check)',
    ) {
    }
}
