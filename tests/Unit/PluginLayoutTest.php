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
}
