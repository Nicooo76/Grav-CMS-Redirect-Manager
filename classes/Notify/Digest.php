<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

/** A finished report mail: subject, plain text (wrapped at about 76 characters) and HTML. */
final readonly class Digest
{
    public function __construct(
        public string $subject,
        public string $text,
        public string $html,
    ) {
    }
}
