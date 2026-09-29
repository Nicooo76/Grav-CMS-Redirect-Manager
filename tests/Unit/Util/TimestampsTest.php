<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Util;

use Grav\Plugin\RedirectManager\Util\Timestamps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Timestamps::class)]
#[Group('util')]
final class TimestampsTest extends TestCase
{
    public function testAnIsoTimeWithOffsetIsParsedOnceAndSharedAfterwards(): void
    {
        $a = Timestamps::parse('2026-09-29T10:00:00+02:00');
        $b = Timestamps::parse('2026-09-29T10:00:00+02:00');

        self::assertNotNull($a);
        self::assertSame($a, $b, 'immutable, so one instance serves every rule with this timestamp');
        self::assertSame('2026-09-29T08:00:00+00:00', $a->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame(1790668800, $a->getTimestamp());
    }

    public function testZuluTimeAndOtherOffsetsAreDifferentEntries(): void
    {
        $z = Timestamps::parse('2026-09-29T10:00:00Z');
        $plus = Timestamps::parse('2026-09-29T10:00:00+01:00');

        self::assertNotNull($z);
        self::assertNotNull($plus);
        self::assertNotSame($z->getTimestamp(), $plus->getTimestamp());
    }

    public function testRelativeAndZonelessTextIsReadAgainEveryTime(): void
    {
        foreach (['tomorrow', 'now', '2026-09-29 10:00:00', '2026-09-29T10:00:00'] as $text) {
            $a = Timestamps::parse($text);
            $b = Timestamps::parse($text);

            self::assertNotNull($a, $text);
            self::assertNotSame($a, $b, $text . ' depends on the moment or the default time zone and must not be remembered');
        }
    }

    public function testTextThatIsNoDateGivesNull(): void
    {
        self::assertNull(Timestamps::parse('not a date at all'));
        self::assertNull(Timestamps::parse('2026-13-45T99:99:99+00:00x'));
    }

    public function testTheMemoIsBoundedAndKeepsWorkingWhenItOverflows(): void
    {
        $first = null;
        for ($i = 0; $i < 4200; ++$i) {
            $text = gmdate('Y-m-d\TH:i:s\Z', 1_700_000_000 + $i);
            $date = Timestamps::parse($text);
            $first ??= $date;

            self::assertSame(1_700_000_000 + $i, $date?->getTimestamp());
        }

        self::assertSame(1_700_000_000, Timestamps::parse('2023-11-14T22:13:20Z')?->getTimestamp(), 'entries dropped by the reset are parsed again');
    }
}
