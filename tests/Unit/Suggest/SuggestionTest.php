<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Suggestion::class)]
#[CoversClass(SuggestionReason::class)]
final class SuggestionTest extends TestCase
{
    public function testScoreIsClampedAndRoundedToThreeDecimals(): void
    {
        self::assertSame(0.667, (new Suggestion('/a', 2 / 3, SuggestionReason::SameSlug))->score);
        self::assertSame(1.0, (new Suggestion('/a', 1.7, SuggestionReason::SameSlug))->score);
        self::assertSame(0.0, (new Suggestion('/a', -0.4, SuggestionReason::SameSlug))->score);
        self::assertSame(0.95, (new Suggestion('/a', 0.9500001, SuggestionReason::SameSlug))->score);
    }

    public function testArrayRoundTrip(): void
    {
        $suggestion = new Suggestion('/a', 0.8, SuggestionReason::TitleMatch, 'Title', ['tokens' => ['x']]);

        self::assertEquals($suggestion, Suggestion::fromArray($suggestion->toArray()));
        self::assertSame('title_match', $suggestion->toArray()['reason']);
    }

    public function testFromArrayIsTolerant(): void
    {
        $suggestion = Suggestion::fromArray(['score' => 1, 'reason' => 'bogus', 'details' => 'x']);

        self::assertSame('/', $suggestion->target);
        self::assertSame(1.0, $suggestion->score);
        self::assertSame(SuggestionReason::SimilarRoute, $suggestion->reason);
        self::assertSame([], $suggestion->details);
        self::assertSame(0.0, Suggestion::fromArray(['score' => 'high'])->score);
    }

    public function testReasonOrderAndFallbacks(): void
    {
        self::assertTrue(SuggestionReason::ParentFallback->isFallback());
        self::assertTrue(SuggestionReason::HomeFallback->isFallback());
        self::assertFalse(SuggestionReason::SameSlug->isFallback());
        self::assertSame(0, SuggestionReason::SameSlug->rank());
        self::assertLessThan(SuggestionReason::TitleMatch->rank(), SuggestionReason::OtherLanguage->rank());
    }
}
