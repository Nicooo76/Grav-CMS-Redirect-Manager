<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use PHPUnit\Framework\Attributes\Group;

/** Automatic changes announce themselves through onRedirectRuleSaved with action "auto" (and "delete"). */
#[Group('integration')]
#[Group('auto')]
final class RuleEventsTest extends AutoRedirectTestCase
{
    private const LISTENER = <<<'PHP'
<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;

class RmSavedPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onRedirectRuleSaved' => ['onSaved', 0]];
    }

    public function onSaved($event): void
    {
        $previous = $event['previous'];
        file_put_contents(GRAV_ROOT . '/logs/rm-saved.log', implode('|', [
            $event['action'],
            $event['rule']->source,
            $event['rule']->target,
            $event['rule']->origin->value,
            $previous === null ? '-' : $previous->target,
        ]) . "\n", FILE_APPEND);
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $site = $this->site();
        $site->writeFile('user/plugins/rm-saved/rm-saved.php', self::LISTENER);
        $site->writeFile('user/plugins/rm-saved/rm-saved.yaml', "enabled: true\n");
        $site->writeFile('user/plugins/rm-saved/blueprints.yaml', "name: Rm Saved\nslug: rm-saved\ntype: plugin\nversion: 1.0.0\ndescription: Test listener\ncompatibility:\n  grav: ['2.0']\n");
        $site->writeFile('user/config/plugins/rm-saved.yaml', "enabled: true\n");
        $this->page('03.blog', 'Blog');
        $this->page('03.blog/post', 'Post');
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function sorted(array $lines): array
    {
        sort($lines);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function saved(): array
    {
        $lines = file($this->site()->dir . '/logs/rm-saved.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        sort($lines);

        return $lines;
    }

    public function testCreatedUpdatedAndDeletedRulesAreAnnounced(): void
    {
        $this->setSlug('/blog', 'news');
        self::assertSame(self::sorted(['auto|/blog|/news|auto|-', 'auto|/blog/*|/news/$1|auto|-']), $this->saved());

        @unlink($this->site()->dir . '/logs/rm-saved.log');
        $this->setSlug('/blog', 'press');
        self::assertSame(self::sorted([
            'auto|/blog|/press|auto|/news',
            'auto|/blog/*|/press/$1|auto|/news/$1',
            'auto|/news|/press|auto|-',
            'auto|/news/*|/press/$1|auto|-',
        ]), $this->saved());

        @unlink($this->site()->dir . '/logs/rm-saved.log');
        $this->setSlug('/blog', 'blog');
        $lines = $this->saved();
        self::assertCount(2, array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'delete|')), 'the /blog rules of the way back are dropped');
        self::assertContains('auto|/news|/blog|auto|/press', $lines, 'the old /news redirect follows the page back');
    }
}
