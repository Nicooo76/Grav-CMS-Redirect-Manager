<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML parsing for untrusted files: no objects, no PHP constants, no custom tags, bounded nesting.
 * Anything Symfony would turn into an object or constant throws instead of being instantiated.
 */
final class SafeYaml
{
    public const MAX_FLOW_DEPTH = 64;
    public const MAX_INDENT = 128;

    public static function parse(string $content): mixed
    {
        self::guardDepth($content);
        try {
            // Flags: exception on invalid types (objects, constants, unknown tags). No PARSE_OBJECT,
            // PARSE_OBJECT_FOR_MAP, PARSE_CONSTANT or PARSE_CUSTOM_TAGS.
            return Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new ImportException(new ImportIssue('invalid_yaml', 'The file is not valid YAML: ' . $e->getMessage()));
        } catch (\Throwable) {
            throw new ImportException(new ImportIssue('too_deep', 'The YAML is nested too deeply.'));
        }
    }

    /**
     * Symfony parses nested structures recursively, and nested blocks cost memory quadratically (the
     * remaining block is copied at every level). Real rule files stay a few levels deep, so anything
     * beyond generous limits is refused before parsing.
     */
    private static function guardDepth(string $content): void
    {
        $tooDeep = new ImportException(new ImportIssue('too_deep', 'The YAML is nested too deeply.', ['max' => self::MAX_FLOW_DEPTH]));

        if (preg_match('/(?:-[ \t]+){' . (self::MAX_FLOW_DEPTH + 1) . '}|^ {' . (self::MAX_INDENT + 1) . ',}\S/m', $content) === 1) {
            throw $tooDeep;
        }

        $length = strlen($content);
        $depth = 0;
        $pos = 0;
        while (($pos += strcspn($content, '[]{}', $pos)) < $length) {
            if ($content[$pos] === '[' || $content[$pos] === '{') {
                if (++$depth > self::MAX_FLOW_DEPTH) {
                    throw $tooDeep;
                }
            } elseif ($depth > 0) {
                --$depth;
            }
            ++$pos;
        }
    }
}
