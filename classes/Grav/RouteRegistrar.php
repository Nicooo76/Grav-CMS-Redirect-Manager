<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\Api\ApiRouteCollector;
use Grav\Plugin\RedirectManager\Api\ApiController;
use Grav\Plugin\RedirectManager\Api\AutoRedirectController;
use Grav\Plugin\RedirectManager\Api\ImportExportApiController;
use Grav\Plugin\RedirectManager\Api\NotFoundApiController;
use Grav\Plugin\RedirectManager\Api\SuggestionApiController;
use Grav\Plugin\RedirectManager\Api\SystemApiController;

/**
 * The plugin's REST routes below the API prefix (docs/API.md). Static routes come before parameterized ones.
 *
 * $routes is the API plugin's route collector (get/post/patch/delete(path, [controller, method])); any object with
 * those methods works, which is how the tests list every registered route.
 */
final class RouteRegistrar
{
    /**
     * @param ApiRouteCollector $routes
     */
    public static function register(object $routes): void
    {
        $rules = ApiController::class;
        $notFound = NotFoundApiController::class;
        $suggestions = SuggestionApiController::class;
        $files = ImportExportApiController::class;
        $system = SystemApiController::class;

        $routes->get('/redirects/rules', [$rules, 'index']);
        $routes->post('/redirects/rules', [$rules, 'create']);
        $routes->post('/redirects/rules/restore', [$rules, 'restore']);
        $routes->post('/redirects/rules/bulk', [$rules, 'bulk']);
        $routes->post('/redirects/rules/reorder', [$rules, 'reorder']);
        $routes->post('/redirects/rules/validate', [$rules, 'validate']);
        $routes->get('/redirects/rules/{id}', [$rules, 'show']);
        $routes->patch('/redirects/rules/{id}', [$rules, 'update']);
        $routes->delete('/redirects/rules/{id}', [$rules, 'delete']);
        $routes->post('/redirects/rules/{id}/shorten-chain', [$rules, 'shortenChain']);
        $routes->get('/redirects/analysis', [$rules, 'analysis']);
        $routes->get('/redirects/groups', [$rules, 'groups']);
        $routes->post('/redirects/test', [$rules, 'test']);

        $routes->get('/redirects/404', [$notFound, 'index']);
        $routes->delete('/redirects/404', [$notFound, 'delete']);
        $routes->get('/redirects/404/trend', [$notFound, 'trend']);
        $routes->get('/redirects/404/entries', [$notFound, 'entries']);
        $routes->post('/redirects/404/ignore', [$notFound, 'ignore']);
        $routes->post('/redirects/404/resolve', [$notFound, 'resolve']);

        $routes->get('/redirects/suggest', [$suggestions, 'suggest']);
        $routes->get('/redirects/suggestions', [$suggestions, 'index']);
        $routes->post('/redirects/suggestions/generate', [$suggestions, 'generate']);
        $routes->post('/redirects/suggestions/bulk-accept', [$suggestions, 'bulkAccept']);
        $routes->post('/redirects/suggestions/{id}/accept', [$suggestions, 'accept']);
        $routes->post('/redirects/suggestions/{id}/reject', [$suggestions, 'reject']);

        $routes->get('/redirects/import/formats', [$files, 'formats']);
        $routes->post('/redirects/import/preview', [$files, 'preview']);
        $routes->post('/redirects/import/commit', [$files, 'commit']);
        $routes->post('/redirects/import/sitemap', [$files, 'sitemap']);
        $routes->get('/redirects/export', [$files, 'export']);
        $routes->get('/redirects/site-config', [$files, 'siteConfig']);
        $routes->post('/redirects/site-config/import', [$files, 'siteConfigImport']);

        $routes->get('/redirects/stats', [$system, 'stats']);
        $routes->get('/redirects/checks', [$system, 'checks']);
        $routes->post('/redirects/checks/run', [$system, 'runChecks']);
        $routes->get('/redirects/pages', [$system, 'pages']);
    }

    /** /redirects/pending, /redirects/pending/{id}/resolve, /redirects/badge, /redirects/badge/seen. */
    /**
     * @param ApiRouteCollector $routes
     */
    public static function registerAuto(object $routes): void
    {
        $auto = AutoRedirectController::class;

        $routes->get('/redirects/pending', [$auto, 'pending']);
        $routes->post('/redirects/pending/{id}/resolve', [$auto, 'resolve']);
        $routes->get('/redirects/badge', [$auto, 'badge']);
        $routes->post('/redirects/badge/seen', [$auto, 'badgeSeen']);
    }
}
