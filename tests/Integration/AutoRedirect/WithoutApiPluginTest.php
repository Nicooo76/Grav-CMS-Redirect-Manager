<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The API and Admin 2 plugins are optional: without them the page events never fire and the rest keeps working. */
#[Group('integration')]
#[Group('auto')]
final class WithoutApiPluginTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        putenv('RM_PORT_RANGE=' . (getenv('RM_PORT_RANGE') ?: '8300-8399'));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writeFile('user/config/plugins/api.yaml', "enabled: false\n");
        $this->site()->writeFile('user/config/plugins/admin2.yaml', "enabled: false\n");
    }

    public function testFrontendRedirectsStillWork(): void
    {
        $this->rules([['id' => 'a', 'source' => '/old', 'target' => '/typography']]);

        $this->assertRedirect($this->get('/old'), 301, '/typography');
        self::assertSame(404, $this->get('/api/v1/pages')->status, 'the API is really off');
        self::assertStringNotContainsString('rror', $this->site()->gravLog());
    }

    public function testTheNotFoundLogAndTheSchedulerStillWork(): void
    {
        self::assertSame(404, $this->get('/missing')->status);
        self::assertCount(1, $this->site()->notFoundEntries());

        $process = proc_open(\Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite::phpCommand('bin/grav', 'scheduler', '--run=redirect-manager-maintenance'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->site()->dir);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertStringContainsString('Job ran successfully', $output);
    }
}
