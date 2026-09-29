<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Jobs;

use Grav\Plugin\RedirectManager\Check\CheckResultStore;
use Grav\Plugin\RedirectManager\Check\TargetCheckResult;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Jobs\DigestJob;
use Grav\Plugin\RedirectManager\Notify\Digest;
use Grav\Plugin\RedirectManager\Notify\DigestBuilder;
use Grav\Plugin\RedirectManager\Notify\DigestPeriod;
use Grav\Plugin\RedirectManager\Stats\HitRecorder;
use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(DigestJob::class)]
#[Group('jobs')]
final class DigestJobTest extends JobsTestCase
{
    /** @var list<array{string, Digest}> */
    private array $sent = [];

    private function job(string $recipient = 'editor@example.org', bool $sendOk = true, string $locale = 'en'): DigestJob
    {
        $rules = new RuleRepository($this->tmp . '/data', $this->clock);
        $rules->saveAll([
            Rule::fromArray(['id' => 'r1', 'source' => '/old-shop', 'target' => '/shop']),
            Rule::fromArray(['id' => 'r2', 'source' => '/gone', 'target' => 'https://dead.example/x']),
            Rule::fromArray(['id' => 'r3', 'source' => '/unused', 'target' => '/y']),
        ]);
        (new CheckResultStore($this->tmp . '/data/target-checks.json'))->save([
            new TargetCheckResult('r2', 'https://dead.example/x', 404, false, null, null, 0, 10, $this->clock->now()),
        ]);

        return new DigestJob(
            $this->log,
            new StatsStore($this->tmp . '/data/stats.json', $this->tmp . '/data/hits', $this->clock),
            $rules,
            new SuggestionStore($this->tmp . '/data/suggestions.json', $this->clock),
            new CheckResultStore($this->tmp . '/data/target-checks.json'),
            $this->clock,
            new DigestBuilder(),
            function (string $to, Digest $digest) use ($sendOk): bool {
                $this->sent[] = [$to, $digest];

                return $sendOk;
            },
            $recipient,
            $locale,
            'Example Shop',
            'https://shop.example',
            'https://shop.example/admin/plugin/redirect-manager',
        );
    }

    private function seed(): void
    {
        $this->hits('/missing-a', 5);
        $this->hits('/missing-b', 2);
        $this->hit('/older', '2026-09-20T10:00:00+00:00');
        $recorder = new HitRecorder($this->tmp . '/data/hits', $this->clock);
        for ($i = 0; $i < 4; $i++) {
            $recorder->record('r1');
        }
        $recorder->record('r3');
    }

    public function testCollectsTheDailyNumbers(): void
    {
        $this->seed();
        (new SuggestionStore($this->tmp . '/data/suggestions.json', $this->clock))->upsertOpen('/missing-a', new Suggestion('/shop', 0.8, SuggestionReason::SimilarRoute, 'Shop'));

        $data = $this->job()->collect(DigestPeriod::Daily);

        self::assertSame(7, $data->notFoundTotal);
        self::assertSame(['/missing-a' => 5, '/missing-b' => 2], $data->notFoundTopPaths);
        self::assertSame(2, $data->newPaths, 'the path from 9 days ago is not new in the last day');
        self::assertSame(5, $data->redirectHits);
        self::assertSame([['id' => 'r1', 'source' => '/old-shop', 'hits' => 4], ['id' => 'r3', 'source' => '/unused', 'hits' => 1]], $data->topRules);
        self::assertSame(1, $data->openSuggestions);
        self::assertSame([['source' => '/gone', 'target' => 'https://dead.example/x', 'status' => 404, 'error' => null]], $data->deadTargets);
        self::assertSame('Example Shop', $data->siteName);
        self::assertSame('https://shop.example/admin/plugin/redirect-manager', $data->adminUrl);
        self::assertSame('2026-09-28T12:00:00+00:00', $data->from->format(DATE_ATOM));
    }

    public function testWeeklyReachesFurtherBack(): void
    {
        $this->seed();
        $this->hit('/six-days', '2026-09-23T10:00:00+00:00');

        $data = $this->job()->collect(DigestPeriod::Weekly);

        self::assertSame(8, $data->notFoundTotal);
        self::assertSame(3, $data->newPaths);
    }

    public function testSendsTheBuiltMailToTheRecipient(): void
    {
        $this->seed();

        $result = $this->job(locale: 'de')->run(DigestPeriod::Daily);

        self::assertTrue($result['sent']);
        self::assertNull($result['reason']);
        self::assertCount(1, $this->sent);
        [$to, $digest] = $this->sent[0];
        self::assertSame('editor@example.org', $to);
        self::assertSame($result['subject'], $digest->subject);
        self::assertStringContainsString('Tagesbericht', $digest->subject);
        self::assertStringContainsString('/missing-a', $digest->text);
        self::assertStringContainsString('/missing-a', $digest->html);
        self::assertStringContainsString('/old-shop', $digest->text);
    }

    public function testWeeklySubject(): void
    {
        $this->job()->run(DigestPeriod::Weekly);

        self::assertStringContainsString('Weekly', $this->sent[0][1]->subject);
    }

    public function testNoRecipientNoMail(): void
    {
        $result = $this->job(recipient: '  ')->run(DigestPeriod::Daily);

        self::assertFalse($result['sent']);
        self::assertSame('no_recipient', $result['reason']);
        self::assertSame([], $this->sent);
    }

    public function testFailedSendIsReported(): void
    {
        $result = $this->job(sendOk: false)->run(DigestPeriod::Daily);

        self::assertFalse($result['sent']);
        self::assertSame('send_failed', $result['reason']);
    }

    public function testAnEmptyDayStillProducesAMail(): void
    {
        $result = $this->job()->run(DigestPeriod::Daily);

        self::assertTrue($result['sent']);
        self::assertStringContainsString('0', $this->sent[0][1]->subject);
    }
}
