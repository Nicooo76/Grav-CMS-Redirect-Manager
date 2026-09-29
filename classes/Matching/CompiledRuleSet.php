<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Matching;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;
use InvalidArgumentException;

/**
 * Indexed, ready-to-match form of a rule list. Built by RuleCompiler, consumed by Matcher.
 *
 * All state lives in one plain PHP array (scalars and arrays only) so it can be written with var_export
 * into an OPcache file and loaded again with fromArray() at almost no cost. Rule objects are built
 * lazily, one index at a time, and memoized.
 *
 * Rules are stored in rank order (priority DESC, match type, created_at ASC, id ASC), so a rule's index
 * IS its rank: sorting candidate indexes sorts them by precedence.
 *
 * Layout of the array:
 *  v         format version
 *  rules     list of Rule::toArray() rows without default-valued fields, in rank order
 *  meta      list of per-rule compiled data: p (normalized path, exact), re (PCRE, wildcard and regex), q (parsed source query)
 *  disabled  indexes of disabled rules (kept, but not in any index)
 *  skipped   rule id => reason for rules that could not be compiled (bad regex, bad source)
 *  exact     four hash maps path => list of indexes: cs_t / cs_s (case sensitive, slash tolerant / strict), ci_t / ci_s
 *  trie      wildcard rules by the complete path segments before their first "*": nodes {r: indexes, c: children}
 *  rx        regex rules by literal first path segment (cs / ci) plus "any" for the rest
 *
 * @phpstan-type Node array{r?: list<int>, c?: array<int|string, mixed>}
 * @phpstan-type Data array{
 *     v: int,
 *     rules: list<array<string, mixed>>,
 *     meta: list<array<string, mixed>>,
 *     disabled: list<int>,
 *     skipped: array<string, string>,
 *     exact: array{cs_t: array<int|string, list<int>>, cs_s: array<int|string, list<int>>, ci_t: array<int|string, list<int>>, ci_s: array<int|string, list<int>>},
 *     trie: array{cs: array<string, mixed>, ci: array<string, mixed>},
 *     rx: array{cs: array<int|string, list<int>>, ci: array<int|string, list<int>>, any: list<int>}
 * }
 */
final class CompiledRuleSet
{
    public const VERSION = 1;

    /** @var array<int, Rule> */
    private array $hydrated = [];

    /**
     * @param Data $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException when the array is not a compiled set of this format version
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported compiled rule set version.');
        }
        foreach (['rules', 'meta', 'disabled', 'skipped', 'exact', 'trie', 'rx'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new InvalidArgumentException(sprintf('Compiled rule set is missing "%s".', $key));
            }
        }
        /** @var Data $data */

        return new self($data);
    }

    /**
     * @return Data
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /** Rules in the set, disabled ones included. */
    public function count(): int
    {
        return count($this->data['rules']);
    }

    public function disabledCount(): int
    {
        return count($this->data['disabled']);
    }

    /**
     * Rules that were left out because they cannot work (invalid regex, unusable source), id => reason code.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->data['skipped'];
    }

    /**
     * @return list<int>
     */
    public function disabledIndexes(): array
    {
        return $this->data['disabled'];
    }

    public function rule(int $index): Rule
    {
        return $this->hydrated[$index] ??= Rule::fromArray($this->data['rules'][$index]);
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        $out = [];
        foreach (array_keys($this->data['rules']) as $index) {
            $out[] = $this->rule($index);
        }

        return $out;
    }

    /** Normalized source path of an exact rule. */
    public function exactPath(int $index): string
    {
        $p = $this->data['meta'][$index]['p'] ?? '';

        return is_string($p) ? $p : '';
    }

    /** Compiled PCRE of a wildcard or regex rule. */
    public function pattern(int $index): string
    {
        $re = $this->data['meta'][$index]['re'] ?? '';

        return is_string($re) ? $re : '';
    }

    /**
     * Parsed "?query" of the rule source, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function sourceQuery(int $index): ?array
    {
        $q = $this->data['meta'][$index]['q'] ?? null;
        if (!is_array($q)) {
            return null;
        }
        /** @var array<string, mixed> $q */

        return $q;
    }

    /**
     * Indexes of the enabled rules whose path part may match, in rank order. This is a superset filter:
     * the matcher still verifies every candidate.
     *
     * @return list<int>
     */
    public function candidates(string $path): array
    {
        $out = [];
        $stripped = PathNormalizer::stripTrailingSlash($path);
        $lower = PathNormalizer::lower($path);
        $lowerStripped = PathNormalizer::stripTrailingSlash($lower);
        $exact = $this->data['exact'];

        foreach ([$exact['cs_t'][$stripped] ?? [], $exact['cs_s'][$path] ?? [], $exact['ci_t'][$lowerStripped] ?? [], $exact['ci_s'][$lower] ?? []] as $list) {
            foreach ($list as $index) {
                $out[] = $index;
            }
        }

        $segments = self::segments($path);
        $lowerSegments = self::segments($lower);
        $this->walk($this->data['trie']['cs'], $segments, $out);
        $this->walk($this->data['trie']['ci'], $lowerSegments, $out);

        $rx = $this->data['rx'];
        if ($segments !== []) {
            foreach ($rx['cs'][$segments[0]] ?? [] as $index) {
                $out[] = $index;
            }
            foreach ($rx['ci'][$lowerSegments[0]] ?? [] as $index) {
                $out[] = $index;
            }
        }
        foreach ($rx['any'] as $index) {
            $out[] = $index;
        }

        sort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $segments
     * @param list<int>            $out
     */
    private function walk(array $node, array $segments, array &$out): void
    {
        foreach (self::nodeRules($node) as $index) {
            $out[] = $index;
        }
        foreach ($segments as $segment) {
            $children = $node['c'] ?? null;
            if (!is_array($children) || !isset($children[$segment]) || !is_array($children[$segment])) {
                return;
            }
            /** @var array<string, mixed> $node */
            $node = $children[$segment];
            foreach (self::nodeRules($node) as $index) {
                $out[] = $index;
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return list<int>
     */
    private static function nodeRules(array $node): array
    {
        $rules = $node['r'] ?? [];
        if (!is_array($rules)) {
            return [];
        }
        /** @var list<int> $rules */

        return $rules;
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '') {
                $out[] = $segment;
            }
        }

        return $out;
    }
}
