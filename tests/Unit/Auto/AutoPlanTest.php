<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\AutoPlan;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PlanNote;
use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoPlan::class)]
#[CoversClass(PlanNote::class)]
#[Group('auto')]
final class AutoPlanTest extends TestCase
{
    public function testDefaultsAreAnEmptyPlan(): void
    {
        $plan = new AutoPlan();

        self::assertTrue($plan->isEmpty());
        self::assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'pending' => 0], $plan->counts());
    }

    /**
     * @return iterable<string, array{AutoPlan}>
     */
    public static function nonEmptyProvider(): iterable
    {
        yield 'create' => [new AutoPlan(create: [new Rule('a', '/a', '/b')])];
        yield 'update' => [new AutoPlan(update: ['a' => new Rule('a', '/a', '/c')])];
        yield 'delete' => [new AutoPlan(delete: ['a'])];
        yield 'pending' => [new AutoPlan(pending: new PageSnapshot('T', '/t', ['*' => '/t']))];
    }

    #[DataProvider('nonEmptyProvider')]
    public function testAnyChangeMakesThePlanNonEmpty(AutoPlan $plan): void
    {
        self::assertFalse($plan->isEmpty());
    }

    public function testNotesAloneDoNotMakeAPlanNonEmpty(): void
    {
        $plan = new AutoPlan(notes: [new PlanNote(PlanNote::CONFLICT, '/old', 'A manual rule exists.')]);

        self::assertTrue($plan->isEmpty(), 'nothing to apply, the notes are only information');
        self::assertSame(1, $plan->counts()['skipped']);
    }

    public function testCounts(): void
    {
        $plan = new AutoPlan(
            [new Rule('a', '/a', '/b'), new Rule('b', '/b', '/c')],
            ['x' => new Rule('x', '/x', '/y')],
            ['d1', 'd2', 'd3'],
            [new PlanNote(PlanNote::EXISTS, '/a'), new PlanNote(PlanNote::LOOP, '/b'), new PlanNote(PlanNote::SKIPPED, '/c'), new PlanNote(PlanNote::OCCUPIED, '/d')],
            new PageSnapshot('T', '/t', ['*' => '/t']),
        );

        self::assertSame(['created' => 2, 'updated' => 1, 'deleted' => 3, 'skipped' => 4, 'pending' => 1], $plan->counts());
    }

    public function testNoteDefaultsAndKinds(): void
    {
        $note = new PlanNote(PlanNote::SHADOWED, '/new');

        self::assertSame('shadowed', $note->kind);
        self::assertSame('/new', $note->source);
        self::assertSame('', $note->message);
        self::assertSame(
            ['conflict', 'loop', 'occupied', 'shadowed', 'ambiguous', 'exists', 'skipped'],
            [PlanNote::CONFLICT, PlanNote::LOOP, PlanNote::OCCUPIED, PlanNote::SHADOWED, PlanNote::AMBIGUOUS, PlanNote::EXISTS, PlanNote::SKIPPED],
        );
    }
}
