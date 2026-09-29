<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\Support\Csv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Csv::class)]
final class CsvTest extends TestCase
{
    public function testParsesQuotesDelimitersAndMultilineFields(): void
    {
        $csv = "a,b,c\n\"x,1\",\"say \"\"hi\"\"\",\"line1\nline2\"\n\n  \n,,\nlast,,\n";
        $records = Csv::parse($csv);

        self::assertSame(['a', 'b', 'c'], $records[0]['cells']);
        self::assertSame(['x,1', 'say "hi"', "line1\nline2"], $records[1]['cells']);
        self::assertSame(['last', '', ''], $records[2]['cells'], 'blank and all-empty lines are skipped');
        self::assertSame([1, 2, 7], array_column($records, 'line'), 'multiline fields advance the line counter');
        self::assertSame("\"x,1\",\"say \"\"hi\"\"\",\"line1\nline2\"", $records[1]['raw']);
    }

    public function testBackslashIsNotAnEscapeCharacter(): void
    {
        $records = Csv::parse("a,b\n\"^/x\\\",/y\n");

        self::assertSame(['^/x\\', '/y'], $records[1]['cells']);
    }

    public function testLimit(): void
    {
        self::assertCount(2, Csv::parse("a\nb\n", ',', 2));
        $this->expectException(ImportException::class);
        Csv::parse("a\nb\nc\n", ',', 2);
    }

    public function testTruncateStopsQuietly(): void
    {
        self::assertCount(2, Csv::parse("a\nb\nc\nd\n", ',', 2, true));
    }

    public function testLimitErrorNamesReportedMax(): void
    {
        try {
            Csv::parse("a\nb\nc\n", ',', 2, false, 7);
            self::fail('expected an exception');
        } catch (ImportException $e) {
            self::assertSame('too_many_rows', $e->issue->code);
            self::assertSame(7, $e->issue->params['max']);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function delimiters(): iterable
    {
        yield 'comma' => ["source,target,status\n/a,/b,301\n/c,/d,302\n", ','];
        yield 'semicolon' => ["source;target;status\n/a;/b;301\n/c;/d?x=1,2;302\n", ';'];
        yield 'tab' => ["source\ttarget\n/a\t/b\n", "\t"];
        yield 'pipe' => ["source|target\n/a|/b\n", '|'];
        yield 'single column falls back to comma' => ["source\n/a\n", ','];
        yield 'commas inside a semicolon file' => ["/a;/b?x=1,2,3;301\n/c;/d;301\n", ';'];
        yield 'empty' => ['', ','];
    }

    #[DataProvider('delimiters')]
    public function testDetectDelimiter(string $content, string $expected): void
    {
        self::assertSame($expected, Csv::detectDelimiter($content));
    }

    public function testDetectDelimiterWithLongFiles(): void
    {
        $rows = ["source;target"];
        for ($i = 0; $i < 3000; ++$i) {
            $rows[] = "/old-{$i};/new-{$i}";
        }

        self::assertSame(';', Csv::detectDelimiter(implode("\n", $rows)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulaCells(): iterable
    {
        foreach (['=1+1', '+49 170', '-5', '@SUM(A1)', "\tcmd", "\rcmd"] as $cell) {
            yield addcslashes($cell, "\t\r") => [$cell, "'" . $cell];
        }
        yield 'plain' => ['/old', '/old'];
        yield 'empty' => ['', ''];
        yield 'apostrophe later' => ["a'=b", "a'=b"];
    }

    #[DataProvider('formulaCells')]
    public function testFormulaInjectionGuard(string $cell, string $safe): void
    {
        self::assertSame($safe, Csv::sanitize($cell));
        self::assertSame($cell, Csv::unsanitize($safe));
    }

    public function testUnsanitizeLeavesOrdinaryApostrophes(): void
    {
        self::assertSame("'quoted'", Csv::unsanitize("'quoted'"));
        self::assertSame("'", Csv::unsanitize("'"));
        self::assertSame("'a", Csv::unsanitize("'a"));
    }

    public function testWriteQuotesAndSanitizes(): void
    {
        $out = Csv::write([['=cmd', 'a,b', "say \"x\"", "two\nlines", '/plain']]);

        self::assertSame("'=cmd,\"a,b\",\"say \"\"x\"\"\",\"two\nlines\",/plain\n", $out);
        $back = Csv::parse($out);
        self::assertSame(["'=cmd", 'a,b', 'say "x"', "two\nlines", '/plain'], $back[0]['cells']);
        self::assertSame("a;b\n", Csv::write([['a', 'b']], ';'));
    }
}
