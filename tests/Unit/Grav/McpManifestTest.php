<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Grav\McpManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpManifest::class)]
final class McpManifestTest extends TestCase
{
    private const SLUG = 'redirect-manager';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rm-mcp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function collector(): object
    {
        return new class () {
            /** @var list<array{string, array<string, mixed>, int}> */
            public array $added = [];
            /** @var list<array{string, ?string}> */
            public array $registered = [];
            /** @var list<array{string, string}> */
            public array $warnings = [];

            public function registerPlugin(string $plugin, ?string $prefix = null): void
            {
                $this->registered[] = [$plugin, $prefix];
            }

            /** @param array<string, mixed> $tool */
            public function add(string $plugin, array $tool, int $version = 2): void
            {
                $this->added[] = [$plugin, $tool, $version];
            }

            public function warn(string $plugin, string $message): void
            {
                $this->warnings[] = [$plugin, $message];
            }
        };
    }

    public function testShippedManifestRegistersTheSixToolsUnderThePrefix(): void
    {
        $collector = $this->collector();
        McpManifest::register($collector, self::SLUG, self::root() . '/' . McpManifest::FILE);

        self::assertSame([[self::SLUG, 'redirects']], $collector->registered);
        self::assertSame([], $collector->warnings);
        self::assertSame(['list', 'create', 'test', 'top_404', 'suggest', 'import'], array_map(static fn (array $call): string => (string) $call[1]['name'], $collector->added));
        foreach ($collector->added as [$plugin, $tool, $version]) {
            self::assertSame([self::SLUG, 1], [$plugin, $version]);
            self::assertStringStartsWith('api.redirects.', (string) $tool['permission']);
        }
    }

    public function testManifestIsNotInThePluginRoot(): void
    {
        self::assertFileExists(self::root() . '/config/mcp.yaml');
        self::assertFileDoesNotExist(self::root() . '/mcp.yaml', 'a root mcp.yaml makes bin/gpm direct-install name the plugin "mcp" (D-024)');
    }

    public function testMissingFileIsAWarningNotAnException(): void
    {
        $collector = $this->collector();
        McpManifest::register($collector, self::SLUG, $this->dir . '/none.yaml');

        self::assertSame([], $collector->added);
        self::assertCount(1, $collector->warnings);
        self::assertStringContainsString('could not be read', $collector->warnings[0][1]);
    }

    public function testBrokenYamlIsAWarningNotAnException(): void
    {
        file_put_contents($this->dir . '/mcp.yaml', "tools: [unclosed\n  - : :\n");
        $collector = $this->collector();
        McpManifest::register($collector, self::SLUG, $this->dir . '/mcp.yaml');

        self::assertSame([], $collector->added);
        self::assertCount(1, $collector->warnings);
        self::assertStringContainsString('could not be parsed', $collector->warnings[0][1]);
    }

    public function testManifestWithoutToolsListIsAWarning(): void
    {
        file_put_contents($this->dir . '/mcp.yaml', "version: 1\nprefix: x\n");
        $collector = $this->collector();
        McpManifest::register($collector, self::SLUG, $this->dir . '/mcp.yaml');

        self::assertSame([], $collector->added);
        self::assertSame([], $collector->registered);
        self::assertStringContainsString("'tools' list", $collector->warnings[0][1]);
    }

    public function testEntryThatIsNoMappingIsSkippedAndTheRestIsKept(): void
    {
        file_put_contents($this->dir . '/mcp.yaml', "version: 2\ntools:\n  - just a string\n  - {name: ok}\n");
        $collector = $this->collector();
        McpManifest::register($collector, self::SLUG, $this->dir . '/mcp.yaml');

        self::assertCount(1, $collector->warnings);
        self::assertCount(1, $collector->added);
        self::assertSame(2, $collector->added[0][2]);
        self::assertSame([[self::SLUG, null]], $collector->registered, 'no prefix declared: the collector falls back to the slug');
    }
}
