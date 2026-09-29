<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Grav\PageIndexCache;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageIndexCache::class)]
#[Group('grav')]
final class PageIndexCacheTest extends TestCase
{
    use TempDirTrait;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function file(string $fingerprint): string
    {
        return $this->tmp . '/page-index-' . md5($fingerprint) . '.php';
    }

    /**
     * @return array{PageIndex, callable(): PageIndex, \Closure(): int}
     */
    private function builder(): array
    {
        $calls = 0;
        $build = static function () use (&$calls): PageIndex {
            ++$calls;

            return PageTreeFixture::index();
        };

        return [PageTreeFixture::index(), $build, static function () use (&$calls): int {
            return $calls;
        }];
    }

    public function testBuildsOnceAndReadsTheStoredIndexAfterwards(): void
    {
        [$expected, $build, $calls] = $this->builder();
        $cache = new PageIndexCache($this->tmp);

        $first = $cache->remember('tree-1', $build);
        self::assertSame(1, $calls());
        self::assertSame($expected->toArray(), $first->toArray());
        self::assertFileExists($this->file('tree-1'));

        $second = $cache->remember('tree-1', $build);
        self::assertSame(1, $calls(), 'the stored file is used');
        self::assertNotSame($first, $second);
        self::assertSame($expected->toArray(), $second->toArray());
        self::assertSame($expected->count(), $second->count());
    }

    public function testCreatesTheCacheDirectoryOnDemand(): void
    {
        [, $build] = $this->builder();
        $cache = new PageIndexCache($this->tmp . '/deep/er');

        $cache->remember('x', $build);

        self::assertFileExists($this->tmp . '/deep/er/page-index-' . md5('x') . '.php');
    }

    public function testANewFingerprintReplacesTheOlderFiles(): void
    {
        [, $build, $calls] = $this->builder();
        $cache = new PageIndexCache($this->tmp);
        file_put_contents($this->tmp . '/other.txt', 'unrelated');

        $cache->remember('tree-1', $build);
        $cache->remember('tree-2', $build);

        self::assertSame(2, $calls());
        self::assertFileDoesNotExist($this->file('tree-1'));
        self::assertFileExists($this->file('tree-2'));
        self::assertFileExists($this->tmp . '/other.txt', 'only page-index files are removed');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'not an array' => ['<?php return "nope";'];
        yield 'empty file' => [''];
        yield 'wrong format version' => ['<?php return ["version" => 999, "entries" => []];'];
        yield 'malformed entry' => ['<?php return ["version" => 1, "entries" => [["page" => "x"]]];'];
        yield 'file throws' => ['<?php throw new \RuntimeException("corrupt");'];
    }

    #[DataProvider('brokenFiles')]
    public function testRebuildsWhenTheStoredFileIsUnusable(string $content): void
    {
        [$expected, $build, $calls] = $this->builder();
        $cache = new PageIndexCache($this->tmp);
        file_put_contents($this->file('tree'), $content);

        $index = $cache->remember('tree', $build);

        self::assertSame(1, $calls());
        self::assertSame($expected->toArray(), $index->toArray());
        // the broken file was replaced by a valid one
        [, $again, $calls2] = $this->builder();
        $cache->remember('tree', $again);
        self::assertSame(0, $calls2());
    }

    public function testReturnsTheBuiltIndexWhenTheCacheCannotBeWritten(): void
    {
        [$expected, $build, $calls] = $this->builder();
        // the cache "directory" is a regular file, so nothing can be created below it
        file_put_contents($this->tmp . '/blocked', 'x');
        $cache = new PageIndexCache($this->tmp . '/blocked');

        $index = $cache->remember('tree', $build);

        self::assertSame($expected->toArray(), $index->toArray());
        self::assertSame(1, $calls());
        $cache->remember('tree', $build);
        self::assertSame(2, $calls(), 'without a stored file every call builds');
    }
}
