<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\NotFound\JsonlLogStore;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Tests\Integration\IntegrationTestCase;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\CaptureServer;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\TestSite;
use Grav\Plugin\RedirectManager\Util\SystemClock;

/**
 * Runs the plugin's scheduler jobs through Grav's own CLI (`bin/grav scheduler --run=<job id>`) inside the temp site.
 */
abstract class SchedulerTestCase extends IntegrationTestCase
{
    protected ?CaptureServer $capture = null;

    public static function setUpBeforeClass(): void
    {
        putenv('RM_PORT_RANGE=' . (getenv('RM_PORT_RANGE') ?: '8300-8399'));
        parent::setUpBeforeClass();
    }

    protected function tearDown(): void
    {
        $this->capture?->stop();
        $this->capture = null;
        parent::tearDown();
    }

    protected function capture(int $status = 200): CaptureServer
    {
        return $this->capture ??= new CaptureServer($status);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{code: int, output: string}
     */
    protected function grav(array $args, array $env = []): array
    {
        $site = $this->site();
        $process = proc_open(
            TestSite::phpCommand('bin/grav', ...$args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $site->dir,
            array_merge(getenv(), ['REDIRECT_MANAGER_WEBHOOK_SECRET' => ''], $env),
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'output' => $output];
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{code: int, output: string}
     */
    protected function runJob(string $id, array $env = []): array
    {
        $result = $this->grav(['scheduler', '--run=' . $id], $env);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('Job ran successfully', $result['output'], $result['output']);

        return $result;
    }

    protected function logStore(): JsonlLogStore
    {
        return new JsonlLogStore($this->site()->dataDir() . '/404', new SystemClock());
    }

    protected function hit404(string $path, ?DateTimeImmutable $when = null, string $referer = ''): void
    {
        $this->logStore()->append(new NotFoundEntry($when ?? new DateTimeImmutable('-30 minutes'), $path, '', $referer, 'Mozilla/5.0', UserAgentClass::Browser, null, null, '127.0.0.1'));
    }

    protected function hits404(string $path, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->hit404($path);
        }
    }

    protected function recordRuleHits(string $ruleId, int $count): void
    {
        $recorder = new HitRecorder($this->site()->dataDir() . '/hits', new SystemClock());
        for ($i = 0; $i < $count; $i++) {
            $recorder->record($ruleId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function readJson(string $relative): array
    {
        $raw = $this->site()->readFile($relative);
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
