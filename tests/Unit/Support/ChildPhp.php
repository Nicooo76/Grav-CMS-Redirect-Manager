<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Support;

/**
 * Starts the PHP child processes of the concurrency tests.
 *
 * Under coverage (pcov overrides zend_execute_ex()) a child that has OPcache's JIT configured prints
 * "PHP Warning:  JIT is incompatible with third party extensions ... JIT disabled." to stderr on startup. That is a
 * property of the environment, not of the code under test, and the tests treat any stderr output of a worker as an
 * error. Two layers keep it out:
 *
 *  - command() starts the child with the JIT switched off completely (`opcache.jit=disable`; plain `off` still
 *    initialises the JIT and warns) and no JIT buffer;
 *  - stderr() removes exactly that one known line, so a worker that was started some other way, or a PHP build that
 *    words the notice differently in the future, still has to explain every other line.
 */
final class ChildPhp
{
    private const JIT_NOTICE = '/^(?:PHP )?Warning:\s+JIT is incompatible with third party extensions that override zend_execute_ex\(\)\. JIT disabled\. in Unknown on line 0\R?/m';

    /**
     * @param string ...$args everything after the binary: script and its arguments, or `-r`, code and `--` arguments
     *
     * @return list<string>
     */
    public static function command(string ...$args): array
    {
        return [PHP_BINARY, '-d', 'opcache.jit=disable', '-d', 'opcache.jit_buffer_size=0', ...$args];
    }

    /** The child's stderr without the JIT startup notice; every other line stays. */
    public static function stderr(string $raw): string
    {
        return (string) preg_replace(self::JIT_NOTICE, '', $raw);
    }
}
