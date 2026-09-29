<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The context panel of Admin 2's page editor: what the rules say about one page (docs/API.md, "Page context").
 *
 *   GET  /redirects/page-context?route=&lang=         rules to, from and below the page          api.redirects.read
 *   GET  /redirects/page-context/badge?route=&lang=   {count} = unseen automatic rules, null when 0   api.redirects.read
 *   POST /redirects/page-context/seen                 body {route, lang}: marks that page's unseen rules   api.redirects.manage
 *
 * The badge route is the `badgeEndpoint` of the panel; Admin 2 adds `route`, `lang` and `type` to it.
 */
final class PageContextApiController extends BaseController
{
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);

            return ApiResponse::create(
                $this->app()->pageContext()->context(self::text($query['route'] ?? null), self::language($query)),
                200,
                [],
                ['permissions' => ['read' => true, 'manage' => $this->may($request, self::MANAGE)]],
            );
        });
    }

    public function badge(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $count = $this->app()->pageContext()->unseenCount(self::text($query['route'] ?? null), self::language($query));

            return ApiResponse::create(['count' => $count > 0 ? $count : null]);
        });
    }

    public function seen(ServerRequestInterface $request): ResponseInterface
    {
        // The seen state is shared by all users, like POST /redirects/badge/seen.
        return $this->manage($request, function () use ($request): ResponseInterface {
            $body = $this->body($request);

            return ApiResponse::create($this->app()->pageContext()->markSeen(self::text($body['route'] ?? null), self::language($body)));
        });
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function language(array $source): ?string
    {
        $value = $source['lang'] ?? $source['language'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
