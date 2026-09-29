<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Cli;

use Grav\Plugin\RedirectManager\Cli\Options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(Options::class)]
#[Group('cli')]
final class OptionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function input(array $params): ArrayInput
    {
        return new ArrayInput($params, new InputDefinition([
            new InputOption('name', null, InputOption::VALUE_REQUIRED),
            new InputOption('list', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED),
            new InputOption('flag', null, InputOption::VALUE_NONE),
            new InputArgument('one', InputArgument::OPTIONAL),
            new InputArgument('many', InputArgument::IS_ARRAY),
        ]));
    }

    public function testCannotBeInstantiated(): void
    {
        $constructor = (new ReflectionClass(Options::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        // The private constructor only exists to keep the class static.
        $constructor->invoke((new ReflectionClass(Options::class))->newInstanceWithoutConstructor());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function stringProvider(): iterable
    {
        yield 'given' => [['--name' => 'abc'], 'abc'];
        yield 'not given' => [[], null];
        yield 'empty value' => [['--name' => ''], null];
        yield 'zero string is kept' => [['--name' => '0'], '0'];
        yield 'whitespace is kept' => [['--name' => ' '], ' '];
        yield 'array option is not a string' => [['--list' => ['a']], null];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('stringProvider')]
    public function testString(array $params, ?string $expected): void
    {
        $name = array_key_exists('--list', $params) ? 'list' : 'name';

        self::assertSame($expected, Options::string($this->input($params), $name));
    }

    public function testBoolIsTrueOnlyWhenTheFlagIsSet(): void
    {
        self::assertTrue(Options::bool($this->input(['--flag' => true]), 'flag'));
        self::assertFalse(Options::bool($this->input([]), 'flag'));
        self::assertFalse(Options::bool($this->input(['--name' => 'x']), 'name'), 'a string option is not a flag');
        self::assertFalse(Options::bool($this->input(['--list' => ['x']]), 'list'));
    }

    public function testArgument(): void
    {
        self::assertSame('value', Options::argument($this->input(['one' => 'value']), 'one'));
        self::assertSame('', Options::argument($this->input([]), 'one'), 'missing argument');
        self::assertSame('', Options::argument($this->input(['many' => ['a', 'b']]), 'many'), 'list argument is not a string');
    }

    public function testArguments(): void
    {
        self::assertSame(['a', 'b'], Options::arguments($this->input(['many' => ['a', 'b']]), 'many'));
        self::assertSame([], Options::arguments($this->input([]), 'many'));
        self::assertSame([], Options::arguments($this->input(['one' => 'x']), 'one'), 'a scalar argument is not a list');
    }

    public function testArgumentsDropsNonStringsAndReindexes(): void
    {
        $input = $this->input(['many' => ['a', 5, 'b', null, ['c']]]);

        self::assertSame(['a', 'b'], Options::arguments($input, 'many'));
    }
}
