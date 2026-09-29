<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\MoveDeriver;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\RouteChangeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageSnapshot::class)]
#[CoversClass(PageNode::class)]
#[CoversClass(RouteChangeDetector::class)]
#[CoversClass(MoveDeriver::class)]
#[Group('auto')]
final class SnapshotAndDetectorTest extends TestCase
{
    public function testSnapshotRoundTrip(): void
    {
        $snapshot = new PageSnapshot(
            'Blog',
            '/blog',
            ['en' => '/blog', 'de' => '/artikel'],
            [new PageNode('a', ['en' => '/blog/a', 'de' => '/artikel/a'])],
            ['en' => ['/x'], 'de' => ['/y']],
            false,
        );

        $copy = PageSnapshot::fromArray(json_decode((string) json_encode($snapshot->toArray()), true));

        self::assertEquals($snapshot, $copy);
        self::assertSame(['en', 'de'], $copy->languages());
        self::assertSame('/blog', $copy->primaryRoute());
    }

    public function testSnapshotFromGarbageDoesNotThrow(): void
    {
        $snapshot = PageSnapshot::fromArray(['routes' => ['en' => 5, 'de' => '/d'], 'descendants' => ['x', ['key' => 'a', 'routes' => ['en' => '/a']]], 'ancestors' => ['en' => 'no']]);

        self::assertSame(['de' => '/d'], $snapshot->routes);
        self::assertCount(1, $snapshot->descendants);
        self::assertSame(['en' => []], $snapshot->ancestors);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $body
     */
    #[DataProvider('bodies')]
    public function testRouteChangeDetection(array $current, array $body, bool $expected): void
    {
        self::assertSame($expected, RouteChangeDetector::affectsRoute($current, $body));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function bodies(): iterable
    {
        yield 'no header in the body' => [['slug' => 'a'], ['content' => 'x'], false];
        yield 'title only' => [['title' => 'A'], ['header' => ['title' => 'B']], false];
        yield 'slug set' => [[], ['header' => ['slug' => 'new']], true];
        yield 'slug unchanged' => [['slug' => 'same'], ['header' => ['slug' => 'same']], false];
        yield 'slug changed' => [['slug' => 'old'], ['header' => ['slug' => 'new']], true];
        yield 'slug removed by null' => [['slug' => 'old'], ['header' => ['slug' => null]], true];
        yield 'routes default' => [[], ['header' => ['routes' => ['default' => '/x']]], true];
        yield 'routes unchanged' => [['routes' => ['default' => '/x']], ['header' => ['routes' => ['default' => '/x']]], false];
        yield 'replace without slug drops it' => [['slug' => 'old'], ['header' => ['title' => 'A'], 'header_mode' => 'replace'], true];
        yield 'replace keeps slug' => [['slug' => 'old'], ['header' => ['title' => 'A', 'slug' => 'old'], 'header_mode' => 'replace'], false];
        yield 'merge without slug keeps it' => [['slug' => 'old'], ['header' => ['title' => 'A']], false];
        yield 'header is not an array' => [[], ['header' => 'x'], false];
    }

    public function testDerivedBeforeStateForARename(): void
    {
        $after = new PageSnapshot('P', '/news/p2', ['en' => '/news/p2'], [new PageNode('c', ['en' => '/news/p2/c'])]);

        $before = MoveDeriver::before($after, '/blog/p', '/news/p2', ['en' => '/blog']);

        self::assertSame(['en' => '/blog/p'], $before->routes);
        self::assertSame(['en' => '/blog/p/c'], $before->descendants[0]->routes);
    }

    public function testDerivedBeforeStateForAMoveKeepsTheSlugPerLanguage(): void
    {
        $after = new PageSnapshot('P', '/news/p', ['en' => '/news/post', 'de' => '/aktuell/beitrag']);

        $before = MoveDeriver::before($after, '/blog/post', '/news/post', ['en' => '/blog', 'de' => '/artikel']);

        self::assertSame(['en' => '/blog/post', 'de' => '/artikel/beitrag'], $before->routes);
    }

    public function testRenamingMoveKeepsLanguagesWithTheirOwnSlug(): void
    {
        // The folder slug changed from "old" to "new"; German has its own slug that did not change.
        $after = new PageSnapshot('P', '/x/new', ['en' => '/x/new', 'de' => '/x/eigener']);

        $before = MoveDeriver::before($after, '/y/old', '/x/new', ['en' => '/y', 'de' => '/y']);

        self::assertSame(['en' => '/y/old', 'de' => '/y/eigener'], $before->routes);
    }

    public function testMoveFromTheTopLevel(): void
    {
        $after = new PageSnapshot('P', '/blog/p', [PageSnapshot::ANY => '/blog/p']);

        $before = MoveDeriver::before($after, '/p', '/blog/p', [PageSnapshot::ANY => '']);

        self::assertSame([PageSnapshot::ANY => '/p'], $before->routes);
    }
}
