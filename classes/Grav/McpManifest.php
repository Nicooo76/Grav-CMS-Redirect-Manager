<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\Api\Mcp\McpToolCollector;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Registers the plugin's MCP tools with the API plugin's collector at runtime (event `onApiMcpTools`).
 *
 * The manifest lives in config/mcp.yaml and not in the plugin root, where the API plugin would find it by itself:
 * GPM names a directly installed package after the first *.yaml in the ZIP root, so `mcp.yaml` there would make
 * `bin/gpm direct-install` install the plugin as "mcp" (docs/DECISIONS.md D-024). The manifest format is the API
 * plugin's own (version, prefix, tools[]), and every tool goes through the same collector validation as a tool read
 * from a root mcp.yaml: an entry that breaks a rule is dropped with a warning in `GET /mcp/tools`, never an exception.
 */
final class McpManifest
{
    /** Manifest path relative to the plugin directory. */
    public const FILE = 'config/mcp.yaml';

    /**
     * @param McpToolCollector $collector the collector of the onApiMcpTools event (any object with the same
     *                                    registerPlugin/add/warn methods works, which is what the unit tests use)
     */
    public static function register(object $collector, string $slug, string $file): void
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            $collector->warn($slug, self::FILE . ' could not be read');

            return;
        }
        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $e) {
            $collector->warn($slug, self::FILE . ' could not be parsed: ' . $e->getMessage());

            return;
        }
        if (!is_array($data) || !is_array($data['tools'] ?? null)) {
            $collector->warn($slug, self::FILE . " needs a 'tools' list");

            return;
        }
        $version = is_int($data['version'] ?? null) ? $data['version'] : 1;
        $collector->registerPlugin($slug, is_string($data['prefix'] ?? null) ? $data['prefix'] : null);
        foreach ($data['tools'] as $tool) {
            if (!is_array($tool)) {
                $collector->warn($slug, self::FILE . ' holds an entry under `tools` that is not a mapping');

                continue;
            }
            $collector->add($slug, $tool, $version);
        }
    }
}
