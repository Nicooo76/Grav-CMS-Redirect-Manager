<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use PHPUnit\Framework\Attributes\Group;

/** Two languages with translated slugs: rules are limited to the language whose route changed. */
#[Group('integration')]
#[Group('auto')]
final class MultilanguageChangeTest extends AutoRedirectTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->multilanguage();
        $this->page('03.about', 'About', '', 'en');
        $this->page('03.about', 'Über uns', "slug: ueber\n", 'de');
        $this->page('03.about/team', 'Team', '', 'en');
        $this->page('03.about/team', 'Team', "slug: mannschaft\n", 'de');
    }

    public function testSiteServesBothLanguagesUnderTheirOwnSlug(): void
    {
        self::assertSame(200, $this->get('/en/about')->status);
        self::assertSame(200, $this->get('/de/ueber')->status);
        self::assertSame(200, $this->get('/de/ueber/mannschaft')->status);
    }

    public function testRenamingOneLanguageLimitsTheRuleToIt(): void
    {
        $this->setSlug('/about', 'about-us', 'en');

        self::assertSame(['/about -> /about-us [en]', '/about/* -> /about-us/$1 [en]'], $this->autoRules());
        $this->assertRedirect($this->get('/en/about'), 301, '/en/about-us');
        $this->assertRedirect($this->get('/en/about/team'), 301, '/en/about-us/team');
        self::assertNull($this->get('/de/ueber')->header('x-redirect-by'), 'German is unchanged');
        self::assertSame(200, $this->get('/de/ueber')->status);
        self::assertSame(200, $this->get('/en/about-us')->status);
    }

    public function testRenamingBothLanguagesGivesEachItsOwnRule(): void
    {
        $this->setSlug('/about', 'about-us', 'en');
        $this->setSlug('/about', 'ueber-uns', 'de');

        self::assertSame([
            '/about -> /about-us [en]',
            '/about/* -> /about-us/$1 [en]',
            '/ueber -> /ueber-uns [de]',
            '/ueber/* -> /ueber-uns/$1 [de]',
        ], $this->autoRules());
        $this->assertRedirect($this->get('/en/about'), 301, '/en/about-us');
        $this->assertRedirect($this->get('/de/ueber'), 301, '/de/ueber-uns');
        $this->assertRedirect($this->get('/de/ueber/mannschaft'), 301, '/de/ueber-uns/mannschaft');
        self::assertSame(200, $this->get('/de/ueber-uns/mannschaft')->status);
    }

    public function testRenamingBackInOneLanguageRemovesOnlyItsRules(): void
    {
        $this->setSlug('/about', 'about-us', 'en');
        $this->setSlug('/about', 'ueber-uns', 'de');

        $this->setSlug('/about', 'about', 'en');

        self::assertSame(['/ueber -> /ueber-uns [de]', '/ueber/* -> /ueber-uns/$1 [de]'], $this->autoRules());
        self::assertSame(200, $this->get('/en/about')->status);
        $this->assertRedirect($this->get('/de/ueber'), 301, '/de/ueber-uns');
    }

    public function testMovingUnderAParentWithTranslatedSlugs(): void
    {
        $this->page('04.company', 'Company', '', 'en');
        $this->page('04.company', 'Firma', "slug: firma\n", 'de');

        $this->movePage('/about', '/company');

        self::assertSame([
            '/about -> /company/about [en]',
            '/about/* -> /company/about/$1 [en]',
            '/ueber -> /firma/ueber [de]',
            '/ueber/* -> /firma/ueber/$1 [de]',
        ], $this->autoRules());
        $this->assertRedirect($this->get('/en/about'), 301, '/en/company/about');
        $this->assertRedirect($this->get('/de/ueber'), 301, '/de/firma/ueber');
        $this->assertRedirect($this->get('/de/ueber/mannschaft'), 301, '/de/firma/ueber/mannschaft');
        self::assertSame(200, $this->get('/de/firma/ueber/mannschaft')->status);
    }

    public function testIdenticalRoutesInBothLanguagesShareOneRule(): void
    {
        $this->page('05.services', 'Services', '', 'en');
        $this->page('05.services', 'Leistungen', '', 'de');
        $this->page('06.company', 'Company', '', 'en');
        $this->page('06.company', 'Firma', '', 'de');

        $this->movePage('/services', '/company');

        self::assertSame(['/services -> /company/services'], $this->autoRules());
        $this->assertRedirect($this->get('/en/services'), 301, '/en/company/services');
        $this->assertRedirect($this->get('/de/services'), 301, '/de/company/services');
    }

    public function testReorganizeKeepsTranslatedSlugsPerLanguage(): void
    {
        $this->page('04.company', 'Company', '', 'en');
        $this->page('04.company', 'Firma', "slug: firma\n", 'de');

        $response = $this->api->post('/pages/reorganize', ['operations' => [['route' => '/about', 'parent' => '/company']]]);
        self::assertSame(200, $response->status, $response->describe());

        self::assertSame([
            '/about -> /company/about [en]',
            '/about/* -> /company/about/$1 [en]',
            '/ueber -> /firma/ueber [de]',
            '/ueber/* -> /firma/ueber/$1 [de]',
        ], $this->autoRules());
    }

    public function testDeletingOneLanguageOfAPageThatKeepsOthersDoesNothing(): void
    {
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => 'gone']]);

        $this->deletePage('/about', 'de');

        self::assertSame([], $this->autoRules());
        self::assertSame(200, $this->get('/en/about')->status);
    }

    public function testDeletingTheWholePageInBothLanguagesAppliesThePolicyPerLanguage(): void
    {
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => 'gone']]);

        $this->deletePage('/about');

        self::assertSame([
            '/about -> (none) [en] 410',
            '/about/* -> (none) [en] 410',
            '/ueber -> (none) [de] 410',
            '/ueber/* -> (none) [de] 410',
        ], $this->autoRules());
        self::assertSame(410, $this->get('/en/about')->status);
        self::assertSame(410, $this->get('/de/ueber')->status);
        self::assertSame(410, $this->get('/de/ueber/mannschaft')->status);
    }
}
