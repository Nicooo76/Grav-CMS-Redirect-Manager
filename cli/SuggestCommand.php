<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class SuggestCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('suggest')
            ->setAliases(['redirects:suggest'])
            ->setDescription('Generate suggestions for open 404 paths and accept the confident ones')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, '404 paths of the last N days (1-366)', '30')
            ->addOption('accept-min', null, InputOption::VALUE_REQUIRED, 'Accept open suggestions with at least this score (default: suggestions.bulk_accept_score, 0.9)')
            ->addOption('no-accept', null, InputOption::VALUE_NONE, 'Only generate suggestions, accept none')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Store nothing; list what would be stored and accepted')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
The <info>suggest</info> command matches the 404 paths of the last days against the site's pages, stores a suggestion for each, and turns the ones above the score into rules.

  <info>bin/plugin redirect-manager suggest --days=14 --accept-min=0.95 --dry-run</info>

Exit codes: 0 ok, 1 runtime error, 2 invalid option value.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->suggest(
            Options::string($input, 'days') ?? '30',
            Options::string($input, 'accept-min'),
            Options::bool($input, 'no-accept'),
            Options::bool($input, 'dry-run'),
            Options::bool($input, 'json'),
        );
    }
}
