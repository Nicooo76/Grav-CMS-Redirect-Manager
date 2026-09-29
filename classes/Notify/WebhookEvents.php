<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

/** Names of the webhook events and builders for their `data` objects. */
final class WebhookEvents
{
    public const NOT_FOUND_THRESHOLD = 'not_found_threshold';
    public const DEAD_TARGET = 'dead_target';

    /**
     * @param string  $window       human-readable counting window, for example "24h" or "7d"
     * @param ?string $firstSeen    ISO 8601 time of the first hit in the window
     * @param ?string $topReferer   most frequent referer of the hits, if any
     *
     * @return array{path: string, hits: int, window: string, first_seen: ?string, top_referer: ?string}
     */
    public static function notFoundThreshold(
        string $path,
        int $hits,
        string $window,
        ?string $firstSeen = null,
        ?string $topReferer = null,
    ): array {
        return [
            'path' => $path,
            'hits' => $hits,
            'window' => $window,
            'first_seen' => $firstSeen,
            'top_referer' => $topReferer,
        ];
    }

    /**
     * @return array{rule_id: string, source: string, target: string, status: ?int, error: ?string}
     */
    public static function deadTarget(string $ruleId, string $source, string $target, ?int $status, ?string $error): array
    {
        return [
            'rule_id' => $ruleId,
            'source' => $source,
            'target' => $target,
            'status' => $status,
            'error' => $error,
        ];
    }
}
