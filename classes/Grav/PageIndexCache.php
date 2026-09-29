<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Storage\AtomicFileException;
use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Throwable;

/**
 * Stores a PageIndex as a PHP array file (cache://redirect-manager/page-index-<hash>.php) keyed by a
 * fingerprint of the page tree. A new fingerprint builds a new file and removes the older ones.
 */
final class PageIndexCache
{
    public function __construct(private readonly string $cacheDir)
    {
    }

    /**
     * @param callable(): PageIndex $build
     */
    public function remember(string $fingerprint, callable $build): PageIndex
    {
        $file = $this->cacheDir . '/page-index-' . md5($fingerprint) . '.php';
        if (is_file($file)) {
            try {
                $data = @include $file;
                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    return PageIndex::fromArray($data);
                }
            } catch (Throwable) {
                // corrupt or outdated cache file: rebuild below
            }
        }

        $index = $build();
        try {
            AtomicFile::write($file, '<?php return ' . var_export($index->toArray(), true) . ";\n");
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            foreach (glob($this->cacheDir . '/page-index-*.php') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        } catch (AtomicFileException) {
            // the index is still usable, it just is not cached
        }

        return $index;
    }
}
