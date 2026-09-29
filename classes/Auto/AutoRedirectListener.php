<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

/**
 * Creates and maintains redirects when pages change through the API plugin (Admin 2, REST, MCP).
 *
 * The API fires "before" events while the page still has its old state and "after" events when the change is
 * saved. This listener captures a PageSnapshot in the before event (kept in memory, both events run in the same
 * request), reads the page again in the after event, and lets AutoRedirectPlanner decide:
 *
 *   onApiBeforePageUpdate / onApiPageUpdated      only when the request touches header.slug or header.routes
 *                                                 (also for method "batch"); the tree is reloaded from disk to read
 *                                                 the new routes
 *   onApiPageMoved                                POST /pages/{route}/move; no before event exists, the old routes are
 *                                                 derived (see MoveDeriver)
 *   onApiBeforePagesReorganize / onApiPagesReorganized
 *                                                 exact: before snapshots of every moved page, after snapshots by
 *                                                 final folder; the per-page onApiPageMoved events of the same request
 *                                                 (method "reorganize") are then skipped
 *   onApiBeforePageDelete / onApiPageDeleted      snapshot of the subtree, then the delete policy; deleting one
 *                                                 language of a page that keeps other translations does nothing
 *
 * Nothing here may break the API request: every handler catches Throwable and logs it.
 */
final class AutoRedirectListener
{
    /** @var array<string, PageSnapshot> folder path => snapshot taken before an update */
    private array $updates = [];

    /** @var list<array{keys: list<string>, snapshot: ?PageSnapshot}> */
    private array $deletes = [];

    /** @var array<string, PageSnapshot> old folder path => snapshot taken before a reorganize */
    private array $reorganize = [];

    /** @var array<string, true> old routes (lowercase) already handled by onApiPagesReorganized */
    private array $handledMoves = [];

    private ?PageSnapshotter $snapshotter = null;
    private ?AutoRedirectApplier $applier = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly ServiceFactory $services,
        private readonly RuleEvents $events,
    ) {
    }

    public function onBeforePageUpdate(Event $event): void
    {
        $this->guard('update capture', function () use ($event): void {
            if (!$this->active()) {
                return;
            }
            $page = $event['page'] ?? null;
            $data = $event['data'] ?? null;
            if (!$page instanceof PageInterface || !is_array($data)) {
                return;
            }
            if (!RouteChangeDetector::affectsRoute(self::headerOf($page), $data)) {
                return;
            }
            $snapshot = $this->snapshotter()->snapshot($page);
            if ($snapshot !== null) {
                $this->updates[(string) $page->path()] = $snapshot;
            }
        });
    }

    public function onPageUpdated(Event $event): void
    {
        $this->guard('update', function () use ($event): void {
            $page = $event['page'] ?? null;
            if (!$page instanceof PageInterface) {
                return;
            }
            $path = (string) $page->path();
            $before = $this->updates[$path] ?? null;
            unset($this->updates[$path]);
            if ($before === null || !$this->active()) {
                return;
            }
            $snapshotter = $this->snapshotter();
            $snapshotter->reload();
            $fresh = $snapshotter->byPath($path);
            $after = $fresh !== null ? $snapshotter->snapshot($fresh) : null;
            if ($after === null) {
                return;
            }
            $this->applier()->move($before, $after, $snapshotter->isLive(...));
        });
    }

    public function onPageMoved(Event $event): void
    {
        $this->guard('move', function () use ($event): void {
            if (!$this->active()) {
                return;
            }
            $oldRoute = is_string($event['old_route'] ?? null) ? $event['old_route'] : '';
            $newRoute = is_string($event['new_route'] ?? null) ? $event['new_route'] : '';
            if ($oldRoute === '' || $newRoute === '') {
                return;
            }
            if (($event['method'] ?? null) === 'reorganize' && isset($this->handledMoves[strtolower('/' . trim($oldRoute, '/'))])) {
                return;
            }
            $snapshotter = $this->snapshotter();
            $moved = $event['page'] ?? null;
            if (!$moved instanceof PageInterface) {
                $moved = $snapshotter->byRoute($newRoute);
            }
            $after = $moved instanceof PageInterface ? $snapshotter->snapshot($moved) : null;
            if ($after === null) {
                return;
            }
            $before = MoveDeriver::before($after, $oldRoute, $newRoute, $this->oldParentRoutes($after, $oldRoute));
            $this->applier()->move($before, $after, $snapshotter->isLive(...));
        });
    }

    public function onBeforePagesReorganize(Event $event): void
    {
        $this->guard('reorganize capture', function () use ($event): void {
            if (!$this->active()) {
                return;
            }
            $operations = $event['operations'] ?? null;
            foreach (is_array($operations) ? $operations : [] as $op) {
                if (!is_array($op) || empty($op['actuallyMoves']) || !($op['page'] ?? null) instanceof PageInterface) {
                    continue;
                }
                $snapshot = $this->snapshotter()->snapshot($op['page']);
                if ($snapshot !== null) {
                    $this->reorganize[(string) ($op['oldPath'] ?? $op['page']->path())] = $snapshot;
                }
            }
        });
    }

    public function onPagesReorganized(Event $event): void
    {
        $this->guard('reorganize', function () use ($event): void {
            $operations = $event['operations'] ?? null;
            $before = $this->reorganize;
            $this->reorganize = [];
            if ($before === [] || !$this->active()) {
                return;
            }
            $snapshotter = $this->snapshotter();
            foreach (is_array($operations) ? $operations : [] as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $oldPath = (string) ($op['oldPath'] ?? '');
                $finalPath = (string) ($op['finalPath'] ?? '');
                $snapshot = $before[$oldPath] ?? null;
                if ($snapshot === null || $finalPath === '') {
                    continue;
                }
                $this->handledMoves[strtolower('/' . trim((string) ($op['route'] ?? ''), '/'))] = true;
                $page = $snapshotter->byPath($finalPath);
                $after = $page !== null ? $snapshotter->snapshot($page) : null;
                if ($after !== null) {
                    $this->guard('reorganize op', fn () => $this->applier()->move($snapshot, $after, $snapshotter->isLive(...)));
                }
            }
        });
    }

    public function onBeforePageDelete(Event $event): void
    {
        $this->guard('delete capture', function () use ($event): void {
            if (!$this->active()) {
                return;
            }
            $page = $event['page'] ?? null;
            if (!$page instanceof PageInterface) {
                return;
            }
            $keys = array_values(array_unique(array_filter([
                strtolower('/' . trim((string) $page->route(), '/')),
                strtolower('/' . trim((string) $page->rawRoute(), '/')),
            ])));
            $snapshotter = $this->snapshotter();
            // One language of a page that keeps other translations: the page lives on (content fallback), nothing to redirect.
            if (isset($event['lang']) && count($this->translations($page)) > 1) {
                $this->deletes[] = ['keys' => $keys, 'snapshot' => null];

                return;
            }
            $this->deletes[] = ['keys' => $keys, 'snapshot' => $snapshotter->snapshot($page)];
        });
    }

    public function onPageDeleted(Event $event): void
    {
        $this->guard('delete', function () use ($event): void {
            $route = strtolower('/' . trim(is_string($event['route'] ?? null) ? $event['route'] : '', '/'));
            $entry = null;
            $rest = [];
            foreach ($this->deletes as $candidate) {
                if ($entry === null && in_array($route, $candidate['keys'], true)) {
                    $entry = $candidate;
                } else {
                    $rest[] = $candidate;
                }
            }
            $this->deletes = $rest;
            if ($entry === null || $entry['snapshot'] === null || !$this->active()) {
                return;
            }
            $this->applier()->delete($entry['snapshot'], $this->snapshotter()->routeExists(...));
        });
    }

    /**
     * Applies a decision on a pending deleted page (used by the REST controller).
     */
    public function applier(): AutoRedirectApplier
    {
        return $this->applier ??= new AutoRedirectApplier(
            $this->services->repository(),
            $this->services->autoState(),
            $this->services->autoRedirectConfig(),
            fn (Rule $rule, ?Rule $previous, string $action) => $this->events->saved($rule, $previous, $action),
            fn () => $this->services->compiledCache()->invalidate(),
            $this->logger(),
        );
    }

    public function snapshotter(): PageSnapshotter
    {
        return $this->snapshotter ??= new PageSnapshotter($this->grav);
    }

    private function active(): bool
    {
        return $this->services->bool('enabled', true) && $this->services->bool('auto_redirect.enabled', true);
    }

    /**
     * Routes of the parent the moved page came from, per language of the moved page.
     *
     * @return array<string, string>
     */
    private function oldParentRoutes(PageSnapshot $after, string $oldRoute): array
    {
        $parentRoute = dirname('/' . trim($oldRoute, '/'));
        $out = [];
        if ($parentRoute === '/' || $parentRoute === '.' || $parentRoute === '') {
            foreach ($after->languages() as $language) {
                $out[$language] = '';
            }

            return $out;
        }
        $snapshotter = $this->snapshotter();
        $parent = $snapshotter->byRoute($parentRoute);
        $map = $parent instanceof PageInterface ? $snapshotter->routeMap($parent) : [];
        foreach ($after->languages() as $language) {
            $route = $map[$language] ?? $map[PageSnapshot::ANY] ?? ($map === [] ? $parentRoute : array_values($map)[0]);
            $out[$language] = $route === '/' ? '' : $route;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function translations(PageInterface $page): array
    {
        try {
            /** @var array<string, string> $languages */
            $languages = $page->translatedLanguages();

            return $languages;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<mixed>
     */
    private static function headerOf(PageInterface $page): array
    {
        $header = json_decode((string) json_encode($page->header()), true);

        return is_array($header) ? $header : [];
    }

    private function logger(): ?\Psr\Log\LoggerInterface
    {
        $log = $this->grav['log'];

        return $log instanceof \Psr\Log\LoggerInterface ? $log : null;
    }

    private function guard(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            try {
                $this->logger()?->error(sprintf('Redirect Manager: auto-redirect (%s) failed: %s (%s:%d)', $what, $e->getMessage(), basename($e->getFile()), $e->getLine()));
            } catch (Throwable) {
                // nothing left to do
            }
        }
    }
}
