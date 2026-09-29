<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

/** One proposed redirect target for a 404 path. */
final readonly class Suggestion
{
    /** Score in 0..1, rounded to three decimals. */
    public float $score;

    /**
     * @param string               $target    route of the suggested page (no language prefix)
     * @param array<string, mixed> $details   strategy specific evidence (matched slug, similarity, language, ...)
     */
    public function __construct(
        public string $target,
        float $score,
        public SuggestionReason $reason,
        public string $pageTitle = '',
        public array $details = [],
    ) {
        $this->score = round(max(0.0, min(1.0, $score)), 3);
    }

    /** @return array{target: string, score: float, reason: string, page_title: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'score' => $this->score,
            'reason' => $this->reason->value,
            'page_title' => $this->pageTitle,
            'details' => $this->details,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $reason = SuggestionReason::tryFrom(is_string($data['reason'] ?? null) ? $data['reason'] : '')
            ?? SuggestionReason::SimilarRoute;
        $score = $data['score'] ?? 0.0;

        return new self(
            target: is_string($data['target'] ?? null) ? $data['target'] : '/',
            score: is_int($score) || is_float($score) ? (float) $score : 0.0,
            reason: $reason,
            pageTitle: is_string($data['page_title'] ?? null) ? $data['page_title'] : '',
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }
}
