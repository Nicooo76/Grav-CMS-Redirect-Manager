<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/** Why a target was suggested. Declaration order is the tie-break order (strongest evidence first). */
enum SuggestionReason: string
{
    case SameSlug = 'same_slug';
    case OtherLanguage = 'other_language';
    case SimilarRoute = 'similar_route';
    case TitleMatch = 'title_match';
    case TaxonomyMatch = 'taxonomy_match';
    case ParentFallback = 'parent_fallback';
    case HomeFallback = 'home_fallback';

    /** Fallbacks are exempt from the minimum score threshold. */
    public function isFallback(): bool
    {
        return $this === self::ParentFallback || $this === self::HomeFallback;
    }

    /** Lower number wins a score tie. */
    public function rank(): int
    {
        return match ($this) {
            self::SameSlug => 0,
            self::OtherLanguage => 1,
            self::SimilarRoute => 2,
            self::TitleMatch => 3,
            self::TaxonomyMatch => 4,
            self::ParentFallback => 5,
            self::HomeFallback => 6,
        };
    }
}
