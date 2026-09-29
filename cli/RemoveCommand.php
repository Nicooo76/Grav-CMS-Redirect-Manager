<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputArgument;

class RemoveCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setAliases(['redirects:remove'])
            ->setDescription('Delete redirect rules by id')
            ->addArgument('id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more rule ids')
            ->setHelp(<<<'HELP'
The <info>remove</info> command deletes the rules with the given ids. Unknown ids do not stop the others.

  <info>bin/plugin redirect-manager remove r-1a2b3c r-4d5e6f</info>

Exit codes: 0 ok, 1 runtime error, 2 invalid input, 4 at least one id does not exist (the others were deleted).
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->remove(Options::arguments($input, 'id'));
    }
}
