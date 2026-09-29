<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Storage\DataDirProtection;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(DataDirProtection::class)]
#[CoversClass(ServiceFactory::class)]
#[Group('storage')]
final class DataDirProtectionTest extends TestCase
{
    use TempDirTrait;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testCreatesTheDirectoryWithHtaccessAndEmptyIndex(): void
    {
        $dir = $this->tmp . '/user/data/redirect-manager';

        self::assertTrue(DataDirProtection::ensure($dir));

        $htaccess = (string) file_get_contents($dir . '/.htaccess');
        self::assertStringContainsString('Require all denied', $htaccess);
        self::assertStringContainsString('Deny from all', $htaccess);
        self::assertStringContainsString('mod_authz_core.c', $htaccess);
        self::assertSame('', file_get_contents($dir . '/index.html'));
    }

    public function testNeverOverwritesFilesThatExist(): void
    {
        $dir = $this->tmp . '/data';
        mkdir($dir);
        file_put_contents($dir . '/.htaccess', "# mine\nRequire ip 10.0.0.0/8\n");
        file_put_contents($dir . '/index.html', '<p>hi</p>');

        self::assertTrue(DataDirProtection::ensure($dir));
        self::assertTrue(DataDirProtection::ensure($dir));

        self::assertSame("# mine\nRequire ip 10.0.0.0/8\n", file_get_contents($dir . '/.htaccess'));
        self::assertSame('<p>hi</p>', file_get_contents($dir . '/index.html'));
    }

    public function testFillsInWhatIsMissingInAnExistingDirectory(): void
    {
        $dir = $this->tmp . '/data';
        mkdir($dir);
        file_put_contents($dir . '/rules.yaml', "version: 1\nrules: []\n");
        file_put_contents($dir . '/.htaccess', 'custom');

        self::assertTrue(DataDirProtection::ensure($dir));

        self::assertSame('custom', file_get_contents($dir . '/.htaccess'));
        self::assertFileExists($dir . '/index.html');
        self::assertSame("version: 1\nrules: []\n", file_get_contents($dir . '/rules.yaml'));
    }

    public function testReportsFailureInsteadOfThrowing(): void
    {
        file_put_contents($this->tmp . '/file', 'x');

        self::assertFalse(DataDirProtection::ensure($this->tmp . '/file/sub'), 'a file is in the way');
    }

    public function testReportsAFileThatCannotBeWritten(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions');
        }
        $dir = $this->tmp . '/locked';
        mkdir($dir, 0555);
        try {
            self::assertFalse(DataDirProtection::ensure($dir));
        } finally {
            chmod($dir, 0775);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writingAccessors(): array
    {
        return [
            'rule repository' => ['repository'],
            'hit recorder' => ['hitRecorder'],
            'stats store' => ['statsStore'],
            'log store' => ['logStore'],
            'suggestion store' => ['suggestionStore'],
            'check result store' => ['checkResultStore'],
            'threshold tracker' => ['thresholdTracker'],
            'auto state' => ['autoState'],
            'resolved paths' => ['resolvedPaths'],
        ];
    }

    #[DataProvider('writingAccessors')]
    public function testTheServiceFactoryProtectsTheDataDirectoryOnFirstWriteAccess(string $accessor): void
    {
        $factory = new ServiceFactory([], [], $this->tmp . '/data', $this->tmp . '/cache');

        $factory->{$accessor}();

        self::assertFileExists($this->tmp . '/data/.htaccess');
        self::assertFileExists($this->tmp . '/data/index.html');
    }

    public function testTheReadOnlyMatchingPathLeavesTheDataDirectoryAlone(): void
    {
        $factory = new ServiceFactory([], [], $this->tmp . '/data', $this->tmp . '/cache');

        $factory->matcher();

        self::assertDirectoryDoesNotExist($this->tmp . '/data');
    }

    public function testAProtectionFailureIsLoggedOnceAndDoesNotStopTheService(): void
    {
        file_put_contents($this->tmp . '/blocked', 'x');
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $factory = new ServiceFactory([], [], $this->tmp . '/blocked/data', $this->tmp . '/cache', $logger);

        $factory->repository();
        $factory->hitRecorder();

        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('warning: Redirect Manager: cannot protect', $logger->lines[0]);
    }
}
