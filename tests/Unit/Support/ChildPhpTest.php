<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChildPhp::class)]
#[Group('util')]
final class ChildPhpTest extends TestCase
{
    private const NOTICE = 'PHP Warning:  JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0';

    public function testCommandStartsTheRunningBinaryWithTheJitSwitchedOffBeforeTheArguments(): void
    {
        self::assertSame(
            [PHP_BINARY, '-d', 'opcache.jit=disable', '-d', 'opcache.jit_buffer_size=0', '-r', 'echo 1;', '--', 'a'],
            ChildPhp::command('-r', 'echo 1;', '--', 'a'),
        );
    }

    public function testStderrDropsTheKnownJitNoticeInBothSpellings(): void
    {
        self::assertSame('', ChildPhp::stderr(self::NOTICE . "\n"));
        self::assertSame('', ChildPhp::stderr("Warning:  JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0\n"));
        self::assertSame('', ChildPhp::stderr(self::NOTICE . "\n" . self::NOTICE . "\n"));
    }

    public function testStderrKeepsEveryOtherLine(): void
    {
        $real = "PHP Fatal error:  Uncaught RuntimeException: boom in /x.php:3\n";

        self::assertSame($real, ChildPhp::stderr(self::NOTICE . "\n" . $real));
        self::assertSame($real, ChildPhp::stderr($real . self::NOTICE . "\n"));
        self::assertSame("PHP Warning:  Undefined variable \$x in /x.php on line 3\n", ChildPhp::stderr("PHP Warning:  Undefined variable \$x in /x.php on line 3\n"));
        self::assertSame('', ChildPhp::stderr(''));
    }

    public function testAChildStartedByCommandPrintsNothingEvenWhenTheEnvironmentWouldWarn(): void
    {
        $err = tempnam(sys_get_temp_dir(), 'rm-child-');
        self::assertIsString($err);
        try {
            $proc = proc_open(ChildPhp::command('-r', 'echo "ok";'), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes);
            self::assertIsResource($proc);
            self::assertSame('ok', stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            proc_close($proc);
            self::assertSame('', (string) file_get_contents($err));
        } finally {
            @unlink($err);
        }
    }
}
