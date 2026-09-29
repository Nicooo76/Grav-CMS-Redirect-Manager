<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Auto\AutoState;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoState::class)]
#[Group('auto')]
final class AutoStateTest extends TestCase
{
    use TempDirTrait;

    private AutoState $state;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->state = new AutoState($this->tmp . '/data/auto-state.json', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private static function snapshot(string $route = '/blog'): PageSnapshot
    {
        return new PageSnapshot('Blog', $route, ['en' => $route], [new PageNode('a', ['en' => $route . '/a'])], ['en' => ['/']]);
    }

    public function testStartsEmptyWithoutAFile(): void
    {
        self::assertSame([], $this->state->pending());
        self::assertSame([], $this->state->unseen());
        self::assertSame(0, $this->state->badgeCount());
        self::assertFileDoesNotExist($this->tmp . '/data/auto-state.json');
    }

    public function testPendingDecisionsSurviveANewInstance(): void
    {
        $entry = $this->state->addPending(self::snapshot());

        $fresh = new AutoState($this->tmp . '/data/auto-state.json', $this->clock);
        $found = $fresh->findPending($entry->id);

        self::assertNotNull($found);
        self::assertSame('Blog', $found->title());
        self::assertSame('/blog', $found->route());
        self::assertSame(['/blog/a'], $found->children());
        self::assertSame(['en'], $found->languages());
        self::assertSame('2026-09-29T10:00:00+00:00', $found->deletedAt->format(DATE_ATOM));
        self::assertSame(1, $fresh->pendingCount());
    }

    public function testDeletingTheSamePageAgainReplacesThePendingEntry(): void
    {
        $first = $this->state->addPending(self::snapshot());
        $second = $this->state->addPending(self::snapshot());
        $other = $this->state->addPending(self::snapshot('/news'));

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $this->state->pendingCount());
        self::assertNull($this->state->findPending($first->id));
        self::assertNotNull($this->state->findPending($other->id));
    }

    public function testResolveRemovesTheEntryAndKeepsARecord(): void
    {
        $entry = $this->state->addPending(self::snapshot());

        $resolved = $this->state->resolvePending($entry->id, 'redirect', '/x');

        self::assertSame($entry->id, $resolved?->id);
        self::assertSame(0, $this->state->pendingCount());
        self::assertNull($this->state->resolvePending($entry->id, 'gone'), 'a second resolve finds nothing');
        $raw = json_decode((string) file_get_contents($this->tmp . '/data/auto-state.json'), true);
        self::assertSame('redirect', $raw['resolved'][0]['action']);
        self::assertSame('/x', $raw['resolved'][0]['target']);
        self::assertSame('/blog', $raw['resolved'][0]['route']);
    }

    public function testUnseenIdsAreDeduplicatedAndClearedByMarkSeen(): void
    {
        $this->state->addUnseen(['r1', 'r2']);
        $this->state->addUnseen(['r2', 'r3']);

        self::assertSame(['r1', 'r2', 'r3'], $this->state->unseen());
        self::assertSame(3, $this->state->badgeCount());
        self::assertSame(3, $this->state->markSeen());
        self::assertSame([], $this->state->unseen());
        self::assertSame(0, $this->state->markSeen());
    }

    public function testForgetUnseen(): void
    {
        $this->state->addUnseen(['r1', 'r2']);
        $this->state->forgetUnseen(['r1', 'zzz']);

        self::assertSame(['r2'], $this->state->unseen());
    }

    public function testBadgeCountsUnseenPlusPendingAndIgnoresDeletedRules(): void
    {
        $this->state->addUnseen(['r1', 'r2']);
        $this->state->addPending(self::snapshot());

        self::assertSame(3, $this->state->badgeCount());
        self::assertSame(2, $this->state->badgeCount(['r1']), 'r2 no longer exists');
        self::assertSame(1, $this->state->badgeCount([]));
    }

    public function testUnseenListIsCapped(): void
    {
        $ids = [];
        for ($i = 0; $i < AutoState::MAX_UNSEEN + 20; $i++) {
            $ids[] = 'r' . $i;
        }
        $this->state->addUnseen($ids);

        self::assertCount(AutoState::MAX_UNSEEN, $this->state->unseen());
        self::assertContains('r' . (AutoState::MAX_UNSEEN + 19), $this->state->unseen(), 'the newest ids stay');
    }

    public function testCorruptFileIsBackedUpAndTheStateStartsEmpty(): void
    {
        mkdir($this->tmp . '/data');
        file_put_contents($this->tmp . '/data/auto-state.json', '{not json');

        self::assertSame([], $this->state->pending());
        $this->state->addUnseen(['r1']);

        self::assertSame(['r1'], $this->state->unseen());
        self::assertNotEmpty(glob($this->tmp . '/data/auto-state.json.corrupt-*'));
    }

    public function testEntriesWithoutASnapshotAreDropped(): void
    {
        mkdir($this->tmp . '/data');
        file_put_contents($this->tmp . '/data/auto-state.json', json_encode(['version' => 1, 'pending' => [['id' => 'x'], 'junk'], 'unseen' => ['a', 5, ''], 'resolved' => []]));

        self::assertSame([], $this->state->pending());
        self::assertSame(['a'], $this->state->unseen());
    }

    public function testConcurrentWritersDoNotLoseUpdates(): void
    {
        $file = $this->tmp . '/data/auto-state.json';
        $script = $this->tmp . '/worker.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
$state = new Grav\Plugin\RedirectManager\Auto\AutoState($argv[1], new Grav\Plugin\RedirectManager\Util\SystemClock());
for ($i = 0; $i < 25; $i++) { $state->addUnseen([$argv[2] . $i]); }
');
        $procs = [];
        foreach (['a', 'b', 'c'] as $prefix) {
            $procs[] = proc_open([PHP_BINARY, $script, $file, $prefix], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $proc) {
            proc_close($proc);
        }

        self::assertCount(75, $this->state->unseen());
    }
}
