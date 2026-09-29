<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

/**
 * Mutable state of one distinct URL while TargetChecker works on it.
 *
 * @internal
 */
final class CheckJob
{
    public string $currentUrl;
    public string $method = 'HEAD';
    public int $redirects = 0;

    /** @var list<string> */
    public array $visited;

    /** IP the current hop is pinned to (external hosts), null for internal targets. */
    public ?string $pinnedIp = null;

    /** hrtime(true) of the first request, 0 while the job waits. */
    public int $startedNs = 0;

    /** hrtime(true) after which a stalled response counts as timed out. */
    public int $deadlineNs = 0;

    /** Status of the response that just settled, null after a failure. */
    public ?int $pendingStatus = null;

    /** @var array<string, list<string>> */
    public array $pendingHeaders = [];

    public ?string $pendingError = null;

    /** True while the current hop carries a Range header. */
    public bool $rangeSent = false;

    /**
     * @param list<int> $members indexes into the caller's target list that share this URL
     */
    public function __construct(
        public readonly string $url,
        public array $members,
    ) {
        $this->currentUrl = $url;
        $this->visited = [$url];
    }

    public function host(): string
    {
        $host = parse_url($this->currentUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
