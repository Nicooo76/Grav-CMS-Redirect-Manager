<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Plugin\RedirectManager\Domain\StatusCode;

/** The auto_redirect.* settings the planner needs. */
final readonly class AutoRedirectConfig
{
    public function __construct(
        public StatusCode $status = StatusCode::MovedPermanently,
        public ChildrenMode $children = ChildrenMode::Wildcard,
        public DeletePolicy $onDelete = DeletePolicy::Ask,
    ) {
    }

    /**
     * @param array<string, mixed> $section the plugin config's "auto_redirect" map
     */
    public static function fromArray(array $section): self
    {
        $status = is_numeric($section['status'] ?? null) ? StatusCode::tryFrom((int) $section['status']) : null;
        if ($status === null || !$status->isRedirect()) {
            $status = StatusCode::MovedPermanently;
        }
        $children = is_string($section['children'] ?? null) ? ChildrenMode::tryFrom(strtolower(trim($section['children']))) : null;

        return new self(
            $status,
            $children ?? ChildrenMode::Wildcard,
            DeletePolicy::fromConfig($section['on_delete'] ?? null),
        );
    }
}
