<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Grav\RequestContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContextFactory::class)]
#[Group('grav')]
final class RequestContextFactoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ROOT = ['PHP_SELF' => '/index.php'];

    public function testPlainRequest(): void
    {
        $r = (new RequestContextFactory())->create('/old/page', 'a=1&b[]=2', ['Host' => 'Example.ORG:8080', 'User-Agent' => 'UA', 'Referer' => 'x'], ['c' => 'v'], self::ROOT, 'get');

        self::assertNotNull($r);
        self::assertSame('/old/page', $r->context->path);
        self::assertSame(['a' => '1', 'b' => ['2']], $r->context->query);
        self::assertSame('example.org', $r->context->host);
        self::assertSame('UA', $r->context->header('user-agent'));
        self::assertSame(['c' => 'v'], $r->context->cookies);
        self::assertSame('', $r->basePath);
        self::assertSame('', $r->languagePrefix);
        self::assertNull($r->context->language);
        self::assertSame('GET', $r->method);
        self::assertSame('/old/page', $r->rawPath);
    }

    public function testPathIsDecodedOnceAndCleaned(): void
    {
        $f = new RequestContextFactory();
        self::assertSame('/über uns', $f->create('/%C3%BCber%20uns', '', [], [], self::ROOT)?->context->path);
        self::assertSame('/a%20b', $f->create('/a%2520b', '', [], [], self::ROOT)?->context->path);
        self::assertSame('/a/b', $f->create('//a///b', '', [], [], self::ROOT)?->context->path);
        self::assertSame('/b', $f->create('/a/../b', '', [], [], self::ROOT)?->context->path);
        self::assertSame('/', $f->create('', '', [], [], self::ROOT)?->context->path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'nul' => ['/a%00b'];
        yield 'control' => ['/a%01b'];
        yield 'bad utf8' => ['/%FF%FE'];
        yield 'too long' => ['/' . str_repeat('a', 2100)];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsReturnNull(string $path): void
    {
        self::assertNull((new RequestContextFactory())->create($path, '', [], [], self::ROOT));
    }

    public function testBasePathFromScriptName(): void
    {
        $f = new RequestContextFactory();
        $r = $f->create('/sub/dir/old', '', [], [], ['PHP_SELF' => '/sub/dir/index.php']);
        self::assertNotNull($r);
        self::assertSame('/sub/dir', $r->basePath);
        self::assertSame('/old', $r->context->path);
        self::assertSame('/sub/dir/old', $r->rawPath);

        // the base path only counts on a segment boundary
        self::assertSame('/sub/dirt/old', $f->create('/sub/dirt/old', '', [], [], ['PHP_SELF' => '/sub/dir/index.php'])?->context->path);
        // the base path itself is the home page
        self::assertSame('/', $f->create('/sub/dir', '', [], [], ['PHP_SELF' => '/sub/dir/index.php'])?->context->path);
    }

    public function testBasePathFromCustomBaseUrl(): void
    {
        $f = new RequestContextFactory(['custom_base_url' => 'https://example.org/shop/']);
        $r = $f->create('/shop/old', '', [], [], self::ROOT);
        self::assertNotNull($r);
        self::assertSame('/shop', $r->basePath);
        self::assertSame('/old', $r->context->path);

        $noPath = new RequestContextFactory(['custom_base_url' => 'https://example.org']);
        self::assertSame('', $noPath->create('/old', '', [], [], ['PHP_SELF' => '/x/index.php'])?->basePath);
    }

    public function testLanguagePrefix(): void
    {
        $f = new RequestContextFactory(['languages' => ['en', 'de']]);

        $r = $f->create('/de/alt/seite', '', [], [], self::ROOT);
        self::assertNotNull($r);
        self::assertSame('/alt/seite', $r->context->path);
        self::assertSame('/de', $r->languagePrefix);
        self::assertSame('de', $r->context->language);

        $home = $f->create('/DE', '', [], [], self::ROOT);
        self::assertSame('/', $home?->context->path);
        self::assertSame('/de', $home?->languagePrefix);

        $none = $f->create('/deutsch/alt', '', [], [], self::ROOT);
        self::assertSame('/deutsch/alt', $none?->context->path, 'only whole segments count');
        self::assertSame('', $none?->languagePrefix);
        self::assertNull($none?->context->language);

        $noLanguages = new RequestContextFactory();
        self::assertSame('/de/alt', $noLanguages->create('/de/alt', '', [], [], self::ROOT)?->context->path);
    }

    public function testLanguagePrefixInsideBasePath(): void
    {
        $f = new RequestContextFactory(['languages' => ['de'], 'custom_base_url' => '/sub']);
        $r = $f->create('/sub/de/x', '', [], [], self::ROOT);
        self::assertSame('/x', $r?->context->path);
        self::assertSame('/sub', $r?->basePath);
        self::assertSame('/de', $r?->languagePrefix);
    }

    public function testProxyHeadersOnlyWhenTrusted(): void
    {
        $headers = ['Host' => 'internal.local', 'X-Forwarded-Host' => 'www.example.org, proxy', 'X-Forwarded-Proto' => 'https, http'];

        $untrusted = (new RequestContextFactory())->create('/a', '', $headers, [], ['PHP_SELF' => '/index.php']);
        self::assertSame('internal.local', $untrusted?->context->host);
        self::assertSame('http', $untrusted?->context->scheme);

        $trusted = (new RequestContextFactory(['trust_proxy_headers' => true]))->create('/a', '', $headers, [], ['PHP_SELF' => '/index.php']);
        self::assertSame('www.example.org', $trusted?->context->host);
        self::assertSame('https', $trusted?->context->scheme);

        $bogus = (new RequestContextFactory(['trust_proxy_headers' => true]))->create('/a', '', ['X-Forwarded-Proto' => 'gopher'], [], ['PHP_SELF' => '/index.php']);
        self::assertSame('http', $bogus?->context->scheme);
    }

    public function testDuplicateHostValuesFromThePsrRequestUseTheFirst(): void
    {
        // Grav's request can carry Host as header and as URI part: "a.test:8101, a.test"
        self::assertSame('a.test', (new RequestContextFactory())->create('/a', '', ['host' => 'a.test:8101, a.test'], [], [])?->context->host);
    }

    public function testSchemeFromServerVariables(): void
    {
        $f = new RequestContextFactory();
        self::assertSame('https', $f->create('/a', '', [], [], ['HTTPS' => 'on'])?->context->scheme);
        self::assertSame('http', $f->create('/a', '', [], [], ['HTTPS' => 'off'])?->context->scheme);
        self::assertSame('https', $f->create('/a', '', [], [], ['REQUEST_SCHEME' => 'https'])?->context->scheme);
        self::assertSame('https', $f->create('/a', '', [], [], ['SERVER_PORT' => '443'])?->context->scheme);
    }

    public function testHostFallbacksAndIpv6(): void
    {
        $f = new RequestContextFactory();
        self::assertSame('fallback.test', $f->create('/a', '', [], [], ['HTTP_HOST' => 'fallback.test:81'])?->context->host);
        self::assertSame('[::1]', $f->create('/a', '', ['host' => '[::1]:8080'], [], [])?->context->host);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function exclusions(): iterable
    {
        yield 'api' => ['/api/v1/pages', true];
        yield 'api root' => ['/api', true];
        yield 'api case' => ['/API/v1', true];
        yield 'not api' => ['/apiary', false];
        yield 'admin' => ['/admin/pages', true];
        yield 'configured' => ['/private/area', true];
        yield 'configured glob' => ['/shop/checkout', true];
        yield 'user dir' => ['/user/pages/x.jpg', true];
        yield 'system dir' => ['/system/assets/a.css', true];
        yield 'vendor' => ['/vendor/x', true];
        yield 'cache' => ['/cache/x', true];
        yield 'logs' => ['/logs/x', true];
        yield 'plain user page' => ['/user', false];
        yield 'normal' => ['/blog/post', false];
    }

    #[DataProvider('exclusions')]
    public function testIsExcluded(string $path, bool $excluded): void
    {
        $f = new RequestContextFactory(['excluded_paths' => ['/private', '/shop/checkout*']]);
        self::assertSame($excluded, $f->isExcluded($path));
    }

    public function testCustomApiAndAdminRoutes(): void
    {
        $f = new RequestContextFactory(['api_route' => '/rest', 'admin_route' => '/backend/']);
        self::assertTrue($f->isExcluded('/rest/v1/x'));
        self::assertTrue($f->isExcluded('/backend'));
        self::assertFalse($f->isExcluded('/api/x'));
    }
}
