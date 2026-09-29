<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One logged 404 request. Fields are already sanitized by NotFoundLogger.
 *
 * Serialized with short keys: t (unix seconds), p, q, r, ua, c, ip, l, h, m.
 * Empty query, referer and user agent and null ip / language are omitted.
 */
final readonly class NotFoundEntry
{
    public DateTimeImmutable $time;

    /**
     * @param string      $path     request path, starts with "/"
     * @param string      $query    query string without "?", secrets redacted
     * @param string      $referer  scheme + host + path of the referer, or ""
     * @param string|null $ip       anonymized address or null when not stored
     * @param string|null $language language code of the request, if any
     */
    public function __construct(
        DateTimeImmutable $time,
        public string $path,
        public string $query = '',
        public string $referer = '',
        public string $userAgent = '',
        public UserAgentClass $uaClass = UserAgentClass::Unknown,
        public ?string $ip = null,
        public ?string $language = null,
        public string $host = '',
        public string $method = 'GET',
    ) {
        $this->time = $time->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        $data = [
            't' => $this->time->getTimestamp(),
            'p' => $this->path,
        ];
        if ($this->query !== '') {
            $data['q'] = $this->query;
        }
        if ($this->referer !== '') {
            $data['r'] = $this->referer;
        }
        if ($this->userAgent !== '') {
            $data['ua'] = $this->userAgent;
        }
        $data['c'] = $this->uaClass->value;
        if ($this->ip !== null) {
            $data['ip'] = $this->ip;
        }
        if ($this->language !== null) {
            $data['l'] = $this->language;
        }
        $data['h'] = $this->host;
        $data['m'] = $this->method;

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException when time or path are missing or of the wrong type
     */
    public static function fromArray(array $data): self
    {
        $t = $data['t'] ?? null;
        $p = $data['p'] ?? null;
        if (!is_int($t) || !is_string($p)) {
            throw new InvalidArgumentException('404 entry needs an integer "t" and a string "p".');
        }

        return new self(
            new DateTimeImmutable('@' . $t),
            $p,
            self::string($data, 'q'),
            self::string($data, 'r'),
            self::string($data, 'ua'),
            UserAgentClass::tryFrom(self::string($data, 'c')) ?? UserAgentClass::Unknown,
            self::nullableString($data, 'ip'),
            self::nullableString($data, 'l'),
            self::string($data, 'h'),
            self::string($data, 'm', 'GET'),
        );
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
