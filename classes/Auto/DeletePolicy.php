<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/** What to do with the URLs of a deleted page (auto_redirect.on_delete). */
enum DeletePolicy: string
{
    /** Keep a pending decision; the editor resolves it in the admin. */
    case Ask = 'ask';
    /** 410 Gone for the page and everything below it. */
    case Gone = 'gone';
    /** 301 to the nearest existing parent page. */
    case Parent = 'parent';
    case Never = 'never';

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Ask) : self::Ask;
    }
}
