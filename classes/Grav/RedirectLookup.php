<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\StatusCode;

/**
 * Answers "would this URL be redirected?" without side effects (no hit recorded, nothing sent).
 * Behind the Twig function redirect_for() and the filter redirect_target.
 *
 * Only rules that apply before Grav looks for a page are considered; "only if not found" rules are
 * not, because the lookup does not know whether a page exists.
 */
final class RedirectLookup
{
    /**
     * @param array<string, mixed> $server $_SERVER of the current request (base path detection)
     */
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly array $server = [],
        private readonly string $currentHost = '',
    ) {
    }

    /**
     * @return array{status: int, location: string, rule_id: string}|null
     */
    public function lookup(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
        $host = $parts['host'] ?? $this->currentHost;

        $factory = $this->services->requestFactory();
        $request = $factory->create($path, $parts['query'] ?? '', ['host' => $host], [], $this->server);
        if ($request === null || $factory->isExcluded($request->context->path)) {
            return null;
        }

        $result = $this->services->matcher()->match($request->context, MatchPhase::Early);
        if ($result === null) {
            return null;
        }

        $location = $result->status === StatusCode::PassThrough || $result->status->isError()
            ? ''
            : $this->services->responder()->location($result, $request);

        return ['status' => $result->status->value, 'location' => $location, 'rule_id' => $result->rule->id];
    }
}
