<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteContext::class)]
#[Group('app')]
final class SiteContextTest extends TestCase
{
    public function testDefaults(): void
    {
        $site = new SiteContext();

        self::assertSame([], $site->server);
        self::assertSame('http://localhost', $site->baseUrl);
        self::assertSame([], $site->siteRedirects);
        self::assertSame([], $site->siteRoutes);
        self::assertSame([], $site->pageSettings);
        self::assertSame(301, $site->redirectDefaultCode);
        self::assertSame([], $site->languages);
        self::assertNull($site->defaultLanguage);
        self::assertSame('localhost', $site->host());
    }

    public function testConstructorKeepsAllFacts(): void
    {
        $site = new SiteContext(
            ['SCRIPT_NAME' => '/index.php'],
            'https://Example.com/sub',
            ['/a' => '/b'],
            ['/c' => '/d'],
            ['default_code' => 302],
            302,
            ['de', 'en'],
            'de',
        );

        self::assertSame(['SCRIPT_NAME' => '/index.php'], $site->server);
        self::assertSame(['/a' => '/b'], $site->siteRedirects);
        self::assertSame(['/c' => '/d'], $site->siteRoutes);
        self::assertSame(['default_code' => 302], $site->pageSettings);
        self::assertSame(302, $site->redirectDefaultCode);
        self::assertSame(['de', 'en'], $site->languages);
        self::assertSame('de', $site->defaultLanguage);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostProvider(): iterable
    {
        yield 'lowercased' => ['https://Example.COM', 'example.com'];
        yield 'with path and port' => ['http://www.example.com:8080/sub/dir', 'www.example.com'];
        yield 'ip' => ['http://127.0.0.1:8000', '127.0.0.1'];
        yield 'credentials are not part of the host' => ['https://user:pw@example.org/', 'example.org'];
        yield 'no host' => ['/just/a/path', ''];
        yield 'empty' => ['', ''];
        yield 'unparsable' => ['http:///', ''];
    }

    #[DataProvider('hostProvider')]
    public function testHost(string $baseUrl, string $expected): void
    {
        self::assertSame($expected, (new SiteContext(baseUrl: $baseUrl))->host());
    }
}
