<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Cli;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed reads of console input for the command classes in cli/ (InputInterface::getOption() returns mixed).
 */
final class Options
{
    private function __construct()
    {
    }

    /** The option as a string; null when it was not given or is empty. */
    public static function string(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function bool(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }

    /** The argument as a string, "" when missing. */
    public static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * A list argument (IS_ARRAY) as a list of strings.
     *
     * @return list<string>
     */
    public static function arguments(InputInterface $input, string $name): array
    {
        $value = $input->getArgument($name);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
