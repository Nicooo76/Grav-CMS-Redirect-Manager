<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * blueprints.yaml (the settings form of Admin 2), redirect-manager.yaml (the shipped defaults), languages.yaml
 * (the texts of the form) and the package metadata must agree. A config key without a form field is invisible to
 * the admin; a form field without a config key or with another default shows a value the plugin does not use.
 */
#[CoversNothing]
#[Group('i18n')]
final class BlueprintConfigParityTest extends TestCase
{
    /** Config keys that are deliberately not in the form: measuring aid, see the comment in redirect-manager.yaml. */
    private const HIDDEN_KEYS = ['debug_timing'];

    /**
     * Form fields without a `default`: the value comes from the config file. All are empty in redirect-manager.yaml
     * except the query parameters that are ignored by default (asserted in testShippedDefaults).
     */
    private const FIELDS_WITHOUT_DEFAULT = [
        'redirects.query_ignore', 'redirects.excluded_paths', 'security.allowed_hosts', 'log.ignore_patterns',
        'base_url', 'notifications.webhook_url', 'notifications.webhook_secret', 'notifications.email_to',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $blueprint = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function blueprint(): array
    {
        if (self::$blueprint === null) {
            $doc = Yaml::parseFile(self::root() . '/blueprints.yaml');
            self::assertIsArray($doc);
            self::$blueprint = $doc;
        }

        return self::$blueprint;
    }

    /**
     * Every leaf field of the form: dotted config key => field definition. Containers (tabs, tab) are walked into.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(): array
    {
        $out = [];
        $walk = static function (array $fields) use (&$walk, &$out): void {
            foreach ($fields as $key => $field) {
                self::assertIsArray($field, (string) $key);
                if (isset($field['fields'])) {
                    $walk($field['fields']);

                    continue;
                }
                self::assertArrayNotHasKey((string) $key, $out, 'field declared twice');
                $out[(string) $key] = $field;
            }
        };
        $form = self::blueprint()['form'];
        self::assertIsArray($form);
        $walk($form['fields']);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $doc = Yaml::parseFile(self::root() . '/redirect-manager.yaml');
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * Leaf values of the config file: dotted key => value. Lists and empty maps count as leaves.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $out
     *
     * @return array<string, mixed>
     */
    private static function leaves(array $node, string $prefix = '', array &$out = []): array
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                self::leaves($value, $path, $out);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /** A toggle says 1/0 where the YAML says true/false: compare as text of the scalar. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        self::assertIsScalar($value);

        return (string) $value;
    }

    public function testEveryFormFieldHasAConfigKeyWithTheSameDefault(): void
    {
        $leaves = self::leaves(self::config());
        $checked = 0;
        foreach (self::fields() as $key => $field) {
            self::assertArrayHasKey($key, $leaves, $key . ' is in the form but not in redirect-manager.yaml');
            if (array_key_exists('default', $field)) {
                self::assertSame(self::scalar($field['default']), self::scalar($leaves[$key]), $key . ': the form default differs from the shipped default');
                ++$checked;
            } else {
                self::assertContains($key, self::FIELDS_WITHOUT_DEFAULT, $key . ' has no default in the form');
            }
        }
        self::assertGreaterThan(35, $checked);
    }

    public function testEveryConfigKeyHasAFormField(): void
    {
        $fields = self::fields();
        foreach (array_keys(self::leaves(self::config())) as $key) {
            if (in_array($key, self::HIDDEN_KEYS, true)) {
                continue;
            }
            self::assertArrayHasKey($key, $fields, $key . ' is in redirect-manager.yaml but has no form field (invisible to the admin)');
        }
        // The hidden keys are still documented in the config file.
        $raw = (string) file_get_contents(self::root() . '/redirect-manager.yaml');
        foreach (self::HIDDEN_KEYS as $key) {
            self::assertStringContainsString($key . ':', $raw);
        }
    }

    public function testTheEnabledToggleAndTheSectionKeys(): void
    {
        $fields = self::fields();
        self::assertSame('toggle', $fields['enabled']['type']);
        self::assertSame(1, $fields['enabled']['default']);
        self::assertTrue(self::config()['enabled']);
        foreach (array_keys($fields) as $key) {
            self::assertTrue(in_array($key, ['enabled', 'base_url'], true) || str_contains($key, '.'), $key . ' is neither a top-level setting nor a section.key');
        }
    }

    public function testFieldDefaultsSatisfyTheirOwnConstraints(): void
    {
        foreach (self::fields() as $key => $field) {
            if (!array_key_exists('default', $field)) {
                continue;
            }
            if (isset($field['options'])) {
                self::assertContains(self::scalar($field['default']), array_map(self::scalar(...), array_keys($field['options'])), $key . ': the default is not one of the options');
            }
            $validate = $field['validate'] ?? [];
            if (isset($validate['min'])) {
                self::assertGreaterThanOrEqual($validate['min'], $field['default'], $key);
            }
            if (isset($validate['max'])) {
                self::assertLessThanOrEqual($validate['max'], $field['default'], $key);
            }
        }
    }

    public function testEveryTextTheBlueprintUsesExistsInEnglishAndGerman(): void
    {
        preg_match_all('/PLUGIN_REDIRECT_MANAGER\.[A-Z0-9_.]+/', (string) file_get_contents(self::root() . '/blueprints.yaml'), $m);
        $used = array_unique($m[0]);
        self::assertGreaterThan(60, count($used));

        $doc = Yaml::parseFile(self::root() . '/languages.yaml');
        self::assertIsArray($doc);
        foreach (['en', 'de'] as $lang) {
            $texts = [];
            $flatten = static function (mixed $node, string $prefix) use (&$flatten, &$texts): void {
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        $flatten($v, $prefix === '' ? (string) $k : $prefix . '.' . $k);
                    }

                    return;
                }
                $texts[$prefix] = (string) $node;
            };
            $flatten($doc[$lang], '');
            foreach ($used as $key) {
                self::assertArrayHasKey($key, $texts, $lang . ' ' . $key);
                self::assertNotSame('', trim($texts[$key]), $lang . ' ' . $key);
            }
        }
        foreach (self::fields() as $key => $field) {
            self::assertArrayHasKey('label', $field, $key . ' has no label');
        }
    }

    public function testShippedDefaults(): void
    {
        $c = self::leaves(self::config());
        $expected = [
            'enabled' => true,
            'debug_timing' => false,
            'redirects.enabled' => true,
            'redirects.default_status' => 0,
            'redirects.keep_language_prefix' => true,
            'redirects.cache_control_permanent' => 'public, max-age=3600',
            'redirects.cache_control_temporary' => 'no-store',
            'redirects.max_chain_depth' => 10,
            'redirects.query_ignore' => ['utm_*', 'fbclid', 'gclid', 'msclkid'],
            'redirects.excluded_paths' => [],
            'redirects.disable_expired' => false,
            'security.allowed_hosts' => [],
            'security.allow_any_external' => false,
            'security.trust_proxy_headers' => false,
            'security.regex_backtrack_limit' => 100000,
            'log.enabled' => true,
            'log.backend' => 'jsonl',
            'log.retention_days' => 30,
            'log.max_size_mb' => 50,
            'log.log_bots' => true,
            'log.ip_mode' => 'anonymize',
            'log.use_default_ignores' => true,
            'log.ignore_patterns' => [],
            'auto_redirect.enabled' => true,
            'auto_redirect.status' => 301,
            'auto_redirect.children' => 'wildcard',
            'auto_redirect.on_delete' => 'ask',
            'suggestions.min_score' => 0.5,
            'suggestions.bulk_accept_score' => 0.9,
            'base_url' => '',
            'checker.enabled' => true,
            'checker.schedule' => '30 3 * * 0',
            'checker.timeout' => 5,
            'checker.max_per_run' => 500,
            'checker.check_external' => true,
            'checker.manual_interval' => 300,
            'notifications.webhook_url' => '',
            'notifications.webhook_secret' => '',
            'notifications.not_found_threshold' => 25,
            'notifications.dead_targets' => true,
            'notifications.email_digest' => 'none',
            'notifications.email_to' => '',
            'stats.keep_days' => 90,
            'stats.unused_days' => 180,
            'import.max_mb' => 10,
        ];
        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $c, $key);
            self::assertSame($value, $c[$key], $key);
        }
        self::assertSame([], array_diff_key($c, $expected), 'a shipped default without an assertion: add it above');
    }

    public function testTheShippedDefaultsAreTheSafeOnes(): void
    {
        $c = self::config();
        self::assertFalse($c['security']['allow_any_external'], 'external targets stay behind the allow list');
        self::assertSame([], $c['security']['allowed_hosts']);
        self::assertFalse($c['security']['trust_proxy_headers'], 'forwarded headers are ignored until the admin says so');
        self::assertSame('anonymize', $c['log']['ip_mode']);
        self::assertSame('ask', $c['auto_redirect']['on_delete'], 'a deleted page is never turned into a 410 or a redirect silently');
    }

    // ---- package metadata --------------------------------------------------------------------------

    public function testCompatibilityAndDependencies(): void
    {
        $b = self::blueprint();
        self::assertSame(['2.0'], $b['compatibility']['grav']);
        $deps = [];
        foreach ($b['dependencies'] as $dep) {
            $deps[$dep['name']] = $dep['version'];
        }
        self::assertSame(['grav' => '>=2.1.5', 'php' => '>=8.3'], $deps);
        self::assertSame('plugin', $b['type']);
        self::assertSame('redirect-manager', $b['slug']);
    }

    public function testTheVersionMatchesTheTopEntryOfTheChangelog(): void
    {
        $b = self::blueprint();
        self::assertSame('1.0.0', $b['version']);
        $changelog = (string) file_get_contents(self::root() . '/CHANGELOG.md');
        self::assertSame(1, preg_match('/^# v(\S+)\s*$/m', $changelog, $m), 'CHANGELOG.md has no "# v<version>" heading');
        self::assertSame($b['version'], $m[1], 'blueprints.yaml and the first CHANGELOG entry disagree');
    }

    public function testComposerRequiresThePhpTheBlueprintPromises(): void
    {
        $composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertMatchesRegularExpression('/8\.3/', (string) $composer['require']['php']);
    }
}
