<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Check;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Check\CheckJob;
use Grav\Plugin\RedirectManager\Check\IpGuard;
use Grav\Plugin\RedirectManager\Check\TargetChecker;
use Grav\Plugin\RedirectManager\Check\TargetCheckerOptions;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\Check\UrlTools;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TargetChecker::class)]
#[CoversClass(TargetCheckResult::class)]
#[CoversClass(TargetCheckerOptions::class)]
#[CoversClass(CheckJob::class)]
#[CoversClass(UrlTools::class)]
#[CoversClass(IpGuard::class)]
final class TargetCheckerTest extends TestCase
{
    private const BASE = 'https://site.test';
    private const PUBLIC_IP = '93.184.216.34';

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00'));
    }

    /**
     * @param array<string, mixed>                  $options
     * @param (callable(string): list<string>)|null $resolver
     * @param (callable(int): void)|null            $sleeper
     */
    private function checker(RouteClient $client, array $options = [], ?callable $resolver = null, ?callable $sleeper = null): TargetChecker
    {
        $options += ['baseUrl' => self::BASE, 'minIntervalMs' => 0];

        return new TargetChecker(
            $client,
            $this->clock,
            new TargetCheckerOptions(...$options),
            $resolver ?? static fn (string $host): array => [self::PUBLIC_IP],
            $sleeper ?? static function (int $ms): void {
            },
        );
    }

    /**
     * @param list<string> $targets
     *
     * @return list<TargetCheckResult>
     */
    private function runChecks(TargetChecker $checker, array $targets): array
    {
        $in = [];
        foreach ($targets as $i => $target) {
            $in[] = ['id' => 'r' . $i, 'target' => $target];
        }

        return $checker->check($in);
    }

    public function testEmptyInputGivesNoResults(): void
    {
        self::assertSame([], $this->checker(new RouteClient())->check([]));
    }

    public function testOkTargetUsesHeadAndBuildsAbsoluteUrl(): void
    {
        $client = new RouteClient(['HEAD https://site.test/a' => ['code' => 200]]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame('r0', $r->ruleId);
        self::assertSame('https://site.test/a', $r->url);
        self::assertSame(200, $r->status);
        self::assertTrue($r->ok);
        self::assertNull($r->error);
        self::assertSame('https://site.test/a', $r->finalUrl);
        self::assertSame(0, $r->redirects);
        self::assertGreaterThanOrEqual(0, $r->durationMs);
        self::assertEquals($this->clock->now(), $r->checkedAt);
        self::assertFalse($r->isDead());
        self::assertFalse($r->isSkipped());
        self::assertSame(['HEAD https://site.test/a'], $client->urls());
    }

    public function testSendsConfiguredHeadersAndOptions(): void
    {
        $client = new RouteClient();
        $this->runChecks($this->checker($client, ['userAgent' => 'Probe/9', 'timeoutSeconds' => 3.0]), ['/a']);

        self::assertSame('Probe/9', $client->header(0, 'user-agent'));
        self::assertSame(0, $client->requests[0]['options']['max_redirects']);
        self::assertSame(3.0, $client->requests[0]['options']['timeout']);
        self::assertArrayNotHasKey('resolve', $client->requests[0]['options']);
    }

    public function testRedirectChainReportsFinalUrl(): void
    {
        $client = new RouteClient([
            'https://site.test/a' => ['code' => 301, 'location' => '/b'],
            'https://site.test/b' => ['code' => 302, 'location' => 'https://site.test/c?x=1#frag'],
            'https://site.test/c?x=1' => ['code' => 200],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(200, $r->status);
        self::assertTrue($r->ok);
        self::assertSame('https://site.test/a', $r->url);
        self::assertSame('https://site.test/c?x=1', $r->finalUrl);
        self::assertSame(2, $r->redirects);
    }

    public function testRelativeLocationsAreResolved(): void
    {
        $client = new RouteClient([
            'https://site.test/dir/a' => ['code' => 301, 'location' => '../up'],
            'https://site.test/up' => ['code' => 301, 'location' => 'sub/page'],
            'https://site.test/sub/page' => ['code' => 301, 'location' => '?q=2'],
            'https://site.test/sub/page?q=2' => ['code' => 301, 'location' => '//site.test/proto'],
            'https://site.test/proto' => ['code' => 301, 'location' => '#same'],
        ]);
        [$r] = $this->runChecks($this->checker($client, ['maxRedirects' => 9]), ['/dir/a']);

        // The last hop redirects to itself (fragment only): that is a loop.
        self::assertSame(TargetCheckResult::ERROR_TOO_MANY_REDIRECTS, $r->error);
        self::assertSame('https://site.test/proto', $r->finalUrl);
        self::assertSame(
            ['/dir/a', '/up', '/sub/page', '/sub/page?q=2', '/proto'],
            array_map(static fn (array $q): string => substr($q['url'], strlen(self::BASE)), $client->requests),
        );
    }

    public function testRedirectLoopIsDetected(): void
    {
        $client = new RouteClient([
            'https://site.test/a' => ['code' => 301, 'location' => '/b'],
            'https://site.test/b' => ['code' => 301, 'location' => '/a'],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_TOO_MANY_REDIRECTS, $r->error);
        self::assertSame(301, $r->status);
        self::assertFalse($r->ok);
        self::assertTrue($r->isDead());
        self::assertCount(2, $client->requests);
    }

    public function testTooManyRedirects(): void
    {
        $routes = [];
        for ($i = 0; $i < 8; ++$i) {
            $routes['https://site.test/h' . $i] = ['code' => 302, 'location' => '/h' . ($i + 1)];
        }
        $client = new RouteClient($routes);
        [$r] = $this->runChecks($this->checker($client, ['maxRedirects' => 3]), ['/h0']);

        self::assertSame(TargetCheckResult::ERROR_TOO_MANY_REDIRECTS, $r->error);
        self::assertSame(4, $r->redirects);
        self::assertSame('https://site.test/h3', $r->finalUrl);
        self::assertCount(4, $client->requests);
        self::assertTrue($r->isDead());
    }

    public function testRedirectWithoutLocationIsFinal(): void
    {
        [$r] = $this->runChecks($this->checker(new RouteClient(['https://site.test/a' => ['code' => 302]])), ['/a']);

        self::assertSame(302, $r->status);
        self::assertTrue($r->ok);
        self::assertSame(0, $r->redirects);
    }

    public function testRedirectToUnsupportedSchemeIsInvalidUrl(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 301, 'location' => 'mailto:x@site.test']]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_INVALID_URL, $r->error);
        self::assertSame(301, $r->status);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function errorStatuses(): array
    {
        return ['404' => [404, true], '410' => [410, true], '500' => [500, true], '503' => [503, true], '403' => [403, true]];
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusesAreDead(int $status, bool $dead): void
    {
        [$r] = $this->runChecks($this->checker(new RouteClient(['https://site.test/a' => ['code' => $status]])), ['/a']);

        self::assertSame($status, $r->status);
        self::assertFalse($r->ok);
        self::assertNull($r->error);
        self::assertSame($dead, $r->isDead());
    }

    public function testRedirectToDeadPageIsDead(): void
    {
        $client = new RouteClient([
            'https://site.test/a' => ['code' => 301, 'location' => '/gone'],
            'https://site.test/gone' => ['code' => 410],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(410, $r->status);
        self::assertSame('https://site.test/gone', $r->finalUrl);
        self::assertSame(1, $r->redirects);
        self::assertTrue($r->isDead());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function headUnsupported(): array
    {
        return ['405' => [405], '501' => [501]];
    }

    #[DataProvider('headUnsupported')]
    public function testHeadFallsBackToRangedGet(int $headStatus): void
    {
        $client = new RouteClient([
            'HEAD https://site.test/a' => ['code' => $headStatus],
            'GET https://site.test/a' => ['code' => 206],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(['HEAD https://site.test/a', 'GET https://site.test/a'], $client->urls());
        self::assertNull($client->header(0, 'range'));
        self::assertSame('bytes=0-0', $client->header(1, 'range'));
        self::assertSame(200, $r->status, '206 and 416 to a ranged GET count as 200');
        self::assertTrue($r->ok);
        self::assertSame(0, $r->redirects);
    }

    public function testGetFallbackAlsoAcceptsRangeNotSatisfiable(): void
    {
        $client = new RouteClient([
            'HEAD https://site.test/a' => ['code' => 405],
            'GET https://site.test/a' => ['code' => 416],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(200, $r->status);
    }

    public function testGetFallbackReportsRealErrors(): void
    {
        $client = new RouteClient([
            'HEAD https://site.test/a' => ['code' => 405],
            'GET https://site.test/a' => ['code' => 404],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(404, $r->status);
        self::assertTrue($r->isDead());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function transportErrors(): array
    {
        return [
            'idle timeout' => ['Idle timeout reached for "https://site.test/a".', 'timeout'],
            'curl timeout' => ['cURL error 28: Operation timed out after 5001 milliseconds', 'timeout'],
            'curl dns' => ['cURL error 6: Could not resolve host: nope.test', 'dns'],
            'getaddrinfo' => ['getaddrinfo failed for nope.test', 'dns'],
            'curl tls' => ['cURL error 60: SSL certificate problem: certificate has expired', 'tls'],
            'handshake' => ['TLS handshake failure', 'tls'],
            'refused' => ['cURL error 7: Failed to connect to site.test port 443: Connection refused', 'connection'],
            'reset' => ['Connection reset by peer', 'connection'],
            'redirect' => ['Max redirects exceeded', 'too_many_redirects'],
        ];
    }

    #[DataProvider('transportErrors')]
    public function testTransportErrorInResponseIsMapped(string $message, string $code): void
    {
        [$r] = $this->runChecks($this->checker(new RouteClient(['https://site.test/a' => ['error' => $message]])), ['/a']);

        self::assertSame($code, $r->error);
        self::assertNull($r->status);
        self::assertNull($r->finalUrl);
        self::assertFalse($r->ok);
        self::assertTrue($r->isDead());
    }

    #[DataProvider('transportErrors')]
    public function testTransportExceptionFromRequestIsMapped(string $message, string $code): void
    {
        $client = new RouteClient(['https://site.test/a' => static function () use ($message): never {
            throw new TransportException($message);
        }]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame($code, $r->error);
    }

    public function testTimeoutExceptionTypeWinsOverMessage(): void
    {
        $client = new RouteClient(['https://site.test/a' => static function (): never {
            throw new TimeoutException('something else entirely');
        }]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_TIMEOUT, $r->error);
    }

    public function testRefusedOptionsBecomeInvalidUrl(): void
    {
        $client = new RouteClient(['https://site.test/a' => static function (): never {
            throw new \InvalidArgumentException('Malformed URL');
        }]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_INVALID_URL, $r->error);
        self::assertFalse($r->isDead());
    }

    public function testErrorOnLaterHopKeepsFinalUrlAndRedirectCount(): void
    {
        $client = new RouteClient([
            'https://site.test/a' => ['code' => 301, 'location' => '/b'],
            'https://site.test/b' => ['error' => 'cURL error 28: timed out'],
        ]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame('timeout', $r->error);
        self::assertSame('https://site.test/b', $r->finalUrl);
        self::assertSame(1, $r->redirects);
    }

    public function testStreamTimeoutChunkOnFullTimeoutIsATimeout(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 200]]);
        $client->streamHook = static fn (array $responses): array => [[$responses[0], new ErrorChunk(0, 'idle')]];
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_TIMEOUT, $r->error);
        self::assertTrue($r->isDead());
    }

    public function testStreamTimeoutChunkBeforeDeadlineIsIgnored(): void
    {
        $client = new RouteClient();
        $fired = false;
        $client->streamHook = static function (array $responses) use (&$fired): array {
            if ($fired) {
                return [];
            }
            $fired = true;

            return [[$responses[0], new ErrorChunk(0, 'poll')]];
        };
        // Same host, spaced 200 ms: while /b waits, the poll timeout is short and a timeout chunk is only a wake-up.
        $checker = $this->checker($client, ['minIntervalMs' => 200, 'timeoutSeconds' => 30.0]);
        $results = $this->runChecks($checker, ['/a', '/b']);

        self::assertSame([200, 200], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
    }

    public function testStreamTimeoutChunkAfterDeadlineIsATimeout(): void
    {
        $client = new RouteClient();
        $client->streamHook = static function (array $responses): array {
            usleep(20_000);

            return [[$responses[0], new ErrorChunk(0, 'poll')]];
        };
        $checker = $this->checker($client, ['minIntervalMs' => 200, 'timeoutSeconds' => 0.005]);
        $results = $this->runChecks($checker, ['/a', '/b']);

        self::assertSame(TargetCheckResult::ERROR_TIMEOUT, $results[0]->error);
    }

    public function testDynamicTargetsAreSkipped(): void
    {
        $client = new RouteClient();
        $results = $this->runChecks($this->checker($client), ['/blog/$1', '/x/${10}', '/{lang}/home', '/news/{slug}', '/ok']);

        foreach (array_slice($results, 0, 4) as $r) {
            self::assertSame(TargetCheckResult::ERROR_SKIPPED_DYNAMIC, $r->error);
            self::assertNull($r->status);
            self::assertFalse($r->ok);
            self::assertFalse($r->isDead());
            self::assertTrue($r->isSkipped());
        }
        self::assertSame('/blog/$1', $results[0]->url);
        self::assertSame(200, $results[4]->status);
        self::assertCount(1, $client->requests);
    }

    public function testExternalTargetsCanBeDisabled(): void
    {
        $client = new RouteClient();
        $results = $this->runChecks($this->checker($client, ['checkExternal' => false]), ['https://other.test/x', '/local', '//cdn.test/y']);

        self::assertSame(TargetCheckResult::ERROR_SKIPPED_EXTERNAL, $results[0]->error);
        self::assertSame('https://other.test/x', $results[0]->url);
        self::assertTrue($results[0]->isSkipped());
        self::assertFalse($results[0]->isDead());
        self::assertSame(200, $results[1]->status);
        self::assertSame(TargetCheckResult::ERROR_SKIPPED_EXTERNAL, $results[2]->error);
        self::assertSame('https://cdn.test/y', $results[2]->url);
        self::assertSame(['HEAD https://site.test/local'], $client->urls());
    }

    public function testRedirectLeavingTheSiteStopsWhenExternalIsDisabled(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 301, 'location' => 'https://other.test/landing']]);
        [$r] = $this->runChecks($this->checker($client, ['checkExternal' => false]), ['/a']);

        self::assertSame(301, $r->status);
        self::assertTrue($r->ok);
        self::assertNull($r->error);
        self::assertSame('https://other.test/landing', $r->finalUrl);
        self::assertCount(1, $client->requests);
    }

    public function testExternalTargetIsResolvedPinnedAndChecked(): void
    {
        $lookups = [];
        $client = new RouteClient();
        $checker = $this->checker($client, [], static function (string $host) use (&$lookups): array {
            $lookups[] = $host;

            return ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
        });
        $results = $this->runChecks($checker, ['https://Example.ORG/page', 'https://example.org/other']);

        self::assertSame([200, 200], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
        self::assertSame(['example.org'], $lookups, 'one lookup per host and run');
        self::assertSame(['example.org' => '93.184.216.34'], $client->requests[0]['options']['resolve']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateTargets(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/'],
            'private 10' => ['http://10.0.0.5/'],
            'private 192' => ['http://192.168.1.10/admin'],
            'private 172' => ['http://172.16.0.1/'],
            'link-local' => ['http://169.254.169.254/latest/meta-data'],
            'unspecified' => ['http://0.0.0.0/'],
            'cgnat' => ['http://100.64.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'ipv4-mapped' => ['http://[::ffff:127.0.0.1]/'],
            'multicast' => ['http://[ff02::1]/'],
            'resolves to private' => ['http://intranet.example/'],
        ];
    }

    #[DataProvider('privateTargets')]
    public function testPrivateTargetsAreRefusedWithoutRequest(string $target): void
    {
        $client = new RouteClient();
        $checker = $this->checker($client, [], static fn (string $host): array => ['192.168.1.10']);
        [$r] = $this->runChecks($checker, [$target]);

        self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        self::assertNull($r->status);
        self::assertFalse($r->ok);
        self::assertFalse($r->isDead());
        self::assertTrue($r->isSkipped());
        self::assertSame([], $client->requests);
    }

    public function testOneBadAddressBlocksAHostThatAlsoHasPublicOnes(): void
    {
        $client = new RouteClient();
        $checker = $this->checker($client, [], static fn (): array => ['93.184.216.34', '10.1.2.3']);
        [$r] = $this->runChecks($checker, ['https://mixed.example/']);

        self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        self::assertSame([], $client->requests);
    }

    public function testPublicIpLiteralIsAllowed(): void
    {
        $client = new RouteClient();
        [$r] = $this->runChecks($this->checker($client), ['https://93.184.216.34/x']);

        self::assertSame(200, $r->status);
        self::assertArrayNotHasKey('resolve', $client->requests[0]['options']);
    }

    public function testUnresolvableHostIsADnsError(): void
    {
        $client = new RouteClient();
        $checker = $this->checker($client, [], static fn (): array => []);
        $results = $this->runChecks($checker, ['https://nope.example/a', 'https://nope.example/b']);

        foreach ($results as $r) {
            self::assertSame(TargetCheckResult::ERROR_DNS, $r->error);
            self::assertTrue($r->isDead());
        }
        self::assertSame([], $client->requests);
    }

    public function testResolverGarbageIsIgnored(): void
    {
        $checker = $this->checker(new RouteClient(), [], static fn (): array => ['', '93.184.216.34']);
        [$r] = $this->runChecks($checker, ['https://example.org/']);

        self::assertSame(200, $r->status);
    }

    public function testSiteOwnHostMayBeLocal(): void
    {
        $lookups = 0;
        $client = new RouteClient();
        $checker = $this->checker($client, ['baseUrl' => 'http://localhost:8080/sub/'], static function () use (&$lookups): array {
            ++$lookups;

            return ['127.0.0.1'];
        });
        [$r] = $this->runChecks($checker, ['/page', 'http://localhost:8080/other']);

        self::assertSame('http://localhost:8080/sub/page', $r->url);
        self::assertSame(200, $r->status);
        self::assertSame(0, $lookups);
        self::assertArrayNotHasKey('resolve', $client->requests[0]['options']);
    }

    public function testRedirectFromSiteToPrivateAddressIsRefused(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 302, 'location' => 'http://10.0.0.5/secret']]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        self::assertSame(302, $r->status);
        self::assertCount(1, $client->requests);
    }

    public function testRedirectToHostThatResolvesPrivateIsRefused(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 302, 'location' => 'https://evil.example/']]);
        $checker = $this->checker($client, [], static fn (): array => ['127.0.0.1']);
        [$r] = $this->runChecks($checker, ['/a']);

        self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        self::assertCount(1, $client->requests);
    }

    public function testRedirectHopsAreResolvedAndPinnedAgain(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 302, 'location' => 'https://far.example/x']]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(200, $r->status);
        self::assertSame(['far.example' => self::PUBLIC_IP], $client->requests[1]['options']['resolve']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'empty' => [''],
            'mailto' => ['mailto:info@site.test'],
            'javascript' => ['javascript:alert(1)'],
            'ftp' => ['ftp://files.example/x'],
            'relative' => ['foo/bar'],
            'credentials' => ['https://user:secret@example.org/'],
            'no host' => ['https:///path'],
            'bad idn' => ["https://\xF0\x28\x8C\x28.example/"],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargets(string $target): void
    {
        $client = new RouteClient();
        [$r] = $this->runChecks($this->checker($client), [$target]);

        self::assertSame(TargetCheckResult::ERROR_INVALID_URL, $r->error);
        self::assertFalse($r->isDead());
        self::assertSame([], $client->requests);
    }

    public function testUnsafeCharactersAreEncoded(): void
    {
        $client = new RouteClient();
        $this->runChecks($this->checker($client), ['/über uns', '/a b#frag']);

        self::assertSame(
            ['HEAD https://site.test/%C3%BCber%20uns', 'HEAD https://site.test/a%20b'],
            $client->urls(),
        );
    }

    public function testUnicodeHostIsConverted(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl missing');
        }
        $client = new RouteClient();
        $this->runChecks($this->checker($client), ['https://bücher.example/x']);

        self::assertSame(['HEAD https://xn--bcher-kva.example/x'], $client->urls());
    }

    public function testDuplicateUrlsAreRequestedOnce(): void
    {
        $client = new RouteClient(['https://site.test/a' => ['code' => 404]]);
        $results = $this->runChecks($this->checker($client), ['/a', '/b', '/a', 'https://site.test/a']);

        self::assertCount(2, $client->requests);
        self::assertSame(['r0', 'r1', 'r2', 'r3'], array_map(static fn (TargetCheckResult $r): string => $r->ruleId, $results));
        self::assertSame([404, 200, 404, 404], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
    }

    public function testRejectedUrlsAreRememberedAcrossDuplicates(): void
    {
        $lookups = 0;
        $checker = $this->checker(new RouteClient(), [], static function () use (&$lookups): array {
            ++$lookups;

            return ['10.0.0.1'];
        });
        $results = $this->runChecks($checker, ['https://x.example/a', 'https://x.example/a', 'https://x.example/b']);

        foreach ($results as $r) {
            self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        }
        self::assertSame(1, $lookups);
    }

    public function testMaxPerRunLimitsRequestsAndMarksTheRest(): void
    {
        $client = new RouteClient();
        $targets = ['/1', '/dyn/$1', '/2', '/3', '/2', '/4'];
        $results = $this->runChecks($this->checker($client, ['maxPerRun' => 2]), $targets);

        self::assertSame(['HEAD https://site.test/1', 'HEAD https://site.test/2'], $client->urls());
        self::assertSame(
            [null, 'skipped_dynamic', null, 'rate_limited', null, 'rate_limited'],
            array_map(static fn (TargetCheckResult $r): ?string => $r->error, $results),
        );
        self::assertSame([200, null, 200, null, 200, null], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
        self::assertSame('https://site.test/3', $results[3]->url);
        self::assertFalse($results[3]->isDead());
        self::assertTrue($results[3]->isSkipped());
    }

    public function testMaxPerRunZeroChecksNothing(): void
    {
        $client = new RouteClient();
        $results = $this->runChecks($this->checker($client, ['maxPerRun' => 0]), ['/a']);

        self::assertSame('rate_limited', $results[0]->error);
        self::assertSame([], $client->requests);
    }

    public function testRequestsToTheSameHostAreSpaced(): void
    {
        $sleeps = [];
        $client = new RouteClient();
        $checker = $this->checker(
            $client,
            ['minIntervalMs' => 200],
            null,
            static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );
        $results = $this->runChecks($checker, ['/a', '/b', '/c']);

        self::assertSame([200, 200], $sleeps);
        self::assertSame(['HEAD https://site.test/a', 'HEAD https://site.test/b', 'HEAD https://site.test/c'], $client->urls());
        self::assertSame([200, 200, 200], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
    }

    public function testSpacingRespectsTimeThatAlreadyPassed(): void
    {
        $sleeps = [];
        $clock = $this->clock;
        $checker = $this->checker(
            new RouteClient(),
            ['minIntervalMs' => 200],
            null,
            static function (int $ms) use (&$sleeps, $clock): void {
                $sleeps[] = $ms;
                $clock->set($clock->now()->modify('+' . $ms . ' milliseconds'));
            },
        );
        $this->runChecks($checker, ['/a', '/b']);

        self::assertSame([200], $sleeps);
    }

    public function testDifferentHostsAreNotSpaced(): void
    {
        $sleeps = [];
        $checker = $this->checker(
            new RouteClient(),
            ['minIntervalMs' => 500],
            null,
            static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );
        $this->runChecks($checker, ['https://a.example/', 'https://b.example/', 'https://c.example/']);

        self::assertSame([], $sleeps);
    }

    public function testRedirectHopsToTheSameHostAreSpacedToo(): void
    {
        $sleeps = [];
        $client = new RouteClient(['https://site.test/a' => ['code' => 301, 'location' => '/b']]);
        $checker = $this->checker($client, ['minIntervalMs' => 100], null, static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });
        [$r] = $this->runChecks($checker, ['/a']);

        self::assertSame(200, $r->status);
        self::assertSame([100], $sleeps);
    }

    public function testConcurrencyIsCapped(): void
    {
        $client = new RouteClient();
        $targets = [];
        foreach (range(1, 7) as $i) {
            $targets[] = 'https://h' . $i . '.example/';
        }
        $results = $this->runChecks($this->checker($client, ['concurrency' => 3]), $targets);

        self::assertCount(7, $results);
        self::assertSame(3, max($client->streamSizes));
        self::assertLessThanOrEqual(3, max($client->streamSizes));
        foreach ($results as $r) {
            self::assertSame(200, $r->status);
        }
    }

    public function testConcurrencyOfOneRunsSequentially(): void
    {
        $client = new RouteClient();
        $this->runChecks($this->checker($client, ['concurrency' => 0]), ['https://a.example/', 'https://b.example/']);

        self::assertSame(1, max($client->streamSizes));
    }

    public function testResultsKeepInputOrder(): void
    {
        $client = new RouteClient();
        $results = $this->runChecks($this->checker($client), ['/a', '/dyn/$1', 'https://x.example/', 'mailto:a@b.c', '/b']);

        self::assertSame(['r0', 'r1', 'r2', 'r3', 'r4'], array_map(static fn (TargetCheckResult $r): string => $r->ruleId, $results));
    }

    public function testDefaultsWorkWithoutInjectedCollaborators(): void
    {
        $client = new RouteClient();
        $checker = new TargetChecker($client, $this->clock, new TargetCheckerOptions('https://site.test', minIntervalMs: 1));
        $results = $checker->check([['id' => 'x', 'target' => '/a'], ['id' => 'y', 'target' => '/b']]);

        self::assertSame([200, 200], array_map(static fn (TargetCheckResult $r): ?int => $r->status, $results));
    }

    public function testDefaultResolverRefusesLocalhost(): void
    {
        $client = new RouteClient();
        $checker = new TargetChecker($client, $this->clock, new TargetCheckerOptions('https://site.test'));
        [$r] = $checker->check([['id' => 'x', 'target' => 'http://localhost/']]);

        self::assertSame(TargetCheckResult::ERROR_BLOCKED_PRIVATE, $r->error);
        self::assertSame([], $client->requests);
    }

    public function testDefaultResolverReportsUnknownHostsAsDns(): void
    {
        $client = new RouteClient();
        $checker = new TargetChecker($client, $this->clock, new TargetCheckerOptions('https://site.test'));
        [$r] = $checker->check([['id' => 'x', 'target' => 'https://no-such-host.invalid/']]);

        self::assertSame(TargetCheckResult::ERROR_DNS, $r->error);
    }

    public function testResultArrayRoundTrip(): void
    {
        $result = new TargetCheckResult('r1', 'https://site.test/a', 301, true, null, 'https://site.test/b', 1, 42, $this->clock->now());
        $copy = TargetCheckResult::fromArray($result->toArray());

        self::assertEquals($result, $copy);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function brokenRows(): array
    {
        return [
            'empty' => [[]],
            'no id' => [['url' => 'x', 'checked_at' => '2026-01-01T00:00:00+00:00']],
            'empty id' => [['rule_id' => '', 'url' => 'x', 'checked_at' => '2026-01-01T00:00:00+00:00']],
            'no url' => [['rule_id' => 'a', 'checked_at' => '2026-01-01T00:00:00+00:00']],
            'bad date' => [['rule_id' => 'a', 'url' => 'x', 'checked_at' => 'not a date']],
        ];
    }

    /**
     * @param array<mixed> $row
     */
    #[DataProvider('brokenRows')]
    public function testFromArrayRejectsBrokenRows(array $row): void
    {
        self::assertNull(TargetCheckResult::fromArray($row));
    }

    public function testFromArrayDefaultsMissingFields(): void
    {
        $r = TargetCheckResult::fromArray(['rule_id' => 'a', 'url' => 'https://x/', 'checked_at' => '2026-01-01T00:00:00+00:00']);

        self::assertNotNull($r);
        self::assertNull($r->status);
        self::assertFalse($r->ok);
        self::assertSame(0, $r->redirects);
        self::assertSame(0, $r->durationMs);
    }

    public function testMockResponseObjectsAreAccepted(): void
    {
        $client = new RouteClient(['https://site.test/a' => static fn (): MockResponse => new MockResponse('', ['http_code' => 204])]);
        [$r] = $this->runChecks($this->checker($client), ['/a']);

        self::assertSame(204, $r->status);
        self::assertTrue($r->ok);
    }
}
