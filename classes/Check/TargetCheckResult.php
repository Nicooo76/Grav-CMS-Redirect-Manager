<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

use DateTimeImmutable;

/**
 * Outcome of one live check of a rule target.
 *
 * `ok` is true only when a response with status < 400 arrived and no error occurred.
 * Results that carry a skip code (skipped_*, rate_limited, blocked_private, invalid_url)
 * are "not checked" and never count as dead, see isSkipped() and isDead().
 */
final readonly class TargetCheckResult
{
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_DNS = 'dns';
    public const ERROR_TLS = 'tls';
    public const ERROR_CONNECTION = 'connection';
    public const ERROR_TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const ERROR_INVALID_URL = 'invalid_url';
    public const ERROR_SKIPPED_EXTERNAL = 'skipped_external';
    public const ERROR_SKIPPED_DYNAMIC = 'skipped_dynamic';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_BLOCKED_PRIVATE = 'blocked_private';

    /** Errors that mean "the target is unreachable". */
    private const DEAD_ERRORS = [
        self::ERROR_TIMEOUT,
        self::ERROR_DNS,
        self::ERROR_TLS,
        self::ERROR_CONNECTION,
        self::ERROR_TOO_MANY_REDIRECTS,
    ];

    /** Codes that mean "no request was made (or its result is unknown)". */
    private const SKIP_ERRORS = [
        self::ERROR_INVALID_URL,
        self::ERROR_SKIPPED_EXTERNAL,
        self::ERROR_SKIPPED_DYNAMIC,
        self::ERROR_RATE_LIMITED,
        self::ERROR_BLOCKED_PRIVATE,
    ];

    public function __construct(
        public string $ruleId,
        public string $url,
        public ?int $status,
        public bool $ok,
        public ?string $error,
        public ?string $finalUrl,
        public int $redirects,
        public int $durationMs,
        public DateTimeImmutable $checkedAt,
    ) {
    }

    public function isDead(): bool
    {
        if ($this->error !== null && in_array($this->error, self::DEAD_ERRORS, true)) {
            return true;
        }

        return $this->status !== null && $this->status >= 400;
    }

    public function isSkipped(): bool
    {
        return $this->error !== null && in_array($this->error, self::SKIP_ERRORS, true);
    }

    /**
     * @return array{rule_id: string, url: string, status: ?int, ok: bool, error: ?string, final_url: ?string, redirects: int, duration_ms: int, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'url' => $this->url,
            'status' => $this->status,
            'ok' => $this->ok,
            'error' => $this->error,
            'final_url' => $this->finalUrl,
            'redirects' => $this->redirects,
            'duration_ms' => $this->durationMs,
            'checked_at' => $this->checkedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $ruleId = $data['rule_id'] ?? null;
        $url = $data['url'] ?? null;
        $checkedAt = $data['checked_at'] ?? null;
        if (!is_string($ruleId) || $ruleId === '' || !is_string($url) || !is_string($checkedAt)) {
            return null;
        }
        try {
            $when = new DateTimeImmutable($checkedAt);
        } catch (\Exception) {
            return null;
        }
        $status = $data['status'] ?? null;
        $error = $data['error'] ?? null;
        $finalUrl = $data['final_url'] ?? null;

        return new self(
            $ruleId,
            $url,
            is_int($status) ? $status : null,
            ($data['ok'] ?? false) === true,
            is_string($error) ? $error : null,
            is_string($finalUrl) ? $finalUrl : null,
            is_int($data['redirects'] ?? null) ? $data['redirects'] : 0,
            is_int($data['duration_ms'] ?? null) ? $data['duration_ms'] : 0,
            $when,
        );
    }
}
