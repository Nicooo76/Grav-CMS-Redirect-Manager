<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\HttpResponse;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite;
use PHPUnit\Framework\TestCase;

/**
 * Base class of the integration tests: one Grav site copy and PHP server per test class, reset before each test.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?TestSite $site = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required for the integration tests.');
        }
        if (TestSite::baseDir() === null) {
            self::markTestSkipped('No Grav test site. Run scripts/setup-test-site.sh first.');
        }
        self::$site = TestSite::create(static::siteIni());
    }

    /**
     * php.ini settings of the test site's `php -S` process. Benchmarks turn OPcache on for the CLI server, as a
     * production PHP has it.
     *
     * @return array<string, string>
     */
    protected static function siteIni(): array
    {
        return [];
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$site = null;
    }

    protected function setUp(): void
    {
        $this->site()->reset();
    }

    protected function site(): TestSite
    {
        self::assertNotNull(self::$site, 'test site is not running');

        return self::$site;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    protected function rules(array $rules): void
    {
        $this->site()->writeRules($rules);
    }

    /**
     * @param array{headers?: array<string, string>, cookies?: array<string, string>, method?: string} $options
     */
    protected function get(string $path, array $options = []): HttpResponse
    {
        return $this->site()->request($path, $options);
    }

    protected function assertRedirect(HttpResponse $response, int $status, string $location, string $message = ''): void
    {
        self::assertSame($status, $response->status, $message . ' ' . $response->describe());
        self::assertSame($location, $response->location(), $message);
        self::assertSame('Grav Redirect Manager', $response->header('x-redirect-by'), $message);
    }

    protected function assertNotRedirected(HttpResponse $response, string $message = ''): void
    {
        self::assertNull($response->header('x-redirect-by'), $message . ' ' . $response->describe());
        self::assertFalse($response->status >= 300 && $response->status < 400 && $response->header('x-redirect-by') !== null, $message);
    }
}
