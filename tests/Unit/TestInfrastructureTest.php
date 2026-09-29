<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Rules for the integration suite itself. docs/TESTING.md promises that RM_PORT_RANGE keeps parallel runs apart, so
 * no test class may force a port range over the caller's value (NF-3).
 */
#[CoversNothing]
final class TestInfrastructureTest extends TestCase
{
    public function testNoIntegrationTestForcesAPortRangeOverTheCallers(): void
    {
        $dir = dirname(__DIR__) . '/Integration';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $withDefault = 0;
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            self::assertDoesNotMatchRegularExpression("/putenv\\(\\s*'RM_PORT_RANGE=\\d/", $source, basename((string) $file) . ' overrides the caller\'s RM_PORT_RANGE');
            if (preg_match("/putenv\\('RM_PORT_RANGE=' \\. \\(getenv\\('RM_PORT_RANGE'\\) \\?: '\\d+-\\d+'\\)\\);/", $source) === 1) {
                ++$withDefault;
            }
        }
        self::assertSame(3, $withDefault, 'AutoRedirectTestCase, SchedulerTestCase and WithoutApiPluginTest keep a default but honour the caller');
    }
}
