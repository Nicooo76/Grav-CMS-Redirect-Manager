<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\AutoPlan;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectConfig;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectPlanner;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Auto\PageNode;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use PHPUnit\Framework\TestCase;

abstract class PlannerTestCase extends TestCase
{
    private int $counter = 0;

    protected function planner(ChildrenMode $children = ChildrenMode::Wildcard, DeletePolicy $policy = DeletePolicy::Ask, StatusCode $status = StatusCode::MovedPermanently): AutoRedirectPlanner
    {
        return new AutoRedirectPlanner(new AutoRedirectConfig($status, $children, $policy), fn (): string => 'n' . ++$this->counter);
    }

    /**
     * @param array<string, string>            $routes
     * @param array<string, array<string, string>> $descendants key => routes
     * @param array<string, list<string>>      $ancestors
     */
    protected static function snap(string $title, array $routes, array $descendants = [], array $ancestors = [], bool $home = false): PageSnapshot
    {
        $nodes = [];
        foreach ($descendants as $key => $nodeRoutes) {
            $nodes[] = new PageNode((string) $key, $nodeRoutes);
        }

        return new PageSnapshot($title, '/' . trim((string) reset($routes), '/'), $routes, $nodes, $ancestors, $home);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected static function rule(string $id, string $source, string $target, array $fields = []): Rule
    {
        return Rule::fromArray($fields + ['id' => $id, 'source' => $source, 'target' => $target]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected static function auto(string $id, string $source, string $target, array $fields = []): Rule
    {
        return self::rule($id, $source, $target, $fields + ['origin' => 'auto', 'target_type' => 'page']);
    }

    /**
     * Created rules as "source -> target [languages] (match, status)" strings, sorted, for compact assertions.
     *
     * @return list<string>
     */
    protected static function created(AutoPlan $plan): array
    {
        $out = array_map(static fn (Rule $r): string => self::describe($r), $plan->create);
        sort($out);

        return $out;
    }

    protected static function describe(Rule $r): string
    {
        $languages = $r->conditions->languages;

        return sprintf(
            '%s -> %s%s%s',
            $r->source,
            $r->target === '' ? '(none)' : $r->target,
            $languages === [] ? '' : ' [' . implode(',', $languages) . ']',
            $r->status === StatusCode::MovedPermanently ? '' : ' ' . $r->status->value,
        );
    }
}
