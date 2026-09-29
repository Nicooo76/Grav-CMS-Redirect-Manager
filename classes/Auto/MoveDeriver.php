<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Auto;

/**
 * Rebuilds the "before" snapshot of a page that was moved through POST /pages/{route}/move.
 *
 * The API reports only old_route and new_route after the folder was renamed, and there is no "before" event, so the
 * old routes are derived from the state afterwards: the old parent (still in place) gives the parent route per
 * language, the moved page's slug per language comes from its new routes. When the move renamed the folder
 * (old and new route end differently), languages that use the folder slug take the old one; languages with their
 * own slug in the front matter keep it. Descendants keep their route below the page.
 *
 * Limit: a move that both renames and reaches pages with translated slugs is exact only for the languages whose slug
 * follows the folder.
 */
final class MoveDeriver
{
    /**
     * @param array<string, string> $oldParentRoutes language => public route of the old parent, "" for the pages root
     */
    public static function before(PageSnapshot $after, string $oldRoute, string $newRoute, array $oldParentRoutes): PageSnapshot
    {
        $oldSlug = basename(trim($oldRoute, '/'));
        $newSlug = basename(trim($newRoute, '/'));
        $renamed = $oldSlug !== $newSlug;

        $routes = [];
        foreach ($after->routes as $language => $newRoute) {
            $slug = basename(trim($newRoute, '/'));
            if ($renamed && $slug === $newSlug) {
                $slug = $oldSlug;
            }
            $parent = $oldParentRoutes[$language] ?? $oldParentRoutes[PageSnapshot::ANY] ?? null;
            if ($parent === null) {
                continue;
            }
            $parent = rtrim($parent, '/');
            $routes[$language] = ($parent === '' ? '' : $parent) . '/' . $slug;
        }

        $descendants = [];
        foreach ($after->descendants as $node) {
            $old = [];
            foreach ($node->routes as $language => $route) {
                $base = $after->routes[$language] ?? null;
                if ($base === null || !isset($routes[$language]) || stripos($route, rtrim($base, '/') . '/') !== 0) {
                    continue;
                }
                $old[$language] = $routes[$language] . substr($route, strlen(rtrim($base, '/')));
            }
            if ($old !== []) {
                $descendants[] = new PageNode($node->key, $old);
            }
        }

        return new PageSnapshot($after->title, '/' . trim($oldRoute, '/'), $routes, $descendants, [], $after->home);
    }
}
