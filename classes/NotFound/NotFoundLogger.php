<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * Turns a 404 request into a sanitized NotFoundEntry and stores it.
 *
 * Nothing is logged when logging is disabled, the method is not GET or HEAD, the path is on the
 * ignore list, or (with logBots = false) the user agent is classified as bot. Bots are logged by
 * default but carry their class, so the UI can filter them.
 *
 * The IP is stored only when the options allow it AND the anonymizer is not in mode None, and
 * then only in anonymized form. See FieldSanitizer for what happens to each field.
 */
final class NotFoundLogger
{
    public function __construct(
        private readonly LogStore $store,
        private readonly IgnoreList $ignore,
        private readonly IpAnonymizer $anonymizer,
        private readonly UserAgentClassifier $classifier,
        private readonly Clock $clock,
        private readonly NotFoundLoggerOptions $options = new NotFoundLoggerOptions(),
    ) {
    }

    /** @return NotFoundEntry|null the stored entry, or null when the request was not logged */
    public function log(
        string $path,
        string $query,
        ?string $referer,
        ?string $ua,
        ?string $ip,
        ?string $language,
        string $host,
        string $method = 'GET',
    ): ?NotFoundEntry {
        if (!$this->options->enabled) {
            return null;
        }
        $method = strtoupper(trim($method));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $path = FieldSanitizer::text($path, $this->options->maxPathBytes);
        if ($path === '' || $path[0] !== '/') {
            $path = mb_strcut('/' . $path, 0, $this->options->maxPathBytes, 'UTF-8');
        }
        $query = FieldSanitizer::query($query, $this->options->maxQueryBytes);
        if ($this->ignore->matches($path, $query)) {
            return null;
        }

        $userAgent = FieldSanitizer::text($ua ?? '', $this->options->maxUserAgentBytes);
        $class = $this->classifier->classify($userAgent);
        if ($class === UserAgentClass::Bot && !$this->options->logBots) {
            return null;
        }

        $entry = new NotFoundEntry(
            $this->clock->now(),
            $path,
            $query,
            FieldSanitizer::referer($referer, $this->options->maxRefererBytes),
            $userAgent,
            $class,
            $this->options->storeIp ? $this->anonymizer->anonymize($ip) : null,
            FieldSanitizer::language($language),
            FieldSanitizer::host($host),
            $method,
        );
        $this->store->append($entry);

        return $entry;
    }

    /**
     * Retention: deletes entries older than $days days. $days <= 0 keeps everything.
     *
     * @return int number of deleted entries
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->store->purge($this->clock->now()->modify('-' . $days . ' days'));
    }
}
