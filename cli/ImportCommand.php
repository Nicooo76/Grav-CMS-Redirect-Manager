<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ImportCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('import')
            ->setAliases(['redirects:import'])
            ->setDescription('Import redirects from a file (CSV, JSON, YAML, .htaccess, nginx, ...)')
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the file to import')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'csv, json, yaml, grav_site, htaccess, nginx, wordpress_json, wordpress_csv, crawler_csv, netlify (default: detect)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only parse and validate, save nothing')
            ->addOption('skip-duplicates', null, InputOption::VALUE_NEGATABLE, 'Skip rows that duplicate an existing rule', true)
            ->addOption('skip-invalid', null, InputOption::VALUE_NEGATABLE, 'Import the valid rows even when others are invalid', false)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
The <info>import</info> command reads a file and creates the rules in it. Without <info>--skip-invalid</info> nothing is saved when any row is invalid.

  <info>bin/plugin redirect-manager import redirects.csv --dry-run</info>
  <info>bin/plugin redirect-manager import redirects.csv --skip-invalid</info>

Exit codes: 0 ok, 1 runtime error, 2 the file is missing, has errors or invalid rows (also for --dry-run).
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->import(
            Options::argument($input, 'file'),
            Options::string($input, 'format'),
            Options::bool($input, 'dry-run'),
            $input->getOption('skip-duplicates') !== false,
            $input->getOption('skip-invalid') === true,
            Options::bool($input, 'json'),
        );
    }
}
