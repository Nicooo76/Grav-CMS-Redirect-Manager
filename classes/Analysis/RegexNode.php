<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * Node of the tiny regex tree RegexSampler builds. Internal.
 *
 * type "lit": fixed text. type "var": a character class or escape (kind text, digit or upper) that stands for
 * arbitrary text; $width is null for "one or more" and the exact character count for {n} or an unquantified atom.
 * type "group": children, capturing when $index is set.
 */
final class RegexNode
{
    /**
     * @param list<RegexNode> $children
     */
    private function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly string $kind = 'text',
        public ?int $width = 1,
        public int $repeat = 1,
        public readonly ?int $index = null,
        public readonly ?string $name = null,
        public readonly array $children = [],
    ) {
    }

    public static function literal(string $text): self
    {
        return new self('lit', $text);
    }

    public static function variable(string $kind): self
    {
        return new self('var', '', $kind);
    }

    /**
     * @param list<RegexNode> $children
     */
    public static function group(?int $index, ?string $name, array $children): self
    {
        return new self('group', '', 'text', null, 1, $index, $name, $children);
    }
}
