<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Suggestions: /redirects/suggest (live), /redirects/suggestions (stored, generate, accept, reject, bulk-accept).
 */
final class SuggestionApiController extends BaseController
{
    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $path = $query['path'] ?? '';
            $language = $query['language'] ?? null;
            $limit = $query['limit'] ?? 5;

            return ApiResponse::create($this->app()->suggestions()->suggest(
                is_string($path) ? $path : '',
                is_string($language) ? $language : null,
                is_numeric($limit) ? (int) $limit : 5,
            ));
        });
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $suggestions = $this->app()->suggestions();
            $result = $suggestions->listWithCounts($this->query($request));

            return ApiResponse::create($result['rows'], 200, [], [
                'total' => count($result['rows']),
                'counts' => $result['counts'],
                'bulk_accept_score' => $suggestions->bulkAcceptScore(),
            ]);
        });
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, fn (): ResponseInterface => ApiResponse::create($this->app()->suggestions()->generate()));
    }

    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $result = $this->app()->suggestions()->accept((string) $this->getRouteParam($request, 'id'), $this->body($request));
            $ruleId = is_string($result['rule']['id'] ?? null) ? $result['rule']['id'] : '';

            return ApiResponse::created($result, $this->getApiBaseUrl() . '/redirects/rules/' . rawurlencode($ruleId));
        });
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, fn (): ResponseInterface => ApiResponse::create(
            $this->app()->suggestions()->reject((string) $this->getRouteParam($request, 'id')),
        ));
    }

    public function bulkAccept(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $body = $this->body($request);
            $min = $body['min_score'] ?? null;
            if ($min !== null && !is_numeric($min)) {
                throw new InvalidInputException('"min_score" must be a number.', field: 'min_score', errorCode: 'invalid_type');
            }
            $ids = $body['ids'] ?? null;

            return ApiResponse::create($this->app()->suggestions()->bulkAccept(
                $min === null ? null : (float) $min,
                is_array($ids) ? array_values(array_filter($ids, is_string(...))) : null,
                self::flag($body['dry_run'] ?? null),
            ));
        });
    }
}
