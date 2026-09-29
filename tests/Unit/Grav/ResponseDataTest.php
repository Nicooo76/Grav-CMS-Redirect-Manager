<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Grav\RequestContextResult;
use Grav\Plugin\RedirectManager\Grav\ResponseData;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ResponseData::class)]
#[CoversClass(RequestContextResult::class)]
#[Group('grav')]
final class ResponseDataTest extends GravTestCase
{
    public function testDefaults(): void
    {
        $data = new ResponseData(204);

        self::assertSame(204, $data->status);
        self::assertSame([], $data->headers);
        self::assertSame('', $data->body);
    }

    public function testHeaderLookupIgnoresCase(): void
    {
        $data = new ResponseData(301, ['Location' => '/new', 'Cache-Control' => 'no-store']);

        self::assertSame('/new', $data->header('location'));
        self::assertSame('no-store', $data->header('CACHE-CONTROL'));
        self::assertNull($data->header('X-Missing'));
    }

    public function testToPsr7BuildsTheResponseWithStatusHeadersAndBody(): void
    {
        $response = (new ResponseData(410, ['Content-Type' => 'text/html', 'X-Rule' => 'r1'], '<p>Gone</p>'))->toPsr7();

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('r1', $response->getHeaderLine('x-rule'));
        self::assertSame('<p>Gone</p>', (string) $response->getBody());
    }

    public function testToPsr7WithoutHeadersOrBody(): void
    {
        $response = (new ResponseData(302))->toPsr7();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $response->getHeaders());
        self::assertSame('', (string) $response->getBody());
    }

    public function testRequestContextResultDefaultsToGet(): void
    {
        $context = new RequestContext('/x');
        $result = new RequestContextResult($context, '/sub', '/de', '/sub/de/x%20y');

        self::assertSame($context, $result->context);
        self::assertSame('/sub', $result->basePath);
        self::assertSame('/de', $result->languagePrefix);
        self::assertSame('/sub/de/x%20y', $result->rawPath);
        self::assertSame('', $result->rawQuery);
        self::assertSame('GET', $result->method);
        self::assertFalse($result->isHead());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function methods(): iterable
    {
        yield 'HEAD' => ['HEAD', true];
        yield 'GET' => ['GET', false];
        yield 'POST' => ['POST', false];
        yield 'lowercase head is not normalized here' => ['head', false];
    }

    #[DataProvider('methods')]
    public function testIsHead(string $method, bool $expected): void
    {
        $result = new RequestContextResult(new RequestContext('/x'), '', '', '/x', 'a=1', $method);

        self::assertSame($expected, $result->isHead());
        self::assertSame('a=1', $result->rawQuery);
    }
}
