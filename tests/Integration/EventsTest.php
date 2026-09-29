<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** Other plugins can listen to onRedirectMatched (cancel or replace the result) and onNotFoundLogged. */
#[Group('integration')]
final class EventsTest extends IntegrationTestCase
{
    private const LISTENER = <<<'PHP'
<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\RedirectManager\Domain\MatchResult;

class RmEventsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onRedirectMatched' => ['onMatched', 0], 'onNotFoundLogged' => ['onLogged', 0]];
    }

    public function onMatched($event): void
    {
        $result = $event['result'];
        $this->write('matched:' . $result->rule->id . ':' . $result->location . ':' . $event['context']->path);
        if ($result->rule->id === 'cancelme') {
            $event['cancel'] = true;
        }
        if ($result->rule->id === 'replaceme') {
            $event['result'] = new MatchResult($result->rule, $result->status, '/typography', $result->rules);
        }
    }

    public function onLogged($event): void
    {
        $this->write('logged:' . $event['entry']->path);
    }

    private function write(string $line): void
    {
        file_put_contents(GRAV_ROOT . '/logs/rm-events.log', $line . "\n", FILE_APPEND);
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeFile('user/plugins/rm-events/rm-events.php', self::LISTENER);
        $this->site()->writeFile('user/plugins/rm-events/rm-events.yaml', "enabled: true\n");
        $this->site()->writeFile('user/plugins/rm-events/blueprints.yaml', "name: Rm Events\nslug: rm-events\ntype: plugin\nversion: 1.0.0\ndescription: Test listener\ncompatibility:\n  grav: ['2.0']\n");
        $this->site()->writeFile('user/config/plugins/rm-events.yaml', "enabled: true\n");
    }

    private function events(): string
    {
        return $this->site()->readFile('logs/rm-events.log') ?? '';
    }

    public function testOnRedirectMatchedFiresWithResultAndContext(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);
        $this->assertRedirect($this->get('/old'), 301, '/typography');
        self::assertStringContainsString("matched:a:/typography:/old\n", $this->events());
    }

    public function testAListenerCanCancelARedirect(): void
    {
        $this->rules([['id' => 'cancelme', 'source' => '/cancel', 'target' => '/typography']]);
        $r = $this->get('/cancel');
        self::assertSame(404, $r->status);
        self::assertNull($r->header('x-redirect-by'));
        self::assertStringContainsString('matched:cancelme', $this->events());
        self::assertSame([], $this->site()->recordedHits(), 'a cancelled redirect is not a hit');
    }

    public function testAListenerCanReplaceTheResult(): void
    {
        $this->rules([['id' => 'replaceme', 'source' => '/rep', 'target' => '/home']]);
        $this->assertRedirect($this->get('/rep'), 301, '/typography');
    }

    public function testOnNotFoundLoggedFires(): void
    {
        self::assertSame(404, $this->get('/nothing-there')->status);
        self::assertStringContainsString("logged:/nothing-there\n", $this->events());

        $before = $this->events();
        $this->get('/wp-login.php');
        self::assertSame($before, $this->events(), 'ignored paths do not fire the event');
    }

    public function testOnRedirectMatchedAlsoFiresForNotFoundRules(): void
    {
        $this->rules([['id' => 'nf', 'source' => '/late', 'target' => '/typography', 'only_if_not_found' => true]]);
        $this->assertRedirect($this->get('/late'), 301, '/typography');
        self::assertStringContainsString("matched:nf:/typography:/late\n", $this->events());
    }
}
