<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support;

/** Per-test temp directory under sys_get_temp_dir(), removed in tearDown(). */
trait TempDirTrait
{
    protected string $tmp = '';

    protected function makeTempDir(): string
    {
        $this->tmp = sys_get_temp_dir() . '/rm-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);

        return $this->tmp;
    }

    protected function removeTempDir(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            self::rmTree($this->tmp);
        }
        $this->tmp = '';
    }

    private static function rmTree(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
