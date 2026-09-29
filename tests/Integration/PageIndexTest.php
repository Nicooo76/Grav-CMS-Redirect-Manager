<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** GravPageIndexBuilder against a real page tree, read through a small test plugin. */
#[Group('integration')]
final class PageIndexTest extends IntegrationTestCase
{
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
        $this->grav->close(new Response(200, ['Content-Type' => 'application/json'], json_encode($index->toArray())));
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
        $r = $this->get($prefix . '/rm-index-dump');
        self::assertSame(200, $r->status, $r->body);
        $data = json_decode($r->body, true);
        self::assertIsArray($data);
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
