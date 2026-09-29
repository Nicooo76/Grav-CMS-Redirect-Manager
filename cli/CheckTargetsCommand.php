<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class CheckTargetsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('check-targets')
            ->setAliases(['redirects:check-targets'])
            ->setDescription('Request the targets of all enabled rules and list the dead ones')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for internal targets (default: plugin setting base_url, else the site URL)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the dead targets as JSON')
            ->setHelp(<<<'HELP'
The <info>check-targets</info> command sends a live request to every rule target (no rate limit on the command line) and prints the dead ones.

  <info>bin/plugin redirect-manager check-targets --base-url=https://example.org</info>

Exit codes: 0 no dead target, 1 runtime error, 3 at least one dead target.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance(), null, Options::string($input, 'base-url'));

        return (new CliCommands($app, $this->getIO()))->checkTargets(Options::bool($input, 'json'));
    }
}
