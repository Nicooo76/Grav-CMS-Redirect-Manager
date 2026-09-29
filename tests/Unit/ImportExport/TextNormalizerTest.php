<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\ImportExport\TextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextNormalizer::class)]
final class TextNormalizerTest extends TestCase
{
    public function testPlainUtf8PassesThrough(): void
    {
        $result = TextNormalizer::normalize("Grüße\nzweite Zeile");

        self::assertSame(['text' => "Grüße\nzweite Zeile", 'converted' => null, 'binary' => false], $result);
    }

    public function testBomsAndLineEndings(): void
    {
        self::assertSame("a\nb\nc\n", TextNormalizer::normalize("\xEF\xBB\xBFa\r\nb\rc\n")['text']);
        $le = TextNormalizer::normalize("\xFF\xFE" . mb_convert_encoding("ä\r\nb", 'UTF-16LE', 'UTF-8'));
        self::assertSame(["ä\nb", 'UTF-16LE'], [$le['text'], $le['converted']]);
        $be = TextNormalizer::normalize("\xFE\xFF" . mb_convert_encoding('ö', 'UTF-16BE', 'UTF-8'));
        self::assertSame(['ö', 'UTF-16BE'], [$be['text'], $be['converted']]);
    }

    public function testLegacyEncodingIsConverted(): void
    {
        $result = TextNormalizer::normalize("Gr\xFC\xDFe \x80 \x93quoted\x94");

        self::assertSame('Grüße € “quoted”', $result['text']);
        self::assertSame('Windows-1252', $result['converted']);
    }

    public function testBinaryDetection(): void
    {
        self::assertTrue(TextNormalizer::normalize("abc\0def")['binary']);
        self::assertTrue(TextNormalizer::looksBinary("\x89PNG\r\n"));
        self::assertTrue(TextNormalizer::looksBinary(str_repeat("\x01\x02", 100)));
        self::assertFalse(TextNormalizer::looksBinary(''));
        self::assertFalse(TextNormalizer::looksBinary("tabs\tand\nnewlines\r\nare fine\x0C"));
        self::assertFalse(TextNormalizer::looksBinary("Gr\xFC\xDFe aus Windows"), 'a few high bytes are legacy text, not binary');
        self::assertSame('', TextNormalizer::normalize("\x1F\x8B\x08\0")['text']);
    }
}
