<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectApplier;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectConfig;
use Grav\Plugin\RedirectManager\Auto\AutoState;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\DeleteAction;
use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(AutoRedirectApplier::class)]
#[Group('auto')]
final class AutoRedirectApplierTest extends TestCase
{
    use TempDirTrait;

    private const ANY = PageSnapshot::ANY;

    private RuleRepository $repo;
    private AutoState $state;

    /** @var list<array{string, ?string, string}> */
    private array $events = [];
    private int $invalidated = 0;
    private object $logger;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->repo = new RuleRepository($this->tmp . '/data', $clock);
        $this->state = new AutoState($this->tmp . '/data/auto-state.json', $clock);
        $this->events = [];
        $this->invalidated = 0;
        $this->logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function applier(DeletePolicy $policy = DeletePolicy::Ask, ChildrenMode $children = ChildrenMode::Wildcard): AutoRedirectApplier
    {
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->logger;

        return new AutoRedirectApplier(
            $this->repo,
            $this->state,
            new AutoRedirectConfig(StatusCode::MovedPermanently, $children, $policy),
            function (Rule $rule, ?Rule $previous, string $action): void {
                $this->events[] = [$rule->source, $previous?->target, $action];
            },
            function (): void {
                ++$this->invalidated;
            },
            $logger,
        );
    }

    private static function snap(string $title, string $route, array $children = []): PageSnapshot
    {
        $nodes = [];
        foreach ($children as $key => $childRoute) {
            $nodes[] = new PageNode($key, [self::ANY => $childRoute]);
        }

        return new PageSnapshot($title, $route, [self::ANY => $route], $nodes);
    }

    public function testMoveWritesRulesFiresEventsAndRecordsUnseen(): void
    {
        $plan = $this->applier()->move(self::snap('Blog', '/blog', ['a' => '/blog/a']), self::snap('Blog', '/news', ['a' => '/news/a']));

        self::assertCount(2, $plan->create);
        $rules = $this->repo->all();
        self::assertCount(2, $rules);
        foreach ($rules as $rule) {
            self::assertSame(RuleSource::Auto, $rule->origin);
            self::assertNotNull($rule->createdAt, 'the repository stamps created rules');
        }
        self::assertSame(1, $this->invalidated);
        self::assertEqualsCanonicalizing([['/blog', null, 'auto'], ['/blog/*', null, 'auto']], $this->events);
        self::assertEqualsCanonicalizing(array_map(static fn (Rule $r): string => $r->id, $rules), $this->state->unseen());
        self::assertStringContainsString('2 created', implode("\n", $this->logger->lines));
        self::assertStringContainsString('"Blog"', implode("\n", $this->logger->lines));
    }

    public function testUnchangedRouteLeavesRulesYamlUntouched(): void
    {
        $this->applier()->move(self::snap('A', '/a'), self::snap('A', '/a'));

        self::assertFileDoesNotExist($this->repo->file());
        self::assertSame(0, $this->invalidated);
        self::assertSame([], $this->events);
    }

    public function testChainUpdatePreservesTheIdAndFiresAnUpdateEvent(): void
    {
        $this->applier()->move(self::snap('P', '/a'), self::snap('P', '/b'));
        $first = $this->repo->all()[0];
        $this->events = [];

        $this->applier()->move(self::snap('P', '/b'), self::snap('P', '/c'));

        $byId = [];
        foreach ($this->repo->all() as $rule) {
            $byId[$rule->id] = $rule;
        }
        self::assertCount(2, $byId);
        self::assertSame('/c', $byId[$first->id]->target);
        self::assertEqualsCanonicalizing([['/a', '/b', 'auto'], ['/b', null, 'auto']], $this->events);
    }

    public function testRenameBackDeletesTheRuleAndFiresADeleteEvent(): void
    {
        $this->applier()->move(self::snap('P', '/a'), self::snap('P', '/b'));
        $id = $this->repo->all()[0]->id;
        $this->events = [];

        $this->applier()->move(self::snap('P', '/b'), self::snap('P', '/a'));

        self::assertSame([], $this->repo->all());
        self::assertSame([['/a', '/b', 'delete']], $this->events);
        self::assertNotContains($id, $this->state->unseen(), 'a deleted rule is not unseen');
    }

    public function testManualRulesAreLeftAlone(): void
    {
        $this->repo->saveAll([Rule::fromArray(['id' => 'm', 'source' => '/a', 'target' => '/elsewhere'])]);

        $plan = $this->applier()->move(self::snap('P', '/a'), self::snap('P', '/b'));

        self::assertSame([], $plan->create);
        self::assertCount(1, $this->repo->all());
        self::assertStringContainsString('conflict', implode("\n", $this->logger->lines));
    }

    public function testDeleteWithPolicyAskRecordsAPendingDecisionOnly(): void
    {
        $plan = $this->applier(DeletePolicy::Ask)->delete(self::snap('P', '/p'));

        self::assertNotNull($plan->pending);
        self::assertSame(1, $this->state->pendingCount());
        self::assertFileDoesNotExist($this->repo->file());
        self::assertSame(1, $this->state->badgeCount());
    }

    public function testDeleteWithPolicyNeverDoesNothing(): void
    {
        $this->applier(DeletePolicy::Never)->delete(self::snap('P', '/p'));

        self::assertSame(0, $this->state->pendingCount());
        self::assertFileDoesNotExist($this->repo->file());
    }

    public function testDeleteWithPolicyGoneWrites410(): void
    {
        $this->applier(DeletePolicy::Gone)->delete(self::snap('P', '/p'));

        $rules = $this->repo->all();
        self::assertCount(1, $rules);
        self::assertSame(StatusCode::Gone, $rules[0]->status);
        self::assertSame('/p', $rules[0]->source);
    }

    public function testResolvingAPendingDecision(): void
    {
        $snapshot = self::snap('P', '/p', ['a' => '/p/a']);

        $plan = $this->applier()->resolve($snapshot, DeleteAction::Redirect, '/elsewhere');

        self::assertCount(2, $plan->create);
        $sources = array_map(static fn (Rule $r): string => $r->source, $this->repo->all());
        sort($sources);
        self::assertSame(['/p', '/p/*'], $sources);
    }

    public function testAFailingListenerDoesNotUndoTheChange(): void
    {
        $applier = new AutoRedirectApplier(
            $this->repo,
            $this->state,
            new AutoRedirectConfig(),
            static function (): void {
                throw new \RuntimeException('boom');
            },
        );

        $applier->move(self::snap('P', '/a'), self::snap('P', '/b'));

        self::assertCount(1, $this->repo->all());
    }

    public function testEventNamesMatchTheRuleEventsConstants(): void
    {
        self::assertSame('auto', RuleEvents::ACTION_AUTO);
        self::assertSame('delete', RuleEvents::ACTION_DELETE);
    }
}
