<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RedirectManager\App\RuleQuery;
use Grav\Plugin\RedirectManager\App\RuleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Rules and the rule tester: /redirects/rules..., /redirects/analysis, /redirects/groups, /redirects/test.
 * Thin: reads the request, calls RuleService or TesterService and shapes the response (docs/API.md).
 */
final class ApiController extends BaseController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $query = $this->query($request);
            $result = $this->app()->rules()->list(RuleQuery::fromArray($query, 50));

            return ApiResponse::paginated(
                $result['rows'],
                $result['total'],
                $result['page'],
                $result['per_page'],
                $this->getApiBaseUrl() . '/redirects/rules',
                200,
                [],
                ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page']] + $result['meta'],
                null,
                $query,
            );
        });
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $body = $this->body($request);
            if (self::flag($this->query($request)['dry_run'] ?? null)) {
                $checked = $rules->validate($body);

                return ApiResponse::create([
                    'rule' => $checked['rule'],
                    'issues' => self::issues($checked['issues']),
                    'preview' => $checked['preview'],
                ]);
            }
            $saved = $rules->create($body);

            return ApiResponse::created(
                $rules->presentSaved($saved['rule'], $saved['issues']),
                $this->getApiBaseUrl() . '/redirects/rules/' . rawurlencode($saved['rule']->id),
                ['ETag' => '"' . $rules->etag($saved['rule']) . '"'],
            );
        });
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $id = (string) $this->getRouteParam($request, 'id');
            $etag = $rules->etag($rules->find($id));

            return $this->respondWithEtag($rules->get($id), 200, [], $etag);
        });
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $id = (string) $this->getRouteParam($request, 'id');
            $current = $rules->find($id);
            $this->validateEtag($request, $rules->etag($current));

            $saved = $rules->update($id, $this->body($request));

            return $this->respondWithEtag(
                $rules->presentSaved($saved['rule'], $saved['issues']),
                200,
                [],
                $rules->etag($saved['rule']),
            );
        });
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $id = (string) $this->getRouteParam($request, 'id');
            $current = $rules->find($id);
            $this->validateEtag($request, $rules->etag($current));

            return ApiResponse::create($rules->plain($rules->delete($id)));
        });
    }

    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $rows = $this->body($request)['rules'] ?? null;
            $restored = $rules->restore(is_array($rows) ? array_values($rows) : []);

            return ApiResponse::create([
                'restored' => count($restored),
                'rules' => array_map($rules->plain(...), $restored),
            ]);
        });
    }

    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $body = $this->body($request);
            $ids = $body['ids'] ?? null;
            $result = $rules->bulk(
                is_string($body['action'] ?? null) ? $body['action'] : '',
                is_array($ids) ? array_values($ids) : [],
                $body['value'] ?? null,
            );

            return ApiResponse::create([
                'affected' => $result['affected'],
                'rules' => array_map($rules->plain(...), $result['rules']),
                'skipped' => array_map(static fn (array $s): array => [
                    'id' => $s['id'],
                    'reason' => $s['reason'],
                    'errors' => self::errors($s['issues']),
                ], $result['skipped']),
            ]);
        });
    }

    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $ids = $this->body($request)['ids'] ?? null;
            $changed = $rules->reorder(is_array($ids) ? array_values($ids) : []);

            return ApiResponse::create([
                'affected' => count($changed),
                'rules' => array_map($rules->plain(...), $changed),
            ]);
        });
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, function () use ($request): ResponseInterface {
            $checked = $this->app()->rules()->validate($this->body($request));

            return ApiResponse::create(['issues' => self::issues($checked['issues']), 'preview' => $checked['preview']]);
        });
    }

    public function shortenChain(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $rules = $this->app()->rules();
            $saved = $rules->shortenChain((string) $this->getRouteParam($request, 'id'));

            return $this->respondWithEtag(
                $rules->presentSaved($saved['rule'], $saved['issues']),
                200,
                [],
                $rules->etag($saved['rule']),
            );
        });
    }

    public function analysis(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->rules()->analysis()));
    }

    public function groups(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->rules()->groups()));
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->tester()->test($this->body($request))));
    }
}
