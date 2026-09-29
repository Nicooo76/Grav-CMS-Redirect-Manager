<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Cli;

use Grav\Plugin\RedirectManager\Cli\ExitCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ExitCode::class)]
#[Group('cli')]
final class ExitCodeTest extends TestCase
{
    public function testDocumentedCodes(): void
    {
        self::assertSame(
            ['OK' => 0, 'ERROR' => 1, 'INVALID' => 2, 'MISMATCH' => 3, 'NOT_FOUND' => 4],
            (new ReflectionClass(ExitCode::class))->getConstants(),
        );
    }

    public function testCodesAreDistinct(): void
    {
        $codes = (new ReflectionClass(ExitCode::class))->getConstants();

        self::assertSame(array_values(array_unique($codes)), array_values($codes));
    }

    public function testCannotBeInstantiated(): void
    {
        $class = new ReflectionClass(ExitCode::class);
        $constructor = $class->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        // The private constructor only exists to keep the class a constant holder.
        $constructor->invoke($class->newInstanceWithoutConstructor());
    }
}
