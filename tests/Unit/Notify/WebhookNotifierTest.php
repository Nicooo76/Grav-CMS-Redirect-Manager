<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Notify;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Notify\WebhookEvents;
use Grav\Plugin\RedirectManager\Notify\WebhookNotifier;
use Grav\Plugin\RedirectManager\Notify\WebhookResult;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(WebhookNotifier::class)]
#[CoversClass(WebhookResult::class)]
#[CoversClass(WebhookEvents::class)]
final class WebhookNotifierTest extends TestCase
{
    private const URL = 'https://hooks.example.org/redirects';
    private const SECRET = 's3cret-value';

    private FixedClock $clock;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
        $this->requests = [];
        $this->sleeps = [];
    }

    /**
     * @param list<callable(): ResponseInterface> $answers one per expected attempt
     */
    private function notifier(array $answers, string $url = self::URL, string $secret = self::SECRET): WebhookNotifier
    {
        $client = new MockHttpClient(function (string $method, string $u, array $options) use (&$answers): ResponseInterface {
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $lines) {
                $headers[$name] = substr($lines[0], strlen((string) $name) + 2);
            }
            $this->requests[] = ['method' => $method, 'url' => $u, 'headers' => $headers, 'body' => (string) $options['body'], 'options' => $options];
            $next = array_shift($answers);
            self::assertNotNull($next, 'more requests than answers');

            return $next();
        });

        return new WebhookNotifier($client, $this->clock, $url, $secret, 5, 'https://site.test', function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private static function answer(int $code): callable
    {
        return static fn (): ResponseInterface => new MockResponse('', ['http_code' => $code]);
    }

    public function testPostsSignedJson(): void
    {
        $notifier = $this->notifier([self::answer(204)]);
        $result = $notifier->send(WebhookEvents::NOT_FOUND_THRESHOLD, WebhookEvents::notFoundThreshold('/old', 30, '24h', '2026-09-29T08:00:00+00:00', 'https://google.com/'));

        self::assertTrue($result->ok);
        self::assertSame(204, $result->status);
        self::assertNull($result->error);
        self::assertSame(1, $result->attempts);
        self::assertSame([], $this->sleeps);

        $req = $this->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame(self::URL, $req['url']);
        self::assertSame(0, $req['options']['max_redirects']);
        self::assertSame('application/json', $req['headers']['content-type']);
        self::assertSame(WebhookNotifier::USER_AGENT, $req['headers']['user-agent']);
        self::assertSame('not_found_threshold', $req['headers']['x-redirect-manager-event']);
        self::assertSame('1790683200', $req['headers']['x-redirect-manager-timestamp']);

        $body = json_decode($req['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('not_found_threshold', $body['event']);
        self::assertSame('2026-09-29T12:00:00+00:00', $body['sent_at']);
        self::assertSame('https://site.test', $body['site']);
        self::assertSame(
            ['path' => '/old', 'hits' => 30, 'window' => '24h', 'first_seen' => '2026-09-29T08:00:00+00:00', 'top_referer' => 'https://google.com/'],
            $body['data'],
        );
        self::assertStringContainsString('https://google.com/', $req['body'], 'slashes are not escaped');
    }

    public function testSignatureMatchesTheDocumentedFormula(): void
    {
        $this->notifier([self::answer(200)])->send('dead_target', ['x' => 'ä']);
        $req = $this->requests[0];

        $expected = 'sha256=' . hash_hmac('sha256', $req['headers']['x-redirect-manager-timestamp'] . '.' . $req['body'], self::SECRET);
        self::assertSame($expected, $req['headers']['x-redirect-manager-signature']);
        self::assertStringContainsString('ä', $req['body'], 'unicode is not escaped');
    }

    public function testReceiverCanVerifyWhatWasSent(): void
    {
        $this->notifier([self::answer(200)])->send('dead_target', ['a' => 1]);
        $req = $this->requests[0];
        $ts = $req['headers']['x-redirect-manager-timestamp'];
        $sig = $req['headers']['x-redirect-manager-signature'];
        $now = $this->clock->now()->getTimestamp();

        self::assertTrue(WebhookNotifier::verify($req['body'], $ts, $sig, self::SECRET, 300, $now));
        self::assertTrue(WebhookNotifier::verify($req['body'], $ts, $sig, self::SECRET, 300, $now + 300));
        self::assertFalse(WebhookNotifier::verify($req['body'], $ts, $sig, self::SECRET, 300, $now + 301), 'too old');
        self::assertFalse(WebhookNotifier::verify($req['body'], $ts, $sig, self::SECRET, 300, $now - 301), 'from the future');
        self::assertFalse(WebhookNotifier::verify($req['body'] . ' ', $ts, $sig, self::SECRET, 300, $now), 'body changed');
        self::assertFalse(WebhookNotifier::verify($req['body'], (string) ((int) $ts + 1), $sig, self::SECRET, 300, $now), 'timestamp changed');
        self::assertFalse(WebhookNotifier::verify($req['body'], $ts, $sig, 'other', 300, $now), 'wrong secret');
        self::assertFalse(WebhookNotifier::verify($req['body'], $ts, substr($sig, 7), self::SECRET, 300, $now), 'prefix missing');
        self::assertFalse(WebhookNotifier::verify($req['body'], 'abc', $sig, self::SECRET, 300, $now), 'timestamp not numeric');
        self::assertFalse(WebhookNotifier::verify($req['body'], '', $sig, self::SECRET, 300, $now));
        self::assertFalse(WebhookNotifier::verify($req['body'], $ts, $sig, '', 300, $now), 'empty secret never verifies');
    }

    public function testNoSignatureHeaderWithoutSecret(): void
    {
        $this->notifier([self::answer(200)], self::URL, '')->send('dead_target', []);

        self::assertArrayNotHasKey('x-redirect-manager-signature', $this->requests[0]['headers']);
        self::assertArrayHasKey('x-redirect-manager-timestamp', $this->requests[0]['headers']);
    }

    public function testRetriesOnceOn503ThenSucceeds(): void
    {
        $result = $this->notifier([self::answer(503), self::answer(200)])->send('dead_target', []);

        self::assertTrue($result->ok);
        self::assertSame(200, $result->status);
        self::assertSame(2, $result->attempts);
        self::assertSame([500], $this->sleeps);
        self::assertCount(2, $this->requests);
        self::assertSame($this->requests[0]['body'], $this->requests[1]['body'], 'a retry resends the same signed body');
        self::assertSame($this->requests[0]['headers']['x-redirect-manager-signature'], $this->requests[1]['headers']['x-redirect-manager-signature']);
    }

    public function testGivesUpAfterOneRetry(): void
    {
        $result = $this->notifier([self::answer(502), self::answer(500)])->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertSame(500, $result->status);
        self::assertSame(WebhookResult::ERROR_HTTP, $result->error);
        self::assertSame(2, $result->attempts);
    }

    public function testNoRetryOn400(): void
    {
        $result = $this->notifier([self::answer(400)])->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertSame(400, $result->status);
        self::assertSame(WebhookResult::ERROR_HTTP, $result->error);
        self::assertSame(1, $result->attempts);
        self::assertSame([], $this->sleeps);
    }

    public function testRedirectIsAFailureAndNotFollowed(): void
    {
        $result = $this->notifier([self::answer(302)])->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertSame(302, $result->status);
        self::assertSame(1, $result->attempts);
    }

    public function testTimeoutGivesOkFalseWithoutExceptionAndRetries(): void
    {
        $timeout = static function (): never {
            throw new TimeoutException('Idle timeout reached');
        };
        $result = $this->notifier([$timeout, $timeout])->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertNull($result->status);
        self::assertSame(WebhookResult::ERROR_TIMEOUT, $result->error);
        self::assertSame(2, $result->attempts);
        self::assertSame([500], $this->sleeps);
    }

    public function testTimeoutThenSuccess(): void
    {
        $timeout = static fn (): ResponseInterface => new MockResponse('', ['error' => 'cURL error 28: Operation timed out']);
        $result = $this->notifier([$timeout, self::answer(200)])->send('dead_target', []);

        self::assertTrue($result->ok);
        self::assertSame(2, $result->attempts);
    }

    public function testConnectionErrorIsFinalAndDoesNotThrow(): void
    {
        $refused = static function (): never {
            throw new TransportException('cURL error 7: Connection refused');
        };
        $result = $this->notifier([$refused])->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertSame(WebhookResult::ERROR_CONNECTION, $result->error);
        self::assertSame(1, $result->attempts);
        self::assertSame([], $this->sleeps);
    }

    public function testMessageBasedTimeoutIsRecognized(): void
    {
        $slow = static function (): never {
            throw new TransportException('Operation timed out');
        };
        $result = $this->notifier([$slow, $slow])->send('dead_target', []);

        self::assertSame(WebhookResult::ERROR_TIMEOUT, $result->error);
        self::assertSame(2, $result->attempts);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://hooks.example.org/x', true],
            'https with port' => ['https://hooks.example.org:8443/x?a=1', true],
            'http remote' => ['http://hooks.example.org/x', false],
            'http localhost' => ['http://localhost:8080/hook', true],
            'http 127.0.0.1' => ['http://127.0.0.1/hook', true],
            'http ipv6 loopback' => ['http://[::1]:3000/hook', true],
            'http dev host' => ['http://receiver.localhost/hook', true],
            'http lookalike' => ['http://localhost.evil.example/hook', false],
            'ftp' => ['ftp://hooks.example.org/x', false],
            'no scheme' => ['hooks.example.org/x', false],
            'credentials' => ['https://user:pw@hooks.example.org/', false],
            'empty' => ['', false],
            'javascript' => ['javascript:alert(1)', false],
        ];
    }

    #[DataProvider('urls')]
    public function testUrlPolicy(string $url, bool $allowed): void
    {
        self::assertSame($allowed, WebhookNotifier::isAllowedUrl($url));
    }

    public function testHttpUrlIsRejectedWithoutRequest(): void
    {
        $result = $this->notifier([], 'http://hooks.example.org/x')->send('dead_target', []);

        self::assertFalse($result->ok);
        self::assertSame(WebhookResult::ERROR_INVALID_URL, $result->error);
        self::assertSame(0, $result->attempts);
        self::assertNull($result->status);
        self::assertSame([], $this->requests);
    }

    public function testLocalhostHttpIsSent(): void
    {
        $result = $this->notifier([self::answer(200)], 'http://localhost:9000/hook')->send('dead_target', []);

        self::assertTrue($result->ok);
        self::assertSame('http://localhost:9000/hook', $this->requests[0]['url']);
    }

    public function testInvalidUtf8IsSubstitutedNotFatal(): void
    {
        $result = $this->notifier([self::answer(200)])->send('not_found_threshold', ['path' => "/bad\xB1path"]);

        self::assertTrue($result->ok);
        self::assertNotNull(json_decode($this->requests[0]['body'], true));
    }

    public function testUnencodablePayloadReturnsErrorResult(): void
    {
        $payload = [];
        $payload['self'] = &$payload;
        $result = $this->notifier([])->send('dead_target', $payload);

        self::assertFalse($result->ok);
        self::assertSame(WebhookResult::ERROR_ENCODE, $result->error);
        self::assertSame(0, $result->attempts);
    }

    public function testDefaultSleeperIsUsable(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 200])]);
        $notifier = new WebhookNotifier($client, $this->clock, self::URL, self::SECRET);

        self::assertTrue($notifier->send('dead_target', [])->ok);
    }

    public function testEventBuilders(): void
    {
        self::assertSame(
            ['path' => '/p', 'hits' => 5, 'window' => '7d', 'first_seen' => null, 'top_referer' => null],
            WebhookEvents::notFoundThreshold('/p', 5, '7d'),
        );
        self::assertSame(
            ['rule_id' => 'r1', 'source' => '/a', 'target' => '/b', 'status' => 404, 'error' => null],
            WebhookEvents::deadTarget('r1', '/a', '/b', 404, null),
        );
    }
}
