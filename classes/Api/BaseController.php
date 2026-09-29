<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\App\RedirectService;
use Grav\Plugin\RedirectManager\Grav\GravBootstrap;
use Grav\Plugin\RedirectManager\Storage\ConcurrentModificationException;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use Grav\Plugin\RedirectManager\Storage\LockTimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing of the plugin's API controllers: permission checks, the application service, and the mapping
 * of application exceptions to RFC 7807 errors (validation 422, unknown record 404, changed record 409, too
 * many checks 429, import too large 413).
 */
abstract class BaseController extends AbstractApiController
{
    public const READ = 'api.redirects.read';
    public const MANAGE = 'api.redirects.manage';

    private ?RedirectService $app = null;

    protected function app(): RedirectService
    {
        return $this->app ??= GravBootstrap::service($this->grav);
    }

    /**
     * Checks the permission, runs the action and turns application exceptions into API errors.
     *
     * @param callable(): ResponseInterface $action
     */
    protected function run(ServerRequestInterface $request, string $permission, callable $action): ResponseInterface
    {
        $this->requirePermission($request, $permission);
        try {
            return $action();
        } catch (ApiException $e) {
            throw $e;
        } catch (RuleValidationException $e) {
            throw new ValidationException($e->getMessage(), self::errors($e->issues));
        } catch (InvalidInputException $e) {
            throw new ValidationException($e->getMessage(), self::errors($e->issues));
        } catch (ResourceNotFoundException $e) {
            throw new NotFoundException($e->getMessage());
        } catch (RevisionConflictException|ConcurrentModificationException $e) {
            throw new ConflictException($e->getMessage());
        } catch (RateLimitedException $e) {
            throw new TooManyRequestsException($e->getMessage(), $e->retryAfter);
        } catch (PayloadTooLargeException $e) {
            throw new ApiException(413, 'Payload Too Large', $e->getMessage());
        } catch (UnavailableException|LockTimeoutException $e) {
            throw new ApiException(503, 'Service Unavailable', $e->getMessage());
        } catch (CorruptRulesFileException $e) {
            throw new ApiException(500, 'Rules file is corrupt', $e->getMessage());
        }
    }

    /**
     * Whether the request may exercise the permission. Same resolution as requirePermission() (key scope cap, super
     * admin, `api.access` plus the permission), but it answers instead of throwing.
     */
    protected function may(ServerRequestInterface $request, string $permission): bool
    {
        try {
            $this->requirePermission($request, $permission);
        } catch (ApiException) {
            return false;
        }

        return true;
    }

    /**
     * Read access: `api.redirects.read`.
     *
     * @param callable(): ResponseInterface $action
     */
    protected function read(ServerRequestInterface $request, callable $action): ResponseInterface
    {
        return $this->run($request, self::READ, $action);
    }

    /**
     * Write access: `api.redirects.manage`.
     *
     * @param callable(): ResponseInterface $action
     */
    protected function manage(ServerRequestInterface $request, callable $action): ResponseInterface
    {
        return $this->run($request, self::MANAGE, $action);
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<array<string, mixed>>
     */
    protected static function errors(array $issues): array
    {
        return array_map(static function (ValidationIssue $i): array {
            $row = ['field' => $i->field, 'code' => $i->code, 'message' => $i->message, 'severity' => $i->severity->value];
            if ($i->params !== []) {
                $row['params'] = $i->params;
            }

            return $row;
        }, $issues);
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<array<string, mixed>>
     */
    protected static function issues(array $issues): array
    {
        return array_map(static fn (ValidationIssue $i): array => $i->toArray(), $issues);
    }

    /**
     * Query parameters as plain strings and arrays.
     *
     * @return array<string, mixed>
     */
    protected function query(ServerRequestInterface $request): array
    {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        return $query;
    }

    /**
     * A request body that must be a JSON object.
     *
     * @return array<string, mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        /** @var array<string, mixed> $body */
        $body = $this->getRequestBody($request);

        return $body;
    }

    protected static function flag(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes', 'on'], true);
    }
}
