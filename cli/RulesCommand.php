<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\Options;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Symfony\Component\Console\Input\InputOption;

class RulesCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('rules')
            ->setAliases(['redirects:list'])
            ->setDescription('List redirect rules (named "rules" because "list" is reserved by Symfony; alias redirects:list)')
            ->addOption('q', null, InputOption::VALUE_REQUIRED, 'Search in source, target, note, group and tags')
            ->addOption('match-type', null, InputOption::VALUE_REQUIRED, 'exact, wildcard or regex')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only rules with this HTTP status')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'active, disabled, expired or scheduled')
            ->addOption('badge', null, InputOption::VALUE_REQUIRED, 'Only rules with this badge (chain, loop, conflict, dead_target, unused, ...)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Only rules of this group')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'Only rules from this origin (manual, import, auto, suggestion, ...)')
            ->addOption('unused-days', null, InputOption::VALUE_REQUIRED, 'Only rules without a hit for this many days')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'priority, source, target, status, hits, last_hit, created_at or updated_at')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'asc or desc')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rules per page (1-500)', '50')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number', '1')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print {"data": [...], "meta": {...}} as JSON')
            ->setHelp(<<<'HELP'
The <info>rules</info> command lists the redirect rules (Symfony reserves the name "list", so the alias <info>redirects:list</info> is the long form).

  <info>bin/plugin redirect-manager rules --q=shop --state=active --limit=20</info>
  <info>bin/plugin redirect-manager rules --badge=loop --json</info>

Exit codes: 0 ok, 1 runtime error, 2 invalid filter value.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->rules([
            'q' => Options::string($input, 'q'),
            'match_type' => Options::string($input, 'match-type'),
            'status' => Options::string($input, 'status'),
            'state' => Options::string($input, 'state'),
            'badge' => Options::string($input, 'badge'),
            'group' => Options::string($input, 'group'),
            'origin' => Options::string($input, 'origin'),
            'unused_days' => Options::string($input, 'unused-days'),
            'sort' => Options::string($input, 'sort'),
            'dir' => Options::string($input, 'dir'),
            'per_page' => Options::string($input, 'limit'),
            'page' => Options::string($input, 'page'),
        ], Options::bool($input, 'json'));
    }
}
