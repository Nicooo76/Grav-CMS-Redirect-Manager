<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

use Grav\Plugin\RedirectManager\Util\Clock;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends signed JSON webhooks.
 *
 * Request: POST with body {"event": "...", "sent_at": "<ISO 8601>", "site": "...", "data": {...}}
 * and these headers:
 *
 *   Content-Type: application/json
 *   User-Agent: GravRedirectManager/1.0 (webhook)
 *   X-Redirect-Manager-Event: <event name>
 *   X-Redirect-Manager-Timestamp: <Unix time in seconds>
 *   X-Redirect-Manager-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the secret>
 *
 * Receivers must compute the HMAC over the raw request body (not a re-encoded copy), compare with
 * a constant-time function and reject old timestamps. verify() does exactly that; a receiver
 * written in PHP can call it directly. Without a secret no signature header is sent.
 *
 * Only https URLs are accepted, except for localhost. Redirects are not followed. 5xx answers and
 * timeouts are retried once after a short pause; other failures are final. send() never throws.
 */
final class WebhookNotifier
{
    public const USER_AGENT = 'GravRedirectManager/1.0 (webhook)';
    private const RETRY_BACKOFF_MS = 500;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper waits the given milliseconds (default: usleep)
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Clock $clock,
        private readonly string $url,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 5,
        private readonly string $site = '',
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** True for https URLs and for http URLs whose host is localhost, 127.0.0.1 or [::1]. */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme !== 'http') {
            return false;
        }
        $host = trim(strtolower($parts['host']), '[]');

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');
    }

    /**
     * @param array<mixed> $payload becomes the "data" member
     */
    public function send(string $event, array $payload): WebhookResult
    {
        if (!self::isAllowedUrl($this->url)) {
            return new WebhookResult(false, null, WebhookResult::ERROR_INVALID_URL, 0);
        }

        $now = $this->clock->now();
        try {
            $body = json_encode(
                ['event' => $event, 'sent_at' => $now->format(DATE_ATOM), 'site' => $this->site, 'data' => $payload],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return new WebhookResult(false, null, WebhookResult::ERROR_ENCODE, 0);
        }

        $timestamp = (string) $now->getTimestamp();
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
            'X-Redirect-Manager-Event' => $event,
            'X-Redirect-Manager-Timestamp' => $timestamp,
        ];
        if ($this->secret !== '') {
            $headers['X-Redirect-Manager-Signature'] = self::sign($body, $timestamp, $this->secret);
        }

        $result = $this->attempt($body, $headers, 1);
        $retryable = $result->error === WebhookResult::ERROR_TIMEOUT
            || ($result->status !== null && $result->status >= 500);
        if (!$result->ok && $retryable) {
            ($this->sleeper)(self::RETRY_BACKOFF_MS);
            $result = $this->attempt($body, $headers, 2);
        }

        return $result;
    }

    /**
     * Checks a received webhook. Pass the raw body and the header values as received.
     *
     * @param string $signature        value of X-Redirect-Manager-Signature ("sha256=<hex>")
     * @param int    $toleranceSeconds maximum age (and clock skew) of the timestamp
     * @param int    $now              current Unix time of the receiver
     */
    public static function verify(string $body, string $timestamp, string $signature, string $secret, int $toleranceSeconds, int $now): bool
    {
        if ($secret === '' || $timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::sign($body, $timestamp, $secret), $signature);
    }

    private static function sign(string $body, string $timestamp, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * @param array<string, string> $headers
     */
    private function attempt(string $body, array $headers, int $attempt): WebhookResult
    {
        try {
            $response = $this->client->request('POST', $this->url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => (float) $this->timeoutSeconds,
                'max_duration' => (float) $this->timeoutSeconds,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $response->cancel();
        } catch (TimeoutExceptionInterface) {
            return new WebhookResult(false, null, WebhookResult::ERROR_TIMEOUT, $attempt);
        } catch (TransportExceptionInterface $e) {
            $timedOut = preg_match('/timed out|timeout/i', $e->getMessage()) === 1;

            return new WebhookResult(false, null, $timedOut ? WebhookResult::ERROR_TIMEOUT : WebhookResult::ERROR_CONNECTION, $attempt);
        }

        if ($status >= 200 && $status < 300) {
            return new WebhookResult(true, $status, null, $attempt);
        }

        return new WebhookResult(false, $status, WebhookResult::ERROR_HTTP, $attempt);
    }
}
