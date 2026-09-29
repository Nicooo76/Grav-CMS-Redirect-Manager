<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use Throwable;

/**
 * Keeps the parsed rows of rules.yaml in a PHP file (cache://redirect-manager/parsed-rules-*.php), keyed by the
 * hash of the YAML content. Parsing 10,000 rules with Symfony YAML takes about 0.8 s, including the file
 * costs about 25 ms.
 *
 * The key is the content hash, so a cache file can never be stale: whatever the file on disk says is hashed
 * first, and a row set stored under another hash is ignored. RuleRepository writes the rows of every save
 * through, so the request after an edit finds the cache warm.
 *
 * Every failure (missing directory, unwritable file, truncated file) means "no cache": the caller parses the YAML.
 */
final class ParsedRulesCache
{
    /** Bump when the stored form of a rule changes, so rows written by an older plugin version are parsed again. */
    private const VERSION = 1;

    public function __construct(private readonly string $cacheDir, private readonly string $rulesFile)
    {
    }

    public function file(): string
    {
        return $this->cacheDir . '/parsed-rules-' . md5($this->rulesFile) . '.php';
    }

    /** Deletes the cache file (`rebuild-cache`). */
    public function forget(): void
    {
        $file = $this->file();
        if (is_file($file)) {
            @unlink($file);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * @return list<array<string, mixed>>|null the rows stored for $hash, null when there are none
     */
    public function rows(string $hash): ?array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = @include $file;
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION || ($data['hash'] ?? null) !== $hash || !is_array($data['rows'] ?? null)) {
            return null;
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['rows'];

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function store(string $hash, array $rows): void
    {
        $file = $this->file();
        try {
            AtomicFile::write($file, '<?php return ' . var_export(['version' => self::VERSION, 'hash' => $hash, 'rows' => $rows], true) . ";\n");
        } catch (Throwable) {
            return;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
