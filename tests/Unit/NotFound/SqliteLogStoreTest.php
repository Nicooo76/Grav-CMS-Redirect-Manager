<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\DayRange;
use Grav\Plugin\RedirectManager\NotFound\GroupDetail;
use Grav\Plugin\RedirectManager\NotFound\GroupPage;
use Grav\Plugin\RedirectManager\NotFound\GroupPlan;
use Grav\Plugin\RedirectManager\NotFound\GroupPlanner;
use Grav\Plugin\RedirectManager\NotFound\GroupQuery;
use Grav\Plugin\RedirectManager\NotFound\GroupRow;
use Grav\Plugin\RedirectManager\NotFound\GroupSort;
use Grav\Plugin\RedirectManager\NotFound\GroupTotals;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\LogStoreException;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\NotFound\SqliteLogStore;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\WorkerRunner;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SqliteLogStore::class)]
#[CoversClass(GroupDetail::class)]
#[CoversClass(GroupPlan::class)]
#[CoversClass(GroupPlanner::class)]
#[CoversClass(GroupQuery::class)]
#[CoversClass(GroupRow::class)]
#[CoversClass(GroupPage::class)]
#[CoversClass(GroupTotals::class)]
#[CoversClass(GroupSort::class)]
#[CoversClass(SortDirection::class)]
#[CoversClass(DayRange::class)]
#[CoversClass(NotFoundEntry::class)]
#[CoversClass(UserAgentClass::class)]
#[CoversClass(LogStoreException::class)]
#[Group('notfound')]
final class SqliteLogStoreTest extends LogStoreContract
{
    protected function createStore(string $dir, FixedClock $clock): LogStore
    {
        if (!SqliteLogStore::isAvailable()) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        return new SqliteLogStore($dir . '/db/404.sqlite', $clock);
    }

    private function dbFile(): string
    {
        return $this->tmp . '/db/404.sqlite';
    }

    public function testIsAvailableReflectsTheExtension(): void
    {
        self::assertSame(extension_loaded('pdo_sqlite'), SqliteLogStore::isAvailable());
    }

    public function testDatabaseUsesWalAndHasTheIndexes(): void
    {
        $this->add('/a');

        $pdo = new PDO('sqlite:' . $this->dbFile());
        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('log_t', $indexes);
        self::assertContains('log_path', $indexes);
    }

    public function testSqlInjectionInDataIsStoredLiterally(): void
    {
        $evil = "/x'; DROP TABLE log; --";
        $this->add($evil, 0, query: "' OR 1=1 --");
        $this->add('/normal', 1);

        self::assertSame(2, $this->store->groups($this->query())->total);
        self::assertSame(1, $this->store->deletePath($evil));
        self::assertSame(['/normal'], $this->pathsOf($this->query()));
    }

    public function testMaintainPurgesByRetentionAndCheckpoints(): void
    {
        $this->add('/old', self::day(-40));
        $this->add('/new', 0);

        self::assertSame(0, $this->store->maintain(0));
        self::assertSame(1, $this->store->maintain(30));
        self::assertSame(['/new'], $this->pathsOf($this->query()));
    }

    public function testSizeBytesIncludesTheDatabaseFile(): void
    {
        $this->add('/a');

        self::assertGreaterThanOrEqual((int) filesize($this->dbFile()), $this->store->sizeBytes());
    }

    public function testUnopenableDatabaseThrowsLogStoreException(): void
    {
        mkdir($this->tmp . '/is-a-dir.sqlite');
        $store = new SqliteLogStore($this->tmp . '/is-a-dir.sqlite', $this->clock);

        $this->expectException(LogStoreException::class);
        $store->append(new NotFoundEntry(new DateTimeImmutable('@' . self::NOW), '/a'));
    }

    public function testReopeningAnExistingDatabaseKeepsTheData(): void
    {
        $this->add('/a');

        $again = new SqliteLogStore($this->dbFile(), $this->clock);

        self::assertSame(1, $again->groups($this->query())->total);
    }

    public function testEightProcessesAppendingConcurrentlyLoseNothing(): void
    {
        mkdir($this->tmp . '/scratch');

        WorkerRunner::run('sqlite', $this->dbFile(), 8, 500, $this->tmp . '/scratch');

        $seen = [];
        foreach ($this->store->entries(self::at(-3600), self::at(3600)) as $e) {
            $seen[$e->path] = ($seen[$e->path] ?? 0) + 1;
            self::assertStringStartsWith('n=', $e->query);
        }
        self::assertCount(4000, $seen);
        self::assertSame(4000, array_sum($seen));
        self::assertSame(4000, $this->store->groups($this->query(['perPage' => 10]))->totals->hits);
    }
}
