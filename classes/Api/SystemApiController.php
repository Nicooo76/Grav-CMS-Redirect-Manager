<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dashboard numbers (`meta.permissions` tells what the caller may do), live target checks and the page search: /redirects/stats, /redirects/checks, /redirects/pages.
 */
final class SystemApiController extends BaseController
{
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->stats()->dashboard(), 200, [], [
            'permissions' => [
                'read' => $this->may($request, self::READ),
                'manage' => $this->may($request, self::MANAGE),
            ],
            'default_status' => $this->app()->rules()->defaultStatus()->value,
        ]));
    }

    public function checks(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->checks()->list()));
    }

    public function runChecks(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $ids = $this->body($request)['ids'] ?? null;

            return ApiResponse::create($this->app()->checks()->run(
                is_array($ids) ? array_values(array_filter($ids, is_string(...))) : null,
                true,
            ));
        });
    }

    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $q = $query['q'] ?? null;
            $language = $query['language'] ?? null;
            $limit = $query['limit'] ?? 20;

            return ApiResponse::create($this->app()->stats()->pages(
                is_string($q) ? $q : null,
                is_string($language) && $language !== '' ? $language : null,
                is_numeric($limit) ? (int) $limit : 20,
            ));
        });
    }
}
