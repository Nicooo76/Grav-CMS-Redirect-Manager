<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GPM::getPackageName() (Grav 2.2.2) names a directly installed package after the first *.yaml in the package root,
 * ignoring only blueprints.yaml and languages.yaml. Any other root yaml that sorts before redirect-manager.yaml
 * (mcp.yaml, permissions.yaml) installs the plugin under the wrong name. docs/DECISIONS.md D-024.
 */
final class PluginLayoutTest extends TestCase
{
    public function testTheOnlyRootYamlFilesAreTheOnesGpmAccepts(): void
    {
        $root = dirname(__DIR__, 2);
        $found = array_map('basename', glob($root . '/*.{yaml,yml}', GLOB_BRACE) ?: []);
        sort($found);

        self::assertSame(['blueprints.yaml', 'languages.yaml', 'redirect-manager.yaml'], $found);
    }

    public function testPermissionsAndMcpManifestLiveBelowConfig(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/config/permissions.yaml');
        self::assertFileExists($root . '/config/mcp.yaml');
    }

    // ---- I-2: the plugin file is event wiring only -------------------------------------------------------

    private static function pluginSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/redirect-manager.php');
    }

    public function testThePluginFileStaysSmall(): void
    {
        self::assertLessThan(200, substr_count(self::pluginSource(), "\n") + 1);
    }

    public function testEveryEventMethodIsAtMostThreeStatementsOfDelegation(): void
    {
        $source = self::pluginSource();
        preg_match_all('/public function (on\w+)\([^)]*\): void\s*\{(.*?)\n    \}/s', $source, $matches, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(15, count($matches), 'the event methods were not found; did the layout change?');
        foreach ($matches as [, $name, $body]) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), static fn (string $l): bool => $l !== ''));
            self::assertLessThanOrEqual(3, count($lines), $name . ' is more than delegation');
            foreach ($lines as $line) {
                self::assertDoesNotMatchRegularExpression('/\b(try|catch|foreach|if|while|header|file_put_contents)\b|\$_SERVER\[/', $line, $name . ' holds logic: ' . $line);
            }
        }
    }

    public function testEverySubscribedMethodExistsInThePluginFile(): void
    {
        $source = self::pluginSource();
        preg_match_all("/\\['(on\w+)', -?\d+\\]/", $source, $literal);
        $methods = array_unique([...$literal[1], 'onApiPageEvent', 'onApiRegisterAutoRoutes']);
        self::assertContains('onPluginsLoaded', $methods);
        foreach ($methods as $method) {
            self::assertMatchesRegularExpression('/function ' . $method . '\(/', $source, $method . ' is subscribed but not defined');
        }
    }

    public function testThePluginClassNeedsNoAutoloadedBaseClassOrTrait(): void
    {
        // Grav include_once's the plugin file before it calls autoload(): a `use SomeTrait;` or `extends` of a
        // class from classes/ ends in a fatal error on every request.
        $source = self::pluginSource();
        self::assertDoesNotMatchRegularExpression('/^\s+use [A-Z]\w*;/m', $source, 'trait use');
        self::assertMatchesRegularExpression('/class RedirectManagerPlugin extends Plugin\b/', $source);
    }
}
