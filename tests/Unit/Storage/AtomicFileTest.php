<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Storage\AtomicFileException;
use Grav\Plugin\RedirectManager\Storage\LockTimeoutException;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AtomicFile::class)]
#[CoversClass(AtomicFileException::class)]
#[CoversClass(LockTimeoutException::class)]
#[Group('storage')]
final class AtomicFileTest extends TestCase
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

    /** @return list<string> */
    private function leftovers(string $dir): array
    {
        return array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => str_ends_with($n, '.tmp')));
    }

    public function testWriteCreatesMissingDirectoriesAndTheFile(): void
    {
        $path = $this->tmp . '/a/b/c/data.yaml';

        AtomicFile::write($path, "version: 1\n");

        self::assertSame("version: 1\n", file_get_contents($path));
        self::assertDirectoryExists($this->tmp . '/a/b/c');
    }

    public function testWriteReplacesExistingContentCompletely(): void
    {
        $path = $this->tmp . '/data.txt';
        AtomicFile::write($path, str_repeat('long content ', 1000));

        AtomicFile::write($path, 'short');

        self::assertSame('short', file_get_contents($path));
    }

    public function testWriteHandlesEmptyAndBinaryContent(): void
    {
        $path = $this->tmp . '/bin';
        $binary = random_bytes(100_000) . "\0\r\n";

        AtomicFile::write($path, $binary);
        self::assertSame($binary, file_get_contents($path));

        AtomicFile::write($path, '');
        self::assertSame('', file_get_contents($path));
    }

    public function testNoTempFilesRemainAfterWrites(): void
    {
        for ($i = 0; $i < 20; $i++) {
            AtomicFile::write($this->tmp . '/f.txt', (string) $i);
        }

        self::assertSame([], $this->leftovers($this->tmp));
        self::assertSame(['.', '..', 'f.txt'], scandir($this->tmp));
    }

    public function testExistingFilePermissionsAreKept(): void
    {
        $path = $this->tmp . '/perm.txt';
        file_put_contents($path, 'x');
        chmod($path, 0600);

        AtomicFile::write($path, 'y');

        clearstatcache();
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testNewFilesAreGroupWritable(): void
    {
        AtomicFile::write($this->tmp . '/new.txt', 'x');

        clearstatcache();
        self::assertSame(0664, fileperms($this->tmp . '/new.txt') & 0777);
    }

    public function testWriteFailsWithARuntimeExceptionWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->tmp . '/blocker', 'i am a file');

        try {
            AtomicFile::write($this->tmp . '/blocker/sub/file.txt', 'x');
            self::fail('expected an exception');
        } catch (AtomicFileException $e) {
            self::assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function testFailedRenameCleansUpTheTempFileAndLeavesTheTargetAlone(): void
    {
        mkdir($this->tmp . '/target-is-a-dir');
        file_put_contents($this->tmp . '/target-is-a-dir/keep', 'k');

        try {
            AtomicFile::write($this->tmp . '/target-is-a-dir', 'x');
            self::fail('expected an exception');
        } catch (AtomicFileException) {
            self::assertSame([], $this->leftovers($this->tmp));
            self::assertSame('k', file_get_contents($this->tmp . '/target-is-a-dir/keep'));
        }
    }

    public function testUnwritableDirectoryFailsCleanly(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }
        mkdir($this->tmp . '/ro', 0555);

        try {
            AtomicFile::write($this->tmp . '/ro/file.txt', 'x');
            self::fail('expected an exception');
        } catch (AtomicFileException) {
            self::assertFileDoesNotExist($this->tmp . '/ro/file.txt');
        } finally {
            chmod($this->tmp . '/ro', 0775);
        }
    }

    public function testReadReturnsNullForMissingFilesAndDirectories(): void
    {
        self::assertNull(AtomicFile::read($this->tmp . '/missing'));
        self::assertNull(AtomicFile::read($this->tmp));
    }

    public function testReadReturnsTheContent(): void
    {
        file_put_contents($this->tmp . '/x', "line\n");

        self::assertSame("line\n", AtomicFile::read($this->tmp . '/x'));
        file_put_contents($this->tmp . '/empty', '');
        self::assertSame('', AtomicFile::read($this->tmp . '/empty'));
    }

    public function testReadFailsForAnExistingButUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read everything.');
        }
        file_put_contents($this->tmp . '/secret', 'x');
        chmod($this->tmp . '/secret', 0000);

        try {
            $this->expectException(AtomicFileException::class);
            @AtomicFile::read($this->tmp . '/secret');
        } finally {
            chmod($this->tmp . '/secret', 0600);
        }
    }

    public function testWithLockReturnsTheCallbackResultAndCreatesTheLockFile(): void
    {
        $result = AtomicFile::withLock($this->tmp . '/sub/x.lock', static fn (): array => ['ok' => 42]);

        self::assertSame(['ok' => 42], $result);
        self::assertFileExists($this->tmp . '/sub/x.lock');
    }

    public function testWithLockReleasesTheLockWhenTheCallbackThrows(): void
    {
        $lock = $this->tmp . '/x.lock';
        try {
            AtomicFile::withLock($lock, static function (): never {
                throw new \LogicException('boom');
            });
            self::fail('expected the exception to propagate');
        } catch (\LogicException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('again', AtomicFile::withLock($lock, static fn (): string => 'again', true, 0.2));
    }

    public function testExclusiveLockTimesOutWhileHeld(): void
    {
        $lock = $this->tmp . '/x.lock';
        $start = microtime(true);

        $this->expectException(LockTimeoutException::class);
        try {
            AtomicFile::withLock($lock, static fn (): mixed => AtomicFile::withLock($lock, static fn (): string => 'never', true, 0.3));
        } finally {
            self::assertGreaterThanOrEqual(0.25, microtime(true) - $start);
            self::assertLessThan(3.0, microtime(true) - $start);
        }
    }

    public function testSharedLocksCoexistButBlockExclusive(): void
    {
        $lock = $this->tmp . '/x.lock';

        $inner = AtomicFile::withLock($lock, static fn (): string => AtomicFile::withLock($lock, static fn (): string => 'shared+shared', false, 0.2), false);
        self::assertSame('shared+shared', $inner);

        $this->expectException(LockTimeoutException::class);
        AtomicFile::withLock($lock, static fn (): mixed => AtomicFile::withLock($lock, static fn (): string => 'x', true, 0.2), false);
    }

    public function testTimeoutIsAnAtomicFileException(): void
    {
        $lock = $this->tmp . '/x.lock';

        $this->expectException(AtomicFileException::class);
        AtomicFile::withLock($lock, static fn (): mixed => AtomicFile::withLock($lock, static fn (): string => 'x', true, 0.05));
    }

    public function testLockFileInImpossiblePlaceFails(): void
    {
        file_put_contents($this->tmp . '/blocker', 'x');

        $this->expectException(AtomicFileException::class);
        AtomicFile::withLock($this->tmp . '/blocker/x.lock', static fn (): string => 'never');
    }

    public function testLockFileThatCannotBeOpenedFails(): void
    {
        mkdir($this->tmp . '/dir.lock');

        $this->expectException(AtomicFileException::class);
        @AtomicFile::withLock($this->tmp . '/dir.lock', static fn (): string => 'never');
    }

    public function testLockedReadModifyWriteAcrossProcessesLosesNoUpdate(): void
    {
        $counter = $this->tmp . '/counter.txt';
        $lock = $this->tmp . '/counter.lock';
        AtomicFile::write($counter, '0');
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            use Grav\Plugin\RedirectManager\Storage\AtomicFile;
            for ($i = 0; $i < 60; $i++) {
                AtomicFile::withLock($argv[3], static function () use ($argv): void {
                    AtomicFile::write($argv[2], (string) ((int) AtomicFile::read($argv[2]) + 1));
                });
            }
            PHP;
        $root = dirname(__DIR__, 3);

        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open(
                [PHP_BINARY, '-r', $code, '--', $root, $counter, $lock],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        }
        foreach ($procs as $proc) {
            self::assertIsResource($proc);
            proc_close($proc);
        }

        self::assertSame('360', file_get_contents($counter));
        self::assertSame([], $this->leftovers($this->tmp));
    }

    public function testEnsureDirIsIdempotent(): void
    {
        AtomicFile::ensureDir($this->tmp . '/x/y');
        AtomicFile::ensureDir($this->tmp . '/x/y');

        self::assertDirectoryExists($this->tmp . '/x/y');
    }
}
