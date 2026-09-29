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

class TestCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('test')
            ->setAliases(['redirects:test'])
            ->setDescription('Show what happens to a URL, optionally as an assertion')
            ->addArgument('url', InputArgument::REQUIRED, 'URL or path to test')
            ->addOption('expect-status', null, InputOption::VALUE_REQUIRED, 'Expected status of the first hop')
            ->addOption('expect-location', null, InputOption::VALUE_REQUIRED, 'Expected Location of the first hop')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method', 'GET')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language code of the request')
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'early, not_found or any', 'any')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the full result as JSON')
            ->setHelp(<<<'HELP'
The <info>test</info> command runs a URL through the rules like a real request and prints the redirect chain and the final status.

  <info>bin/plugin redirect-manager test /old-page --expect-status=301 --expect-location=/new-page</info>

Exit codes: 0 ok (or all expectations met), 1 runtime error, 2 invalid input, 3 an expectation did not match.
HELP);
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $this->initializePlugins();
        $input = $this->getInput();
        $app = GravBootstrap::service(Grav::instance());

        return (new CliCommands($app, $this->getIO()))->test(
            Options::argument($input, 'url'),
            Options::string($input, 'expect-status'),
            Options::string($input, 'expect-location'),
            Options::string($input, 'method'),
            Options::string($input, 'language'),
            Options::string($input, 'phase'),
            Options::bool($input, 'json'),
        );
    }
}
