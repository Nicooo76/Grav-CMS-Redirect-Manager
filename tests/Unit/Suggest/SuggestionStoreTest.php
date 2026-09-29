<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SuggestionStore::class)]
final class SuggestionStoreTest extends TestCase
{
    private string $dir;
    private string $file;
    private FixedClock $clock;
    private SuggestionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rm-suggest-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->file = $this->dir . '/suggestions.json';
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->store = new SuggestionStore($this->file, $this->clock);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) ? self::removeDir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private static function suggestion(string $target = '/blog/my-post', float $score = 0.95, SuggestionReason $reason = SuggestionReason::SameSlug): Suggestion
    {
        return new Suggestion($target, $score, $reason, 'Title of ' . $target);
    }

    public function testStartsEmptyWithoutCreatingFiles(): void
    {
        self::assertSame([], $this->store->all());
        self::assertSame([], $this->store->open());
        self::assertNull($this->store->find('nope'));
        self::assertFileDoesNotExist($this->file);
    }

    public function testUpsertCreatesAnOpenRecordAndPersistsIt(): void
    {
        $record = $this->store->upsertOpen('/old/my-post', self::suggestion(), SuggestionStore::SOURCE_SITEMAP);

        self::assertNotNull($record);
        self::assertStringStartsWith('s', $record['id']);
        self::assertSame('/old/my-post', $record['path']);
        self::assertSame('/blog/my-post', $record['target']);
        self::assertSame(0.95, $record['score']);
        self::assertSame('same_slug', $record['reason']);
        self::assertSame('Title of /blog/my-post', $record['title']);
        self::assertSame('open', $record['status']);
        self::assertSame('2026-09-29T10:00:00+00:00', $record['createdAt']);
        self::assertNull($record['decidedAt']);
        self::assertSame('sitemap', $record['source']);

        $json = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(1, $json['version']);
        self::assertSame([$record], $json['suggestions']);

        // A new instance sees the same data.
        self::assertSame([$record], (new SuggestionStore($this->file, $this->clock))->all());
        self::assertSame($record, $this->store->find($record['id']));
    }

    public function testDefaultSourceIsNotFound(): void
    {
        self::assertSame('404', $this->store->upsertOpen('/a', self::suggestion())['source'] ?? null);
    }

    public function testUpsertKeepsTheBestSuggestionPerPath(): void
    {
        $first = $this->store->upsertOpen('/old', self::suggestion('/a', 0.6, SuggestionReason::TitleMatch));
        $weaker = $this->store->upsertOpen('/old', self::suggestion('/b', 0.4, SuggestionReason::SimilarRoute));
        $equal = $this->store->upsertOpen('/old', self::suggestion('/c', 0.6, SuggestionReason::SimilarRoute));

        self::assertNotNull($first);
        self::assertSame($first, $weaker);
        self::assertSame($first, $equal);
        self::assertCount(1, $this->store->all());

        $this->clock->set(new DateTimeImmutable('2026-09-30T00:00:00+00:00'));
        $better = $this->store->upsertOpen('/old', self::suggestion('/d', 0.9, SuggestionReason::SameSlug), '404');

        self::assertNotNull($better);
        self::assertSame($first['id'], $better['id']);
        self::assertSame($first['createdAt'], $better['createdAt']);
        self::assertSame('/d', $better['target']);
        self::assertSame(0.9, $better['score']);
        self::assertSame('same_slug', $better['reason']);
        self::assertCount(1, $this->store->all());
    }

    public function testDifferentPathsGetSeparateRecords(): void
    {
        $this->store->upsertOpen('/a', self::suggestion());
        $this->store->upsertOpen('/b', self::suggestion());

        self::assertCount(2, $this->store->all());
    }

    public function testRejectedPairIsNotSuggestedAgain(): void
    {
        $record = $this->store->upsertOpen('/old', self::suggestion('/a', 0.9));
        self::assertNotNull($record);

        $rejected = $this->store->reject($record['id']);
        self::assertNotNull($rejected);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('2026-09-29T10:00:00+00:00', $rejected['decidedAt']);
        self::assertTrue($this->store->isRejected('/old', '/a'));
        self::assertFalse($this->store->isRejected('/old', '/b'));

        self::assertNull($this->store->upsertOpen('/old', self::suggestion('/a', 0.99)));
        self::assertSame([], $this->store->open());

        // A different target for the same path is a new suggestion.
        $other = $this->store->upsertOpen('/old', self::suggestion('/b', 0.5));
        self::assertNotNull($other);
        self::assertNotSame($record['id'], $other['id']);
        self::assertSame('open', $other['status']);
        self::assertSame(2, count($this->store->all()));
        // The same target for another path is unaffected.
        self::assertNotNull($this->store->upsertOpen('/other-old', self::suggestion('/a', 0.9)));
    }

    public function testAcceptAndDecisionRules(): void
    {
        $record = $this->store->upsertOpen('/old', self::suggestion());
        self::assertNotNull($record);

        $this->clock->set(new DateTimeImmutable('2026-09-29T12:30:00+00:00'));
        $accepted = $this->store->accept($record['id']);

        self::assertNotNull($accepted);
        self::assertSame('accepted', $accepted['status']);
        self::assertSame('2026-09-29T12:30:00+00:00', $accepted['decidedAt']);
        self::assertSame([], $this->store->open());

        // Repeating is idempotent, the opposite decision is refused, unknown ids are refused.
        self::assertSame($accepted, $this->store->accept($record['id']));
        self::assertNull($this->store->reject($record['id']));
        self::assertNull($this->store->accept('missing'));
        self::assertNull($this->store->reject('missing'));
        self::assertSame('accepted', $this->store->find($record['id'])['status'] ?? null);
    }

    public function testAcceptedPathCanBeSuggestedAgain(): void
    {
        $record = $this->store->upsertOpen('/old', self::suggestion());
        self::assertNotNull($record);
        $this->store->accept($record['id']);

        $again = $this->store->upsertOpen('/old', self::suggestion());

        self::assertNotNull($again);
        self::assertNotSame($record['id'], $again['id']);
        self::assertCount(2, $this->store->all());
    }

    public function testOpenIsFilteredAndSortedByScore(): void
    {
        $this->store->upsertOpen('/c', self::suggestion('/x', 0.5));
        $this->store->upsertOpen('/a', self::suggestion('/x', 0.9));
        $this->store->upsertOpen('/b', self::suggestion('/x', 0.9));
        $done = $this->store->upsertOpen('/d', self::suggestion('/x', 0.99));
        self::assertNotNull($done);
        $this->store->accept($done['id']);

        self::assertSame(['/a', '/b', '/c'], array_column($this->store->open(), 'path'));
        self::assertSame(['/a', '/b'], array_column($this->store->open(0.9), 'path'));
        self::assertSame([], $this->store->open(0.91));
        self::assertCount(4, $this->store->all());
    }

    public function testBulkAcceptReturnsRecordsToTurnIntoRules(): void
    {
        $this->store->upsertOpen('/high1', self::suggestion('/x', 0.95));
        $this->store->upsertOpen('/high2', self::suggestion('/y', 0.9));
        $this->store->upsertOpen('/low', self::suggestion('/z', 0.5));
        $rejected = $this->store->upsertOpen('/rej', self::suggestion('/w', 0.99));
        self::assertNotNull($rejected);
        $this->store->reject($rejected['id']);

        $this->clock->set(new DateTimeImmutable('2026-10-01T08:00:00+00:00'));
        $accepted = $this->store->bulkAccept(0.9);

        self::assertSame(['/high1', '/high2'], array_column($accepted, 'path'));
        foreach ($accepted as $record) {
            self::assertSame('accepted', $record['status']);
            self::assertSame('2026-10-01T08:00:00+00:00', $record['decidedAt']);
        }
        self::assertSame(['/low'], array_column($this->store->open(), 'path'));
        self::assertSame('rejected', $this->store->find($rejected['id'])['status'] ?? null);
        self::assertSame([], $this->store->bulkAccept(0.9));
    }

    public function testCorruptJsonIsMovedAsideAndTheStoreRecovers(): void
    {
        file_put_contents($this->file, '{"version": 1, "suggestions": [{"id": ');

        self::assertSame([], $this->store->all());
        self::assertFileDoesNotExist($this->file);
        $backups = glob($this->file . '.corrupt-*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('{"version": 1, "suggestions": [{"id": ', file_get_contents($backups[0]));

        $record = $this->store->upsertOpen('/old', self::suggestion());
        self::assertNotNull($record);
        self::assertSame([$record], $this->store->all());
    }

    public function testWrongShapeCountsAsCorrupt(): void
    {
        file_put_contents($this->file, '[1, 2, 3]');
        self::assertSame([], $this->store->all());
        self::assertCount(1, glob($this->file . '.corrupt-*') ?: []);
    }

    public function testEmptyFileIsNotCorruption(): void
    {
        file_put_contents($this->file, "  \n");

        self::assertSame([], $this->store->all());
        self::assertSame([], glob($this->file . '.corrupt-*') ?: []);
        self::assertNotNull($this->store->upsertOpen('/a', self::suggestion()));
    }

    public function testMalformedRecordsAreSkippedAndGapsAreFilled(): void
    {
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'suggestions' => [
                ['id' => 's1', 'path' => '/a', 'target' => '/b', 'score' => 1, 'status' => 'open'],
                ['id' => 's2', 'path' => '/a'],
                'text',
                ['id' => '', 'path' => '/a', 'target' => '/b', 'score' => 0.5, 'status' => 'open'],
                ['id' => 's3', 'path' => '/c', 'target' => '/d', 'score' => 0.5, 'status' => 'weird'],
                ['id' => 's4', 'path' => '/e', 'target' => '/f', 'score' => 'high', 'status' => 'open'],
                ['id' => 's5', 'path' => '/g', 'target' => '/h', 'score' => 0.7, 'status' => 'accepted', 'reason' => 5, 'title' => 5, 'createdAt' => 5, 'decidedAt' => 5, 'source' => 5],
            ],
        ]));

        $all = $this->store->all();

        self::assertSame(['s1', 's5'], array_column($all, 'id'));
        self::assertSame(1.0, $all[0]['score']);
        self::assertSame('similar_route', $all[0]['reason']);
        self::assertSame('404', $all[0]['source']);
        self::assertSame('', $all[0]['title']);
        self::assertNull($all[1]['decidedAt']);
        self::assertSame('', $all[1]['createdAt']);
    }

    public function testPruningDropsAcceptedThenOldestOpenButKeepsRejected(): void
    {
        $store = new SuggestionStore($this->file, $this->clock, 3);

        $a = $store->upsertOpen('/a', self::suggestion('/x'));
        $b = $store->upsertOpen('/b', self::suggestion('/x'));
        self::assertNotNull($a);
        self::assertNotNull($b);
        $store->accept($a['id']);
        $store->reject($b['id']);
        $store->upsertOpen('/c', self::suggestion('/x'));
        $store->upsertOpen('/d', self::suggestion('/x'));

        // Four records exceed the cap of three: the accepted one goes first.
        self::assertSame(['/b', '/c', '/d'], array_column($store->all(), 'path'));

        $store->upsertOpen('/e', self::suggestion('/x'));
        // Rejected stays; the oldest open one goes.
        self::assertSame(['/b', '/d', '/e'], array_column($store->all(), 'path'));
        self::assertTrue($store->isRejected('/b', '/x'));
    }

    public function testCreatesMissingDirectories(): void
    {
        $store = new SuggestionStore($this->dir . '/deep/er/suggestions.json', $this->clock);

        self::assertNotNull($store->upsertOpen('/a', self::suggestion()));
        self::assertFileExists($this->dir . '/deep/er/suggestions.json');
    }

    public function testUnwritableLocationThrows(): void
    {
        file_put_contents($this->dir . '/blocker', 'x');
        $store = new SuggestionStore($this->dir . '/blocker/suggestions.json', $this->clock);

        $this->expectException(RuntimeException::class);
        $store->upsertOpen('/a', self::suggestion());
    }

    public function testWritesAreAtomicAndLeaveNoTempFiles(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->store->upsertOpen('/p' . $i, self::suggestion('/x', 0.5 + $i / 10));
        }

        self::assertSame([], glob($this->dir . '/*.tmp') ?: []);
        self::assertNotNull(json_decode((string) file_get_contents($this->file), true));
        self::assertCount(5, $this->store->all());
    }

    public function testInvalidUtf8InPathDoesNotBreakPersistence(): void
    {
        $record = $this->store->upsertOpen("/bad-\xFF-path", self::suggestion());

        self::assertNotNull($record);
        self::assertSame(1, preg_match('//u', $record['path']));
        self::assertSame($record, $this->store->upsertOpen("/bad-\xFF-path", self::suggestion()));
        self::assertCount(1, $this->store->all());
        self::assertSame($record['path'], $this->store->all()[0]['path']);
    }

    public function testNothingIsWrittenWhenNothingChanged(): void
    {
        $this->store->upsertOpen('/a', self::suggestion('/x', 0.9));
        $mtime = filemtime($this->file);
        clearstatcache();
        touch($this->file, $mtime - 100);
        clearstatcache();

        $this->store->upsertOpen('/a', self::suggestion('/y', 0.5));
        $this->store->accept('missing');
        $this->store->bulkAccept(2.0);
        clearstatcache();

        self::assertSame($mtime - 100, filemtime($this->file));
    }
}
