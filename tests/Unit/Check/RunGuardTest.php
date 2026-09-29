<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Check;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Check\RunGuard;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunGuard::class)]
final class RunGuardTest extends TestCase
{
    private string $dir;
    private string $lock;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rm-guard-' . bin2hex(random_bytes(4));
        $this->lock = $this->dir . '/nested/check.lock';
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00'));
    }

    protected function tearDown(): void
    {
        @unlink($this->lock);
        @rmdir($this->dir . '/nested');
        @rmdir($this->dir);
    }

    public function testFirstCallIsAllowed(): void
    {
        $guard = new RunGuard($this->lock, $this->clock);

        self::assertSame(0, $guard->retryAfter());
        self::assertTrue($guard->tryAcquire());
        $guard->release();
    }

    public function testSecondCallWithinIntervalIsRefused(): void
    {
        $first = new RunGuard($this->lock, $this->clock, 300);
        self::assertTrue($first->tryAcquire());
        $first->release();

        $this->clock->set(new DateTimeImmutable('2026-09-29 12:04:59'));
        $second = new RunGuard($this->lock, $this->clock, 300);
        self::assertFalse($second->tryAcquire());
        self::assertSame(1, $second->retryAfter());

        $this->clock->set(new DateTimeImmutable('2026-09-29 12:05:00'));
        self::assertSame(0, $second->retryAfter());
        self::assertTrue($second->tryAcquire());
        $second->release();
    }

    public function testIntervalCountsFromTheEndOfTheRun(): void
    {
        $guard = new RunGuard($this->lock, $this->clock, 300);
        self::assertTrue($guard->tryAcquire());
        $this->clock->set(new DateTimeImmutable('2026-09-29 12:10:00'));
        $guard->release();

        $this->clock->set(new DateTimeImmutable('2026-09-29 12:14:00'));
        self::assertFalse((new RunGuard($this->lock, $this->clock, 300))->tryAcquire());
        $this->clock->set(new DateTimeImmutable('2026-09-29 12:15:00'));
        self::assertTrue((new RunGuard($this->lock, $this->clock, 300))->tryAcquire());
    }

    public function testConcurrentRunsAreRefusedWhileTheLockIsHeld(): void
    {
        $first = new RunGuard($this->lock, $this->clock, 0);
        self::assertTrue($first->tryAcquire());

        $other = new RunGuard($this->lock, $this->clock, 0);
        self::assertFalse($other->tryAcquire(), 'flock is held by the running check');
        self::assertFalse($first->tryAcquire(), 'the holder cannot acquire twice');

        $first->release();
        self::assertTrue($other->tryAcquire());
        $other->release();
    }

    public function testDestructorReleasesTheLock(): void
    {
        $first = new RunGuard($this->lock, $this->clock, 0);
        self::assertTrue($first->tryAcquire());
        unset($first);

        self::assertTrue((new RunGuard($this->lock, $this->clock, 0))->tryAcquire());
    }

    public function testReleaseWithoutAcquireIsHarmless(): void
    {
        $guard = new RunGuard($this->lock, $this->clock);
        $guard->release();

        self::assertFileDoesNotExist($this->lock);
    }

    public function testGarbageInLockFileCountsAsNoPreviousRun(): void
    {
        mkdir(dirname($this->lock), 0775, true);
        file_put_contents($this->lock, 'garbage');
        $guard = new RunGuard($this->lock, $this->clock);

        self::assertSame(0, $guard->retryAfter());
        self::assertTrue($guard->tryAcquire());
    }

    public function testUnusableLockPathRefuses(): void
    {
        $blocker = $this->dir . '-file';
        file_put_contents($blocker, 'x');
        try {
            self::assertFalse((new RunGuard($blocker . '/sub/l.lock', $this->clock))->tryAcquire());
        } finally {
            unlink($blocker);
        }
    }

    public function testLockFileIsAPlainDirectoryPath(): void
    {
        mkdir($this->dir . '/nested', 0775, true);
        mkdir($this->lock);
        try {
            self::assertFalse((new RunGuard($this->lock, $this->clock))->tryAcquire());
        } finally {
            rmdir($this->lock);
        }
    }
}
