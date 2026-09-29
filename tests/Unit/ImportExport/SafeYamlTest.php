<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use ErrorException;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\Support\SafeYaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SafeYaml::class)]
#[Group('import')]
final class SafeYamlTest extends TestCase
{
    public function testParsesPlainDocuments(): void
    {
        self::assertSame(['version' => 1, 'rules' => [['id' => 'a', 'source' => '/x']]], SafeYaml::parse("version: 1\nrules:\n  - {id: a, source: /x}\n"));
        self::assertNull(SafeYaml::parse(''));
        self::assertSame('text', SafeYaml::parse('text'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'object tag' => ["a: !php/object 'O:8:\"stdClass\":0:{}'\n", 'invalid_yaml'];
        yield 'constant tag' => ["a: !php/const PHP_INT_MAX\n", 'invalid_yaml'];
        yield 'undefined alias' => ["a: *nope\n", 'invalid_yaml'];
        yield 'duplicate key' => ["a: 1\na: 2\n", 'invalid_yaml'];
        yield 'several documents' => ["---\na: 1\n---\nb: 2\n", 'invalid_yaml'];
        yield 'broken flow' => ["rules: [broken\n", 'invalid_yaml'];
        yield 'invalid utf-8' => ["a: \xff\xfe\n", 'invalid_yaml'];
    }

    #[DataProvider('invalidProvider')]
    public function testUnsafeOrBrokenYamlThrowsAnImportException(string $yaml, string $code): void
    {
        try {
            SafeYaml::parse($yaml);
            self::fail('Expected an ImportException.');
        } catch (ImportException $e) {
            self::assertSame($code, $e->issue->code);
            self::assertStringStartsWith('The file is not valid YAML: ', $e->issue->message);
        }
    }

    public function testFlowNestingBeyondTheLimitIsRefusedBeforeParsing(): void
    {
        $ok = str_repeat('[', SafeYaml::MAX_FLOW_DEPTH) . str_repeat(']', SafeYaml::MAX_FLOW_DEPTH);
        self::assertIsArray(SafeYaml::parse($ok));

        foreach (['[', '{'] as $open) {
            try {
                SafeYaml::parse(str_repeat($open, SafeYaml::MAX_FLOW_DEPTH + 1));
                self::fail('Expected an ImportException.');
            } catch (ImportException $e) {
                self::assertSame('too_deep', $e->issue->code);
                self::assertSame(['max' => SafeYaml::MAX_FLOW_DEPTH], $e->issue->params);
            }
        }
    }

    public function testClosingBracketsResetTheDepthCounter(): void
    {
        // Two levels deep however many pairs follow each other.
        $parsed = SafeYaml::parse('[' . implode(',', array_fill(0, 200, '[1]')) . ']');

        self::assertIsArray($parsed);
        self::assertCount(200, $parsed);
    }

    public function testBlockNestingBeyondTheLimitIsRefused(): void
    {
        try {
            SafeYaml::parse(str_repeat('- ', SafeYaml::MAX_FLOW_DEPTH + 1) . "x\n");
            self::fail('Expected an ImportException.');
        } catch (ImportException $e) {
            self::assertSame('too_deep', $e->issue->code);
        }
    }

    public function testIndentationBeyondTheLimitIsRefused(): void
    {
        $yaml = "a:\n" . str_repeat(' ', SafeYaml::MAX_INDENT + 1) . "b: 1\n";

        try {
            SafeYaml::parse($yaml);
            self::fail('Expected an ImportException.');
        } catch (ImportException $e) {
            self::assertSame('too_deep', $e->issue->code);
        }
    }

    public function testIndentationAtTheLimitIsParsed(): void
    {
        $yaml = "a:\n" . str_repeat(' ', SafeYaml::MAX_INDENT) . "b: 1\n";

        self::assertSame(['a' => ['b' => 1]], SafeYaml::parse($yaml));
    }

    public function testAnythingThatIsNotAParseExceptionIsReportedAsTooDeep(): void
    {
        // A host that turns PHP notices and deprecations into exceptions (Grav does in debug mode) makes the
        // parser fail with an ErrorException. Symfony deprecates a repeated key that has an empty value.
        set_error_handler(static function (int $no, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $no, $file, $line);
        });
        try {
            SafeYaml::parse("x:\nx: 1\n");
            self::fail('Expected an ImportException.');
        } catch (ImportException $e) {
            self::assertSame('too_deep', $e->issue->code);
            self::assertSame('The YAML is nested too deeply.', $e->issue->message);
        } finally {
            restore_error_handler();
        }
    }
}
