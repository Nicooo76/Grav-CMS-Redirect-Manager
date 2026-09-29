<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Common\Plugins;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RedirectManager\Analysis\RuleValidator;
use Grav\Plugin\RedirectManager\Auto\AutoPlan;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectListener;
use Grav\Plugin\RedirectManager\Auto\AutoRedirectPlanner;
use Grav\Plugin\RedirectManager\Auto\DeleteAction;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\PendingDelete;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * REST routes of the auto-redirect feature (registered by onApiRegisterAutoRoutes, see docs/API.md):
 *
 *   GET  /redirects/pending               deleted pages waiting for a decision            api.redirects.read
 *   POST /redirects/pending/{id}/resolve  body {action: gone|parent|redirect|dismiss, target?}  api.redirects.manage
 *   GET  /redirects/badge                 {count} = unseen auto rules + pending decisions    api.redirects.read
 *   POST /redirects/badge/seen            marks the auto rules as seen                       api.redirects.read
 */
final class AutoRedirectController extends AbstractApiController
{
    private const READ = 'api.redirects.read';
    private const MANAGE = 'api.redirects.manage';
    private const MAX_CHILDREN = 50;

    public function pending(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ);
        $listener = $this->listener();
        $rows = [];
        foreach ($this->services()->autoState()->pending() as $entry) {
            $rows[] = $this->row($entry, $listener);
        }

        return ApiResponse::create($rows, 200, [], ['total' => count($rows)]);
    }

    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::MANAGE);
        $id = (string) $this->getRouteParam($request, 'id');
        $body = $this->getRequestBody($request);
        $action = is_string($body['action'] ?? null) ? strtolower(trim($body['action'])) : '';
        $target = is_string($body['target'] ?? null) ? trim($body['target']) : null;

        if (!in_array($action, ['gone', 'parent', 'redirect', 'dismiss'], true)) {
            throw new ValidationException('The action must be gone, parent, redirect or dismiss.', [
                ['field' => 'action', 'code' => 'action_invalid', 'message' => 'Use gone, parent, redirect or dismiss.', 'severity' => 'error'],
            ]);
        }
        $services = $this->services();
        $state = $services->autoState();
        $entry = $state->findPending($id);
        if ($entry === null) {
            throw new NotFoundException('No pending decision with this id.');
        }

        if ($action === 'dismiss') {
            $state->resolvePending($id, $action);

            return ApiResponse::create(['id' => $id, 'action' => $action, 'created' => [], 'updated' => [], 'deleted' => [], 'notes' => []]);
        }
        if ($action === 'redirect' && !self::validTarget($target)) {
            throw new ValidationException('A redirect needs a target: a route such as /new-page or an absolute URL.', [
                ['field' => 'target', 'code' => 'target_required', 'message' => 'Enter a route starting with / or an http(s) URL.', 'severity' => 'error'],
            ]);
        }

        $deleteAction = DeleteAction::from($action);
        $listener = $this->listener();
        $exists = $listener->snapshotter()->routeExists(...);
        $this->validateCandidates($entry->snapshot, $deleteAction, $target, $exists);

        $plan = $listener->applier()->resolve($entry->snapshot, $deleteAction, $target, $exists);
        $state->resolvePending($id, $action, $target);

        return ApiResponse::create($this->planData($id, $action, $plan));
    }

    public function badge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ);
        $services = $this->services();
        $state = $services->autoState();
        $unseen = $state->unseen();
        $existing = null;
        if ($unseen !== []) {
            try {
                $existing = array_map(static fn (Rule $r): string => $r->id, $services->repository()->all());
            } catch (CorruptRulesFileException) {
                $existing = null;
            }
        }
        $pending = $state->pendingCount();
        $count = $state->badgeCount($existing);

        return ApiResponse::create(['count' => $count, 'unseen' => $count - $pending, 'pending' => $pending]);
    }

    public function badgeSeen(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ);
        $state = $this->services()->autoState();
        $state->markSeen();

        return ApiResponse::create(['count' => $state->badgeCount()]);
    }

    /**
     * Dry run of the decision: the created rules must pass the same validation as rules made by hand.
     *
     * @param callable(string, string): bool $exists
     */
    private function validateCandidates(PageSnapshot $snapshot, DeleteAction $action, ?string $target, callable $exists): void
    {
        $services = $this->services();
        try {
            $existing = $services->repository()->all();
        } catch (CorruptRulesFileException $e) {
            throw new ValidationException('rules.yaml cannot be read: ' . $e->getMessage());
        }
        $planner = new AutoRedirectPlanner($services->autoRedirectConfig());
        $plan = $planner->planDeleteAction($snapshot, $existing, $action, $target, $exists);
        $validator = new RuleValidator($services->matcherOptions(), $services->targetGuard(), $services->clock());
        $errors = [];
        foreach ($plan->create as $rule) {
            foreach ($validator->validate($rule, $existing)->errors() as $issue) {
                $errors[] = ['field' => $issue->field, 'code' => $issue->code, 'message' => $issue->message, 'severity' => 'error', 'source' => $rule->source];
            }
        }
        if ($errors !== []) {
            throw new ValidationException('The redirect is not valid.', $errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(string $id, string $action, AutoPlan $plan): array
    {
        return [
            'id' => $id,
            'action' => $action,
            'created' => array_map(static fn (Rule $r): array => $r->toArray(), $plan->create),
            'updated' => array_values(array_map(static fn (Rule $r): array => $r->toArray(), $plan->update)),
            'deleted' => $plan->delete,
            'notes' => array_map(static fn ($n): array => ['kind' => $n->kind, 'source' => $n->source, 'message' => $n->message], $plan->notes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PendingDelete $entry, AutoRedirectListener $listener): array
    {
        $children = $entry->children();
        $languages = array_values(array_filter($entry->languages(), static fn (string $l): bool => $l !== PageSnapshot::ANY));
        $suggested = null;
        foreach ($entry->snapshot->ancestors as $language => $routes) {
            foreach ($routes as $route) {
                if ($listener->snapshotter()->routeExists($route, (string) $language)) {
                    $suggested = $route;
                    break 2;
                }
            }
        }

        return [
            'id' => $entry->id,
            'title' => $entry->title(),
            'route' => $entry->route(),
            'routes' => $entry->routes(),
            'languages' => $languages,
            'children' => array_slice($children, 0, self::MAX_CHILDREN),
            'children_count' => count($children),
            'deleted_at' => $entry->deletedAt->format(DATE_ATOM),
            'suggested_parent' => $suggested,
        ];
    }

    private static function validTarget(?string $target): bool
    {
        if ($target === null || $target === '') {
            return false;
        }

        return $target[0] === '/' && !str_starts_with($target, '//') || preg_match('~^https?://[^\s/]+~i', $target) === 1;
    }

    private function plugin(): \Grav\Plugin\RedirectManagerPlugin
    {
        $plugin = Plugins::getPlugin('redirect-manager');
        if (!$plugin instanceof \Grav\Plugin\RedirectManagerPlugin) {
            throw new \RuntimeException('The Redirect Manager plugin is not loaded.');
        }

        return $plugin;
    }

    private function services(): ServiceFactory
    {
        return $this->plugin()->services();
    }

    private function listener(): AutoRedirectListener
    {
        return $this->plugin()->autoRedirects();
    }
}
