<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** Language prefixes on a two-language site (en, de; the default language is in the URL). */
#[Group('integration')]
final class MultilanguageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About', 'About us', 'en', '10');
        $this->site()->writePage('ueber', 'Ueber uns', 'Ueber uns', 'de', '10');
    }

    public function testTheLanguagePrefixIsKeptOnInternalTargets(): void
    {
        $this->rules([['id' => 'a', 'source' => '/alt', 'target' => '/neu']]);
        $this->assertRedirect($this->get('/de/alt'), 301, '/de/neu');
        $this->assertRedirect($this->get('/en/alt'), 301, '/en/neu');
        $this->assertRedirect($this->get('/DE/alt'), 301, '/de/neu', 'prefix detection ignores case like Grav');
    }

    public function testQueryStringAndHomeTarget(): void
    {
        $this->rules([
            ['id' => 'q', 'source' => '/alt', 'target' => '/neu', 'query_mode' => 'pass'],
            ['id' => 'h', 'source' => '/start', 'target' => '/'],
        ]);
        $this->assertRedirect($this->get('/de/alt?a=1'), 301, '/de/neu?a=1');
        $this->assertRedirect($this->get('/de/start'), 301, '/de');
    }

    public function testATargetWithItsOwnPrefixIsNotPrefixedAgain(): void
    {
        $this->rules([['id' => 'a', 'source' => '/legacy', 'target' => '/en/about']]);
        $this->assertRedirect($this->get('/de/legacy'), 301, '/en/about');
    }

    public function testKeepLanguagePrefixCanBeSwitchedOff(): void
    {
        $this->site()->writePluginConfig(['redirects' => ['keep_language_prefix' => false]]);
        $this->rules([['id' => 'a', 'source' => '/alt', 'target' => '/neu']]);
        $this->assertRedirect($this->get('/de/alt'), 301, '/neu');
    }

    public function testLangPlaceholder(): void
    {
        $this->rules([['id' => 'a', 'source' => '/alt', 'target' => '/{lang}/neu']]);
        $this->assertRedirect($this->get('/de/alt'), 301, '/de/neu');
        $this->assertRedirect($this->get('/en/alt'), 301, '/en/neu');
    }

    public function testLanguageCondition(): void
    {
        $this->rules([['id' => 'only-de', 'source' => '/nur-de', 'target' => '/neu', 'conditions' => ['languages' => ['de']]]]);
        $this->assertRedirect($this->get('/de/nur-de'), 301, '/de/neu');
        $this->assertNotRedirected($this->get('/en/nur-de'));
    }

    public function testRulesNeverSeeThePrefix(): void
    {
        $this->rules([['id' => 'with-prefix', 'source' => '/de/alt', 'target' => '/neu']]);
        $this->assertNotRedirected($this->get('/de/alt'), 'sources are language-free');
    }

    public function testOnlyIfNotFoundRulesKeepThePrefixToo(): void
    {
        $this->rules([['id' => 'nf', 'source' => '/weg', 'target' => '/neu', 'only_if_not_found' => true]]);
        $r = $this->get('/de/weg');
        $this->assertRedirect($r, 301, '/de/neu');
        self::assertFalse($r->has('set-cookie'));
    }

    public function testThe404LogStoresLanguageAndLanguageFreePath(): void
    {
        self::assertSame(404, $this->get('/de/gibt-es-nicht')->status);
        $entries = $this->site()->notFoundEntries();
        self::assertCount(1, $entries);
        self::assertSame('/gibt-es-nicht', $entries[0]['p']);
        self::assertSame('de', $entries[0]['l']);
    }

    public function testExistingTranslatedPagesStillWork(): void
    {
        $this->rules([['id' => 'a', 'source' => '/alt', 'target' => '/neu']]);
        self::assertSame(200, $this->get('/en/about')->status);
        self::assertSame(200, $this->get('/de/ueber')->status);
    }

    public function testPassThroughOnAMultilanguageSite(): void
    {
        $this->rules([['id' => 'p', 'source' => '/alias', 'target' => '/about', 'status' => 200]]);
        $r = $this->get('/en/alias');
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString('About us', $r->body);
    }

    public function testGoneTemplateInTheRequestLanguage(): void
    {
        $this->rules([['id' => 'g', 'source' => '/weg', 'target' => '', 'status' => 410]]);
        self::assertStringContainsString('Diese Seite wurde entfernt', $this->get('/de/weg')->body);
        self::assertStringContainsString('This page has been removed', $this->get('/en/weg')->body);
    }
}
