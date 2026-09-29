<?php

declare(strict_types=1);

/**
 * Stand-ins for the parts of the Grav API plugin that the plugin's REST controllers use: the exceptions, ApiResponse
 * and AbstractApiController. Modelled on .grav/2.2.2/user/plugins/api/classes/Api (constructor signatures, status
 * codes, response envelope and ETag handling are copied); permissions are a test-controlled allow-list.
 */

namespace Grav\Plugin\Api\Exceptions {
    if (!class_exists(ApiException::class, false)) {
        class ApiException extends \RuntimeException
        {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(
                protected readonly int $statusCode,
                protected readonly string $errorTitle,
                string $detail = '',
                protected readonly array $headers = [],
                ?\Throwable $previous = null,
                protected readonly ?string $errorCode = null,
            ) {
                parent::__construct($detail, $statusCode, $previous);
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            public function getErrorTitle(): string
            {
                return $this->errorTitle;
            }

            public function getErrorCode(): ?string
            {
                return $this->errorCode;
            }

            /** @return array<string, string> */
            public function getHeaders(): array
            {
                return $this->headers;
            }
        }
    }

    if (!class_exists(ConflictException::class, false)) {
        class ConflictException extends ApiException
        {
            public function __construct(string $detail = 'The resource has been modified. Refresh and try again.', ?\Throwable $previous = null)
            {
                parent::__construct(409, 'Conflict', $detail, [], $previous);
            }
        }
    }

    if (!class_exists(ForbiddenException::class, false)) {
        class ForbiddenException extends ApiException
        {
            public function __construct(string $detail = 'You do not have permission to perform this action.', ?\Throwable $previous = null)
            {
                parent::__construct(403, 'Forbidden', $detail, [], $previous);
            }
        }
    }

    if (!class_exists(NotFoundException::class, false)) {
        class NotFoundException extends ApiException
        {
            public function __construct(string $detail = 'The requested resource was not found.', ?\Throwable $previous = null)
            {
                parent::__construct(404, 'Not Found', $detail, [], $previous);
            }
        }
    }

    if (!class_exists(TooManyRequestsException::class, false)) {
        class TooManyRequestsException extends ApiException
        {
            public function __construct(string $detail = 'Too many requests.', int $retryAfter = 0, ?\Throwable $previous = null)
            {
                $headers = [];
                if ($retryAfter > 0) {
                    $headers['Retry-After'] = (string) $retryAfter;
                }
                parent::__construct(429, 'Too Many Requests', $detail, $headers, $previous);
            }
        }
    }

    if (!class_exists(ValidationException::class, false)) {
        class ValidationException extends ApiException
        {
            /**
             * @param list<array<string, mixed>> $errors
             */
            public function __construct(
                string $detail = 'The request data is invalid.',
                protected readonly array $errors = [],
                ?\Throwable $previous = null,
            ) {
                parent::__construct(422, 'Unprocessable Entity', $detail, [], $previous);
            }

            /** @return list<array<string, mixed>> */
            public function getValidationErrors(): array
            {
                return $this->errors;
            }
        }
    }
}

namespace Grav\Plugin\Api\Response {
    use Nyholm\Psr7\Response;
    use Psr\Http\Message\ResponseInterface;

    if (!class_exists(ApiResponse::class, false)) {
        class ApiResponse
        {
            /**
             * @param array<string, string> $headers
             * @param array<string, mixed>  $body
             */
            private static function json(int $status, array $headers, array $body): ResponseInterface
            {
                $headers = array_merge($headers, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-store, max-age=0',
                ]);

                return new Response($status, $headers, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            }

            /**
             * @param array<string, string>     $headers
             * @param array<string, mixed>|null $meta
             */
            public static function create(mixed $data, int $status = 200, array $headers = [], ?array $meta = null): ResponseInterface
            {
                $body = ['data' => $data];
                if ($meta !== null) {
                    $body['meta'] = $meta;
                }

                return self::json($status, $headers, $body);
            }

            /**
             * @param array<mixed>          $data
             * @param array<string, string> $headers
             * @param array<string, mixed>  $extraMeta
             * @param array<string, mixed>  $query
             */
            public static function paginated(
                array $data,
                int $total,
                int $page,
                int $perPage,
                string $baseUrl,
                int $status = 200,
                array $headers = [],
                array $extraMeta = [],
                ?int $locatedAtIndex = null,
                array $query = [],
            ): ResponseInterface {
                $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
                $pagination = ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages];
                if ($locatedAtIndex !== null) {
                    $pagination['located_at_index'] = $locatedAtIndex;
                }
                $meta = ['pagination' => $pagination];
                if ($extraMeta !== []) {
                    $meta = array_merge($meta, $extraMeta);
                }

                unset($query['page'], $query['per_page']);
                $link = static fn (int $to): string => $baseUrl . '?' . http_build_query(['page' => $to, 'per_page' => $perPage] + $query);
                $body = ['data' => $data, 'meta' => $meta, 'links' => ['self' => $link($page)]];
                if ($page > 1) {
                    $body['links']['first'] = $link(1);
                    $body['links']['prev'] = $link($page - 1);
                }
                if ($page < $totalPages) {
                    $body['links']['next'] = $link($page + 1);
                    $body['links']['last'] = $link($totalPages);
                }

                return self::json($status, $headers, $body);
            }

            /** @param array<string, string> $headers */
            public static function ok(mixed $data, array $headers = []): ResponseInterface
            {
                return self::create($data, 200, $headers);
            }

            /** @param array<string, string> $headers */
            public static function created(mixed $data, string $location, array $headers = []): ResponseInterface
            {
                return self::create($data, 201, array_merge($headers, ['Location' => $location]));
            }

            /** @param array<string, string> $headers */
            public static function noContent(array $headers = []): ResponseInterface
            {
                return new Response(204, $headers);
            }
        }
    }
}

namespace Grav\Plugin\Api\Controllers {
    use Grav\Common\Config\Config;
    use Grav\Common\Grav;
    use Grav\Plugin\Api\Exceptions\ConflictException;
    use Grav\Plugin\Api\Exceptions\ForbiddenException;
    use Grav\Plugin\Api\Response\ApiResponse;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    if (!class_exists(AbstractApiController::class, false)) {
        abstract class AbstractApiController
        {
            /**
             * Permissions the fake caller holds; requirePermission() throws ForbiddenException for anything else.
             * '*' grants everything (the default). Tests set this and restore the default in tearDown().
             *
             * @var list<string>
             */
            public static array $granted = ['*'];

            public function __construct(
                protected readonly Grav $grav,
                protected readonly Config $config,
            ) {
            }

            protected function requirePermission(ServerRequestInterface $request, string $permission): void
            {
                if (!in_array('*', self::$granted, true) && !in_array($permission, self::$granted, true)) {
                    throw new ForbiddenException();
                }
            }

            /** @return array<string, mixed> */
            protected function getRequestBody(ServerRequestInterface $request): array
            {
                $body = $request->getAttribute('json_body');
                if ($body === null && method_exists($request, 'getParsedBody')) {
                    $body = $request->getParsedBody();
                }

                return is_array($body) ? $body : [];
            }

            protected function getRouteParam(ServerRequestInterface $request, string $name): ?string
            {
                $params = $request->getAttribute('route_params', []);

                return is_array($params) ? ($params[$name] ?? null) : null;
            }

            protected function validateEtag(ServerRequestInterface $request, string $currentHash): void
            {
                $ifMatch = $request->getHeaderLine('If-Match');
                if ($ifMatch && $this->normalizeEtag($ifMatch) !== $currentHash) {
                    throw new ConflictException('The resource has been modified since you last retrieved it. Please fetch the latest version and try again.');
                }
            }

            private function normalizeEtag(string $etag): string
            {
                $etag = trim($etag);
                if (str_starts_with($etag, 'W/')) {
                    $etag = substr($etag, 2);
                }
                $etag = trim($etag, '"');

                return preg_replace('/[-;](?:gzip|br|deflate|zstd)$/i', '', $etag) ?? $etag;
            }

            protected function generateEtag(mixed $data): string
            {
                return md5((string) json_encode($data));
            }

            /**
             * @param array<int, string>        $invalidates
             * @param array<string, mixed>|null $meta
             */
            protected function respondWithEtag(mixed $data, int $status = 200, array $invalidates = [], ?string $etag = null, ?array $meta = null): ResponseInterface
            {
                $etag ??= $this->generateEtag($data);
                $headers = ['ETag' => '"' . $etag . '"'];
                if ($invalidates !== []) {
                    $headers['X-Invalidates'] = implode(', ', $invalidates);
                }

                return ApiResponse::create($data, $status, $headers, $meta);
            }

            protected function getApiBaseUrl(): string
            {
                $base = (string) $this->config->get('plugins.api.route', '/api');
                $prefix = (string) $this->config->get('plugins.api.version_prefix', 'v1');

                return '/' . trim($base, '/') . '/' . $prefix;
            }
        }
    }
}
