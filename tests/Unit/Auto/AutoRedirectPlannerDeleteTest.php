<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\AutoRedirectPlanner;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\DeleteAction;
use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PlanNote;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(AutoRedirectPlanner::class)]
#[Group('auto')]
final class AutoRedirectPlannerDeleteTest extends PlannerTestCase
{
    private const ANY = PageSnapshot::ANY;

    public function testPolicyNeverDoesNothing(): void
    {
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p']), [], DeletePolicy::Never);

        self::assertTrue($plan->isEmpty());
    }

    public function testPolicyAskKeepsAPendingDecision(): void
    {
        $snapshot = self::snap('P', [self::ANY => '/p']);
        $plan = $this->planner()->planDelete($snapshot, [], DeletePolicy::Ask);

        self::assertSame($snapshot, $plan->pending);
        self::assertSame([], $plan->create);
        self::assertFalse($plan->isEmpty());
    }

    public function testConfiguredPolicyIsTheDefault(): void
    {
        $plan = $this->planner(policy: DeletePolicy::Gone)->planDelete(self::snap('P', [self::ANY => '/p']), []);

        self::assertSame(['/p -> (none) 410'], self::created($plan));
    }

    public function testGoneCreatesExactAndWildcard410ForDescendants(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('Blog', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a']]),
            [],
            DeletePolicy::Gone,
        );

        self::assertSame(['/blog -> (none) 410', '/blog/* -> (none) 410'], self::created($plan));
        foreach ($plan->create as $rule) {
            self::assertSame(StatusCode::Gone, $rule->status);
            self::assertSame('', $rule->target);
        }
    }

    public function testGoneLeafHasNoWildcard(): void
    {
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p']), [], DeletePolicy::Gone);

        self::assertCount(1, $plan->create);
        self::assertSame(MatchType::Exact, $plan->create[0]->matchType);
    }

    public function testGoneWithChildrenModeEachCreatesARuleForEveryDescendant(): void
    {
        $plan = $this->planner(ChildrenMode::Each)->planDelete(
            self::snap('Blog', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a'], 'b' => [self::ANY => '/blog/b']]),
            [],
            DeletePolicy::Gone,
        );

        self::assertSame(['/blog -> (none) 410', '/blog/a -> (none) 410', '/blog/b -> (none) 410'], self::created($plan));
    }

    public function testParentRedirectsToTheNearestParent(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('Post', [self::ANY => '/blog/2026/post'], ancestors: [self::ANY => ['/blog/2026', '/blog']]),
            [],
            DeletePolicy::Parent,
        );

        self::assertSame(['/blog/2026/post -> /blog/2026'], self::created($plan));
        self::assertSame(StatusCode::MovedPermanently, $plan->create[0]->status);
        self::assertSame(TargetType::Page, $plan->create[0]->targetType);
    }

    public function testParentSkipsAncestorsThatDoNotExist(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('Post', [self::ANY => '/blog/2026/post'], ancestors: [self::ANY => ['/blog/2026', '/blog']]),
            [],
            DeletePolicy::Parent,
            static fn (string $route, string $language): bool => $route === '/blog',
        );

        self::assertSame(['/blog/2026/post -> /blog'], self::created($plan));
    }

    public function testTopLevelPageFallsBackToTheHomePage(): void
    {
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p']), [], DeletePolicy::Parent);

        self::assertSame(['/p -> /'], self::created($plan));
    }

    public function testParentWithDescendantsUsesAWildcardToTheSameParent(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('Sec', [self::ANY => '/blog/sec'], ['a' => [self::ANY => '/blog/sec/a']], [self::ANY => ['/blog']]),
            [],
            DeletePolicy::Parent,
        );

        self::assertSame(['/blog/sec -> /blog', '/blog/sec/* -> /blog'], self::created($plan));
    }

    public function testParentPerLanguageWithTranslatedSlugs(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('P', ['en' => '/blog/post', 'de' => '/artikel/beitrag'], ancestors: ['en' => ['/blog'], 'de' => ['/artikel']]),
            [],
            DeletePolicy::Parent,
        );

        self::assertSame(['/artikel/beitrag -> /artikel [de]', '/blog/post -> /blog [en]'], self::created($plan));
    }

    public function testIdenticalRoutesAndParentsShareOneRule(): void
    {
        $plan = $this->planner()->planDelete(
            self::snap('P', ['en' => '/blog/post', 'de' => '/blog/post'], ancestors: ['en' => ['/blog'], 'de' => ['/blog']]),
            [],
            DeletePolicy::Gone,
        );

        self::assertSame(['/blog/post -> (none) 410'], self::created($plan));
    }

    public function testHomePageAndRootRouteNeverGetARule(): void
    {
        $planner = $this->planner();
        self::assertTrue($planner->planDelete(self::snap('Home', [self::ANY => '/home'], home: true), [], DeletePolicy::Gone)->isEmpty());
        self::assertTrue($planner->planDelete(self::snap('Home', [self::ANY => '/']), [], DeletePolicy::Gone)->isEmpty());
        self::assertTrue($planner->planDelete(self::snap('Home', [self::ANY => '/home'], home: true), [], DeletePolicy::Ask)->isEmpty());
    }

    public function testAnExistingRuleForTheSourceIsKept(): void
    {
        $existing = [self::rule('m', '/p', '/elsewhere')];
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p']), $existing, DeletePolicy::Gone);

        self::assertSame([], $plan->create);
        self::assertSame(PlanNote::CONFLICT, $plan->notes[0]->kind);
    }

    public function testAnExistingAutoRuleForTheSourceIsKeptToo(): void
    {
        $existing = [self::auto('a', '/p', '/elsewhere')];
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p']), $existing, DeletePolicy::Gone);

        self::assertSame([], $plan->create);
        self::assertSame(PlanNote::EXISTS, $plan->notes[0]->kind);
    }

    public function testGoneTurnsAutoRulesThatPointedAtThePageInto410(): void
    {
        $existing = [
            self::auto('r1', '/old-p', '/p'),
            self::auto('r2', '/old-a', '/p/a'),
            self::rule('manual', '/m', '/p', ['target_type' => 'route']),
        ];
        $plan = $this->planner()->planDelete(self::snap('P', [self::ANY => '/p'], ['a' => [self::ANY => '/p/a']]), $existing, DeletePolicy::Gone);

        self::assertSame(['r1', 'r2'], array_keys($plan->update));
        foreach ($plan->update as $rule) {
            self::assertSame(StatusCode::Gone, $rule->status);
            self::assertSame('', $rule->target);
        }
    }

    public function testParentRetargetsAutoRulesThatPointedAtThePage(): void
    {
        $existing = [self::auto('r1', '/old-p', '/blog/p')];
        $plan = $this->planner()->planDelete(
            self::snap('P', [self::ANY => '/blog/p'], ancestors: [self::ANY => ['/blog']]),
            $existing,
            DeletePolicy::Parent,
        );

        self::assertSame('/blog', $plan->update['r1']->target);
        self::assertSame(['/blog/p -> /blog'], self::created($plan));
    }

    public function testRetargetedRuleThatWouldPointAtItselfIsRemoved(): void
    {
        $existing = [self::auto('r1', '/blog', '/blog/p')];
        $plan = $this->planner()->planDelete(
            self::snap('P', [self::ANY => '/blog/p'], ancestors: [self::ANY => ['/blog']]),
            $existing,
            DeletePolicy::Parent,
        );

        self::assertSame(['r1'], $plan->delete);
    }

    public function testResolveWithAChosenTarget(): void
    {
        $snapshot = self::snap('P', [self::ANY => '/p'], ['a' => [self::ANY => '/p/a']]);
        $plan = $this->planner()->planDeleteAction($snapshot, [], DeleteAction::Redirect, '/new-home');

        self::assertSame(['/p -> /new-home', '/p/* -> /new-home'], self::created($plan));
    }

    public function testResolveWithAnExternalTarget(): void
    {
        $plan = $this->planner()->planDeleteAction(self::snap('P', [self::ANY => '/p']), [], DeleteAction::Redirect, 'https://example.org/p');

        self::assertSame(TargetType::Url, $plan->create[0]->targetType);
        self::assertSame('https://example.org/p', $plan->create[0]->target);
    }

    public function testRedirectToTheSameRouteCreatesNothing(): void
    {
        $plan = $this->planner()->planDeleteAction(self::snap('P', [self::ANY => '/p']), [], DeleteAction::Redirect, '/p');

        self::assertTrue($plan->isEmpty());
    }

    public function testRedirectWithoutTargetCreatesNothing(): void
    {
        self::assertTrue($this->planner()->planDeleteAction(self::snap('P', [self::ANY => '/p']), [], DeleteAction::Redirect, null)->isEmpty());
    }

    public function testResolveGoneMatchesThePolicy(): void
    {
        $snapshot = self::snap('P', [self::ANY => '/p']);

        self::assertEquals(
            self::created($this->planner()->planDelete($snapshot, [], DeletePolicy::Gone)),
            self::created($this->planner()->planDeleteAction($snapshot, [], DeleteAction::Gone)),
        );
    }

    public function testConfiguredStatusAppliesToParentRedirects(): void
    {
        $plan = $this->planner(status: StatusCode::Found)->planDelete(self::snap('P', [self::ANY => '/p']), [], DeletePolicy::Parent);

        self::assertSame(StatusCode::Found, $plan->create[0]->status);
    }
}
