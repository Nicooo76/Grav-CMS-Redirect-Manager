<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** GravPageIndexBuilder against a real page tree, read through a small test plugin. */
#[Group('integration')]
final class PageIndexTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $lastState = [];

    private const DUMPER = <<<'PHP'
<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Plugins;
use Nyholm\Psr7\Response;

class RmIndexPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onPagesInitialized' => ['onPagesInitialized', -100]];
    }

    public function onPagesInitialized(): void
    {
        if (!str_ends_with($_SERVER['REQUEST_URI'] ?? '', '/rm-index-dump')) {
            return;
        }
        $plugin = Plugins::getPlugin('redirect-manager');
        $index = $plugin->services()->pageIndex();
        $language = $this->grav['language'];
        $page = $this->grav['pages']->find('/about');
        $state = [
            'active' => $language->getActive(),
            'about_language' => $page ? $page->language() : null,
            'about_route' => $page ? $page->route() : null,
        ];
        $this->grav->close(new Response(200, ['Content-Type' => 'application/json'], json_encode($index->toArray() + ['state' => $state])));
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeFile('user/plugins/rm-index/rm-index.php', self::DUMPER);
        $this->site()->writeFile('user/plugins/rm-index/blueprints.yaml', "name: Rm Index\nslug: rm-index\ntype: plugin\nversion: 1.0.0\ndescription: Test\ncompatibility:\n  grav: ['2.0']\n");
        $this->site()->writeFile('user/config/plugins/rm-index.yaml', "enabled: true\n");
    }

    /**
     * @return array<string, array<string, mixed>> "language|route" => page row
     */
    private function dump(string $prefix = ''): array
    {
        return $this->decoded($this->get($prefix . '/rm-index-dump'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decoded(\Grav\Plugin\RedirectManager\Tests\Integration\Support\HttpResponse $r): array
    {
        self::assertSame(200, $r->status, $r->body);
        $data = json_decode($r->body, true);
        self::assertIsArray($data);
        $this->lastState = $data['state'] ?? [];
        $rows = [];
        foreach ($data['entries'] as $entry) {
            $page = $entry['page'];
            $rows[($page['language'] ?? '-') . '|' . $page['route']] = $page;
        }

        return $rows;
    }

    public function testSingleLanguageSite(): void
    {
        $this->site()->writePage('about', 'About us', '', null, '10', "taxonomy:\n    tag: [team, news]\n");
        $rows = $this->dump();

        self::assertArrayHasKey('-|/about', $rows);
        self::assertSame('About us', $rows['-|/about']['title']);
        self::assertSame('about', $rows['-|/about']['slug']);
        self::assertNull($rows['-|/about']['language']);
        self::assertSame([], $rows['-|/about']['translations']);
        self::assertSame(['tag' => ['team', 'news']], $rows['-|/about']['taxonomy']);
        self::assertArrayHasKey('-|/typography', $rows);
        self::assertTrue($rows['-|/typography']['routable']);
    }

    public function testMultilanguageSiteMapsTranslatedRoutes(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        // one page folder, two translations; the German one has its own slug
        $this->site()->writePage('about', 'About us', '', 'en', '10');
        $this->site()->writePage('about', 'Ueber uns', '', 'de', '10', "slug: ueber\n");
        $rows = $this->dump('/en');

        self::assertArrayHasKey('en|/about', $rows);
        self::assertArrayHasKey('de|/ueber', $rows);
        self::assertSame(['de' => '/ueber'], $rows['en|/about']['translations']);
        self::assertSame(['en' => '/about'], $rows['de|/ueber']['translations']);
        self::assertSame('About us', $rows['en|/about']['title']);
        self::assertSame('Ueber uns', $rows['de|/ueber']['title'], 'the title comes from the translation itself');
    }

    public function testPagesThatExistOnlyInANonDefaultLanguageAreIndexed(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de', 'fr'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About us', '', 'en', '10');
        $this->site()->writePage('about', 'Ueber uns', '', 'de', '10', "slug: ueber\n");
        $this->site()->writePage('nur-de', 'Nur Deutsch', '', 'de', '20', "taxonomy:\n    tag: [neu]\n");
        $this->site()->writePage('seulement-fr', 'Seulement', '', 'fr', '30');
        $this->site()->writePage('entwurf', 'Entwurf', '', 'de', '35', "published: false\n");
        $this->site()->writePage('only-en', 'Only English', '', 'en', '40');
        $this->site()->writePage('neutral', 'Neutral', '', null, '50');

        $rows = $this->dump('/en');
        self::assertArrayHasKey('de|/nur-de', $rows, 'a page without an English file is in the index');
        self::assertSame('Nur Deutsch', $rows['de|/nur-de']['title']);
        self::assertSame(['tag' => ['neu']], $rows['de|/nur-de']['taxonomy']);
        self::assertSame([], $rows['de|/nur-de']['translations']);
        self::assertArrayNotHasKey('en|/nur-de', $rows);
        self::assertArrayHasKey('fr|/seulement-fr', $rows);
        self::assertArrayNotHasKey('de|/entwurf', $rows, 'unpublished pages are no redirect targets');
        self::assertArrayHasKey('en|/only-en', $rows);
        self::assertArrayNotHasKey('de|/only-en', $rows, 'English-only pages are not repeated as German fallbacks');
        self::assertArrayHasKey('en|/neutral', $rows, 'a file without language extension belongs to the default language');
        self::assertArrayNotHasKey('de|/neutral', $rows);
        self::assertSame(['de' => '/ueber'], $rows['en|/about']['translations']);
        self::assertSame(['en' => '/about'], $rows['de|/ueber']['translations']);

        // Same result whichever language the request had, and the request's own language and tree stay intact.
        self::assertSame('en', $this->lastState['active']);
        self::assertSame(['en', '/about'], [$this->lastState['about_language'], $this->lastState['about_route']]);
        foreach (glob($this->site()->dir . '/cache/redirect-manager/page-index-*.php') ?: [] as $cached) {
            unlink($cached);
        }
        $viaGerman = $this->dump('/de');
        self::assertSame(array_keys($rows), array_keys($viaGerman));
        self::assertSame('de', $this->lastState['active'], 'the German request stays German afterwards');
        self::assertSame(['de', '/ueber'], [$this->lastState['about_language'], $this->lastState['about_route']]);
    }

    public function testWorksWithGravsPageCacheEnabled(): void
    {
        $this->site()->writeSystemConfig(['cache' => ['enabled' => true, 'check' => ['method' => 'file', 'interval' => 0]], 'languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About us', '', 'en', '10');
        $this->site()->writePage('nur-de', 'Nur Deutsch', '', 'de', '20');

        $rows = $this->dump('/en');
        self::assertArrayHasKey('de|/nur-de', $rows);
        self::assertSame(['en', '/about'], [$this->lastState['about_language'], $this->lastState['about_route']]);

        $this->site()->writePage('spaeter', 'Spaeter', '', 'de', '30');
        // Grav compares folder mtimes with one second resolution: make the change unmistakable.
        foreach ([$this->site()->dir . '/user/pages', $this->site()->dir . '/user/pages/30.spaeter', $this->site()->dir . '/user/pages/30.spaeter/default.de.md'] as $path) {
            touch($path, time() + 5);
        }
        clearstatcache();
        self::assertArrayHasKey('de|/spaeter', $this->dump('/en'), 'a new German-only page shows up although Grav caches the trees');
        self::assertArrayHasKey('de|/spaeter', $this->dump('/de'));
    }

    public function testTheIndexIsCachedAndRebuiltWhenAPageAppearsInAnotherLanguage(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
        $this->site()->writePage('about', 'About us', '', 'en', '10');
        self::assertArrayNotHasKey('de|/nur-de', $this->dump('/en'));
        $files = glob($this->site()->dir . '/cache/redirect-manager/page-index-*.php') ?: [];
        self::assertCount(1, $files);

        // The English tree does not change (no page, no mtime), only a German-only page is added.
        $this->site()->writePage('nur-de', 'Nur Deutsch', '', 'de', '20');
        self::assertArrayHasKey('de|/nur-de', $this->dump('/en'));
        self::assertCount(1, glob($this->site()->dir . '/cache/redirect-manager/page-index-*.php') ?: []);
    }

    public function testTheIndexIsCachedAndRebuiltWhenPagesChange(): void
    {
        $this->site()->writePage('about', 'About us', '', null, '10');
        $this->dump();
        $files = glob($this->site()->dir . '/cache/redirect-manager/page-index-*.php') ?: [];
        self::assertCount(1, $files);
        $mtime = filemtime($files[0]);

        $this->dump();
        clearstatcache();
        self::assertSame($mtime, filemtime($files[0]), 'served from the cache');

        $this->site()->writePage('news', 'News', '', null, '11');
        $rows = $this->dump();
        self::assertArrayHasKey('-|/news', $rows);
        self::assertCount(1, glob($this->site()->dir . '/cache/redirect-manager/page-index-*.php') ?: [], 'old cache files are removed');
    }
}
