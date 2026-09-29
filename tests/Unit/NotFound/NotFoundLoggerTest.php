<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\IgnoreList;
use Grav\Plugin\RedirectManager\NotFound\IpAnonymizer;
use Grav\Plugin\RedirectManager\NotFound\IpMode;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLogger;
use Grav\Plugin\RedirectManager\NotFound\NotFoundLoggerOptions;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClassifier;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotFoundLogger::class)]
#[CoversClass(NotFoundLoggerOptions::class)]
#[Group('notfound')]
final class NotFoundLoggerTest extends TestCase
{
    use TempDirTrait;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private FixedClock $clock;

    private JsonlLogStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
        $this->store = new JsonlLogStore($this->makeTempDir() . '/404', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function logger(?NotFoundLoggerOptions $options = null, ?IpAnonymizer $anonymizer = null, ?IgnoreList $ignore = null): NotFoundLogger
    {
        return new NotFoundLogger(
            $this->store,
            $ignore ?? new IgnoreList(),
            $anonymizer ?? new IpAnonymizer(),
            new UserAgentClassifier(),
            $this->clock,
            $options ?? new NotFoundLoggerOptions(),
        );
    }

    /** @return list<NotFoundEntry> */
    private function stored(): array
    {
        return iterator_to_array($this->store->entries(new DateTimeImmutable('2026-01-01 UTC'), new DateTimeImmutable('2026-10-31 UTC')), false);
    }

    private function rawLog(): string
    {
        return (string) file_get_contents($this->tmp . '/404/2026-09-29.jsonl');
    }

    public function testLogsASanitizedEntryAndReturnsIt(): void
    {
        $entry = $this->logger(new NotFoundLoggerOptions(storeIp: true))->log(
            '/old-page',
            'a=1',
            'https://example.com/from?secret=1#x',
            self::CHROME,
            '203.0.113.77',
            'DE-de',
            'Example.ORG',
            'get',
        );

        self::assertNotNull($entry);
        self::assertSame('/old-page', $entry->path);
        self::assertSame('a=1', $entry->query);
        self::assertSame('https://example.com/from', $entry->referer);
        self::assertSame(self::CHROME, $entry->userAgent);
        self::assertSame(UserAgentClass::Browser, $entry->uaClass);
        self::assertSame('203.0.113.0', $entry->ip);
        self::assertSame('de-de', $entry->language);
        self::assertSame('example.org', $entry->host);
        self::assertSame('GET', $entry->method);
        self::assertSame(1_790_683_200, $entry->time->getTimestamp());
        self::assertEquals([$entry], $this->stored());
    }

    public function testDisabledLoggerLogsNothing(): void
    {
        $result = $this->logger(new NotFoundLoggerOptions(enabled: false))->log('/x', '', null, self::CHROME, null, null, 'h');

        self::assertNull($result);
        self::assertSame([], $this->stored());
    }

    public function testOnlyGetAndHeadAreLogged(): void
    {
        $logger = $this->logger();

        self::assertNotNull($logger->log('/a', '', null, self::CHROME, null, null, 'h', 'GET'));
        self::assertNotNull($logger->log('/b', '', null, self::CHROME, null, null, 'h', 'head'));
        foreach (['POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'TRACE', 'CONNECT', ''] as $method) {
            self::assertNull($logger->log('/c', '', null, self::CHROME, null, null, 'h', $method), $method);
        }
        self::assertSame(['/a', '/b'], array_map(static fn (NotFoundEntry $e): string => $e->path, $this->stored()));
        self::assertSame('HEAD', $this->stored()[1]->method);
    }

    public function testIgnoredPathsAreNotLogged(): void
    {
        $logger = $this->logger();

        self::assertNull($logger->log('/wp-login.php', '', null, self::CHROME, null, null, 'h'));
        self::assertNull($logger->log('/.env', '', null, self::CHROME, null, null, 'h'));
        self::assertNotNull($logger->log('/real-page', '', null, self::CHROME, null, null, 'h'));
        self::assertCount(1, $this->stored());
    }

    public function testIgnoreListSeesTheQuery(): void
    {
        $logger = $this->logger(ignore: new IgnoreList(['/search?q=*']));

        self::assertNull($logger->log('/search', 'q=x', null, self::CHROME, null, null, 'h'));
        self::assertNotNull($logger->log('/search', 'p=x', null, self::CHROME, null, null, 'h'));
    }

    public function testBotsAreLoggedByDefaultWithTheirClass(): void
    {
        $entry = $this->logger()->log('/x', '', null, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null, null, 'h');

        self::assertSame(UserAgentClass::Bot, $entry?->uaClass);
    }

    public function testBotsCanBeSwitchedOffButMonitoringAndBrowsersStay(): void
    {
        $logger = $this->logger(new NotFoundLoggerOptions(logBots: false));

        self::assertNull($logger->log('/a', '', null, 'curl/8.7.1', null, null, 'h'));
        self::assertNull($logger->log('/b', '', null, null, null, null, 'h'), 'a missing user agent counts as bot');
        self::assertNotNull($logger->log('/c', '', null, self::CHROME, null, null, 'h'));
        self::assertNotNull($logger->log('/d', '', null, 'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)', null, null, 'h'));
        self::assertSame(['/c', '/d'], array_map(static fn (NotFoundEntry $e): string => $e->path, $this->stored()));
    }

    public function testNoIpIsStoredByDefault(): void
    {
        $entry = $this->logger()->log('/x', '', null, self::CHROME, '203.0.113.77', null, 'h');

        self::assertNull($entry?->ip);
        self::assertStringNotContainsString('203.0.113', $this->rawLog());
    }

    public function testIpIsAnonymizedWhenStored(): void
    {
        $logger = $this->logger(new NotFoundLoggerOptions(storeIp: true));

        self::assertSame('203.0.113.0', $logger->log('/a', '', null, self::CHROME, '203.0.113.77', null, 'h')?->ip);
        self::assertSame('2001:db8:1::', $logger->log('/b', '', null, self::CHROME, '2001:db8:1:2:3:4:5:6', null, 'h')?->ip);
        self::assertNull($logger->log('/c', '', null, self::CHROME, 'garbage', null, 'h')?->ip);
        self::assertNull($logger->log('/d', '', null, self::CHROME, null, null, 'h')?->ip);
        self::assertStringNotContainsString('203.0.113.77', $this->rawLog());
        self::assertStringNotContainsString('2001:db8:1:2', $this->rawLog());
    }

    public function testAnonymizerModeNoneOverridesStoreIp(): void
    {
        $logger = $this->logger(new NotFoundLoggerOptions(storeIp: true), new IpAnonymizer(IpMode::None));

        self::assertNull($logger->log('/x', '', null, self::CHROME, '203.0.113.77', null, 'h')?->ip);
    }

    public function testLogInjectionAttemptsProduceExactlyOneCleanLogLine(): void
    {
        $this->logger(new NotFoundLoggerOptions(storeIp: true))->log(
            "/a\r\n{\"t\":1,\"p\":\"/forged\"}\0\x1B[31m",
            "x=1\r\n\x1B]0;title\x07&token=abc",
            "https://evil.example/\r\nSet-Cookie: a=b",
            "Mozilla/5.0\r\nX-Injected: 1\0 AppleWebKit \x1B[2J",
            "1.2.3.4\r\n5.6.7.8",
            "de\r\nx",
            "example.org\r\nEvil: 1",
        );

        $raw = $this->rawLog();
        self::assertSame(1, substr_count($raw, "\n"));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F\x7F]/', $raw);
        self::assertStringNotContainsString("\x1B", $raw);
        self::assertStringNotContainsString('abc', $raw, 'token value is redacted');
        $entries = $this->stored();
        self::assertCount(1, $entries);
        self::assertStringNotContainsString("\n", $entries[0]->path . $entries[0]->query . $entries[0]->referer . $entries[0]->userAgent . $entries[0]->host);
        self::assertNull($entries[0]->language);
        self::assertNull($entries[0]->ip);
    }

    public function testHugeFieldsAreCappedToTheConfiguredByteLengths(): void
    {
        $entry = $this->logger()->log(
            '/' . str_repeat('ä', 5000),
            'a=' . str_repeat('b', 5000),
            'https://example.com/' . str_repeat('c', 5000),
            str_repeat('Mozilla ', 5000),
            null,
            null,
            str_repeat('h', 5000),
        );

        self::assertNotNull($entry);
        self::assertLessThanOrEqual(2048, strlen($entry->path));
        self::assertLessThanOrEqual(1024, strlen($entry->query));
        self::assertLessThanOrEqual(1024, strlen($entry->referer));
        self::assertLessThanOrEqual(512, strlen($entry->userAgent));
        self::assertLessThanOrEqual(255, strlen($entry->host));
        foreach ([$entry->path, $entry->query, $entry->referer, $entry->userAgent, $entry->host] as $field) {
            self::assertTrue(mb_check_encoding($field, 'UTF-8'));
        }
        self::assertLessThan(6000, strlen($this->rawLog()));
    }

    public function testCustomFieldLengths(): void
    {
        $logger = $this->logger(new NotFoundLoggerOptions(maxPathBytes: 10, maxQueryBytes: 5, maxRefererBytes: 30, maxUserAgentBytes: 8));

        $entry = $logger->log('/0123456789abc', 'abcdefgh', 'https://example.com/long/path/here', 'Mozilla/5.0 x', null, null, 'h');

        self::assertSame('/012345678', $entry?->path);
        self::assertSame('abcde', $entry?->query);
        self::assertSame('https://example.com/long/path/', $entry?->referer);
        self::assertSame('Mozilla/', $entry?->userAgent);
    }

    public function testInvalidUtf8IsMadeValid(): void
    {
        $entry = $this->logger()->log("/bad\xFF\xC3", "q=\xFF", "https://example.com/\xFF", "UA\xFF", null, null, 'h');

        self::assertNotNull($entry);
        foreach ([$entry->path, $entry->query, $entry->referer, $entry->userAgent] as $field) {
            self::assertTrue(mb_check_encoding($field, 'UTF-8'));
        }
    }

    public function testSensitiveQueryValuesNeverReachTheLog(): void
    {
        $this->logger()->log('/x', 'email=jane@example.com&token=t0k3n&page=2&PHPSESSID=abc', null, self::CHROME, null, null, 'h');

        $raw = $this->rawLog();
        self::assertStringNotContainsString('jane@example.com', $raw);
        self::assertStringNotContainsString('t0k3n', $raw);
        self::assertStringContainsString('email=***&token=***&page=2&PHPSESSID=***', $raw);
    }

    public function testRefererQueryNeverReachesTheLog(): void
    {
        $this->logger()->log('/x', '', 'https://mail.example.com/inbox?user=jane@example.com&sid=99', self::CHROME, null, null, 'h');

        self::assertStringNotContainsString('jane', $this->rawLog());
        self::assertStringContainsString('https://mail.example.com/inbox', $this->rawLog());
    }

    public function testPathWithoutLeadingSlashGetsOneAndEmptyBecomesRoot(): void
    {
        $logger = $this->logger();

        self::assertSame('/no-slash', $logger->log('no-slash', '', null, self::CHROME, null, null, 'h')?->path);
        self::assertSame('/', $logger->log('', '', null, self::CHROME, null, null, 'h')?->path);
    }

    public function testMissingUserAgentIsStoredEmptyAndClassifiedAsBot(): void
    {
        $entry = $this->logger()->log('/x', '', null, null, null, null, 'h');

        self::assertSame('', $entry?->userAgent);
        self::assertSame(UserAgentClass::Bot, $entry?->uaClass);
    }

    public function testPurgeOlderThanUsesTheClock(): void
    {
        $logger = $this->logger();
        foreach ([-40, -31, -29, 0] as $i => $days) {
            $this->clock->set(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));
            $this->clock->set($this->clock->now()->modify($days . ' days'));
            $logger->log('/p' . $i, '', null, self::CHROME, null, null, 'h');
        }
        $this->clock->set(new DateTimeImmutable('2026-09-29 12:00:00 UTC'));

        self::assertSame(2, $logger->purgeOlderThan(30));
        self::assertSame(['/p2', '/p3'], array_map(static fn (NotFoundEntry $e): string => $e->path, $this->stored()));
    }

    public function testPurgeWithZeroOrNegativeDaysKeepsEverything(): void
    {
        $logger = $this->logger();
        $logger->log('/x', '', null, self::CHROME, null, null, 'h');

        self::assertSame(0, $logger->purgeOlderThan(0));
        self::assertSame(0, $logger->purgeOlderThan(-5));
        self::assertCount(1, $this->stored());
    }
}
