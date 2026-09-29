<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotFoundEntry::class)]
#[Group('notfound')]
final class NotFoundEntryTest extends TestCase
{
    public function testToArrayUsesShortKeys(): void
    {
        $entry = new NotFoundEntry(
            new DateTimeImmutable('2026-09-29 12:00:00 UTC'),
            '/old',
            'a=1',
            'https://ref.example/x',
            'UA',
            UserAgentClass::Bot,
            '1.2.3.0',
            'de',
            'example.org',
            'HEAD',
        );

        self::assertSame(
            ['t' => 1_790_683_200, 'p' => '/old', 'q' => 'a=1', 'r' => 'https://ref.example/x', 'ua' => 'UA', 'c' => 'bot', 'ip' => '1.2.3.0', 'l' => 'de', 'h' => 'example.org', 'm' => 'HEAD'],
            $entry->toArray(),
        );
    }

    public function testEmptyOptionalFieldsAreOmitted(): void
    {
        $entry = new NotFoundEntry(new DateTimeImmutable('@100'), '/x', host: 'h');

        self::assertSame(['t' => 100, 'p' => '/x', 'c' => 'unknown', 'h' => 'h', 'm' => 'GET'], $entry->toArray());
    }

    public function testRoundTrip(): void
    {
        $entry = new NotFoundEntry(new DateTimeImmutable('2026-01-02 03:04:05 Europe/Berlin'), '/ä', 'q=1', 'https://r/', 'UA', UserAgentClass::Monitoring, '::', 'en', 'h.example', 'GET');

        $copy = NotFoundEntry::fromArray($entry->toArray());

        self::assertEquals($entry, $copy);
        self::assertSame('UTC', $copy->time->getTimezone()->getName());
    }

    public function testTimeIsAlwaysUtc(): void
    {
        $entry = new NotFoundEntry(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('Asia/Tokyo')), '/x');

        self::assertSame('UTC', $entry->time->getTimezone()->getName());
        self::assertSame('2026-06-01 03:00:00', $entry->time->format('Y-m-d H:i:s'));
    }

    public function testFromArrayToleratesMissingAndWrongTypedOptionalFields(): void
    {
        $entry = NotFoundEntry::fromArray(['t' => 5, 'p' => '/x', 'q' => 5, 'r' => null, 'c' => 'nonsense', 'ip' => 3, 'l' => [], 'h' => false, 'm' => 7]);

        self::assertSame('', $entry->query);
        self::assertSame('', $entry->referer);
        self::assertSame(UserAgentClass::Unknown, $entry->uaClass);
        self::assertNull($entry->ip);
        self::assertNull($entry->language);
        self::assertSame('', $entry->host);
        self::assertSame('GET', $entry->method);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [[]];
        yield 'no path' => [['t' => 1]];
        yield 'no time' => [['p' => '/x']];
        yield 'string time' => [['t' => '1', 'p' => '/x']];
        yield 'float time' => [['t' => 1.5, 'p' => '/x']];
        yield 'array path' => [['t' => 1, 'p' => ['/x']]];
    }

    /** @param array<mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testFromArrayRejectsEntriesWithoutValidTimeAndPath(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        NotFoundEntry::fromArray($data);
    }
}
