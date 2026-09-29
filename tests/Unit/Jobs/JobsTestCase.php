<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Jobs;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\TestCase;

abstract class JobsTestCase extends TestCase
{
    use TempDirTrait;

    protected FixedClock $clock;
    protected JsonlLogStore $log;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T12:00:00+00:00'));
        $this->log = new JsonlLogStore($this->tmp . '/404', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    protected function hit(string $path, string $when = '2026-09-29T11:00:00+00:00', string $referer = '', ?string $language = 'en'): void
    {
        $this->log->append(new NotFoundEntry(new DateTimeImmutable($when), $path, '', $referer, 'Mozilla/5.0', UserAgentClass::Browser, null, $language, 'example.org'));
    }

    protected function hits(string $path, int $count, string $when = '2026-09-29T11:00:00+00:00'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->hit($path, $when);
        }
    }
}
