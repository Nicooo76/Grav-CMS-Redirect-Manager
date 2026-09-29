<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use Grav\Plugin\RedirectManager\NotFound\IpAnonymizer;
use Grav\Plugin\RedirectManager\NotFound\IpMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpAnonymizer::class)]
#[CoversClass(IpMode::class)]
#[Group('notfound')]
final class IpAnonymizerTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function addresses(): iterable
    {
        yield 'ipv4' => ['1.2.3.4', '1.2.3.0'];
        yield 'ipv4 max' => ['255.255.255.255', '255.255.255.0'];
        yield 'ipv4 already zero' => ['10.0.0.0', '10.0.0.0'];
        yield 'ipv4 with spaces' => [' 192.168.1.77 ', '192.168.1.0'];
        yield 'ipv6 full' => ['2001:db8:abcd:1234:5678:9abc:def0:1234', '2001:db8:abcd::'];
        yield 'ipv6 uppercase' => ['2001:DB8:ABCD:1::5', '2001:db8:abcd::'];
        yield 'ipv6 compressed' => ['2a02:8071:1:2::3', '2a02:8071:1::'];
        yield 'ipv6 loopback' => ['::1', '::'];
        yield 'ipv6 zone id' => ['fe80::1%eth0', 'fe80::'];
        yield 'ipv4 mapped ipv6' => ['::ffff:203.0.113.99', '203.0.113.0'];
        yield 'not an ip' => ['not an ip', null];
        yield 'empty' => ['', null];
        yield 'too short' => ['1.2.3', null];
        yield 'too long' => ['1.2.3.4.5', null];
        yield 'octet out of range' => ['999.1.1.1', null];
        yield 'hostname' => ['example.org', null];
        yield 'garbage ipv6' => ['2001:db8::zzzz', null];
        yield 'with port' => ['1.2.3.4:8080', null];
        yield 'injection' => ["1.2.3.4\n5.6.7.8", null];
    }

    #[DataProvider('addresses')]
    public function testAnonymize(string $ip, ?string $expected): void
    {
        self::assertSame($expected, (new IpAnonymizer())->anonymize($ip));
    }

    public function testNullStaysNull(): void
    {
        self::assertNull((new IpAnonymizer())->anonymize(null));
    }

    public function testModeNoneStoresNothing(): void
    {
        $none = new IpAnonymizer(IpMode::None);

        self::assertNull($none->anonymize('1.2.3.4'));
        self::assertNull($none->anonymize('2001:db8::1'));
        self::assertSame(IpMode::None, $none->mode());
    }

    public function testNoInputEverComesBackWithItsFullAddress(): void
    {
        $anonymizer = new IpAnonymizer();
        foreach (['8.8.8.8', '2001:4860:4860::8888', '::ffff:8.8.4.4', '1.1.1.1'] as $ip) {
            $result = (string) $anonymizer->anonymize($ip);
            self::assertNotSame($ip, $result);
            self::assertStringNotContainsString('8888', $result);
        }
    }

    public function testModeFromConfigFallsBackToNone(): void
    {
        self::assertSame(IpMode::Anonymize, IpMode::fromConfig('anonymize'));
        self::assertSame(IpMode::None, IpMode::fromConfig('none'));
        self::assertSame(IpMode::None, IpMode::fromConfig('full'));
        self::assertSame(IpMode::None, IpMode::fromConfig(null));
        self::assertSame(IpMode::None, IpMode::fromConfig(true));
    }
}
