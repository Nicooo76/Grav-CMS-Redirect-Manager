<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PendingDelete;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingDelete::class)]
#[CoversClass(PageSnapshot::class)]
#[CoversClass(PageNode::class)]
#[Group('auto')]
final class PendingDeleteTest extends TestCase
{
    private function snapshot(): PageSnapshot
    {
        return new PageSnapshot(
            'Blog',
            '/01.blog',
            ['de' => '/blog', 'en' => '/en-blog'],
            [
                new PageNode('post-a', ['de' => '/blog/a', 'en' => '/en-blog/a']),
                new PageNode('post-b', ['en' => '/en-blog/b']),
                new PageNode('empty', []),
            ],
            ['de' => ['/']],
        );
    }

    public function testAccessorsDelegateToTheSnapshot(): void
    {
        $at = new DateTimeImmutable('2026-05-06T07:08:09+00:00');
        $pending = new PendingDelete('p1', $this->snapshot(), $at);

        self::assertSame('p1', $pending->id);
        self::assertSame($at, $pending->deletedAt);
        self::assertSame('Blog', $pending->title());
        self::assertSame(['de' => '/blog', 'en' => '/en-blog'], $pending->routes());
        self::assertSame('/blog', $pending->route());
        self::assertSame(['de', 'en'], $pending->languages());
    }

    public function testChildrenAreTheFirstRoutePerDescendant(): void
    {
        $pending = new PendingDelete('p1', $this->snapshot(), new DateTimeImmutable());

        self::assertSame(['/blog/a', '/en-blog/b'], $pending->children(), 'a node without routes contributes nothing');
    }

    public function testNoDescendants(): void
    {
        $pending = new PendingDelete('p1', new PageSnapshot('T', '/t', ['*' => '/t']), new DateTimeImmutable());

        self::assertSame([], $pending->children());
        self::assertSame(['*'], $pending->languages());
    }

    public function testRoundTrip(): void
    {
        $pending = new PendingDelete('p1', $this->snapshot(), new DateTimeImmutable('2026-05-06T07:08:09+02:00'));

        $array = $pending->toArray();
        self::assertSame('p1', $array['id']);
        self::assertSame('2026-05-06T07:08:09+02:00', $array['deleted_at']);
        self::assertSame($this->snapshot()->toArray(), $array['snapshot']);

        $again = PendingDelete::fromArray($array);

        self::assertNotNull($again);
        self::assertSame($array, $again->toArray());
        self::assertSame('/blog', $again->route());
        self::assertSame(['/blog/a', '/en-blog/b'], $again->children());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'missing id' => [['snapshot' => ['title' => 'T']]];
        yield 'empty id' => [['id' => '', 'snapshot' => ['title' => 'T']]];
        yield 'numeric id' => [['id' => 5, 'snapshot' => ['title' => 'T']]];
        yield 'missing snapshot' => [['id' => 'p']];
        yield 'snapshot is a string' => [['id' => 'p', 'snapshot' => 'nope']];
        yield 'unparsable date' => [['id' => 'p', 'snapshot' => ['title' => 'T'], 'deleted_at' => 'the day after tomorrow-ish?!']];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidProvider')]
    public function testFromArrayRejectsIncompleteData(array $data): void
    {
        self::assertNull(PendingDelete::fromArray($data));
    }

    public function testMissingOrNonStringDateMeansNow(): void
    {
        $before = time();
        foreach ([['id' => 'p', 'snapshot' => ['title' => 'T']], ['id' => 'p', 'snapshot' => ['title' => 'T'], 'deleted_at' => 12345]] as $data) {
            $pending = PendingDelete::fromArray($data);

            self::assertNotNull($pending);
            self::assertGreaterThanOrEqual($before, $pending->deletedAt->getTimestamp());
            self::assertLessThanOrEqual(time(), $pending->deletedAt->getTimestamp());
        }
    }

    public function testSnapshotFromOddDataIsCleaned(): void
    {
        $pending = PendingDelete::fromArray([
            'id' => 'p',
            'deleted_at' => '2026-01-01T00:00:00+00:00',
            'snapshot' => [
                'title' => 7,
                'raw_route' => ['x'],
                'routes' => ['de' => '/a', 'en' => '', 'fr' => 5, 'es' => ['x']],
                'descendants' => [['key' => 'k', 'routes' => ['de' => '/a/b', 'en' => null]], 'garbage', 4],
                'ancestors' => ['de' => ['/x', '', 3, '/y'], 'en' => 'nope'],
                'home' => 1,
            ],
        ]);

        self::assertNotNull($pending);
        self::assertSame('7', $pending->title());
        self::assertSame('', $pending->snapshot->rawRoute);
        self::assertSame(['de' => '/a'], $pending->routes());
        self::assertSame(['/a/b'], $pending->children());
        self::assertSame(['de' => ['/x', '/y'], 'en' => []], $pending->snapshot->ancestors);
        self::assertTrue($pending->snapshot->home);
    }

    public function testSnapshotWithoutRoutesHasNoPrimaryRoute(): void
    {
        $snapshot = PageSnapshot::fromArray([]);

        self::assertSame('', $snapshot->title);
        self::assertSame('', $snapshot->primaryRoute());
        self::assertSame([], $snapshot->languages());
        self::assertFalse($snapshot->home);
        self::assertSame([], $snapshot->descendants);
        self::assertSame([], $snapshot->ancestors);
    }

    public function testStringMapKeepsOnlyNonEmptyStrings(): void
    {
        self::assertSame(['a' => '/x', '0' => '/z'], PageSnapshot::stringMap(['a' => '/x', 'b' => '', 'c' => 3, 'd' => null, 0 => '/z']));
        self::assertSame([], PageSnapshot::stringMap('x'));
        self::assertSame([], PageSnapshot::stringMap(null));
    }

    public function testPageNodeRoundTripAndCleanup(): void
    {
        $node = new PageNode('a/b', ['de' => '/a/b']);

        self::assertSame(['key' => 'a/b', 'routes' => ['de' => '/a/b']], $node->toArray());
        self::assertSame($node->toArray(), PageNode::fromArray($node->toArray())->toArray());

        $odd = PageNode::fromArray(['key' => ['x'], 'routes' => ['de' => '/ok', 'en' => '']]);
        self::assertSame('', $odd->key);
        self::assertSame(['de' => '/ok'], $odd->routes);
        self::assertSame(['key' => '5', 'routes' => []], PageNode::fromArray(['key' => 5])->toArray());
    }
}
