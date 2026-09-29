<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\ResolvedPaths;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedPaths::class)]
#[Group('notfound')]
final class ResolvedPathsTest extends TestCase
{
    use TempDirTrait;

    private string $file;

    protected function setUp(): void
    {
        $this->file = $this->makeTempDir() . '/data/404-state.json';
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testMissingFileMeansNothingIsResolved(): void
    {
        self::assertSame([], (new ResolvedPaths($this->file))->all());
    }

    public function testMarkAndListInUtc(): void
    {
        $paths = new ResolvedPaths($this->file);

        $paths->markResolved('/old/a', new DateTimeImmutable('2026-09-29 14:00:00 Europe/Berlin'));
        $paths->markResolved('/über/ß', new DateTimeImmutable('2026-09-30 00:00:00 UTC'));

        $all = (new ResolvedPaths($this->file))->all();
        self::assertSame(['/old/a', '/über/ß'], array_keys($all));
        self::assertSame('2026-09-29 12:00:00', $all['/old/a']->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $all['/old/a']->getTimezone()->getName());
    }

    public function testMarkingAgainUpdatesTheTime(): void
    {
        $paths = new ResolvedPaths($this->file);
        $paths->markResolved('/a', new DateTimeImmutable('2026-01-01 UTC'));
        $paths->markResolved('/a', new DateTimeImmutable('2026-02-01 UTC'));

        self::assertCount(1, $paths->all());
        self::assertSame('2026-02-01', $paths->all()['/a']->format('Y-m-d'));
    }

    public function testMarkManyAndUnmark(): void
    {
        $paths = new ResolvedPaths($this->file);
        $paths->markManyResolved(['/a', '/b', '/c'], new DateTimeImmutable('2026-01-01 UTC'));
        $paths->unmark('/b');
        $paths->unmark('/never-marked');

        self::assertSame(['/a', '/c'], array_keys($paths->all()));
    }

    public function testFileFormatAndUnknownKeysArePreserved(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode(['version' => 1, 'other' => ['keep' => [1, 2]], 'resolved' => []]));
        $paths = new ResolvedPaths($this->file);

        $paths->markResolved('/a', new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

        $data = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(['keep' => [1, 2]], $data['other']);
        self::assertSame(['/a' => '2026-01-01T00:00:00+00:00'], $data['resolved']);
        self::assertSame(1, $data['version']);
    }

    public function testEmptyResolvedSetIsWrittenAsObject(): void
    {
        $paths = new ResolvedPaths($this->file);
        $paths->markResolved('/a', new DateTimeImmutable('2026-01-01 UTC'));
        $paths->unmark('/a');

        self::assertStringContainsString('"resolved":{}', (string) file_get_contents($this->file));
        self::assertSame([], $paths->all());
    }

    public function testCorruptOrMalformedStateCountsAsEmptyAndIsRepairedOnWrite(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, '{not json');
        $paths = new ResolvedPaths($this->file);
        self::assertSame([], $paths->all());

        $paths->markResolved('/a', new DateTimeImmutable('2026-01-01 UTC'));

        self::assertSame(['/a'], array_keys($paths->all()));
    }

    public function testInvalidTimesAndWrongShapesAreSkipped(): void
    {
        mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode(['resolved' => ['/ok' => '2026-01-01T00:00:00+00:00', '/bad' => 'not a date', '/num' => 5]]));

        self::assertSame(['/ok'], array_keys((new ResolvedPaths($this->file))->all()));

        file_put_contents($this->file, json_encode(['resolved' => 'nonsense']));
        self::assertSame([], (new ResolvedPaths($this->file))->all());
        (new ResolvedPaths($this->file))->markResolved('/a', new DateTimeImmutable('2026-01-01 UTC'));
        self::assertSame(['/a'], array_keys((new ResolvedPaths($this->file))->all()));
    }
}
