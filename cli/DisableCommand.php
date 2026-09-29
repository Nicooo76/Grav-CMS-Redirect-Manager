<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputArgument;

class DisableCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('disable')
            ->setAliases(['redirects:disable'])
            ->setDescription('Disable redirect rules by id')
            ->addArgument('id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more rule ids')
            ->setHelp(<<<'HELP'
The <info>disable</info> command disables the given rules. A rule that would be invalid when enabled is skipped and reported.

  <info>bin/plugin redirect-manager disable r-1a2b3c r-4d5e6f</info>

Exit codes: 0 ok, 1 runtime error, 2 a rule was skipped because it is invalid, 4 an id does not exist.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->setEnabled(Options::arguments($input, 'id'), false);
    }
}
