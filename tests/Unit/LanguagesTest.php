<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * languages.yaml is the one language file of the plugin (config texts, permissions, front-end pages, issue codes
 * and the generated Admin 2 texts). English and German must carry the same keys, every text the blueprints and
 * permissions point at must exist, and the codes the importer and the rule validation report must be translated.
 */
#[CoversNothing]
#[Group('i18n')]
final class LanguagesTest extends TestCase
{
    private const PREFIX = 'PLUGIN_REDIRECT_MANAGER';

    /** @var array<string, array<string, string>>|null */
    private static ?array $flat = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array<string, string>> language => flat key => text
     */
    private static function languages(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }
        $doc = Yaml::parseFile(self::root() . '/languages.yaml');
        self::assertIsArray($doc);
        $flat = [];
        foreach (['en', 'de'] as $lang) {
            self::assertArrayHasKey($lang, $doc);
            $flat[$lang] = [];
            self::flatten($doc[$lang], '', $flat[$lang]);
        }

        return self::$flat = $flat;
    }

    /**
     * @param array<string, string> $out
     */
    private static function flatten(mixed $node, string $prefix, array &$out): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                self::flatten($value, $prefix === '' ? (string) $key : $prefix . '.' . $key, $out);
            }

            return;
        }
        $out[$prefix] = (string) $node;
    }

    public function testEnglishAndGermanHaveTheSameKeys(): void
    {
        $languages = self::languages();
        $en = array_keys($languages['en']);
        $de = array_keys($languages['de']);
        self::assertSame([], array_values(array_diff($en, $de)), 'keys missing in de');
        self::assertSame([], array_values(array_diff($de, $en)), 'keys missing in en');
    }

    public function testNoTextIsEmpty(): void
    {
        foreach (self::languages() as $lang => $texts) {
            foreach ($texts as $key => $text) {
                self::assertNotSame('', trim($text), $lang . ' ' . $key);
            }
        }
    }

    public function testEveryKeyTheBlueprintsAndPermissionsUseHasAText(): void
    {
        $used = [];
        foreach (['blueprints.yaml', 'config/permissions.yaml'] as $file) {
            preg_match_all('/' . self::PREFIX . '\.[A-Z0-9_.]+/', (string) file_get_contents(self::root() . '/' . $file), $m);
            array_push($used, ...$m[0]);
        }
        $used = array_unique($used);
        self::assertGreaterThan(40, count($used));
        foreach (self::languages() as $lang => $texts) {
            foreach ($used as $key) {
                self::assertArrayHasKey($key, $texts, $lang . ' ' . $key);
            }
        }
    }

    public function testEveryTextTheTemplatesUseHasAnEntry(): void
    {
        $used = [];
        foreach (glob(self::root() . '/templates/*/*.twig') ?: [] as $file) {
            preg_match_all('/' . self::PREFIX . '\.[A-Z0-9_.]+/', (string) file_get_contents($file), $m);
            array_push($used, ...$m[0]);
        }
        self::assertNotEmpty($used);
        foreach (self::languages() as $lang => $texts) {
            foreach (array_unique($used) as $key) {
                self::assertArrayHasKey($key, $texts, $lang . ' ' . $key);
            }
        }
    }

    public function testTheTitlesTheEventsTranslateExist(): void
    {
        foreach (self::languages() as $lang => $texts) {
            self::assertArrayHasKey(self::PREFIX . '.TITLE', $texts, $lang);
            self::assertArrayHasKey(self::PREFIX . '.WIDGET.TITLE', $texts, $lang);
        }
    }

    public function testEveryImportIssueCodeIsTranslated(): void
    {
        $codes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/classes/ImportExport', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                preg_match_all("/new ImportIssue\(\s*'([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
                array_push($codes, ...$m[1]);
            }
        }
        $codes = array_unique($codes);
        self::assertGreaterThan(40, count($codes));
        foreach (self::languages() as $lang => $texts) {
            foreach ($codes as $code) {
                self::assertArrayHasKey(self::PREFIX . '.IMPORT.ISSUE.' . strtoupper($code), $texts, $lang . ' ' . $code);
            }
        }
    }

    public function testEveryValidationCodeIsTranslated(): void
    {
        $codes = [];
        foreach (['Analysis/RuleValidator.php', 'Analysis/IssueFactory.php', 'Analysis/ChainAnalyzer.php'] as $file) {
            preg_match_all("/ValidationIssue::(?:error|warning|info)\(\s*'([a-z_]+)'/", (string) file_get_contents(self::root() . '/classes/' . $file), $m);
            array_push($codes, ...$m[1]);
        }
        foreach (['Security/TargetGuard.php', 'Security/RegexSafety.php'] as $file) {
            preg_match_all("/const ERR_[A-Z_]+ = '([a-z_]+)'/", (string) file_get_contents(self::root() . '/classes/' . $file), $m);
            array_push($codes, ...$m[1]);
        }
        $codes = array_unique($codes);
        self::assertGreaterThan(20, count($codes));
        foreach (self::languages() as $lang => $texts) {
            foreach ($codes as $code) {
                self::assertArrayHasKey(self::PREFIX . '.VALIDATION.' . strtoupper($code), $texts, $lang . ' ' . $code);
            }
        }
    }

    public function testEveryUiTextTheBundleAsksForExists(): void
    {
        // the Admin 2 bundle reads PLUGIN_REDIRECT_MANAGER.UI.<AREA>.<KEY>; admin2/src/i18n-yaml.test.ts checks the sources
        $ui = array_filter(array_keys(self::languages()['en']), static fn (string $k): bool => str_starts_with($k, self::PREFIX . '.UI.'));
        self::assertGreaterThan(500, count($ui));
    }
}
