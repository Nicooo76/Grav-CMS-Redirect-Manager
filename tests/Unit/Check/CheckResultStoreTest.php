<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Check;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckResultStore::class)]
final class CheckResultStoreTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rm-store-' . bin2hex(random_bytes(4));
        $this->file = $this->dir . '/data/target-checks.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/data/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/data');
        @rmdir($this->dir);
    }

    private function row(string $id, ?int $status, ?string $error = null, string $at = '2026-09-29 12:00:00'): TargetCheckResult
    {
        return new TargetCheckResult(
            $id,
            'https://site.test/' . $id,
            $status,
            $error === null && $status !== null && $status < 400,
            $error,
            $status !== null ? 'https://site.test/' . $id : null,
            0,
            12,
            new DateTimeImmutable($at),
        );
    }

    public function testEmptyStoreReadsAsEmpty(): void
    {
        $store = new CheckResultStore($this->file);

        self::assertSame([], $store->all());
        self::assertSame([], $store->dead());
        self::assertNull($store->lastRun());
        self::assertFileDoesNotExist($this->file);
    }

    public function testSaveCreatesDirectoryAndPersists(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 200), $this->row('b', 404)]);

        self::assertFileExists($this->file);
        $fresh = new CheckResultStore($this->file);
        self::assertSame(['a', 'b'], array_keys($fresh->all()));
        self::assertSame(404, $fresh->all()['b']->status);
        self::assertSame([], glob($this->dir . '/data/*.tmp'), 'no temp files left behind');
        $doc = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(1, $doc['version']);
    }

    public function testSaveMergesByRuleId(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 404), $this->row('b', 200)]);
        $store->save([$this->row('a', 200, at: '2026-09-30 08:00:00'), $this->row('c', 500)]);

        $all = $store->all();
        self::assertSame(['a', 'b', 'c'], array_keys($all));
        self::assertSame(200, $all['a']->status);
        self::assertSame(['c'], array_map(static fn (TargetCheckResult $r): string => $r->ruleId, $store->dead()));
    }

    public function testEmptySaveDoesNothing(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([]);

        self::assertFileDoesNotExist($this->file);
    }

    public function testRateLimitedResultsKeepThePreviousOne(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 404)]);
        $store->save([$this->row('a', null, TargetCheckResult::ERROR_RATE_LIMITED, '2026-10-01 00:00:00'), $this->row('b', null, TargetCheckResult::ERROR_RATE_LIMITED)]);

        $all = $store->all();
        self::assertSame(['a'], array_keys($all));
        self::assertSame(404, $all['a']->status);
        self::assertSame('2026-09-29', $store->lastRun()?->format('Y-m-d'), 'a skipped result is not a run');
    }

    public function testSkippedResultsReplaceOldOnes(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 404)]);
        $store->save([$this->row('a', null, TargetCheckResult::ERROR_SKIPPED_DYNAMIC)]);

        self::assertSame([], $store->dead());
        self::assertSame('skipped_dynamic', $store->all()['a']->error);
    }

    public function testDeadListsUnreachableAndErrorStatusOnly(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([
            $this->row('ok', 200),
            $this->row('gone', 410),
            $this->row('dns', null, 'dns'),
            $this->row('priv', null, 'blocked_private'),
            $this->row('dyn', null, 'skipped_dynamic'),
        ]);

        self::assertSame(['gone', 'dns'], array_map(static fn (TargetCheckResult $r): string => $r->ruleId, $store->dead()));
    }

    public function testForgetRemovesIds(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 404), $this->row('b', 200), $this->row('c', 500)]);
        $store->forget(['a', 'zzz']);

        self::assertSame(['b', 'c'], array_keys($store->all()));
    }

    public function testForgetWithoutFileOrIdsIsANoop(): void
    {
        $store = new CheckResultStore($this->file);
        $store->forget(['a']);
        self::assertFileDoesNotExist($this->file);

        $store->save([$this->row('a', 200)]);
        $before = (string) file_get_contents($this->file);
        $store->forget([]);
        $store->forget(['unknown']);
        self::assertSame($before, (string) file_get_contents($this->file));
    }

    public function testLastRunIsTheNewestCheckTime(): void
    {
        $store = new CheckResultStore($this->file);
        $store->save([$this->row('a', 200, at: '2026-09-29 12:00:00'), $this->row('b', 200, at: '2026-09-29 12:05:00')]);
        self::assertSame('2026-09-29 12:05:00', $store->lastRun()?->format('Y-m-d H:i:s'));

        $store->save([$this->row('c', 200, at: '2026-09-29 11:00:00')]);
        self::assertSame('2026-09-29 12:05:00', $store->lastRun()?->format('Y-m-d H:i:s'), 'never moves backwards');
    }

    public function testCorruptFilesReadAsEmptyAndAreRepairedOnSave(): void
    {
        mkdir($this->dir . '/data', 0775, true);
        file_put_contents($this->file, '{not json');
        $store = new CheckResultStore($this->file);

        self::assertSame([], $store->all());
        $store->save([$this->row('a', 200)]);
        self::assertSame(['a'], array_keys($store->all()));
    }

    public function testMalformedRowsAreDropped(): void
    {
        mkdir($this->dir . '/data', 0775, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'last_run' => 'garbage',
            'results' => ['x' => 'not an array', 'y' => ['rule_id' => 'y'], 'z' => $this->row('z', 200)->toArray()],
        ]));
        $store = new CheckResultStore($this->file);

        self::assertSame(['z'], array_keys($store->all()));
        self::assertNull($store->lastRun());
    }

    public function testNonArrayResultsAndEmptyFileAreTolerated(): void
    {
        mkdir($this->dir . '/data', 0775, true);
        file_put_contents($this->file, json_encode(['results' => 'oops', 'last_run' => 5]));
        $store = new CheckResultStore($this->file);
        self::assertSame([], $store->all());
        self::assertNull($store->lastRun());

        file_put_contents($this->file, '');
        self::assertSame([], $store->all());
    }

    public function testUnwritableDirectoryThrows(): void
    {
        $blocker = $this->dir . '-file';
        file_put_contents($blocker, 'x');
        try {
            $store = new CheckResultStore($blocker . '/sub/checks.json');
            $this->expectException(\RuntimeException::class);
            $store->save([$this->row('a', 200)]);
        } finally {
            unlink($blocker);
        }
    }
}
