<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Domain;

use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusCode::class)]
#[CoversClass(MatchType::class)]
#[Group('domain')]
final class StatusCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{StatusCode, bool, bool, bool, bool}>
     */
    public static function statusProvider(): iterable
    {
        //                                   redirect permanent needsTarget error
        yield '301' => [StatusCode::MovedPermanently, true, true, true, false];
        yield '302' => [StatusCode::Found, true, false, true, false];
        yield '307' => [StatusCode::TemporaryRedirect, true, false, true, false];
        yield '308' => [StatusCode::PermanentRedirect, true, true, true, false];
        yield '410' => [StatusCode::Gone, false, false, false, true];
        yield '451' => [StatusCode::UnavailableForLegalReasons, false, false, false, true];
        yield '200 pass through' => [StatusCode::PassThrough, false, false, true, false];
    }

    #[DataProvider('statusProvider')]
    public function testClassification(StatusCode $status, bool $redirect, bool $permanent, bool $needsTarget, bool $error): void
    {
        self::assertSame($redirect, $status->isRedirect());
        self::assertSame($permanent, $status->isPermanent());
        self::assertSame($needsTarget, $status->needsTarget());
        self::assertSame($error, $status->isError());
    }

    public function testOnlyTheDocumentedCodesExist(): void
    {
        self::assertSame(
            [301, 302, 307, 308, 410, 451, 200],
            array_map(static fn (StatusCode $s): int => $s->value, StatusCode::cases()),
        );
        self::assertNull(StatusCode::tryFrom(303));
        self::assertNull(StatusCode::tryFrom(404));
    }

    public function testMatchTypeOrderIsExactWildcardRegex(): void
    {
        self::assertSame(0, MatchType::Exact->order());
        self::assertSame(1, MatchType::Wildcard->order());
        self::assertSame(2, MatchType::Regex->order());

        $types = [MatchType::Regex, MatchType::Exact, MatchType::Wildcard];
        usort($types, static fn (MatchType $a, MatchType $b): int => $a->order() <=> $b->order());
        self::assertSame([MatchType::Exact, MatchType::Wildcard, MatchType::Regex], $types);
    }

    public function testMatchTypeValues(): void
    {
        self::assertSame(MatchType::Wildcard, MatchType::from('wildcard'));
        self::assertNull(MatchType::tryFrom('EXACT'), 'values are case-sensitive');
    }
}
