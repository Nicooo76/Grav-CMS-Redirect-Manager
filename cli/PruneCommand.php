<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class PruneCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('prune')
            ->setAliases(['redirects:prune'])
            ->setDescription('Delete (or disable) rules that got no hit for a long time')
            ->addOption('unused-days', null, InputOption::VALUE_REQUIRED, 'No hit for this many days', '180')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the rules, change nothing')
            ->addOption('disable-only', null, InputOption::VALUE_NONE, 'Disable the rules instead of deleting them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
The <info>prune</info> command removes rules without a hit for the given number of days. Start with <info>--dry-run</info>.

  <info>bin/plugin redirect-manager prune --unused-days=365 --dry-run</info>
  <info>bin/plugin redirect-manager prune --disable-only</info>

Exit codes: 0 ok, 1 runtime error, 2 invalid option value.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->prune(
            Options::string($input, 'unused-days') ?? '180',
            Options::bool($input, 'dry-run'),
            Options::bool($input, 'disable-only'),
            Options::bool($input, 'json'),
        );
    }
}
