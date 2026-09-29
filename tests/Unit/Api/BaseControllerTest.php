<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\Storage\ConcurrentModificationException;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use Grav\Plugin\RedirectManager\Storage\LockTimeoutException;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

require_once dirname(__DIR__) . '/Support/GravStubs.php';

#[CoversClass(BaseController::class)]
#[Group('api')]
final class BaseControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        AbstractApiController::$granted = ['*'];
    }

    /**
     * BaseController is abstract; this subclass opens its protected helpers.
     */
    private function probe(): object
    {
        return new class (new Grav(), new Config(['plugins' => ['api' => ['route' => 'rest/', 'version_prefix' => 'v2']]])) extends BaseController {
            public function callRun(ServerRequestInterface $r, string $permission, callable $action): ResponseInterface
            {
                return $this->run($r, $permission, $action);
            }

            public function callRead(ServerRequestInterface $r, callable $action): ResponseInterface
            {
                return $this->read($r, $action);
            }

            public function callManage(ServerRequestInterface $r, callable $action): ResponseInterface
            {
                return $this->manage($r, $action);
            }

            public function callMay(ServerRequestInterface $r, string $permission): bool
            {
                return $this->may($r, $permission);
            }

            /** @return array<string, mixed> */
            public function callQuery(ServerRequestInterface $r): array
            {
                return $this->query($r);
            }

            /** @return array<string, mixed> */
            public function callBody(ServerRequestInterface $r): array
            {
                return $this->body($r);
            }

            /**
             * @param list<ValidationIssue> $issues
             *
             * @return list<array<string, mixed>>
             */
            public function callErrors(array $issues): array
            {
                return self::errors($issues);
            }

            /**
             * @param list<ValidationIssue> $issues
             *
             * @return list<array<string, mixed>>
             */
            public function callIssues(array $issues): array
            {
                return self::issues($issues);
            }

            public function callFlag(mixed $value, bool $default = false): bool
            {
                return self::flag($value, $default);
            }

            public function callBaseUrl(): string
            {
                return $this->getApiBaseUrl();
            }
        };
    }

    /**
     * @param callable(): mixed $callable
     */
    private static function thrownBy(callable $callable): Throwable
    {
        try {
            $callable();
        } catch (Throwable $e) {
            return $e;
        }
        self::fail('Nothing was thrown.');
    }

    public function testRunReturnsTheActionsResponse(): void
    {
        $response = $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn (): ResponseInterface => ApiResponse::create(['ok' => true]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":{"ok":true}}', (string) $response->getBody());
    }

    public function testRunChecksThePermissionBeforeTheActionRuns(): void
    {
        AbstractApiController::$granted = [BaseController::READ];
        $ran = false;

        $e = self::thrownBy(fn () => $this->probe()->callManage(new FakeServerRequest(), static function () use (&$ran): ResponseInterface {
            $ran = true;

            return ApiResponse::create(null);
        }));

        self::assertInstanceOf(ForbiddenException::class, $e);
        self::assertFalse($ran);
    }

    public function testReadAndManageUseTheirOwnPermission(): void
    {
        $probe = $this->probe();
        $ok = static fn (): ResponseInterface => ApiResponse::noContent();

        AbstractApiController::$granted = [BaseController::READ];
        self::assertSame(204, $probe->callRead(new FakeServerRequest(), $ok)->getStatusCode());

        AbstractApiController::$granted = [BaseController::MANAGE];
        self::assertSame(204, $probe->callManage(new FakeServerRequest(), $ok)->getStatusCode());
        self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $probe->callRead(new FakeServerRequest(), $ok)));
    }

    public function testMayAnswersInsteadOfThrowing(): void
    {
        $probe = $this->probe();
        AbstractApiController::$granted = [BaseController::READ];

        self::assertTrue($probe->callMay(new FakeServerRequest(), BaseController::READ));
        self::assertFalse($probe->callMay(new FakeServerRequest(), BaseController::MANAGE));
    }

    /**
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function exceptionMapping(): iterable
    {
        yield 'unknown record' => [new ResourceNotFoundException('rule', 'r1'), 404, NotFoundException::class];
        yield 'revision conflict' => [new RevisionConflictException('changed'), 409, ConflictException::class];
        yield 'concurrent modification' => [new ConcurrentModificationException('a', 'b'), 409, ConflictException::class];
        yield 'rate limited' => [new RateLimitedException(30), 429, TooManyRequestsException::class];
        yield 'payload too large' => [new PayloadTooLargeException(20, 10), 413, ApiException::class];
        yield 'unavailable' => [new UnavailableException('no'), 503, ApiException::class];
        yield 'lock timeout' => [new LockTimeoutException('locked'), 503, ApiException::class];
        yield 'corrupt rules' => [new CorruptRulesFileException('/x/rules.yaml', 'bad yaml'), 500, ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('exceptionMapping')]
    public function testApplicationExceptionsBecomeApiErrors(Throwable $error, int $status, string $class): void
    {
        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static function () use ($error): never {
            throw $error;
        }));

        self::assertInstanceOf($class, $e);
        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame($status, $e->getStatusCode());
        self::assertSame($error->getMessage(), $e->getMessage());
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn () => throw new RateLimitedException(42)));

        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(['Retry-After' => '42'], $e->getHeaders());
    }

    public function testErrorTitlesOfThe5xxMappings(): void
    {
        $titles = [];
        foreach ([new PayloadTooLargeException(2, 1), new UnavailableException('x'), new CorruptRulesFileException('/r', 'bad')] as $error) {
            $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn () => throw $error));
            self::assertInstanceOf(ApiException::class, $e);
            $titles[] = $e->getErrorTitle();
        }

        self::assertSame(['Payload Too Large', 'Service Unavailable', 'Rules file is corrupt'], $titles);
    }

    public function testRuleValidationBecomesA422WithFieldErrors(): void
    {
        $issues = [
            ValidationIssue::error('source_empty', 'source', 'Source is empty.'),
            ValidationIssue::warning('chain', 'target', 'Part of a chain.', ['depth' => 2]),
        ];

        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::MANAGE, static fn () => throw new RuleValidationException($issues, 'Invalid.')));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame(422, $e->getStatusCode());
        self::assertSame('Invalid.', $e->getMessage());
        self::assertSame([
            ['field' => 'source', 'code' => 'source_empty', 'message' => 'Source is empty.', 'severity' => 'error'],
            ['field' => 'target', 'code' => 'chain', 'message' => 'Part of a chain.', 'severity' => 'warning', 'params' => ['depth' => 2]],
        ], $e->getValidationErrors());
    }

    public function testInvalidInputBecomesA422WithItsOwnIssue(): void
    {
        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn () => throw new InvalidInputException('"path" is required.', field: 'path', errorCode: 'required')));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame([['field' => 'path', 'code' => 'required', 'message' => '"path" is required.', 'severity' => 'error']], $e->getValidationErrors());
    }

    public function testApiExceptionsPassThroughUntouched(): void
    {
        $original = new ConflictException('mine');

        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn () => throw $original));

        self::assertSame($original, $e);
    }

    public function testUnknownExceptionsAreNotSwallowed(): void
    {
        $e = self::thrownBy(fn () => $this->probe()->callRun(new FakeServerRequest(), BaseController::READ, static fn () => throw new \LogicException('bug')));

        self::assertInstanceOf(\LogicException::class, $e);
    }

    public function testIssuesUseTheFullIssueShape(): void
    {
        $issue = ValidationIssue::warning('chain', 'target', 'Chain.', ['depth' => 2]);

        self::assertSame([$issue->toArray()], $this->probe()->callIssues([$issue]));
        self::assertSame([], $this->probe()->callIssues([]));
    }

    public function testErrorsLeaveOutEmptyParams(): void
    {
        $rows = $this->probe()->callErrors([ValidationIssue::info('note', 'status', 'Note.')]);

        self::assertSame([['field' => 'status', 'code' => 'note', 'message' => 'Note.', 'severity' => 'info']], $rows);
    }

    public function testQueryAndBodyReadTheRequest(): void
    {
        $request = new FakeServerRequest(['page' => '2'], ['source' => '/a']);
        $probe = $this->probe();

        self::assertSame(['page' => '2'], $probe->callQuery($request));
        self::assertSame(['source' => '/a'], $probe->callBody($request));
        self::assertSame([], $probe->callBody(new FakeServerRequest()));
    }

    /**
     * @return iterable<string, array{mixed, bool, bool}>
     */
    public static function flags(): iterable
    {
        yield 'null uses the default (false)' => [null, false, false];
        yield 'null uses the default (true)' => [null, true, true];
        yield 'empty string uses the default' => ['', true, true];
        yield 'true' => [true, false, true];
        yield 'false beats a true default' => [false, true, false];
        yield '"1"' => ['1', false, true];
        yield '"true"' => ['true', false, true];
        yield '"TRUE"' => ['TRUE', false, true];
        yield '"yes"' => ['yes', false, true];
        yield '"on"' => ['on', false, true];
        yield 'int 1' => [1, false, true];
        yield '"0"' => ['0', true, false];
        yield '"false"' => ['false', true, false];
        yield '"no"' => ['no', true, false];
        yield 'array is never on' => [['1'], true, false];
    }

    #[DataProvider('flags')]
    public function testFlag(mixed $value, bool $default, bool $expected): void
    {
        self::assertSame($expected, $this->probe()->callFlag($value, $default));
    }

    public function testApiBaseUrlTrimsTheConfiguredRoute(): void
    {
        self::assertSame('/rest/v2', $this->probe()->callBaseUrl());
    }
}
