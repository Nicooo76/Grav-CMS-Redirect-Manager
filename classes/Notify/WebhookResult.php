<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

final readonly class WebhookResult
{
    public const ERROR_INVALID_URL = 'invalid_url';
    public const ERROR_ENCODE = 'encode_failed';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_CONNECTION = 'connection';
    public const ERROR_HTTP = 'http_error';

    /**
     * @param ?int    $status   HTTP status of the last attempt, null when none arrived
     * @param ?string $error    one of the ERROR_* codes, null on success
     * @param int     $attempts requests sent (0 when the configuration was refused)
     */
    public function __construct(
        public bool $ok,
        public ?int $status,
        public ?string $error,
        public int $attempts,
    ) {
    }
}
