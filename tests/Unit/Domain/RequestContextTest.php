<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Domain;

use Grav\Plugin\RedirectManager\Domain\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
#[Group('domain')]
final class RequestContextTest extends TestCase
{
    public function testDefaults(): void
    {
        $ctx = new RequestContext('/a');

        self::assertSame('/a', $ctx->path);
        self::assertSame([], $ctx->query);
        self::assertSame('', $ctx->host);
        self::assertSame('https', $ctx->scheme);
        self::assertNull($ctx->language);
        self::assertSame([], $ctx->headers);
        self::assertSame([], $ctx->cookies);
    }

    private function full(): RequestContext
    {
        return new RequestContext('/a', ['q' => '1'], 'example.com', 'http', 'de', ['user-agent' => 'UA'], ['sid' => 'abc']);
    }

    public function testWithPathKeepsEverythingElse(): void
    {
        $ctx = $this->full();
        $new = $ctx->withPath('/b');

        self::assertNotSame($ctx, $new);
        self::assertSame('/a', $ctx->path);
        self::assertSame('/b', $new->path);
        self::assertSame(['q' => '1'], $new->query);
        self::assertSame('example.com', $new->host);
        self::assertSame('http', $new->scheme);
        self::assertSame('de', $new->language);
        self::assertSame(['user-agent' => 'UA'], $new->headers);
        self::assertSame(['sid' => 'abc'], $new->cookies);
    }

    public function testWithQueryKeepsEverythingElse(): void
    {
        $ctx = $this->full();
        $new = $ctx->withQuery(['z' => ['1', '2']]);

        self::assertNotSame($ctx, $new);
        self::assertSame(['q' => '1'], $ctx->query);
        self::assertSame(['z' => ['1', '2']], $new->query);
        self::assertSame('/a', $new->path);
        self::assertSame('example.com', $new->host);
        self::assertSame('http', $new->scheme);
        self::assertSame('de', $new->language);
        self::assertSame(['user-agent' => 'UA'], $new->headers);
        self::assertSame(['sid' => 'abc'], $new->cookies);
    }

    public function testHeaderLookupIgnoresTheCaseOfTheName(): void
    {
        $ctx = $this->full();

        self::assertSame('UA', $ctx->header('User-Agent'));
        self::assertSame('UA', $ctx->header('USER-AGENT'));
        self::assertSame('UA', $ctx->header('user-agent'));
        self::assertNull($ctx->header('Referer'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function queryStringProvider(): iterable
    {
        yield 'empty' => [[], ''];
        yield 'single' => [['a' => '1'], 'a=1'];
        yield 'order is kept' => [['b' => '2', 'a' => '1'], 'b=2&a=1'];
        yield 'space is %20 not plus' => [['q' => 'a b'], 'q=a%20b'];
        yield 'reserved characters are encoded' => [['q' => 'a&b=c/d'], 'q=a%26b%3Dc%2Fd'];
        yield 'nested arrays' => [['a' => ['x', 'y']], 'a%5B0%5D=x&a%5B1%5D=y'];
        yield 'named nesting' => [['f' => ['k' => 'v']], 'f%5Bk%5D=v'];
        yield 'empty value' => [['a' => ''], 'a='];
        yield 'utf8' => [['q' => 'ü'], 'q=%C3%BC'];
        yield 'int' => [['n' => 5], 'n=5'];
        yield 'null is omitted' => [['a' => null, 'b' => '1'], 'b=1'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('queryStringProvider')]
    public function testQueryString(array $query, string $expected): void
    {
        self::assertSame($expected, (new RequestContext('/a', $query))->queryString());
    }
}
