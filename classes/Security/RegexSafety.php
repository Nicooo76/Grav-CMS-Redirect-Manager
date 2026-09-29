<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Security;

/**
 * Compiles and vets user-supplied regular expressions.
 *
 * Rules and header conditions contain PCRE patterns without delimiters. Every pattern is compiled with
 * the "u" flag and runs under low backtrack limits (see withLimits()), so a bad pattern costs a few
 * milliseconds and reports an error instead of hanging the request.
 */
final class RegexSafety
{
    public const MAX_LENGTH = 1000;
    public const ERR_INVALID = 'regex_invalid';
    public const ERR_CATASTROPHIC = 'regex_catastrophic';
    public const ERR_TOO_LONG = 'regex_too_long';

    private const DELIMITER = '#';

    /** Limits used by validate(): tight enough to trip on exponential patterns within milliseconds. */
    private const VALIDATE_BACKTRACK_LIMIT = 20000;
    private const VALIDATE_RECURSION_LIMIT = 5000;

    /**
     * Full PCRE string with delimiter and flags: "u", plus "i" for case-insensitive patterns.
     */
    public static function compile(string $pattern, bool $caseSensitive): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $char . $pattern[++$i];
                continue;
            }
            $out .= $char === self::DELIMITER ? '\\' . $char : $char;
        }

        return self::DELIMITER . $out . self::DELIMITER . 'u' . ($caseSensitive ? '' : 'i');
    }

    /**
     * Full check for saving a rule: syntax, length and catastrophic backtracking.
     * Returns an error code or null.
     */
    public static function validate(string $pattern, bool $caseSensitive = false): ?string
    {
        $error = self::syntaxError($pattern, $caseSensitive);
        if ($error !== null) {
            return $error;
        }
        $compiled = self::compile($pattern, $caseSensitive);

        $catastrophic = self::withLimits(static function () use ($compiled): bool {
            foreach (self::probes() as $probe) {
                $result = self::quietMatch($compiled, $probe);
                if ($result === false && self::isLimitError(preg_last_error())) {
                    return true;
                }
            }

            return false;
        }, self::VALIDATE_BACKTRACK_LIMIT, self::VALIDATE_RECURSION_LIMIT);

        return $catastrophic ? self::ERR_CATASTROPHIC : null;
    }

    /**
     * Cheap check used when compiling rule sets: does the pattern compile at all?
     */
    public static function syntaxError(string $pattern, bool $caseSensitive = false): ?string
    {
        if ($pattern === '') {
            return self::ERR_INVALID;
        }
        if (strlen($pattern) > self::MAX_LENGTH) {
            return self::ERR_TOO_LONG;
        }

        return self::quietMatch(self::compile($pattern, $caseSensitive), '') === false ? self::ERR_INVALID : null;
    }

    /**
     * Runs $fn with the given PCRE limits and restores the previous values afterwards, also on exceptions.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withLimits(callable $fn, int $backtrackLimit, int $recursionLimit): mixed
    {
        $oldBacktrack = ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        $oldRecursion = ini_set('pcre.recursion_limit', (string) $recursionLimit);
        try {
            return $fn();
        } finally {
            if ($oldBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $oldBacktrack);
            }
            if ($oldRecursion !== false) {
                ini_set('pcre.recursion_limit', $oldRecursion);
            }
        }
    }

    public static function isLimitError(int $pregError): bool
    {
        return $pregError === PREG_BACKTRACK_LIMIT_ERROR
            || $pregError === PREG_RECURSION_LIMIT_ERROR
            || $pregError === PREG_JIT_STACKLIMIT_ERROR;
    }

    /**
     * preg_match without warnings: compile errors surface as false, not as E_WARNING.
     */
    private static function quietMatch(string $compiled, string $subject): int|false
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return preg_match($compiled, $subject);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return list<string>
     */
    private static function probes(): array
    {
        return [
            str_repeat('a', 3000) . '!',
            str_repeat('/a', 1500),
            '/' . str_repeat('a/', 1000) . '!',
            str_repeat('ab', 1500) . '!',
            str_repeat('a', 30) . str_repeat('/', 30) . str_repeat('a', 30) . '!',
            str_repeat('x', 3000) . '!',
            '/' . str_repeat('a', 2000) . "\n!",
            str_repeat('0', 2000) . 'x',
            str_repeat(' ', 1500) . '!',
        ];
    }
}
