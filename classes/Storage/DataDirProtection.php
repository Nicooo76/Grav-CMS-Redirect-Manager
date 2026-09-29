<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

/**
 * Defence in depth for user/data/redirect-manager/: an .htaccess that denies all web access (Apache 2.4 and 2.2)
 * and an empty index.html against directory listings. Grav's own server configs already block user/data/, but
 * rules, 404 logs and IP addresses should not depend on that alone.
 *
 * The files are created exclusively (mode "x"): an .htaccess or index.html that exists is never touched, so
 * a change by the site owner survives. Missing files are created whenever the directory is ensured, which also
 * covers data directories made by an earlier version. Nothing is written for a directory that cannot be created.
 */
final class DataDirProtection
{
    public const HTACCESS = <<<'HTACCESS'
        # Redirect Manager: rules, logs and statistics are not for the web.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        HTACCESS;

    /**
     * Creates the directory when needed and makes sure it carries .htaccess and index.html.
     *
     * @return bool false when the directory or one of the files could not be created (never throws)
     */
    public static function ensure(string $dir): bool
    {
        if (is_file($dir . '/.htaccess') && is_file($dir . '/index.html')) {
            return true;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $ok = self::createIfMissing($dir . '/.htaccess', self::HTACCESS);

        return self::createIfMissing($dir . '/index.html', '') && $ok;
    }

    private static function createIfMissing(string $path, string $content): bool
    {
        if (file_exists($path)) {
            return true;
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            // Lost a race against another request that just created it.
            return file_exists($path);
        }
        $written = $content === '' ? true : @fwrite($handle, $content) === strlen($content);
        fclose($handle);
        @chmod($path, 0664);

        return $written;
    }
}
