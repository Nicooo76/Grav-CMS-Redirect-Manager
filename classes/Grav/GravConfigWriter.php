<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\RedirectManager\App\ConfigWriter;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes `log.ignore_patterns` into the plugin's config file the way Grav reads it: user/config/plugins/redirect-manager.yaml,
 * or the file of the active environment when that environment has its own config folder (environment://config).
 * Only that one key changes; everything else in the file is kept.
 */
final class GravConfigWriter implements ConfigWriter
{
    public function __construct(private readonly Grav $grav)
    {
    }

    public function ignorePatterns(): array
    {
        /** @var Config $config */
        $config = $this->grav['config'];
        $value = $config->get('plugins.' . GravBootstrap::SLUG . '.log.ignore_patterns', []);
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    public function saveIgnorePatterns(array $patterns): void
    {
        $file = $this->file();
        $data = [];
        if (is_file($file)) {
            $parsed = Yaml::parse((string) file_get_contents($file));
            $data = is_array($parsed) ? $parsed : [];
        }
        $log = isset($data['log']) && is_array($data['log']) ? $data['log'] : [];
        $log['ignore_patterns'] = array_values($patterns);
        $data['log'] = $log;

        AtomicFile::write($file, Yaml::dump($data, 6, 2));

        /** @var Config $config */
        $config = $this->grav['config'];
        $config->set('plugins.' . GravBootstrap::SLUG . '.log.ignore_patterns', array_values($patterns));
    }

    private function file(): string
    {
        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $dir = $locator->findResource('environment://config', true);
        if (!is_string($dir) || $dir === '') {
            $dir = $locator->findResource('user://config', true, true);
        }

        return rtrim((string) $dir, '/') . '/plugins/' . GravBootstrap::SLUG . '.yaml';
    }
}
