<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the compiled rule set in a plain PHP file so OPcache serves it.
 *
 * Warm request: one stat() of rules.yaml plus one include. The compiled file stores mtime, size, inode
 * and ctime of rules.yaml; any difference triggers a rebuild under a lock (parse YAML, compile, write the
 * PHP file atomically, opcache_invalidate). A rules.yaml modified within the last two seconds is
 * additionally verified by content hash, because mtime has one-second resolution and an in-place edit
 * of the same size would otherwise go unnoticed.
 *
 * A missing rules.yaml is cached as an empty set (no rebuild, no writes per request). A rules.yaml that
 * cannot be parsed keeps the previous compiled rules in force (or an empty set when there are none),
 * logs the problem once per rebuild and never throws.
 *
 * @phpstan-type Signature array{mtime: int, size: int, ino: int, ctime: int}|null
 */
final class CompiledRuleCache
{
    /** Seconds after a modification during which the content hash decides. */
    private const RACY_WINDOW = 2;

    private ?CompiledRuleSet $loaded = null;

    private ?string $error = null;

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $rulesFile,
        private readonly RuleCompiler $compiler,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ParsedRulesCache $parsed = null,
    ) {
    }

    public function cacheFile(): string
    {
        return $this->cacheDir . '/rules-' . md5($this->rulesFile) . '.php';
    }

    /** Message of the last parse error seen by this instance, if any. */
    public function lastError(): ?string
    {
        return $this->error;
    }

    public function load(): CompiledRuleSet
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $signature = $this->signature();
        $cached = $this->readCache();
        if ($cached !== null && $this->isFresh($cached['meta'], $signature)) {
            $set = $this->hydrate($cached['set']);
            if ($set !== null) {
                $this->error = is_string($cached['meta']['error'] ?? null) ? $cached['meta']['error'] : null;

                return $this->loaded = $set;
            }
        }

        return $this->loaded = $this->rebuild();
    }

    public function invalidate(): void
    {
        $this->loaded = null;
        $file = $this->cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
        self::opcacheInvalidate($file);
    }

    private function rebuild(): CompiledRuleSet
    {
        try {
            return AtomicFile::withLock(
                $this->cacheDir . '/rules-' . md5($this->rulesFile) . '.lock',
                function (): CompiledRuleSet {
                    // Another process may have rebuilt while we waited for the lock.
                    $signature = $this->signature();
                    $cached = $this->readCache();
                    if ($cached !== null && $this->isFresh($cached['meta'], $signature)) {
                        $set = $this->hydrate($cached['set']);
                        if ($set !== null) {
                            return $set;
                        }
                    }

                    return $this->compileAndStore($cached);
                },
                true,
                3.0,
            );
        } catch (LockTimeoutException|AtomicFileException $e) {
            $this->logger?->warning('Redirect Manager: cannot lock the rule cache, compiling in memory. ' . $e->getMessage());

            return $this->compileOnly();
        }
    }

    /**
     * @param array{meta: array<string, mixed>, set: array<string, mixed>}|null $previous
     */
    private function compileAndStore(?array $previous): CompiledRuleSet
    {
        $signature = $this->signature();
        $content = null;
        $error = null;
        $set = null;
        try {
            $content = AtomicFile::read($this->rulesFile);
            $set = $this->compiler->compile($this->parse($content));
        } catch (CorruptRulesFileException $e) {
            $error = $e->getMessage();
            $this->logger?->error('Redirect Manager: ' . $error . ' The previously compiled rules stay in force.');
        } catch (AtomicFileException $e) {
            $error = $e->getMessage();
            $this->logger?->error('Redirect Manager: cannot read the rules file. ' . $e->getMessage());
        }
        $this->error = $error;

        if ($set === null) {
            $set = $previous !== null ? $this->hydrate($previous['set']) : null;
            $set ??= $this->compiler->compile([]);
        }

        $meta = ['sig' => $signature, 'hash' => RuleRepository::hashContent($content ?? ''), 'built' => time()];
        if ($error !== null) {
            $meta['error'] = $error;
        }

        try {
            $php = '<?php return ' . var_export(['meta' => $meta, 'set' => $set->toArray()], true) . ";\n";
            AtomicFile::write($this->cacheFile(), $php);
            self::opcacheInvalidate($this->cacheFile());
        } catch (AtomicFileException $e) {
            $this->logger?->warning('Redirect Manager: cannot write the compiled rule cache. ' . $e->getMessage());
        }

        return $set;
    }

    /**
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    private function parse(?string $content): array
    {
        return $this->parsed === null
            ? RuleRepository::parse($content, $this->rulesFile)
            : RuleRepository::parseCached($content, $this->rulesFile, $this->parsed);
    }

    private function compileOnly(): CompiledRuleSet
    {
        try {
            return $this->compiler->compile($this->parse(AtomicFile::read($this->rulesFile)));
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->logger?->error('Redirect Manager: ' . $e->getMessage());

            return $this->compiler->compile([]);
        }
    }

    /**
     * @return array{meta: array<string, mixed>, set: array<string, mixed>}|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = @include $file;
        } catch (Throwable) {
            // ParseError of a truncated or hand-edited file: rebuild.
            return null;
        }
        if (!is_array($data) || !isset($data['meta'], $data['set']) || !is_array($data['meta']) || !is_array($data['set'])) {
            return null;
        }
        /** @var array{meta: array<string, mixed>, set: array<string, mixed>} $data */

        return $data;
    }

    /**
     * @param array<string, mixed> $meta
     * @param Signature            $signature
     */
    private function isFresh(array $meta, ?array $signature): bool
    {
        if (!array_key_exists('sig', $meta) || $meta['sig'] !== $signature) {
            return false;
        }
        if ($signature === null) {
            return true;
        }
        if (time() - $signature['mtime'] > self::RACY_WINDOW && time() - $signature['ctime'] > self::RACY_WINDOW) {
            return true;
        }

        // Modified a moment ago: mtime alone cannot tell two edits apart.
        $content = @file_get_contents($this->rulesFile);

        return $content !== false && ($meta['hash'] ?? null) === RuleRepository::hashContent($content);
    }

    /**
     * @return Signature
     */
    private function signature(): ?array
    {
        clearstatcache(true, $this->rulesFile);
        $stat = @stat($this->rulesFile);
        if ($stat === false) {
            return null;
        }

        return ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'ino' => $stat['ino'], 'ctime' => $stat['ctime']];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): ?CompiledRuleSet
    {
        try {
            return CompiledRuleSet::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function opcacheInvalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
