<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

use RuntimeException;

/** A sitemap could not be read: too large, unsafe (DOCTYPE), malformed or not a sitemap. */
final class SitemapException extends RuntimeException
{
}
