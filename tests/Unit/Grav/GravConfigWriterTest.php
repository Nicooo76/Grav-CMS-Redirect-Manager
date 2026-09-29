<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\RedirectManager\Grav\GravConfigWriter;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(GravConfigWriter::class)]
#[Group('grav')]
final class GravConfigWriterTest extends GravTestCase
{
    use TempDirTrait;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $resources
     */
    private function grav(array $config = [], array $resources = []): Grav
    {
        $grav = new Grav();
        $grav['config'] = new Config($config);
        $grav['locator'] = new UniformResourceLocator($resources + ['user://config' => $this->tmp . '/user/config/']);

        return $grav;
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function configuredPatterns(): iterable
    {
        yield 'none' => [null, []];
        yield 'not a list' => ['/x', []];
        yield 'trimmed scalars only' => [[' /a ', '', '  ', 7, ['x'], '/b'], ['/a', '7', '/b']];
    }

    #[DataProvider('configuredPatterns')]
    public function testIgnorePatternsAreReadFromTheMergedConfig(mixed $value, array $expected): void
    {
        $log = $value === null ? [] : ['log' => ['ignore_patterns' => $value]];
        $writer = new GravConfigWriter($this->grav(['plugins' => ['redirect-manager' => $log]]));

        self::assertSame($expected, $writer->ignorePatterns());
    }

    public function testSavingCreatesTheUserConfigFileAndUpdatesTheLiveConfig(): void
    {
        $grav = $this->grav();
        $writer = new GravConfigWriter($grav);

        $writer->saveIgnorePatterns(['/a-*', '/b']);

        $file = $this->tmp . '/user/config/plugins/redirect-manager.yaml';
        self::assertFileExists($file);
        self::assertSame(['log' => ['ignore_patterns' => ['/a-*', '/b']]], Yaml::parseFile($file));
        self::assertSame(['/a-*', '/b'], $writer->ignorePatterns(), 'the running request sees the change');
    }

    public function testSavingKeepsEverythingElseInTheFile(): void
    {
        $dir = $this->tmp . '/user/config/plugins';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/redirect-manager.yaml', Yaml::dump([
            'enabled' => true,
            'redirects' => ['max_chain_depth' => 4],
            'log' => ['retention_days' => 14, 'ignore_patterns' => ['/old']],
        ]));

        (new GravConfigWriter($this->grav()))->saveIgnorePatterns([3 => '/new']);

        self::assertSame([
            'enabled' => true,
            'redirects' => ['max_chain_depth' => 4],
            'log' => ['retention_days' => 14, 'ignore_patterns' => ['/new']],
        ], Yaml::parseFile($dir . '/redirect-manager.yaml'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableFiles(): iterable
    {
        yield 'empty file' => [''];
        yield 'scalar document' => ['just text'];
        yield 'log is not a map' => ["log: broken\nother: 1\n"];
    }

    #[DataProvider('unusableFiles')]
    public function testSavingRepairsAFileThatIsNotAUsableMap(string $content): void
    {
        $dir = $this->tmp . '/user/config/plugins';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/redirect-manager.yaml', $content);

        (new GravConfigWriter($this->grav()))->saveIgnorePatterns(['/p']);

        $data = Yaml::parseFile($dir . '/redirect-manager.yaml');
        self::assertIsArray($data);
        self::assertSame(['/p'], $data['log']['ignore_patterns']);
    }

    public function testTheActiveEnvironmentsConfigFolderWinsWhenItExists(): void
    {
        $env = $this->tmp . '/user/env/example.org/config';
        mkdir($env, 0775, true);
        $grav = $this->grav([], ['environment://config' => $env . '/']);

        (new GravConfigWriter($grav))->saveIgnorePatterns(['/env']);

        self::assertSame(['log' => ['ignore_patterns' => ['/env']]], Yaml::parseFile($env . '/plugins/redirect-manager.yaml'));
        self::assertFileDoesNotExist($this->tmp . '/user/config/plugins/redirect-manager.yaml');
    }
}
