<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Check;

use DateTimeImmutable;

/**
 * Keeps the last live-check result per rule in target-checks.json.
 *
 * Reads and writes run under an exclusive lock on "<file>.lock"; the document is replaced
 * atomically (temp file + rename), so a reader never sees a half-written file.
 */
final class CheckResultStore
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * Merges results by rule id. A "rate_limited" result means "not checked this time" and keeps
     * the previous result instead of replacing it.
     *
     * @param list<TargetCheckResult> $results
     */
    public function save(array $results): void
    {
        if ($results === []) {
            return;
        }
        $this->locked(function () use ($results): void {
            $doc = $this->readDocument();
            $lastRun = $doc['last_run'] !== null ? $this->parseTime($doc['last_run']) : null;

            foreach ($results as $result) {
                if ($result->error === TargetCheckResult::ERROR_RATE_LIMITED) {
                    continue;
                }
                $doc['results'][$result->ruleId] = $result->toArray();
                if ($lastRun === null || $result->checkedAt > $lastRun) {
                    $lastRun = $result->checkedAt;
                }
            }
            $doc['last_run'] = $lastRun?->format(DATE_ATOM);
            $this->write($doc);
        });
    }

    /**
     * @return array<string, TargetCheckResult> keyed by rule id
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->readDocument()['results'] as $row) {
            $result = TargetCheckResult::fromArray($row);
            if ($result !== null) {
                $out[$result->ruleId] = $result;
            }
        }

        return $out;
    }

    /**
     * @return list<TargetCheckResult>
     */
    public function dead(): array
    {
        return array_values(array_filter($this->all(), static fn (TargetCheckResult $r): bool => $r->isDead()));
    }

    /**
     * Drops stored results, for example when rules were deleted.
     *
     * @param list<string> $ids
     */
    public function forget(array $ids): void
    {
        if ($ids === [] || !is_file($this->file)) {
            return;
        }
        $this->locked(function () use ($ids): void {
            $doc = $this->readDocument();
            $before = count($doc['results']);
            foreach ($ids as $id) {
                unset($doc['results'][$id]);
            }
            if (count($doc['results']) !== $before) {
                $this->write($doc);
            }
        });
    }

    public function lastRun(): ?DateTimeImmutable
    {
        $value = $this->readDocument()['last_run'];

        return $value === null ? null : $this->parseTime($value);
    }

    /**
     * @return array{version: int, last_run: ?string, results: array<string, array<mixed>>}
     */
    private function readDocument(): array
    {
        $empty = ['version' => 1, 'last_run' => null, 'results' => []];
        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;
        if ($raw === false || $raw === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }
        $rows = [];
        $results = $data['results'] ?? [];
        if (is_array($results)) {
            foreach ($results as $id => $row) {
                if (is_array($row)) {
                    $rows[(string) $id] = $row;
                }
            }
        }
        $lastRun = $data['last_run'] ?? null;

        return ['version' => 1, 'last_run' => is_string($lastRun) ? $lastRun : null, 'results' => $rows];
    }

    /**
     * @param array{version: int, last_run: ?string, results: array<string, array<mixed>>} $doc
     */
    private function write(array $doc): void
    {
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $dir));
        }
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $tmp));
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot replace "%s".', $this->file));
        }
    }

    private function locked(callable $fn): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $dir));
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file for "%s".', $this->file));
        }
        try {
            flock($lock, LOCK_EX);
            $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function parseTime(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
