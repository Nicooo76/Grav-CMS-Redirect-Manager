<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class StatsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('stats')
            ->setAliases(['redirects:stats'])
            ->setDescription('Show the dashboard numbers: 404s, redirect hits, rules, suggestions')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the numbers as JSON')
            ->setHelp(<<<'HELP'
The <info>stats</info> command prints the numbers of the dashboard.

  <info>bin/plugin redirect-manager stats --json</info>

Exit codes: 0 ok, 1 runtime error.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->stats(Options::bool($input, 'json'));
    }
}
