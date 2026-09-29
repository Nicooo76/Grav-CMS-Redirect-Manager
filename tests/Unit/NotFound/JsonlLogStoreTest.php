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
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\LogStore;
use Grav\Plugin\RedirectManager\NotFound\LogStoreException;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\SortDirection;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\WorkerRunner;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(JsonlLogStore::class)]
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
final class JsonlLogStoreTest extends LogStoreContract
{
    private function jsonl(): JsonlLogStore
    {
        self::assertInstanceOf(JsonlLogStore::class, $this->store);

        return $this->store;
    }

    protected function createStore(string $dir, FixedClock $clock): LogStore
    {
        return new JsonlLogStore($dir . '/404', $clock);
    }

    private function logDir(): string
    {
        return $this->tmp . '/404';
    }

    private function dayFile(string $day): string
    {
        return $this->logDir() . '/' . $day . '.jsonl';
    }

    /** @return list<string> */
    private function jsonlNames(): array
    {
        $names = array_values(array_filter(scandir($this->logDir()) ?: [], static fn (string $n): bool => str_ends_with($n, '.jsonl')));
        sort($names);

        return $names;
    }

    public function testOneFileAndOneLinePerEntryPerUtcDay(): void
    {
        $this->add('/a', 0);
        $this->add('/b', 5);
        $this->add('/c', self::day(-1));

        self::assertSame(['2026-09-28.jsonl', '2026-09-29.jsonl'], $this->jsonlNames());
        $lines = file($this->dayFile('2026-09-29'), FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['t', 'p', 'ua', 'c', 'l', 'h', 'm'], array_keys($first));
        self::assertSame('/a', $first['p']);
    }

    public function testDayBoundaryFollowsUtcNotTheEntryTimezone(): void
    {
        $late = new DateTimeImmutable('2026-09-29 23:59:59 UTC');
        $this->store->append(new NotFoundEntry($late->setTimezone(new \DateTimeZone('Pacific/Auckland')), '/a'));

        self::assertSame(['2026-09-29.jsonl'], $this->jsonlNames());
    }

    public function testLinesStaySingleLineAndUnicodeIsNotEscaped(): void
    {
        $this->store->append(new NotFoundEntry(self::at(0), "/a\nb\r\n/c\u{2028}d", "q=\"1\"\\", 'https://x/ü', "UA\x00\x1b[31m", UserAgentClass::Bot, null, null, 'h'));

        $raw = (string) file_get_contents($this->dayFile('2026-09-29'));
        self::assertSame(1, substr_count($raw, "\n"));
        self::assertStringContainsString('ü', $raw);
        self::assertStringNotContainsString("\r", $raw);
        $entry = iterator_to_array($this->store->entries(self::at(-1), self::at(1)), false)[0];
        self::assertSame("/a\nb\r\n/c\u{2028}d", $entry->path);
    }

    public function testInvalidUtf8IsSubstitutedNotLost(): void
    {
        $this->store->append(new NotFoundEntry(self::at(0), "/bad-\xFF\xFE-path"));

        $entry = iterator_to_array($this->store->entries(self::at(-1), self::at(1)), false)[0];

        self::assertSame("/bad-\u{FFFD}\u{FFFD}-path", $entry->path);
    }

    public function testSlashesAreNotEscaped(): void
    {
        $this->add('/a/b/c');

        self::assertStringContainsString('"p":"/a/b/c"', (string) file_get_contents($this->dayFile('2026-09-29')));
    }

    public function testDayCapDropsFurtherEntriesAndCountsThem(): void
    {
        $store = new JsonlLogStore($this->logDir(), $this->clock, 10_000_000, 1000);

        for ($i = 0; $i < 50; $i++) {
            $store->append(new NotFoundEntry(self::at($i), '/p' . $i, '', '', '', UserAgentClass::Browser, null, null, 'h'));
        }

        clearstatcache();
        self::assertLessThanOrEqual(1000, filesize($this->dayFile('2026-09-29')));
        $kept = iterator_to_array($store->entries(self::at(-1), self::at(100)), false);
        self::assertGreaterThan(3, count($kept));
        self::assertLessThan(50, count($kept));
        self::assertSame(50 - count($kept), $store->droppedCount());

        $store->append(new NotFoundEntry(self::at(self::day(1)), '/next-day', '', '', '', UserAgentClass::Browser, null, null, 'h'));
        self::assertFileExists($this->dayFile('2026-09-30'));
    }

    public function testRotationDeletesOldestDayFilesWhenTheDirectoryIsTooBig(): void
    {
        $store = new JsonlLogStore($this->logDir(), $this->clock, 2500, 10_000_000);
        // ~700 bytes per day file
        for ($d = -5; $d <= 0; $d++) {
            for ($i = 0; $i < 5; $i++) {
                $store->append(new NotFoundEntry(self::at(self::day($d, $i)), '/' . str_repeat('p', 100) . $i, '', '', '', UserAgentClass::Browser, null, null, 'h'));
            }
        }

        $names = $this->jsonlNames();
        self::assertContains('2026-09-29.jsonl', $names);
        self::assertNotContains('2026-09-24.jsonl', $names);
        self::assertLessThan(6, count($names));
        self::assertLessThanOrEqual(2500 + 800, $store->sizeBytes());
    }

    public function testRotationNeverDeletesTodaysOrTheNewestFile(): void
    {
        $store = new JsonlLogStore($this->logDir(), $this->clock, 1, 10_000_000);
        $store->append(new NotFoundEntry(self::at(self::day(-3)), '/old'));
        $store->append(new NotFoundEntry(self::at(self::day(-2)), '/older'));
        $store->append(new NotFoundEntry(self::at(0), '/today'));
        $store->append(new NotFoundEntry(self::at(self::day(3)), '/future'));

        self::assertSame(['2026-09-29.jsonl', '2026-10-02.jsonl'], $this->jsonlNames());
        self::assertSame(0, $store->rotate());
    }

    public function testRotateOnEmptyDirectoryDoesNothing(): void
    {
        self::assertSame(0, $this->jsonl()->rotate());
        self::assertSame(0, $this->jsonl()->droppedCount());
        self::assertSame(0, $this->jsonl()->sizeBytes());
    }

    public function testCorruptAndPartialLinesAreSkipped(): void
    {
        $this->add('/good-1', 0);
        $file = $this->dayFile('2026-09-29');
        $good = json_encode(['t' => self::NOW + 5, 'p' => '/good-2', 'c' => 'bot', 'h' => 'x', 'm' => 'GET'], JSON_THROW_ON_ERROR);
        file_put_contents($file, implode("\n", [
            '{"t":' . (self::NOW + 1) . ',"p":"/trunc',
            'not json at all',
            "\x00\x01\x02binary\xFF",
            '',
            '{"t":"string","p":"/wrong-type"}',
            '{"t":1}',
            '[1,2,3]',
            '"just a string"',
            $good,
            str_repeat('A', 100_000),
            '{"t":' . (self::NOW + 6) . ',"p":"/after-huge-line"}',
        ]) . "\n" . '{"t":' . (self::NOW + 7) . ',"p":"/unterminated-but-valid"}', FILE_APPEND);
        file_put_contents($file, "\n{\"t\":" . (self::NOW + 8) . ',"p":"/cut-off', FILE_APPEND);

        $paths = array_map(static fn (NotFoundEntry $e): string => $e->path, iterator_to_array($this->store->entries(self::at(-1), self::at(100)), false));

        self::assertSame(['/good-1', '/good-2', '/after-huge-line', '/unterminated-but-valid'], $paths);
        self::assertSame(4, $this->store->groups($this->query())->total);
    }

    public function testPurgeAndDeletePathDropCorruptLinesWhileRewriting(): void
    {
        $this->add('/a', 0);
        $this->add('/b', 1);
        file_put_contents($this->dayFile('2026-09-29'), "garbage\n", FILE_APPEND);

        self::assertSame(1, $this->store->deletePath('/a'));

        self::assertSame(1, substr_count((string) file_get_contents($this->dayFile('2026-09-29')), "\n"));
        self::assertSame(['/b'], $this->pathsOf($this->query()));
    }

    public function testEmptyDayFilesAreRemovedWhenEverythingIsDeleted(): void
    {
        $this->add('/a', self::day(-1));
        $this->add('/a', 0);

        self::assertSame(2, $this->store->deletePath('/a'));

        self::assertSame([], $this->jsonlNames());
        self::assertSame([], glob($this->logDir() . '/*.tmp') ?: []);
    }

    public function testPurgeRemovesWholeOldFilesAndTheirDropCounters(): void
    {
        $store = new JsonlLogStore($this->logDir(), $this->clock, 10_000_000, 200);
        for ($i = 0; $i < 5; $i++) {
            $store->append(new NotFoundEntry(self::at(self::day(-10, $i)), '/old' . $i, '', '', '', UserAgentClass::Browser, null, null, 'h'));
        }
        self::assertGreaterThan(0, $store->droppedCount());

        $store->purge(self::at(self::day(-5)));

        self::assertSame(0, $store->droppedCount());
        self::assertSame([], $this->jsonlNames());
    }

    public function testPurgeLeavesNewerFilesUntouched(): void
    {
        $this->add('/keep', 0);
        $before = (string) file_get_contents($this->dayFile('2026-09-29'));

        $this->store->purge(self::at(-3600));

        self::assertSame($before, (string) file_get_contents($this->dayFile('2026-09-29')));
    }

    public function testForeignFilesInTheDirectoryAreIgnored(): void
    {
        $this->add('/a');
        file_put_contents($this->logDir() . '/notes.txt', 'hello');
        file_put_contents($this->logDir() . '/2026-09-29.jsonl.bak', '{"t":1,"p":"/x"}');
        mkdir($this->logDir() . '/2026-01-01.jsonl.d');

        self::assertSame(1, $this->store->groups($this->query())->total);
        $this->store->clear();
        self::assertFileExists($this->logDir() . '/notes.txt');
    }

    public function testUnwritableDirectoryThrowsLogStoreException(): void
    {
        file_put_contents($this->tmp . '/blocker', 'x');
        $store = new JsonlLogStore($this->tmp . '/blocker/sub', $this->clock);

        $this->expectException(\Grav\Plugin\RedirectManager\Storage\AtomicFileException::class);
        $store->append(new NotFoundEntry(self::at(0), '/a'));
    }

    public function testUnopenableDayFileThrowsLogStoreException(): void
    {
        mkdir($this->logDir() . '/2026-09-29.jsonl', 0775, true);

        $this->expectException(LogStoreException::class);
        $this->store->append(new NotFoundEntry(self::at(0), '/a'));
    }

    public function testGroupsStreamAndKeepMemoryLow(): void
    {
        mkdir($this->logDir(), 0775, true);
        $h = fopen($this->dayFile('2026-09-29'), 'wb');
        for ($i = 0; $i < 30_000; $i++) {
            fwrite($h, json_encode(['t' => self::NOW + ($i % 3000), 'p' => '/p' . ($i % 500), 'q' => 'pad=' . str_repeat('y', 200), 'c' => 'browser', 'h' => 'example.org', 'm' => 'GET']) . "\n");
        }
        fclose($h);

        gc_collect_cycles();
        $before = memory_get_usage();
        $page = $this->store->groups($this->query(['perPage' => 10]));
        $growth = memory_get_peak_usage() - $before;

        self::assertSame(500, $page->total);
        self::assertSame(30_000, $page->totals->hits);
        self::assertLessThan(20 * 1024 * 1024, $growth, 'groups() must not load the log into memory');
    }

    public function testEightProcessesAppendingConcurrentlyProduceExactlyTheExpectedValidLines(): void
    {
        mkdir($this->tmp . '/scratch');

        WorkerRunner::run('jsonl', $this->logDir(), 8, 500, $this->tmp . '/scratch');

        $lines = file($this->dayFile('2026-09-29'), FILE_IGNORE_NEW_LINES);
        self::assertCount(4000, $lines);
        $seen = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame(str_repeat('x', 300), substr($data['q'], -300));
            $seen[$data['p']] = true;
        }
        self::assertCount(4000, $seen, 'no line duplicated, none interleaved');
        self::assertSame(4000, $this->store->groups($this->query(['perPage' => 10]))->totals->hits);
        self::assertSame(0, $this->jsonl()->droppedCount());
    }

    public function testRewritingWhileWritersAreActiveLosesNoEntry(): void
    {
        mkdir($this->tmp . '/scratch');
        $removed = 0;

        WorkerRunner::run('jsonl', $this->logDir(), 4, 400, $this->tmp . '/scratch', function () use (&$removed): void {
            $removed += $this->store->deletePath('/gone/w0/1');
            $removed += $this->store->deletePath('/gone/w1/3');
            $this->store->purge(self::at(-86400));
            usleep(500);
        }, 'mixed');
        $removed += $this->store->deletePath('/gone/w2/5');

        $paths = [];
        foreach ($this->store->entries(self::at(-3600), self::at(3600)) as $e) {
            $paths[$e->path] = true;
        }
        $keep = 0;
        for ($w = 0; $w < 4; $w++) {
            for ($i = 0; $i < 400; $i += 2) {
                self::assertArrayHasKey("/keep/w{$w}/{$i}", $paths, 'entry lost while another process rewrote the file');
                $keep++;
            }
        }
        self::assertSame(800, $keep);
        self::assertGreaterThanOrEqual(0, $removed);
    }

    public function testWriterReopensWhenTheFileIsReplacedWhileItWaitsForTheLock(): void
    {
        mkdir($this->logDir(), 0775, true);
        mkdir($this->tmp . '/scratch');
        $file = $this->dayFile('2026-09-29');
        $kept = json_encode(['t' => self::NOW - 5, 'p' => '/kept', 'c' => 'browser', 'h' => 'h', 'm' => 'GET'], JSON_THROW_ON_ERROR);
        file_put_contents($file, "{\"t\":1,\"p\":\"/to-be-replaced\"}\n");
        $held = fopen($file, 'r+b');
        self::assertIsResource($held);
        flock($held, LOCK_EX);
        $done = false;

        WorkerRunner::run('jsonl', $this->logDir(), 1, 1, $this->tmp . '/scratch', function () use (&$done, $held, $file, $kept): void {
            if ($done) {
                usleep(1000);

                return;
            }
            $done = true;
            usleep(400_000);
            file_put_contents($file . '.new', $kept . "\n");
            rename($file . '.new', $file);
            flock($held, LOCK_UN);
            fclose($held);
        });

        $paths = array_map(static fn (NotFoundEntry $e): string => $e->path, iterator_to_array($this->store->entries(self::at(-3600), self::at(3600)), false));
        self::assertSame(['/kept', '/keep/w0/0'], $paths);
    }
}
