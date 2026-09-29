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

class AddCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('add')
            ->setAliases(['redirects:add'])
            ->setDescription('Add a redirect rule')
            ->addArgument('source', InputArgument::REQUIRED, 'Source path, e.g. /old-page')
            ->addArgument('target', InputArgument::REQUIRED, 'Target path or URL (empty string for 410/451 rules)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'HTTP status (301, 302, 307, 308, 410, 451, 200); default from the plugin settings')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Match type: exact, wildcard or regex', 'exact')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Group name')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Note')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Priority (higher is evaluated first)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate only, save nothing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
The <info>add</info> command validates and stores one rule and prints its id and any warnings.

  <info>bin/plugin redirect-manager add /old-page /new-page --status=301 --group=relaunch</info>
  <info>bin/plugin redirect-manager add '/blog/*' '/news/$1' --type=wildcard --dry-run</info>

Exit codes: 0 ok, 1 runtime error, 2 the rule is invalid (each problem is printed as "field: message (code)").
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->add(
            Options::argument($input, 'source'),
            Options::argument($input, 'target'),
            Options::string($input, 'status'),
            Options::string($input, 'type') ?? 'exact',
            Options::string($input, 'group'),
            Options::string($input, 'note'),
            Options::string($input, 'priority'),
            Options::bool($input, 'dry-run'),
            Options::bool($input, 'json'),
        );
    }
}
