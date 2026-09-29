<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class ExportCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('export')
            ->setAliases(['redirects:export'])
            ->setDescription('Export the rules (CSV, JSON, YAML, .htaccess, nginx, ...)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'csv, json, yaml, grav_site, htaccess, nginx, wordpress_json, wordpress_csv, cloudflare_csv, netlify', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout')
            ->addOption('only-enabled', null, InputOption::VALUE_NONE, 'Leave out disabled rules')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Only rules of this group')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host for formats with absolute sources (cloudflare_csv), e.g. example.org')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print file name, type and counts as JSON (with --output), else the content in a JSON document')
            ->setHelp(<<<'HELP'
The <info>export</info> command writes the rules to stdout or to a file.

  <info>bin/plugin redirect-manager export --format=htaccess --output=redirects.htaccess</info>
  <info>bin/plugin redirect-manager export --format=cloudflare_csv --host=example.org --output=cloudflare.csv</info>

Exit codes: 0 ok, 1 the file cannot be written, 2 unknown format.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->export(
            Options::string($input, 'format') ?? 'csv',
            Options::string($input, 'output'),
            Options::bool($input, 'only-enabled'),
            Options::string($input, 'group'),
            Options::bool($input, 'json'),
            Options::string($input, 'host'),
        );
    }
}
