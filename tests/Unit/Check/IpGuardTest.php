<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Check;

use Grav\Plugin\RedirectManager\Check\IpGuard;
use Grav\Plugin\RedirectManager\Check\UrlTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpGuard::class)]
#[CoversClass(UrlTools::class)]
final class IpGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            'public v4' => ['93.184.216.34', true],
            'public v4 8.8.8.8' => ['8.8.8.8', true],
            'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true],
            'mapped public' => ['::ffff:8.8.8.8', true],
            'loopback' => ['127.0.0.1', false],
            'loopback range' => ['127.9.9.9', false],
            'this network' => ['0.0.0.0', false],
            '10/8' => ['10.1.2.3', false],
            '172.16/12' => ['172.31.255.255', false],
            'just outside 172.16/12' => ['172.32.0.1', true],
            '192.168/16' => ['192.168.0.1', false],
            'link-local' => ['169.254.169.254', false],
            'cgnat low' => ['100.64.0.0', false],
            'cgnat high' => ['100.127.255.255', false],
            'just outside cgnat' => ['100.128.0.1', true],
            'reserved' => ['240.0.0.1', false],
            'v6 loopback' => ['::1', false],
            'v6 unspecified' => ['::', false],
            'v6 ULA' => ['fc00::1', false],
            'v6 link-local' => ['fe80::1', false],
            'v6 multicast' => ['ff02::1', false],
            'v4-compatible' => ['::8.8.8.8', false],
            'mapped loopback' => ['::ffff:127.0.0.1', false],
            'mapped private' => ['::ffff:10.0.0.1', false],
            'not an ip' => ['example.org', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIsPublic(string $ip, bool $public): void
    {
        self::assertSame($public, IpGuard::isPublic($ip));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function references(): array
    {
        return [
            'absolute' => ['https://a.test/x', 'http://b.test/y', 'http://b.test/y'],
            'protocol relative' => ['https://a.test/x', '//b.test/y', 'https://b.test/y'],
            'root relative' => ['https://a.test:8443/x/y', '/z', 'https://a.test:8443/z'],
            'sibling' => ['https://a.test/x/y', 'z', 'https://a.test/x/z'],
            'parent' => ['https://a.test/x/y/', '../z', 'https://a.test/x/z'],
            'above root' => ['https://a.test/x', '../../z', 'https://a.test/z'],
            'dot' => ['https://a.test/x/y', './z', 'https://a.test/x/z'],
            'dot only' => ['https://a.test/x/y', '.', 'https://a.test/x/'],
            'dotdot only' => ['https://a.test/x/y/z', '..', 'https://a.test/x/'],
            'query only' => ['https://a.test/x?old=1', '?new=2', 'https://a.test/x?new=2'],
            'fragment only' => ['https://a.test/x', '#top', 'https://a.test/x'],
            'empty' => ['https://a.test/x', '', 'https://a.test/x'],
            'base without path' => ['https://a.test', 'page', 'https://a.test/page'],
            'dots with query' => ['https://a.test/a/b', '../c?x=../y', 'https://a.test/c?x=../y'],
        ];
    }

    #[DataProvider('references')]
    public function testResolve(string $base, string $ref, string $expected): void
    {
        self::assertSame($expected, UrlTools::resolve($base, $ref));
    }

    public function testNormalizeKeepsPortAndQuery(): void
    {
        self::assertSame('http://a.test:8080/p?q=1%202', UrlTools::normalize('HTTP://A.test:8080/p?q=1 2#f'));
        self::assertSame('https://a.test', UrlTools::normalize('https://a.test'));
        self::assertNull(UrlTools::normalize('https://bad host/'));
        self::assertNull(UrlTools::normalize('http://:80/'));
    }

    public function testHost(): void
    {
        self::assertSame('a.test', UrlTools::host('https://A.Test/x'));
        self::assertSame('::1', UrlTools::host('http://[::1]:80/'));
        self::assertSame('', UrlTools::host('/relative'));
    }
}
