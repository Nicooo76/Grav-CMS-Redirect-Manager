<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Stats;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Tests\Unit\Stats\Support\ScriptedStreamWrapper;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The failure paths of HitRecorder::record() that need a file replaced under the writer's feet or a short write.
 * They run against a scripted in-memory stream, so no timing is involved.
 */
#[CoversClass(HitRecorder::class)]
#[Group('stats')]
final class HitRecorderRaceTest extends TestCase
{
    private const PROTOCOL = 'hitrace';
    private const FILE = 'hitrace://hits/2026-09-29.log';

    private HitRecorder $recorder;

    protected function setUp(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, ScriptedStreamWrapper::class);
        }
        ScriptedStreamWrapper::reset();
        $this->recorder = new HitRecorder(self::PROTOCOL . '://hits', new FixedClock(new DateTimeImmutable('2026-09-29 12:00:00 UTC')));
    }

    protected function tearDown(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
        ScriptedStreamWrapper::reset();
    }

    public function testWritesOneLineThroughAWrapperTransparently(): void
    {
        $this->recorder->record('a');
        $this->recorder->record('b');

        self::assertSame("a\nb\n", ScriptedStreamWrapper::$files[self::FILE]);
        self::assertSame(2, ScriptedStreamWrapper::$opens);
    }

    public function testOnlyTheFirstWriteToAnEmptyFileSetsThePermissions(): void
    {
        $this->recorder->record('a');
        $this->recorder->record('b');

        self::assertSame([self::FILE . ':664'], ScriptedStreamWrapper::$chmods);
    }

    public function testReopensWhenTheFileAtThePathIsNotTheOneItOpened(): void
    {
        ScriptedStreamWrapper::$replacedFor = 2;

        $this->recorder->record('a');

        self::assertSame(3, ScriptedStreamWrapper::$opens, 'two handles pointed at a replaced file, the third was right');
        self::assertSame("a\n", ScriptedStreamWrapper::$files[self::FILE], 'and the hit was written exactly once');
    }

    public function testReopensWhenTheFileVanishedAfterOpening(): void
    {
        ScriptedStreamWrapper::$missingFor = 1;

        $this->recorder->record('a');

        self::assertSame(2, ScriptedStreamWrapper::$opens);
        self::assertSame("a\n", ScriptedStreamWrapper::$files[self::FILE]);
    }

    public function testTheLastAttemptStillCounts(): void
    {
        ScriptedStreamWrapper::$replacedFor = 999;

        $this->recorder->record('a');

        self::assertSame(1000, ScriptedStreamWrapper::$opens);
        self::assertSame("a\n", ScriptedStreamWrapper::$files[self::FILE]);
    }

    public function testKeepsReopeningWhileAggregationsFollowEachOther(): void
    {
        // The old limit of five attempts dropped the hit here, and a dropped hit is a lost hit.
        ScriptedStreamWrapper::$replacedFor = 200;

        $this->recorder->record('a');

        self::assertSame(201, ScriptedStreamWrapper::$opens);
        self::assertSame("a\n", ScriptedStreamWrapper::$files[self::FILE], 'written exactly once');
    }

    public function testGivesUpEventuallyWithoutWritingAnything(): void
    {
        ScriptedStreamWrapper::$replacedFor = 99999;

        try {
            $this->recorder->record('a');
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('Hit log "' . self::FILE . '" kept changing while writing.', $e->getMessage());
        }

        self::assertSame(1000, ScriptedStreamWrapper::$opens);
        self::assertSame('', ScriptedStreamWrapper::$files[self::FILE]);
    }

    public function testRetriesOnceWhenTheOpenFailsAlthoughTheDirectoryExists(): void
    {
        // Two writers on a fresh install: A fails to open because the directory is missing, B creates it, A finds it there.
        ScriptedStreamWrapper::$failOpens = 1;

        $this->recorder->record('a');

        self::assertSame(1, ScriptedStreamWrapper::$opens);
        self::assertSame("a\n", ScriptedStreamWrapper::$files[self::FILE]);
    }

    public function testAFileThatStaysUnopenableIsAnError(): void
    {
        ScriptedStreamWrapper::$failOpens = 99;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open hit log "' . self::FILE . '".');

        $this->recorder->record('a');
    }

    public function testAShortWriteIsAnError(): void
    {
        ScriptedStreamWrapper::$writeLimit = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Short write to hit log "' . self::FILE . '".');

        $this->recorder->record('rule-1');
    }
}
