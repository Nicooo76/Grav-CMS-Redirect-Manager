<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Storage;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\Clock;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes user://data/redirect-manager/rules.yaml.
 *
 * Every write is a read-modify-write under an exclusive lock (rules.lock) and ends in an atomic
 * rename, so two processes never lose each other's changes and readers never see half a file.
 * revision() is a hash of the file content: hand it back to saveAll() for optimistic locking.
 *
 * A missing or empty file is an empty rule list. A file that is not a valid rules document throws
 * CorruptRulesFileException on read and is copied to "rules.yaml.corrupt-<time>" before saveAll()
 * replaces it, so a bad edit is never lost.
 */
final class RuleRepository
{
    public const FILE = 'rules.yaml';
    public const LOCK = 'rules.lock';
    public const FORMAT_VERSION = 1;

    /** Fields that are written even when they hold the default value. */
    private const ALWAYS = ['id', 'source', 'target', 'match_type', 'status', 'enabled', 'priority'];

    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    public function __construct(
        private readonly string $dataDir,
        private readonly Clock $clock,
        private readonly ?ParsedRulesCache $parsed = null,
    ) {
    }

    public function file(): string
    {
        return $this->dataDir . '/' . self::FILE;
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    public function all(): array
    {
        return $this->snapshot()['rules'];
    }

    /**
     * The rules and the hash of the file they were read from, in one consistent read. Rows come from the
     * parsed-rules cache when it holds this exact content, so YAML is parsed only after a change made outside the plugin.
     *
     * @return array{rules: list<Rule>, revision: string}
     *
     * @throws CorruptRulesFileException
     */
    public function snapshot(): array
    {
        $content = AtomicFile::read($this->file());

        return ['rules' => $this->parseContent($content), 'revision' => self::hashContent($content ?? '')];
    }

    /**
     * Like parse(), through the parsed-rules cache.
     *
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    public function parseContent(?string $content): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }
        if ($this->parsed === null) {
            return self::parse($content, $this->file());
        }

        return self::parseCached($content, $this->file(), $this->parsed);
    }

    /**
     * parse() that reads and fills $cache.
     *
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    public static function parseCached(?string $content, string $file, ParsedRulesCache $cache): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }

        return self::hydrate(self::cachedRows($content, $file, $cache), $file);
    }

    /**
     * The rules as normalized rows (the stored form: default values omitted, values in the types the plugin writes), and
     * the hash of the file they were read from. For callers that only count or look up fields and do not need Rule
     * objects: hydrating 10,000 rules costs about 50 ms, reading their rows about 25 ms.
     *
     * @return array{rows: list<array<string, mixed>>, revision: string}
     *
     * @throws CorruptRulesFileException
     */
    public function snapshotRows(): array
    {
        $content = AtomicFile::read($this->file());
        if ($content === null || trim($content) === '') {
            return ['rows' => [], 'revision' => self::hashContent($content ?? '')];
        }
        if ($this->parsed === null) {
            return ['rows' => self::slimRows(self::parse($content, $this->file())), 'revision' => self::hashContent($content)];
        }

        return ['rows' => self::cachedRows($content, $this->file(), $this->parsed), 'revision' => self::hashContent($content)];
    }

    /**
     * Rows of the cache for this content; on a miss the YAML is parsed, the rules are normalized (so a hand-edited
     * `enabled: yes` is stored as `true`) and the rows are stored.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CorruptRulesFileException
     */
    private static function cachedRows(string $content, string $file, ParsedRulesCache $cache): array
    {
        $hash = self::hashContent($content);
        $rows = $cache->rows($hash);
        if ($rows === null) {
            $rows = self::slimRows(self::hydrate(self::rows($content, $file), $file));
            $cache->store($hash, $rows);
        }

        return $rows;
    }

    public function find(string $id): ?Rule
    {
        foreach ($this->all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /** Hash of the current file content; identical for a missing and an empty file. */
    public function revision(): string
    {
        return self::hashContent(AtomicFile::read($this->file()) ?? '');
    }

    public static function hashContent(string $content): string
    {
        return hash('sha1', $content);
    }

    /**
     * Replaces all rules.
     *
     * @param list<Rule> $rules
     *
     * @throws ConcurrentModificationException when $expectedRevision is given and differs from revision()
     * @throws DuplicateRuleIdException
     */
    public function saveAll(array $rules, ?string $expectedRevision = null): void
    {
        self::assertUniqueIds($rules);
        $this->locked(function () use ($rules, $expectedRevision): void {
            $content = AtomicFile::read($this->file());
            if ($expectedRevision !== null) {
                $actual = self::hashContent($content ?? '');
                if (!hash_equals($expectedRevision, $actual)) {
                    throw new ConcurrentModificationException($expectedRevision, $actual);
                }
            }
            $old = [];
            try {
                $old = $this->parseContent($content);
            } catch (CorruptRulesFileException) {
                $this->backupCorrupt();
            }
            $this->write($this->stamp($rules, $old));
        });
    }

    public function upsert(Rule $rule): Rule
    {
        return $this->upsertMany([$rule])[0];
    }

    /**
     * Inserts new rules and replaces rules with an existing id in place. Returns the stored rules.
     *
     * @param list<Rule> $rules
     *
     * @return list<Rule>
     */
    public function upsertMany(array $rules): array
    {
        self::assertUniqueIds($rules);
        $ids = array_map(static fn (Rule $r): string => $r->id, $rules);
        $stored = $this->transaction(static function (array $current) use ($rules): array {
            $index = [];
            foreach ($current as $i => $rule) {
                $index[$rule->id] = $i;
            }
            foreach ($rules as $rule) {
                if (isset($index[$rule->id])) {
                    $current[$index[$rule->id]] = $rule;
                } else {
                    $index[$rule->id] = count($current);
                    $current[] = $rule;
                }
            }

            return array_values($current);
        });
        $byId = [];
        foreach ($stored as $rule) {
            $byId[$rule->id] = $rule;
        }

        return array_values(array_map(static fn (string $id): Rule => $byId[$id], $ids));
    }

    /**
     * Deletes rules by id and returns the deleted ones (unknown ids are ignored).
     *
     * @param list<string> $ids
     *
     * @return list<Rule>
     */
    public function delete(array $ids): array
    {
        $wanted = array_flip($ids);
        $deleted = [];
        $this->transaction(static function (array $current) use ($wanted, &$deleted): array {
            $deleted = [];
            $keep = [];
            foreach ($current as $rule) {
                if (isset($wanted[$rule->id])) {
                    $deleted[] = $rule;
                } else {
                    $keep[] = $rule;
                }
            }

            return $keep;
        });

        return $deleted;
    }

    /**
     * Puts previously deleted rules back exactly as they were (timestamps included).
     * A rule whose id exists again replaces the current one.
     *
     * @param list<Rule> $rules
     */
    public function restore(array $rules): void
    {
        self::assertUniqueIds($rules);
        $this->run(static function (array $current) use ($rules): array {
            $index = [];
            foreach ($current as $i => $rule) {
                $index[$rule->id] = $i;
            }
            foreach ($rules as $rule) {
                if (isset($index[$rule->id])) {
                    $current[$index[$rule->id]] = $rule;
                } else {
                    $index[$rule->id] = count($current);
                    $current[] = $rule;
                }
            }

            return array_values($current);
        }, true);
    }

    /**
     * Read-modify-write under an exclusive lock. The callback gets the current rules and returns the
     * new list. Returns the list as written (with timestamps).
     *
     * @param callable(list<Rule>): list<Rule> $fn
     *
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException when the current file cannot be parsed
     * @throws DuplicateRuleIdException
     * @throws LockTimeoutException
     */
    public function transaction(callable $fn): array
    {
        return $this->run($fn, false);
    }

    /**
     * @param callable(list<Rule>): list<Rule> $fn
     *
     * @return list<Rule>
     */
    private function run(callable $fn, bool $restore): array
    {
        /** @var list<Rule> $result */
        $result = $this->locked(function () use ($fn, $restore): array {
            $old = $this->parseContent(AtomicFile::read($this->file()));
            $new = array_values($fn($old));
            self::assertUniqueIds($new);
            $new = $restore ? $this->fillMissing($new) : $this->stamp($new, $old);
            if (!is_file($this->file()) || self::slimRows($new) !== self::slimRows($old)) {
                $this->write($new);
            }

            return $new;
        });

        return $result;
    }

    /**
     * Parses the content of a rules file. Null and empty content mean "no rules".
     *
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    public static function parse(?string $content, string $file = self::FILE): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }

        return self::hydrate(self::rows($content, $file), $file);
    }

    /**
     * The YAML rows of a rules document, checked for shape.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CorruptRulesFileException
     */
    private static function rows(string $content, string $file): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new CorruptRulesFileException($file, $e->getMessage(), $e);
        }
        if ($data === null) {
            return [];
        }
        if (!is_array($data) || !array_key_exists('rules', $data) || !(is_array($data['rules']) || $data['rules'] === null)) {
            throw new CorruptRulesFileException($file, 'expected a map with a "rules" list.');
        }
        $rows = [];
        foreach ($data['rules'] ?? [] as $i => $row) {
            if (!is_array($row)) {
                throw new CorruptRulesFileException($file, sprintf('rule #%s is not a map.', (string) $i));
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Rule>
     *
     * @throws CorruptRulesFileException
     */
    private static function hydrate(array $rows, string $file): array
    {
        $rules = [];
        $seen = [];
        foreach ($rows as $row) {
            $rule = Rule::fromArray($row);
            if (isset($seen[$rule->id])) {
                throw new CorruptRulesFileException($file, sprintf('duplicate rule id "%s".', $rule->id));
            }
            $seen[$rule->id] = true;
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Serializes rules the way they are stored: readable blocks, stable key order, default values omitted.
     *
     * @param list<Rule> $rules
     */
    public function dump(array $rules): string
    {
        return self::dumpRows(self::slimRows($rules));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function dumpRows(array $rows): string
    {
        $header = "# Redirect Manager rules. Edit here or in the admin; changes are picked up on the next request.\n";

        return $header . Yaml::dump(
            ['version' => self::FORMAT_VERSION, 'rules' => $rows],
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<array<string, mixed>>
     */
    private static function slimRows(array $rules): array
    {
        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = self::slim($rule);
        }

        return $rows;
    }

    /**
     * @param list<Rule> $rules
     */
    private function write(array $rules): void
    {
        $rows = self::slimRows($rules);
        $content = self::dumpRows($rows);
        AtomicFile::write($this->file(), $content);
        clearstatcache(true, $this->file());
        // The next read finds these rows instead of parsing what was just written.
        $this->parsed?->store(self::hashContent($content), $rows);
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        return AtomicFile::withLock($this->dataDir . '/' . self::LOCK, $fn);
    }

    private function backupCorrupt(): void
    {
        $file = $this->file();
        if (!is_file($file)) {
            return;
        }
        $backup = $file . '.corrupt-' . $this->clock->now()->format('Ymd\THis');
        for ($i = 1; file_exists($backup); $i++) {
            $backup = $file . '.corrupt-' . $this->clock->now()->format('Ymd\THis') . '-' . $i;
        }
        @copy($file, $backup);
    }

    /**
     * @param list<Rule> $new
     * @param list<Rule> $old
     *
     * @return list<Rule>
     */
    private function stamp(array $new, array $old): array
    {
        $now = $this->clock->now();
        $before = [];
        foreach ($old as $rule) {
            $before[$rule->id] = $rule;
        }

        $out = [];
        foreach ($new as $rule) {
            $prev = $before[$rule->id] ?? null;
            if ($prev === null) {
                $out[] = $rule->createdAt !== null && $rule->updatedAt !== null
                    ? $rule
                    : $rule->with([
                        'created_at' => ($rule->createdAt ?? $now)->format(Rule::DATE_FORMAT),
                        'updated_at' => ($rule->updatedAt ?? $now)->format(Rule::DATE_FORMAT),
                    ]);
                continue;
            }
            $created = $prev->createdAt ?? $rule->createdAt ?? $now;
            $updated = self::sameContent($prev, $rule) ? ($prev->updatedAt ?? $now) : $now;
            $out[] = $rule->with([
                'created_at' => $created->format(Rule::DATE_FORMAT),
                'updated_at' => $updated->format(Rule::DATE_FORMAT),
            ]);
        }

        return $out;
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<Rule>
     */
    private function fillMissing(array $rules): array
    {
        $now = $this->clock->now()->format(Rule::DATE_FORMAT);
        $out = [];
        foreach ($rules as $rule) {
            $out[] = $rule->createdAt !== null && $rule->updatedAt !== null
                ? $rule
                : $rule->with([
                    'created_at' => $rule->createdAt?->format(Rule::DATE_FORMAT) ?? $now,
                    'updated_at' => $rule->updatedAt?->format(Rule::DATE_FORMAT) ?? $now,
                ]);
        }

        return $out;
    }

    private static function sameContent(Rule $a, Rule $b): bool
    {
        $x = $a->toArray();
        $y = $b->toArray();
        unset($x['created_at'], $x['updated_at'], $y['created_at'], $y['updated_at']);

        return $x === $y;
    }

    /**
     * @param list<Rule> $rules
     */
    private static function assertUniqueIds(array $rules): void
    {
        $seen = [];
        foreach ($rules as $rule) {
            if ($rule->id === '') {
                throw new DuplicateRuleIdException('A rule needs an id.');
            }
            if (isset($seen[$rule->id])) {
                throw new DuplicateRuleIdException(sprintf('Duplicate rule id "%s".', $rule->id));
            }
            $seen[$rule->id] = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function slim(Rule $rule): array
    {
        $defaults = self::$defaults ??= (new Rule('', ''))->toArray();
        $row = [];
        foreach ($rule->toArray() as $key => $value) {
            if (in_array($key, self::ALWAYS, true) || $value !== ($defaults[$key] ?? null)) {
                $row[$key] = $value;
            }
        }
        if (isset($row['conditions']) && is_array($row['conditions'])) {
            $conditions = array_filter($row['conditions'], static fn (mixed $v): bool => $v !== []);
            if ($conditions === []) {
                unset($row['conditions']);
            } else {
                $row['conditions'] = $conditions;
            }
        }

        return $row;
    }
}
