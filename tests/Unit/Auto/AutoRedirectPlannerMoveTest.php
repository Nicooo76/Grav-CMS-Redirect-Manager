<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\AutoRedirectPlanner;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PlanNote;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(AutoRedirectPlanner::class)]
#[Group('auto')]
final class AutoRedirectPlannerMoveTest extends PlannerTestCase
{
    private const ANY = PageSnapshot::ANY;

    public function testRenameCreatesOneExactRule(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('About', [self::ANY => '/about']),
            self::snap('About', [self::ANY => '/about-us']),
            [],
        );

        self::assertSame(['/about -> /about-us'], self::created($plan));
        $rule = $plan->create[0];
        self::assertSame(RuleSource::Auto, $rule->origin);
        self::assertSame(MatchType::Exact, $rule->matchType);
        self::assertSame(StatusCode::MovedPermanently, $rule->status);
        self::assertSame(TargetType::Page, $rule->targetType);
        self::assertTrue($rule->enabled);
        self::assertSame([], $rule->conditions->languages);
        self::assertStringContainsString('About', $rule->note);
        self::assertSame([], $plan->update);
        self::assertSame([], $plan->delete);
    }

    public function testMoveToAnotherParent(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('Post', [self::ANY => '/blog/post']),
            self::snap('Post', [self::ANY => '/news/post']),
            [],
        );

        self::assertSame(['/blog/post -> /news/post'], self::created($plan));
    }

    public function testConfiguredStatusIsUsed(): void
    {
        $plan = $this->planner(status: StatusCode::PermanentRedirect)->planMove(
            self::snap('A', [self::ANY => '/a']),
            self::snap('A', [self::ANY => '/b']),
            [],
        );

        self::assertSame(StatusCode::PermanentRedirect, $plan->create[0]->status);
    }

    public function testNothingChangedNothingPlanned(): void
    {
        $same = self::snap('A', [self::ANY => '/a'], ['x' => [self::ANY => '/a/x']]);

        self::assertTrue($this->planner()->planMove($same, $same, [])->isEmpty());
    }

    public function testRouteThatDiffersOnlyInCaseIsNoChange(): void
    {
        $plan = $this->planner()->planMove(self::snap('A', [self::ANY => '/About']), self::snap('A', [self::ANY => '/about']), []);

        self::assertTrue($plan->isEmpty());
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $plan = $this->planner()->planMove(self::snap('A', [self::ANY => '/a/']), self::snap('A', [self::ANY => '/b/']), []);

        self::assertSame(['/a -> /b'], self::created($plan));
    }

    public function testHomePageAndRootRouteNeverGetARule(): void
    {
        $planner = $this->planner();
        self::assertTrue($planner->planMove(self::snap('Home', [self::ANY => '/home'], home: true), self::snap('Home', [self::ANY => '/start'], home: true), [])->isEmpty());
        self::assertTrue($planner->planMove(self::snap('Home', [self::ANY => '/']), self::snap('Home', [self::ANY => '/start']), [])->isEmpty());
        self::assertTrue($planner->planMove(self::snap('X', [self::ANY => '/x']), self::snap('X', [self::ANY => '/']), [])->isEmpty());
    }

    public function testChildrenModeWildcardAddsOneWildcardRule(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('Blog', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a'], 'a/b' => [self::ANY => '/blog/a/b']]),
            self::snap('Blog', [self::ANY => '/news'], ['a' => [self::ANY => '/news/a'], 'a/b' => [self::ANY => '/news/a/b']]),
            [],
        );

        self::assertSame(['/blog -> /news', '/blog/* -> /news/$1'], self::created($plan));
        $wildcard = null;
        foreach ($plan->create as $rule) {
            if ($rule->matchType === MatchType::Wildcard) {
                $wildcard = $rule;
            }
        }
        self::assertNotNull($wildcard);
        self::assertSame(TargetType::Route, $wildcard->targetType);
    }

    public function testLeafPageGetsNoWildcard(): void
    {
        $plan = $this->planner()->planMove(self::snap('A', [self::ANY => '/a']), self::snap('A', [self::ANY => '/b']), []);

        self::assertCount(1, $plan->create);
    }

    public function testChildrenModeEachAddsOneRulePerDescendant(): void
    {
        $plan = $this->planner(ChildrenMode::Each)->planMove(
            self::snap('Blog', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a'], 'a/b' => [self::ANY => '/blog/a/b']]),
            self::snap('Blog', [self::ANY => '/news'], ['a' => [self::ANY => '/news/a'], 'a/b' => [self::ANY => '/news/a/b']]),
            [],
        );

        self::assertSame(['/blog -> /news', '/blog/a -> /news/a', '/blog/a/b -> /news/a/b'], self::created($plan));
        foreach ($plan->create as $rule) {
            self::assertSame(MatchType::Exact, $rule->matchType);
        }
    }

    public function testChildrenModeEachFallsBackToWildcardForHugeSubtrees(): void
    {
        $before = [];
        $after = [];
        for ($i = 0; $i <= AutoRedirectPlanner::EACH_LIMIT; $i++) {
            $before['c' . $i] = [self::ANY => '/old/c' . $i];
            $after['c' . $i] = [self::ANY => '/new/c' . $i];
        }
        $plan = $this->planner(ChildrenMode::Each)->planMove(self::snap('Old', [self::ANY => '/old'], $before), self::snap('Old', [self::ANY => '/new'], $after), []);

        self::assertSame(['/old -> /new', '/old/* -> /new/$1'], self::created($plan));
        self::assertSame(PlanNote::SKIPPED, $plan->notes[0]->kind);
    }

    public function testDescendantThatDisappearedIsSkippedInEachMode(): void
    {
        $plan = $this->planner(ChildrenMode::Each)->planMove(
            self::snap('Blog', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a'], 'gone' => [self::ANY => '/blog/gone']]),
            self::snap('Blog', [self::ANY => '/news'], ['a' => [self::ANY => '/news/a']]),
            [],
        );

        self::assertSame(['/blog -> /news', '/blog/a -> /news/a'], self::created($plan));
    }

    public function testMultilanguageWithDifferentTranslatedSlugs(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('About', ['en' => '/about', 'de' => '/ueber']),
            self::snap('About', ['en' => '/about-us', 'de' => '/ueber-uns']),
            [],
        );

        self::assertSame(['/about -> /about-us [en]', '/ueber -> /ueber-uns [de]'], self::created($plan));
    }

    public function testMultilanguageWithIdenticalRoutesGetsOneRuleWithoutCondition(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('About', ['en' => '/about', 'de' => '/about']),
            self::snap('About', ['en' => '/about-us', 'de' => '/about-us']),
            [],
        );

        self::assertSame(['/about -> /about-us'], self::created($plan));
    }

    public function testMultilanguageOnlyOneLanguageChangedIsLimitedToIt(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('About', ['en' => '/about', 'de' => '/ueber']),
            self::snap('About', ['en' => '/about-us', 'de' => '/ueber']),
            [],
        );

        self::assertSame(['/about -> /about-us [en]'], self::created($plan));
    }

    public function testThreeLanguagesTwoShareARule(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('P', ['en' => '/p', 'de' => '/p', 'fr' => '/pf']),
            self::snap('P', ['en' => '/q', 'de' => '/q', 'fr' => '/qf']),
            [],
        );

        self::assertSame(['/p -> /q [en,de]', '/pf -> /qf [fr]'], self::created($plan));
    }

    public function testMultilanguageWildcardKeepsTheLanguageLimit(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('B', ['en' => '/blog', 'de' => '/artikel'], ['a' => ['en' => '/blog/a', 'de' => '/artikel/a']]),
            self::snap('B', ['en' => '/news', 'de' => '/aktuell'], ['a' => ['en' => '/news/a', 'de' => '/aktuell/a']]),
            [],
        );

        self::assertSame([
            '/artikel -> /aktuell [de]',
            '/artikel/* -> /aktuell/$1 [de]',
            '/blog -> /news [en]',
            '/blog/* -> /news/$1 [en]',
        ], self::created($plan));
    }

    public function testMultilanguageEachModeDescendantsWithTranslatedSlugs(): void
    {
        $plan = $this->planner(ChildrenMode::Each)->planMove(
            self::snap('B', ['en' => '/blog', 'de' => '/artikel'], ['a' => ['en' => '/blog/first', 'de' => '/artikel/erster']]),
            self::snap('B', ['en' => '/news', 'de' => '/aktuell'], ['a' => ['en' => '/news/first', 'de' => '/aktuell/erster']]),
            [],
        );

        self::assertContains('/blog/first -> /news/first [en]', self::created($plan));
        self::assertContains('/artikel/erster -> /aktuell/erster [de]', self::created($plan));
    }

    public function testRenameBackRemovesTheAutoRuleAndCreatesNothing(): void
    {
        $existing = [self::auto('r1', '/about', '/about-us')];
        $plan = $this->planner()->planMove(self::snap('About', [self::ANY => '/about-us']), self::snap('About', [self::ANY => '/about']), $existing);

        self::assertSame(['r1'], $plan->delete);
        self::assertSame([], $plan->create);
        self::assertSame([], $plan->update);
    }

    public function testRenameBackRemovesTheWildcardRuleToo(): void
    {
        $existing = [
            self::auto('r1', '/blog', '/news'),
            self::auto('r2', '/blog/*', '/news/$1', ['match_type' => 'wildcard', 'target_type' => 'route']),
        ];
        $plan = $this->planner()->planMove(
            self::snap('B', [self::ANY => '/news'], ['a' => [self::ANY => '/news/a']]),
            self::snap('B', [self::ANY => '/blog'], ['a' => [self::ANY => '/blog/a']]),
            $existing,
        );

        self::assertEqualsCanonicalizing(['r1', 'r2'], $plan->delete);
        self::assertSame([], $plan->create);
    }

    public function testNeverCreatesTheReverseOfAnExistingAutoRule(): void
    {
        // B -> A exists (auto), the page moves A -> B: the rule is dropped instead of forming a loop.
        $existing = [self::auto('r1', '/b', '/a')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/a']), self::snap('P', [self::ANY => '/b']), $existing);

        self::assertSame(['r1'], $plan->delete);
        self::assertSame([], $plan->create);
    }

    public function testChainIsFlattenedAtoBthenBtoC(): void
    {
        $existing = [self::auto('r1', '/a', '/b')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/b']), self::snap('P', [self::ANY => '/c']), $existing);

        self::assertSame(['/b -> /c'], self::created($plan));
        self::assertSame('/c', $plan->update['r1']->target);
        self::assertSame('/a', $plan->update['r1']->source);
        self::assertSame([], $plan->delete);
    }

    public function testFlattenReplacesThePrefixOfDescendantTargets(): void
    {
        $existing = [
            self::auto('r1', '/legacy', '/b/child'),
            self::auto('r2', '/legacy/*', '/b/$1', ['match_type' => 'wildcard', 'target_type' => 'route']),
        ];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/b']), self::snap('P', [self::ANY => '/c']), $existing);

        self::assertSame('/c/child', $plan->update['r1']->target);
        self::assertSame('/c/$1', $plan->update['r2']->target);
    }

    public function testFlattenKeepsQueryAndFragmentOfTheTarget(): void
    {
        $existing = [self::rule('r1', '/x', '/b?ref=1#top', ['target_type' => 'page'])];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/b']), self::snap('P', [self::ANY => '/c']), $existing);

        self::assertSame('/c?ref=1#top', $plan->update['r1']->target);
    }

    public function testPageTargetRulesFollowThePageButRouteTargetRulesDoNot(): void
    {
        $existing = [
            self::rule('page', '/promo', '/old', ['target_type' => 'page']),
            self::rule('route', '/promo2', '/old', ['target_type' => 'route']),
            self::rule('url', '/promo3', 'https://example.org/old', ['target_type' => 'url']),
        ];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame(['page'], array_keys($plan->update));
        self::assertSame('/new', $plan->update['page']->target);
        self::assertSame(RuleSource::Manual, $plan->update['page']->origin);
    }

    public function testPageTargetRuleThatWouldRedirectToItselfIsLeftAloneAndNoted(): void
    {
        $existing = [self::rule('m', '/new', '/old', ['target_type' => 'page'])];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame([], $plan->update);
        self::assertSame([], $plan->create, 'the manual rule back is a loop');
        $kinds = array_map(static fn (PlanNote $n): string => $n->kind, $plan->notes);
        self::assertContains(PlanNote::LOOP, $kinds);
    }

    public function testFlattenRespectsTheLanguageOfTheRule(): void
    {
        $existing = [
            self::auto('en', '/x', '/about', ['conditions' => ['languages' => ['en']]]),
            self::auto('de', '/y', '/about', ['conditions' => ['languages' => ['de']]]),
        ];
        $plan = $this->planner()->planMove(
            self::snap('A', ['en' => '/about', 'de' => '/ueber']),
            self::snap('A', ['en' => '/about-us', 'de' => '/ueber']),
            $existing,
        );

        self::assertSame(['en'], array_keys($plan->update));
        self::assertSame('/about-us', $plan->update['en']->target);
    }

    public function testEnabledManualRuleWithTheSameSourceWinsAndIsNoted(): void
    {
        $existing = [self::rule('m', '/old', '/elsewhere')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame([], $plan->create);
        self::assertSame(PlanNote::CONFLICT, $plan->notes[0]->kind);
        self::assertSame('/old', $plan->notes[0]->source);
    }

    public function testDisabledManualRuleDoesNotBlock(): void
    {
        $existing = [self::rule('m', '/old', '/elsewhere', ['enabled' => false])];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame(['/old -> /new'], self::created($plan));
    }

    public function testManualRuleForAnotherLanguageDoesNotBlock(): void
    {
        $existing = [self::rule('m', '/old', '/elsewhere', ['conditions' => ['languages' => ['de']]])];
        $plan = $this->planner()->planMove(self::snap('P', ['en' => '/old', 'de' => '/alt']), self::snap('P', ['en' => '/new', 'de' => '/neu']), $existing);

        self::assertContains('/old -> /new [en]', self::created($plan));
    }

    public function testManualRuleBackToTheOldRouteIsALoop(): void
    {
        $existing = [self::rule('m', '/new', '/old')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame([], $plan->create);
        self::assertSame(PlanNote::LOOP, $plan->notes[0]->kind);
    }

    public function testManualRuleOnTheNewRouteIsReportedAsShadowing(): void
    {
        $existing = [self::rule('m', '/new', '/somewhere')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame(['/old -> /new'], self::created($plan));
        self::assertSame(PlanNote::SHADOWED, $plan->notes[0]->kind);
    }

    public function testStaleAutoRuleOnTheNewRouteIsRemoved(): void
    {
        // /new used to be redirected to /x; a page lives there now.
        $existing = [self::auto('stale', '/new', '/x')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame(['stale'], $plan->delete);
        self::assertSame(['/old -> /new'], self::created($plan));
    }

    public function testExistingAutoRuleWithTheSameSourceIsRetargeted(): void
    {
        $existing = [self::auto('r1', '/old', '/x')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertSame([], $plan->create);
        self::assertSame('/new', $plan->update['r1']->target);
    }

    public function testRepeatedEventCreatesNothingTwice(): void
    {
        $existing = [self::auto('r1', '/old', '/new')];
        $plan = $this->planner()->planMove(self::snap('P', [self::ANY => '/old']), self::snap('P', [self::ANY => '/new']), $existing);

        self::assertTrue($plan->isEmpty());
        self::assertSame(PlanNote::EXISTS, $plan->notes[0]->kind);
    }

    public function testRouteThatAnotherPageServesIsNotUsedAsSource(): void
    {
        $plan = $this->planner()->planMove(
            self::snap('P', [self::ANY => '/old']),
            self::snap('P', [self::ANY => '/new']),
            [],
            static fn (string $route, string $language): bool => $route === '/old',
        );

        self::assertSame([], $plan->create);
        self::assertSame(PlanNote::OCCUPIED, $plan->notes[0]->kind);
    }

    public function testRulesAreNotFlattenedWhileTheirTargetIsStillServed(): void
    {
        // Swap: X moved /x -> /y, and Y moved /y -> /z. "/x -> /y" must keep pointing at the page now at /y.
        $existing = [self::auto('r1', '/from-x', '/y')];
        $plan = $this->planner()->planMove(
            self::snap('Y', [self::ANY => '/y']),
            self::snap('Y', [self::ANY => '/z']),
            $existing,
            static fn (string $route, string $language): bool => $route === '/y',
        );

        self::assertSame([], $plan->update);
    }

    public function testMultilanguageRenameBackInOneLanguageNarrowsTheRule(): void
    {
        // The rule /b -> /a applies to all languages; only English moves back to /b... here: page /a -> /b in en only.
        $existing = [self::auto('r1', '/b', '/a')];
        $plan = $this->planner()->planMove(
            self::snap('P', ['en' => '/a', 'de' => '/a']),
            self::snap('P', ['en' => '/b', 'de' => '/a']),
            $existing,
        );

        self::assertSame([], $plan->create);
        self::assertSame([], $plan->delete);
        self::assertSame(['de'], $plan->update['r1']->conditions->languages);
    }

    public function testAllThreeStepsBackAndForthEndWithOneRule(): void
    {
        $planner = $this->planner();
        $rules = [];
        $step = function (string $from, string $to) use ($planner, &$rules): void {
            $plan = $planner->planMove(self::snap('P', [self::ANY => $from]), self::snap('P', [self::ANY => $to]), $rules);
            $next = [];
            foreach ($rules as $rule) {
                if (in_array($rule->id, $plan->delete, true)) {
                    continue;
                }
                $next[] = $plan->update[$rule->id] ?? $rule;
            }
            $rules = [...$next, ...$plan->create];
        };

        $step('/a', '/b');
        $step('/b', '/c');
        self::assertEqualsCanonicalizing(['/a -> /c', '/b -> /c'], array_map(self::describe(...), $rules));
        $step('/c', '/b');
        self::assertEqualsCanonicalizing(['/a -> /b'], array_map(self::describe(...), $rules));
        $step('/b', '/a');
        self::assertSame([], $rules);
    }
}
