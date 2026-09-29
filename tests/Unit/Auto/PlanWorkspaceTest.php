<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PlanNote;
use Grav\Plugin\RedirectManager\Auto\PlanWorkspace;
use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanWorkspace::class)]
#[Group('auto')]
final class PlanWorkspaceTest extends TestCase
{
    public function testAnUntouchedWorkspaceYieldsAnEmptyPlan(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b'), new Rule('b', '/b', '/c')]);

        $plan = $ws->toPlan();

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->notes);
        self::assertNull($plan->pending);
        self::assertSame(['a', 'b'], array_map(static fn (Rule $r): string => $r->id, $ws->rules()));
        self::assertTrue($ws->has('a'));
        self::assertFalse($ws->has('zzz'));
    }

    public function testAddedRulesBecomeCreates(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b')]);
        $new = new Rule('n', '/n', '/m');

        $ws->add($new);

        self::assertTrue($ws->has('n'));
        self::assertSame(['a', 'n'], array_map(static fn (Rule $r): string => $r->id, $ws->rules()));
        $plan = $ws->toPlan();
        self::assertSame([$new], $plan->create);
        self::assertSame([], $plan->update);
        self::assertSame([], $plan->delete);
    }

    public function testReplacingAnExistingRuleBecomesAnUpdate(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b'), new Rule('c', '/c', '/d')]);
        $changed = new Rule('a', '/a', '/other');

        $ws->replace($changed);

        $plan = $ws->toPlan();
        self::assertSame(['a' => $changed], $plan->update);
        self::assertSame([], $plan->create);
        self::assertSame([], $plan->delete);
    }

    public function testReplacingWithTheSameContentIsNotAnUpdate(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b')]);

        $ws->replace(new Rule('a', '/a', '/b'));

        self::assertTrue($ws->toPlan()->isEmpty(), 'compared by content, not identity');
    }

    public function testReplacingAnUnknownRuleDoesNothing(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b')]);

        $ws->replace(new Rule('ghost', '/g', '/h'));

        self::assertFalse($ws->has('ghost'));
        self::assertTrue($ws->toPlan()->isEmpty());
    }

    public function testRemovingAnExistingRuleBecomesADelete(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b'), new Rule('c', '/c', '/d'), new Rule('e', '/e', '/f')]);

        $ws->remove('c');
        $ws->remove('e');
        $ws->remove('never-existed');

        self::assertFalse($ws->has('c'));
        self::assertSame(['c', 'e'], $ws->toPlan()->delete);
        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $ws->rules()));
    }

    public function testAddingThenRemovingARuleLeavesNoTrace(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b')]);

        $ws->add(new Rule('n', '/n', '/m'));
        $ws->remove('n');

        self::assertTrue($ws->toPlan()->isEmpty());
    }

    public function testAddingARuleWithAnExistingIdCountsAsCreate(): void
    {
        $ws = new PlanWorkspace([new Rule('a', '/a', '/b')]);
        $again = new Rule('a', '/a', '/replacement');

        $ws->add($again);

        $plan = $ws->toPlan();
        self::assertSame([$again], $plan->create, 'ids the workspace created are created, whatever was there before');
        self::assertSame([], $plan->update);
    }

    public function testReplacingACreatedRuleKeepsItACreate(): void
    {
        $ws = new PlanWorkspace([]);
        $ws->add(new Rule('n', '/n', '/m'));
        $final = new Rule('n', '/n', '/final');

        $ws->replace($final);

        $plan = $ws->toPlan();
        self::assertSame([$final], $plan->create);
        self::assertSame([], $plan->update);
    }

    public function testNotesAndPendingAreCarriedIntoThePlan(): void
    {
        $ws = new PlanWorkspace([]);
        $note1 = new PlanNote(PlanNote::EXISTS, '/a');
        $note2 = new PlanNote(PlanNote::LOOP, '/b', 'loop');
        $ws->note($note1);
        $ws->note($note2);
        $snapshot = new PageSnapshot('T', '/t', ['*' => '/t']);

        $plan = $ws->toPlan($snapshot);

        self::assertSame([$note1, $note2], $plan->notes);
        self::assertSame($snapshot, $plan->pending);
        self::assertFalse($plan->isEmpty());
    }

    public function testCombinedPlan(): void
    {
        $ws = new PlanWorkspace([new Rule('keep', '/k', '/k2'), new Rule('upd', '/u', '/u2'), new Rule('del', '/d', '/d2')]);
        $created = new Rule('new', '/n', '/n2');
        $updated = new Rule('upd', '/u', '/u3');

        $ws->add($created);
        $ws->replace($updated);
        $ws->remove('del');

        $plan = $ws->toPlan();
        self::assertSame(['created' => 1, 'updated' => 1, 'deleted' => 1, 'skipped' => 0, 'pending' => 0], $plan->counts());
        self::assertSame([$created], $plan->create);
        self::assertSame(['upd' => $updated], $plan->update);
        self::assertSame(['del'], $plan->delete);
    }
}
