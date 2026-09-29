<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\App\StatsAccess;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatsAccess::class)]
#[Group('app')]
final class StatsAccessTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function store(string $statsFile = ''): StatsStore
    {
        return new StatsStore($statsFile !== '' ? $statsFile : $this->tmp . '/stats.json', $this->tmp . '/hits', $this->clock);
    }

    private function hit(string $id): void
    {
        (new HitRecorder($this->tmp . '/hits', $this->clock))->record($id);
    }

    public function testFoldsPendingHitsBeforeHandingOutTheStore(): void
    {
        $this->hit('a');
        $this->hit('a');
        $store = $this->store();

        self::assertSame(0, $store->forRule('a')->total, 'not aggregated yet');
        self::assertSame($store, (new StatsAccess($store))->store());
        self::assertSame(2, $store->forRule('a')->total);
        self::assertSame([], glob($this->tmp . '/hits/*.log') ?: [], 'the merged log is gone');
    }

    public function testAggregatesOnlyOncePerInstance(): void
    {
        $this->hit('a');
        $access = new StatsAccess($this->store());
        self::assertSame(1, $access->store()->forRule('a')->total);

        $this->hit('a');
        self::assertSame(1, $access->store()->forRule('a')->total, 'second call does not aggregate again');
        self::assertCount(1, glob($this->tmp . '/hits/*.log') ?: [], 'the new hit is still pending');

        self::assertSame(2, (new StatsAccess($this->store()))->store()->forRule('a')->total, 'a new instance (next request) does');
    }

    public function testFailedAggregationIsIgnored(): void
    {
        // The parent of the stats file is a regular file, so the lock file cannot be created.
        file_put_contents($this->tmp . '/blocker', 'x');
        $store = $this->store($this->tmp . '/blocker/stats.json');
        $access = new StatsAccess($store);

        self::assertSame($store, $access->store());
        self::assertSame($store, $access->store(), 'and it is not retried');
        self::assertSame(0, $store->forRule('a')->total);
    }
}
