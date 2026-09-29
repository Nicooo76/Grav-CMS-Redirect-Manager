<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The 404 monitor: /redirects/404, /redirects/404/trend, /entries, /ignore, /resolve (docs/API.md).
 */
final class NotFoundApiController extends BaseController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $result = $this->app()->notFound()->groups($query);

            return ApiResponse::paginated(
                $result['rows'],
                $result['total'],
                $result['page'],
                $result['per_page'],
                $this->getApiBaseUrl() . '/redirects/404',
                200,
                [],
                ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page']] + $result['meta'],
                null,
                $query,
            );
        });
    }

    public function trend(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->notFound()->trend($this->query($request))));
    }

    public function entries(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $path = $this->query($request)['path'] ?? '';
            if (!is_string($path) || $path === '') {
                throw new InvalidInputException('"path" is required.', field: 'path', errorCode: 'required');
            }

            return ApiResponse::create($this->app()->notFound()->entries($path));
        });
    }

    public function ignore(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $body = $this->body($request);
            $pattern = $body['pattern'] ?? '';
            if (!is_string($pattern)) {
                throw new InvalidInputException('"pattern" must be a string.', field: 'pattern', errorCode: 'invalid_type');
            }

            return ApiResponse::create($this->app()->notFound()->ignore($pattern, self::flag($body['purge'] ?? null)));
        });
    }

    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $body = $this->body($request);
            $paths = $body['paths'] ?? [];

            return ApiResponse::create($this->app()->notFound()->resolve(
                is_array($paths) ? array_values($paths) : [],
                self::flag($body['resolved'] ?? null, true),
            ));
        });
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $body = $this->body($request);
            $path = $query['path'] ?? null;

            return ApiResponse::create($this->app()->notFound()->delete(
                is_string($path) && $path !== '' ? $path : null,
                self::flag($body['all'] ?? ($query['all'] ?? null)),
            ));
        });
    }
}
