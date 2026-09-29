<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Notify;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Notify\Digest;
use Grav\Plugin\RedirectManager\Notify\DigestBuilder;
use Grav\Plugin\RedirectManager\Notify\DigestData;
use Grav\Plugin\RedirectManager\Notify\DigestPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DigestBuilder::class)]
#[CoversClass(DigestData::class)]
#[CoversClass(Digest::class)]
final class DigestBuilderTest extends TestCase
{
    private function full(DigestPeriod $period = DigestPeriod::Weekly): DigestData
    {
        $paths = [];
        for ($i = 1; $i <= 12; ++$i) {
            $paths['/missing-' . $i] = 100 - $i;
        }

        return new DigestData(
            period: $period,
            from: new DateTimeImmutable('2026-09-22 00:00:00'),
            to: new DateTimeImmutable('2026-09-29 00:00:00'),
            siteName: 'Example Shop',
            siteUrl: 'https://shop.example',
            notFoundTotal: 1234,
            notFoundTopPaths: $paths,
            newPaths: 7,
            redirectHits: 45678,
            topRules: [
                ['id' => 'r1', 'source' => '/old-shop', 'hits' => 900],
                ['id' => 'r2', 'source' => '/blog/*', 'hits' => 12],
            ],
            openSuggestions: 3,
            deadTargets: [
                ['source' => '/a', 'target' => 'https://gone.example/x', 'status' => 404],
                ['source' => '/b', 'target' => 'https://slow.example/', 'error' => 'timeout'],
                ['source' => '/c', 'target' => '/c2', 'status' => 500, 'error' => 'connection'],
            ],
            adminUrl: 'https://shop.example/admin/redirect-manager',
        );
    }

    private function empty(DigestPeriod $period = DigestPeriod::Daily): DigestData
    {
        return new DigestData($period, new DateTimeImmutable('2026-09-29 00:00:00'), new DateTimeImmutable('2026-09-29 23:59:59'), '', 'https://shop.example', 0);
    }

    public function testEnglishWeeklyDigest(): void
    {
        $d = (new DigestBuilder())->build($this->full());

        self::assertSame('Weekly redirect report for Example Shop: 1,234 404 requests', $d->subject);
        self::assertStringContainsString('Redirect report for Example Shop', $d->text);
        self::assertStringContainsString('Weekly report, 2026-09-22 to 2026-09-29', $d->text);
        self::assertMatchesRegularExpression('/404 requests:\s+1,234/', $d->text);
        self::assertMatchesRegularExpression('/New 404 paths:\s+7/', $d->text);
        self::assertMatchesRegularExpression('/Redirect hits:\s+45,678/', $d->text);
        self::assertMatchesRegularExpression('/Open suggestions:\s+3/', $d->text);
        self::assertMatchesRegularExpression('/Dead targets:\s+3/', $d->text);
        self::assertStringContainsString('1. /missing-1 (99 hits)', $d->text);
        self::assertStringContainsString('10. /missing-10 (90 hits)', $d->text);
        self::assertStringNotContainsString('/missing-11', $d->text, 'top 10 only');
        self::assertStringContainsString('1. /old-shop (900 hits)', $d->text);
        self::assertStringContainsString('- /a -> https://gone.example/x (status 404)', $d->text);
        self::assertStringContainsString('- /b -> https://slow.example/ (error timeout)', $d->text);
        self::assertStringContainsString('(status 500, error connection)', $d->text);
        self::assertStringContainsString("Open Redirect Manager:\nhttps://shop.example/admin/redirect-manager", $d->text);
        self::assertStringContainsString('Sent by Redirect Manager for Example Shop.', $d->text);
        self::assertStringEndsWith("\n", $d->text);
    }

    public function testGermanDailyDigest(): void
    {
        $d = (new DigestBuilder())->build($this->full(DigestPeriod::Daily), 'de_DE');

        self::assertSame('Tagesbericht Weiterleitungen für Example Shop: 1.234 404-Aufrufe', $d->subject);
        self::assertStringContainsString('Tagesbericht, 2026-09-22 bis 2026-09-29', $d->text);
        self::assertMatchesRegularExpression('/Weiterleitungen ausgeführt:\s+45\.678/', $d->text);
        self::assertStringContainsString('Am häufigsten aufgerufene fehlende Pfade', $d->text);
        self::assertStringContainsString('1. /missing-1 (99 Aufrufe)', $d->text);
        self::assertStringContainsString('(Status 404)', $d->text);
        self::assertStringContainsString('(Fehler timeout)', $d->text);
        self::assertStringContainsString('Redirect Manager öffnen:', $d->text);
        self::assertStringContainsString('<html lang="de">', $d->html);
        self::assertStringContainsString('Weiterleitungsziele, die nicht antworten', $d->html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function locales(): array
    {
        return [
            'de' => ['de', 'Tagesbericht'],
            'de-AT' => ['de-AT', 'Tagesbericht'],
            'DE upper' => ['DE', 'Tagesbericht'],
            'en' => ['en', 'Daily redirect report'],
            'en_GB' => ['en_GB', 'Daily redirect report'],
            'unknown falls back to en' => ['fr', 'Daily redirect report'],
            'empty falls back to en' => ['', 'Daily redirect report'],
        ];
    }

    #[DataProvider('locales')]
    public function testLocaleSelection(string $locale, string $subjectStart): void
    {
        $d = (new DigestBuilder())->build($this->full(DigestPeriod::Daily), $locale);

        self::assertStringStartsWith($subjectStart, $d->subject);
    }

    public function testHtmlIsCompleteAndUsesInlineStylesOnly(): void
    {
        $d = (new DigestBuilder())->build($this->full());

        self::assertStringStartsWith('<!DOCTYPE html>', $d->html);
        self::assertStringContainsString('<html lang="en">', $d->html);
        self::assertStringContainsString('<meta charset="utf-8">', $d->html);
        self::assertStringNotContainsString('<style', $d->html);
        self::assertStringNotContainsString('<script', $d->html);
        self::assertStringContainsString('<a href="https://shop.example/admin/redirect-manager"', $d->html);
        self::assertStringContainsString('<a href="https://shop.example"', $d->html);
        self::assertStringContainsString('1,234', $d->html);
        self::assertStringContainsString('/old-shop', $d->html);
        self::assertStringContainsString('(status 404)', $d->html);

        $dom = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML($d->html);
        $problems = array_filter(libxml_get_errors(), static fn (\LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        self::assertSame([], array_values($problems), 'HTML parses without errors');
    }

    public function testEverythingFromTheDataIsEscapedInHtml(): void
    {
        $evil = '<script>alert("x")</script>';
        $data = new DigestData(
            period: DigestPeriod::Daily,
            from: new DateTimeImmutable('2026-09-29'),
            to: new DateTimeImmutable('2026-09-29'),
            siteName: 'Shop <b>& "Co"</b>',
            siteUrl: 'https://shop.example/"><script>alert(1)</script>',
            notFoundTotal: 1,
            notFoundTopPaths: ['/' . $evil => 5, "/it's?a=1&b=2" => 2],
            topRules: [['id' => 'r', 'source' => $evil, 'hits' => 1]],
            deadTargets: [['source' => $evil, 'target' => '<img src=x onerror=alert(1)>', 'error' => '<i>err</i>']],
            adminUrl: 'https://shop.example/admin?a=1&b="2"',
        );
        $d = (new DigestBuilder())->build($data);

        self::assertStringNotContainsString('<script', $d->html);
        self::assertStringNotContainsString('<img', $d->html);
        self::assertStringNotContainsString('<b>', $d->html);
        self::assertStringNotContainsString('<i>', $d->html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $d->html);
        self::assertStringContainsString('Shop &lt;b&gt;&amp; &quot;Co&quot;&lt;/b&gt;', $d->html);
        self::assertStringContainsString('/it&#039;s?a=1&amp;b=2', $d->html);
        // A link whose URL holds a quote or angle bracket is dropped instead of being escaped into an attribute.
        self::assertStringNotContainsString('href=', $d->html);
        self::assertStringNotContainsString('admin?a=1', $d->text);
    }

    public function testNonHttpLinksAreNotEmitted(): void
    {
        $data = new DigestData(DigestPeriod::Daily, new DateTimeImmutable('2026-09-29'), new DateTimeImmutable('2026-09-29'), 'S', 'javascript:alert(1)', 0, adminUrl: 'data:text/html,x');
        $d = (new DigestBuilder())->build($data);

        self::assertStringNotContainsString('href=', $d->html);
        self::assertStringNotContainsString('javascript:', $d->html);
        self::assertStringNotContainsString('data:text', $d->text);
        self::assertStringNotContainsString('Open Redirect Manager', $d->text);
    }

    public function testControlCharactersCannotInjectLines(): void
    {
        $data = new DigestData(
            DigestPeriod::Daily,
            new DateTimeImmutable('2026-09-29'),
            new DateTimeImmutable('2026-09-29'),
            "Site\r\nBcc: attacker@example.org",
            'https://s.example',
            1,
            ["/a\nInjected: yes" => 1],
        );
        $d = (new DigestBuilder())->build($data);

        self::assertStringNotContainsString("\r", $d->subject);
        self::assertStringNotContainsString("\n", $d->subject);
        self::assertStringContainsString('Site Bcc: attacker@example.org', $d->subject);
        self::assertStringNotContainsString("\nInjected", $d->text);
        self::assertStringContainsString('/a Injected: yes', $d->text);
    }

    public function testEmptyData(): void
    {
        $d = (new DigestBuilder())->build($this->empty());

        self::assertSame('Daily redirect report for https://shop.example: 0 404 requests', $d->subject);
        self::assertStringContainsString('Daily report, 2026-09-29', $d->text);
        self::assertSame(3, substr_count($d->text, 'None.'));
        self::assertMatchesRegularExpression('/404 requests:\s+0/', $d->text);
        self::assertStringNotContainsString('Open Redirect Manager', $d->text, 'no admin URL, no link');
        self::assertSame(3, substr_count($d->html, 'None.'));
        self::assertStringNotContainsString('<li', $d->html);
    }

    public function testEmptyDataGerman(): void
    {
        $d = (new DigestBuilder())->build($this->empty(), 'de');

        self::assertSame(3, substr_count($d->text, 'Keine.'));
    }

    public function testTextIsWrappedAt76Characters(): void
    {
        $long = '/' . str_repeat('very-long-path-segment/', 6) . 'end';
        $data = new DigestData(
            DigestPeriod::Weekly,
            new DateTimeImmutable('2026-09-22'),
            new DateTimeImmutable('2026-09-29'),
            'A site with a rather long display name that goes on and on and on past the limit',
            'https://s.example',
            2,
            [$long => 2, '/short' => 1],
            deadTargets: [['source' => '/from', 'target' => 'https://target.example/' . str_repeat('x', 100), 'status' => 404]],
            adminUrl: 'https://s.example/admin/plugins/redirect-manager/with/a/really/long/path/that/must/not/be/cut/at-all',
        );
        $d = (new DigestBuilder())->build($data);

        foreach (explode("\n", $d->text) as $line) {
            if (str_starts_with($line, 'https://s.example/admin')) {
                continue;
            }
            self::assertLessThanOrEqual(76, mb_strlen($line), 'line too long: ' . $line);
        }
        self::assertStringContainsString("\n  1. /very-long-path-segment/", $d->text);
        self::assertStringContainsString('(1 hit)', $d->text);
        self::assertStringContainsString('(2 hits)', $d->text);
        self::assertStringContainsString("\n     ", $d->text, 'continuation lines are indented under the number');
        self::assertStringContainsString('https://s.example/admin/plugins/redirect-manager/with/a/really/long/path/that/must/not/be/cut/at-all', $d->text, 'URLs on their own line stay whole');
        self::assertStringContainsString("Redirect report for A site with a rather long display name that goes on and\non and on past the limit", $d->text);
    }

    public function testWrapIsMultibyteSafe(): void
    {
        $data = new DigestData(
            DigestPeriod::Daily,
            new DateTimeImmutable('2026-09-29'),
            new DateTimeImmutable('2026-09-29'),
            'Bäckerei',
            'https://s.example',
            1,
            ['/' . str_repeat('ü', 120) => 1],
        );
        $d = (new DigestBuilder())->build($data);

        self::assertTrue(mb_check_encoding($d->text, 'UTF-8'));
        foreach (explode("\n", $d->text) as $line) {
            self::assertLessThanOrEqual(76, mb_strlen($line));
        }
    }

    public function testShortNumbersAndPeriodWithSingleDay(): void
    {
        $d = (new DigestBuilder())->build($this->empty(DigestPeriod::Weekly));

        self::assertStringStartsWith('Weekly redirect report', $d->subject);
        self::assertStringContainsString('Weekly report, 2026-09-29', $d->text);
    }
}
