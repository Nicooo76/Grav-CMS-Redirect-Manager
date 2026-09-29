<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Security\RegexSafety;

/**
 * Generates one example path for a simple regular expression: literals, escaped characters, character
 * classes, \d \w and ".", quantifiers, capturing / named / non-capturing groups, alternation (first branch).
 * Lookarounds, backreferences and inline flags other than a leading (?i) are not supported: sample() returns
 * null and the caller skips the rule.
 *
 * Every generated sample is checked against the real pattern, so a wrong guess never leaks out.
 */
final class RegexSampler
{
    private int $pos = 0;
    private readonly int $len;
    private int $groupCount = 0;

    /** @var array<int, string|null> group index => name */
    private array $names = [];

    /** @var array<int, true> groups that took part in a later alternative or cannot get a unique stand-in */
    private array $fixed = [];

    /** @var array<int, string> group index => stand-in value of groups that received one */
    private array $tokens = [];

    private function __construct(
        private readonly string $pattern,
        private readonly string $base,
        private readonly bool $unique,
    ) {
        $this->len = strlen($pattern);
    }

    /**
     * @param string $base   word used for text captures ("sample" for the analysis, "example" for editor previews)
     * @param bool   $unique give each capture its own stand-in value (needed to template a chain's end again)
     */
    public static function sample(string $pattern, bool $caseSensitive, string $base = 'sample', bool $unique = true): ?Sample
    {
        if ($pattern === '' || strlen($pattern) > RegexSafety::MAX_LENGTH || RegexSafety::syntaxError($pattern, $caseSensitive) !== null) {
            return null;
        }
        $self = new self($pattern, $base, $unique);
        $nodes = $self->parseAlternation();
        if ($nodes === null || $self->pos < $self->len) {
            return null;
        }
        $path = $self->render($nodes);
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        $regex = RegexSafety::compile($pattern, $caseSensitive);
        $m = [];
        $found = RegexSafety::withLimits(static function () use ($regex, $path, &$m): int|false {
            set_error_handler(static fn (): bool => true, E_WARNING);
            try {
                return preg_match($regex, $path, $m);
            } finally {
                restore_error_handler();
            }
        }, 20000, 5000);
        if ($found !== 1) {
            return null;
        }

        $tokens = [];
        $templatable = true;
        for ($i = 1; $i <= $self->groupCount; ++$i) {
            $token = $self->tokens[$i] ?? null;
            if ($token === null || isset($self->fixed[$i]) || ($m[$i] ?? null) !== $token) {
                $templatable = false;
                continue;
            }
            $name = $self->names[$i] ?? null;
            $tokens[] = ['token' => $token, 'placeholder' => $name !== null ? '{' . $name . '}' : ($i <= 9 ? '$' . $i : '${' . $i . '}')];
        }

        return new Sample($path, [], $tokens, $templatable);
    }

    /**
     * Example value for a header or cookie condition pattern (case sensitive), or null.
     */
    public static function value(string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > RegexSafety::MAX_LENGTH || RegexSafety::syntaxError($pattern, true) !== null) {
            return null;
        }
        $self = new self($pattern, 'sample', false);
        $nodes = $self->parseAlternation();
        if ($nodes === null || $self->pos < $self->len) {
            return null;
        }
        $value = $self->render($nodes);

        return @preg_match(RegexSafety::compile($pattern, true), $value) === 1 ? $value : null;
    }

    /**
     * @return list<RegexNode>|null
     */
    private function parseAlternation(): ?array
    {
        $first = $this->parseSequence();
        if ($first === null) {
            return null;
        }
        while ($this->pos < $this->len && $this->pattern[$this->pos] === '|') {
            ++$this->pos;
            $before = $this->groupCount;
            if ($this->parseSequence() === null) {
                return null;
            }
            for ($i = $before + 1; $i <= $this->groupCount; ++$i) {
                $this->fixed[$i] = true;
            }
        }

        return $first;
    }

    /**
     * @return list<RegexNode>|null
     */
    private function parseSequence(): ?array
    {
        $nodes = [];
        while ($this->pos < $this->len) {
            $char = $this->pattern[$this->pos];
            if ($char === ')' || $char === '|') {
                break;
            }
            $node = $this->parseAtom();
            if ($node === null) {
                return null;
            }
            $this->parseQuantifier($node);
            $nodes[] = $node;
        }

        return $nodes;
    }

    private function parseAtom(): ?RegexNode
    {
        $char = $this->pattern[$this->pos];
        switch ($char) {
            case '^':
            case '$':
                ++$this->pos;

                return RegexNode::literal('');
            case '.':
                ++$this->pos;

                return RegexNode::variable('text');
            case '[':
                return $this->parseClass();
            case '(':
                return $this->parseGroup();
            case '\\':
                return $this->parseEscape();
            case '*':
            case '+':
            case '?':
                return null;
            default:
                ++$this->pos;

                return RegexNode::literal($char);
        }
    }

    private function parseGroup(): ?RegexNode
    {
        ++$this->pos; // (
        $capture = true;
        $name = null;
        $rest = substr($this->pattern, $this->pos, 4);
        if ($rest !== '' && $rest[0] === '?') {
            if (preg_match('/^\?(?:P?<([A-Za-z_][A-Za-z0-9_]*)>|\'([A-Za-z_][A-Za-z0-9_]*)\')/', substr($this->pattern, $this->pos), $m) === 1) {
                $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                $this->pos += strlen($m[0]);
            } elseif (str_starts_with($rest, '?:')) {
                $capture = false;
                $this->pos += 2;
            } elseif (preg_match('/^\?[imsxuU]+\)/', substr($this->pattern, $this->pos), $flags) === 1) {
                $this->pos += strlen($flags[0]);

                return RegexNode::literal('');
            } else {
                return null; // lookaround, atomic group, conditional, recursion ...
            }
        }
        $index = null;
        if ($capture) {
            $index = ++$this->groupCount;
            $this->names[$index] = $name;
        }
        $children = $this->parseAlternation();
        if ($children === null || $this->pos >= $this->len || $this->pattern[$this->pos] !== ')') {
            return null;
        }
        ++$this->pos; // )

        return RegexNode::group($index, $name, $children);
    }

    private function parseEscape(): ?RegexNode
    {
        if ($this->pos + 1 >= $this->len) {
            return null;
        }
        $next = $this->pattern[$this->pos + 1];
        $this->pos += 2;
        switch ($next) {
            case 'd':
                return RegexNode::variable('digit');
            case 'w':
            case 'W':
            case 'S':
                return RegexNode::variable('text');
            case 'D':
                return RegexNode::variable('alpha');
            case 'b':
            case 'B':
            case 'A':
            case 'z':
            case 'Z':
            case 'G':
                return RegexNode::literal('');
            case 'p':
            case 'P':
                if ($this->pos < $this->len && $this->pattern[$this->pos] === '{') {
                    $close = strpos($this->pattern, '}', $this->pos);
                    if ($close === false) {
                        return null;
                    }
                    $this->pos = $close + 1;
                } else {
                    ++$this->pos;
                }

                return RegexNode::variable('alpha');
        }
        if (ctype_alnum($next)) {
            return null; // \s, \t, backreferences, ...
        }

        return RegexNode::literal($next);
    }

    private function parseClass(): ?RegexNode
    {
        ++$this->pos; // [
        $negated = false;
        if ($this->pos < $this->len && $this->pattern[$this->pos] === '^') {
            $negated = true;
            ++$this->pos;
        }
        $lower = $upper = $digit = false;
        $literal = null;
        $first = true;
        while (true) {
            if ($this->pos >= $this->len) {
                return null;
            }
            $char = $this->pattern[$this->pos];
            if ($char === ']' && !$first) {
                ++$this->pos;
                break;
            }
            $first = false;
            if ($char === '[' && preg_match('/^\[:(\^?)(alpha|alnum|digit|lower|upper|word|punct|space):\]/', substr($this->pattern, $this->pos), $posix) === 1) {
                $this->pos += strlen($posix[0]);
                $lower = $lower || in_array($posix[2], ['alpha', 'alnum', 'lower', 'word'], true);
                $upper = $upper || $posix[2] === 'upper';
                $digit = $digit || in_array($posix[2], ['digit', 'alnum', 'word'], true);
                continue;
            }
            if ($char === '\\') {
                if ($this->pos + 1 >= $this->len) {
                    return null;
                }
                $escaped = $this->pattern[$this->pos + 1];
                $this->pos += 2;
                if ($escaped === 'd') {
                    $digit = true;
                    continue;
                }
                if ($escaped === 'w') {
                    $lower = $digit = true;
                    continue;
                }
                if (ctype_alnum($escaped)) {
                    continue; // \s and friends add nothing usable to a path
                }
                $char = $escaped;
            } else {
                ++$this->pos;
            }
            // range "a-z"
            if ($this->pos + 1 < $this->len && $this->pattern[$this->pos] === '-' && $this->pattern[$this->pos + 1] !== ']') {
                $hi = $this->pattern[$this->pos + 1];
                $this->pos += 2;
                $lo = ord($char);
                $hiOrd = ord($hi);
                $lower = $lower || ($lo <= 122 && $hiOrd >= 97);
                $upper = $upper || ($lo <= 90 && $hiOrd >= 65);
                $digit = $digit || ($lo <= 57 && $hiOrd >= 48);
                continue;
            }
            $literal ??= $char;
            $lower = $lower || ($char >= 'a' && $char <= 'z');
            $upper = $upper || ($char >= 'A' && $char <= 'Z');
            $digit = $digit || ($char >= '0' && $char <= '9');
        }

        if ($negated || ($lower && $digit)) {
            return RegexNode::variable('text');
        }
        if ($lower) {
            return RegexNode::variable('alpha');
        }
        if ($upper) {
            return RegexNode::variable($digit ? 'text' : 'upper');
        }
        if ($digit) {
            return RegexNode::variable('digit');
        }

        return $literal === null ? null : RegexNode::literal($literal);
    }

    private function parseQuantifier(RegexNode $node): void
    {
        if ($this->pos >= $this->len) {
            return;
        }
        $char = $this->pattern[$this->pos];
        if ($char === '?' || $char === '*' || $char === '+') {
            ++$this->pos;
            $node->width = null;
        } elseif ($char === '{' && preg_match('/^\{(\d+)(?:,(\d*))?\}/', substr($this->pattern, $this->pos), $m) === 1) {
            $this->pos += strlen($m[0]);
            $min = (int) $m[1];
            $max = str_contains($m[0], ',') ? (isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null) : $min;
            $count = ($min === 0 && $max === 0) ? 0 : max(1, $min);
            $node->repeat = $count;
            $node->width = $count;
        } else {
            return;
        }
        if ($this->pos < $this->len && ($this->pattern[$this->pos] === '?' || $this->pattern[$this->pos] === '+')) {
            ++$this->pos; // lazy or possessive
        }
    }

    /**
     * @param list<RegexNode> $nodes
     */
    private function render(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->renderNode($node);
        }

        return $out;
    }

    private function renderNode(RegexNode $node): string
    {
        if ($node->type === 'lit') {
            return $node->repeat === 0 ? '' : str_repeat($node->text, $node->repeat);
        }
        if ($node->type === 'var') {
            return $node->repeat === 0 ? '' : $this->value1($node->kind, $node->width, null);
        }

        // group
        if ($node->repeat === 0) {
            return '';
        }
        $children = $node->children;
        if ($node->index !== null && count($children) === 1 && $children[0]->type === 'var' && $children[0]->repeat > 0) {
            $only = $children[0];
            $value = $this->value1($only->kind, $only->width, $node->index);
            $this->tokens[$node->index] = $value;

            return $value;
        }
        $text = $this->render($children);
        if ($node->index !== null) {
            $this->fixed[$node->index] = true;
        }

        return $text;
    }

    /**
     * Stand-in text for a variable atom. $index is set for the sole content of a capturing group and makes the
     * value unique per group when $unique is on.
     */
    private function value1(string $kind, ?int $width, ?int $index): string
    {
        if ($width === null) {
            if ($kind === 'digit') {
                return $this->unique && $index !== null ? (string) (9000 + $index) : '1';
            }
            $word = $kind === 'upper' ? strtoupper($this->base) : $this->base;
            if (!$this->unique || $index === null || $index < 2) {
                return $word;
            }

            // letter-only classes cannot take digits: make the value unique with repeated letters
            return $kind === 'text' ? $word . $index : $word . str_repeat($kind === 'upper' ? 'X' : 'x', $index - 1);
        }

        $char = match ($kind) {
            'digit' => (string) (($this->unique && $index !== null ? $index : 0) % 9 + 1),
            'upper' => chr(ord('A') + ($this->unique && $index !== null ? $index - 1 : 23) % 26),
            default => chr(ord('a') + ($this->unique && $index !== null ? $index - 1 : 23) % 26),
        };

        return str_repeat($char, $width);
    }
}
