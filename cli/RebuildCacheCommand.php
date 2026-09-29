<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;

class RebuildCacheCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('rebuild-cache')
            ->setAliases(['redirects:rebuild-cache'])
            ->setDescription('Drop and rebuild the compiled rule cache')
            ->setHelp(<<<'HELP'
The <info>rebuild-cache</info> command drops the compiled rule set and the analysis cache and compiles the rules again.

  <info>bin/plugin redirect-manager rebuild-cache</info>

Exit codes: 0 ok, 1 runtime error.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->rebuildCache();
    }
}
