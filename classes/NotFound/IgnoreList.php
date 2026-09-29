<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Paths that are never written to the 404 log.
 *
 * Patterns are globs, matched case-insensitively against the whole path:
 * "*" matches any run of characters (including "/"), "?" matches exactly one character.
 * A pattern containing "?" is also tried against "path?query" (when a query exists), so
 * "/search?q=*" ignores every search URL while "/search" alone would not. Because "?" is
 * also a wildcard, "/a?b" matches "/axb" as well; that is deliberate and harmless.
 */
final class IgnoreList
{
    /** @var list<string> */
    private array $patterns;

    private string $pathRegex = '';

    private string $queryRegex = '';

    /**
     * Scanner and browser noise that is never a broken link on the site itself.
     *
     * `*.php` is in the list because most Grav sites serve no PHP URLs and scanners request
     * thousands of them (`/wp-login.php`, `/shell.php`, ...). A site that does serve real
     * `.php` URLs (legacy links to migrate) must use withPatterns() or remove the entry.
     *
     * @return list<string>
     */
    public static function defaultPatterns(): array
    {
        return [
            // WordPress probes
            '/wp-admin*',
            '/wp-login.php',
            '/wp-content/*',
            '/wp-includes/*',
            '/wp-json/*',
            '/xmlrpc.php',
            // Server-side scripts and stacks this site does not run
            '*.php',
            '*.asp',
            '*.aspx',
            '*.jsp',
            '*.cgi',
            '/cgi-bin/*',
            '/actuator/*',
            '/_ignition/*',
            '/_profiler/*',
            '/autodiscover/*',
            '/owa/*',
            '/ecp/*',
            '/boaform/*',
            '/HNAP1*',
            // Admin tools
            '/phpmyadmin*',
            '/pma',
            '/pma/*',
            '/vendor/phpunit/*',
            '/server-status',
            // Secrets and repository files
            '/.env*',
            '*/.env',
            '/.git*',
            '*/.git/*',
            '/.svn/*',
            '/.hg/*',
            '/.aws/*',
            '/.ssh/*',
            '/.idea/*',
            '/.vscode/*',
            '/.ht*',
            '/composer.json',
            '/composer.lock',
            '/package.json',
            '/package-lock.json',
            '/docker-compose.y*ml',
            '/sftp-config.json',
            // Browser and OS convenience requests
            '/.DS_Store',
            '/favicon.ico',
            '/apple-touch-icon*',
            '/browserconfig.xml',
            '/.well-known/traffic-advice',
            '/.well-known/apple-app-site-association',
            '/apple-app-site-association',
            '/.well-known/assetlinks.json',
        ];
    }

    /** @param list<string>|null $patterns null = defaultPatterns() */
    public function __construct(?array $patterns = null)
    {
        $this->patterns = [];
        foreach ($patterns ?? self::defaultPatterns() as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $this->patterns[] = $pattern;
            }
        }
        $this->patterns = array_values(array_unique($this->patterns));
        $this->compile();
    }

    /** @param list<string> $patterns replaces the current patterns (use defaultPatterns() to extend the defaults) */
    public function withPatterns(array $patterns): self
    {
        return new self($patterns);
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function matches(string $path, string $query = ''): bool
    {
        if ($this->pathRegex === '') {
            return false;
        }
        $path = mb_scrub($path, 'UTF-8');
        if (preg_match($this->pathRegex, $path) === 1) {
            return true;
        }
        if ($query === '' || $this->queryRegex === '') {
            return false;
        }

        return preg_match($this->queryRegex, $path . '?' . mb_scrub($query, 'UTF-8')) === 1;
    }

    private function compile(): void
    {
        $all = [];
        $withQuery = [];
        foreach ($this->patterns as $pattern) {
            $regex = $this->toRegex($pattern);
            $all[] = $regex;
            if (str_contains($pattern, '?')) {
                $withQuery[] = $regex;
            }
        }
        if ($all !== []) {
            $this->pathRegex = '~^(?:' . implode('|', $all) . ')$~isu';
        }
        if ($withQuery !== []) {
            $this->queryRegex = '~^(?:' . implode('|', $withQuery) . ')$~isu';
        }
    }

    private function toRegex(string $pattern): string
    {
        $pattern = (string) preg_replace('/\*+/', '*', $pattern);

        return str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '~'));
    }
}
