<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api\Support;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;

require_once dirname(__DIR__, 2) . '/Support/GravStubs.php';

/**
 * Base class of the API controller tests: the real RedirectService (temp dir, fixed clock) behind the controllers,
 * the API plugin stand-ins for everything else, and helpers to decode responses.
 */
abstract class ApiTestCase extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AbstractApiController::$granted = ['*'];
    }

    protected function tearDown(): void
    {
        AbstractApiController::$granted = ['*'];
        parent::tearDown();
    }

    /**
     * @template T of BaseController
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function controller(string $class): BaseController
    {
        $controller = new $class(new Grav(), new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]));
        (new ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $this->app);

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return mixed the "data" member of the response envelope
     */
    protected static function data(ResponseInterface $response): mixed
    {
        return self::decode($response)['data'] ?? null;
    }
}
