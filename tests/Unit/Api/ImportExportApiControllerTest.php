<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\RedirectManager\Api\BaseController;
use Grav\Plugin\RedirectManager\Api\ImportExportApiController;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Tests\Unit\Api\Support\ApiTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\FakeServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[CoversClass(ImportExportApiController::class)]
#[CoversClass(BaseController::class)]
#[Group('api')]
final class ImportExportApiControllerTest extends ApiTestCase
{
    private const CSV = "source,target,status\n/old-a,/new-a,301\n/old-b,/new-b,302\n";

    private ImportExportApiController $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->controller(ImportExportApiController::class);
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

    /**
     * @param array<string, mixed> $config
     */
    private function useApp(array $config = [], ?SiteContext $site = null): void
    {
        $this->app = $this->makeApp($config, [new PageInfo('/target', slug: 'target', title: 'Target')], $site);
        $this->api = $this->controller(ImportExportApiController::class);
    }

    public function testFormatsListsTheImportAndExportFormats(): void
    {
        $data = self::data($this->api->formats(new FakeServerRequest()));

        $byId = array_column($data, null, 'id');
        self::assertArrayHasKey('csv', $byId);
        self::assertTrue($byId['csv']['import']);
        self::assertTrue($byId['csv']['export']);
        self::assertSame('text/csv', explode(';', $byId['csv']['mime'])[0]);
    }

    public function testPreviewShowsTheRowsWithoutStoringAnything(): void
    {
        $data = self::data($this->api->preview(new FakeServerRequest(body: ['content' => self::CSV, 'filename' => 'rules.csv', 'format' => 'csv'])));

        self::assertCount(2, $data['rows']);
        self::assertSame('/old-a', $data['rows'][0]['rule']['source']);
        self::assertSame([], $this->app->rules()->all());
    }

    public function testPreviewWithoutContentIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->preview(new FakeServerRequest(body: [])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('content', $e->getValidationErrors()[0]['field']);
    }

    public function testCommitStoresTheRules(): void
    {
        $data = self::data($this->api->commit(new FakeServerRequest(body: ['content' => self::CSV, 'filename' => 'rules.csv', 'format' => 'csv'])));

        self::assertSame(2, $data['created']);
        self::assertSame(['/old-a', '/old-b'], array_map(static fn ($r): string => $r->source, $this->app->rules()->all()));
    }

    public function testCommitAcceptsBase64Content(): void
    {
        $this->api->commit(new FakeServerRequest(body: ['content' => base64_encode(self::CSV), 'encoding' => 'base64', 'filename' => 'rules.csv', 'format' => 'csv']));

        self::assertCount(2, $this->app->rules()->all());
    }

    public function testSitemapCreatesSuggestionsForPathsWithoutAPage(): void
    {
        $this->useApp();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://old.example.com/target</loc></url><url><loc>https://old.example.com/gone</loc></url></urlset>';

        $data = self::data($this->api->sitemap(new FakeServerRequest(body: ['content' => $xml])));

        self::assertSame(2, $data['total']);
        self::assertSame(['/gone'], $data['missing_paths']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function oversizedBodies(): iterable
    {
        yield 'preview' => ['preview', 2_000_000];
        yield 'commit' => ['commit', 5_000_000];
        yield 'sitemap' => ['sitemap', 9_999_999];
    }

    #[DataProvider('oversizedBodies')]
    public function testAnOversizedContentLengthIsRefusedBeforeParsing(string $action, int $length): void
    {
        $this->useApp(['import' => ['max_mb' => 1]]);

        $e = self::thrownBy(fn () => $this->api->{$action}(new FakeServerRequest(body: ['content' => self::CSV], headers: ['Content-Length' => (string) $length])));

        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(413, $e->getStatusCode());
        self::assertSame('Payload Too Large', $e->getErrorTitle());
        self::assertSame(sprintf('The request is %d bytes; imports are limited to 1 MB.', $length), $e->getMessage());
        self::assertSame([], $this->app->rules()->all());
    }

    public function testAContentLengthWithinTheBase64AllowanceIsParsed(): void
    {
        $this->useApp(['import' => ['max_mb' => 1]]);

        // 1 MB plus the base64 overhead and 64 KiB of JSON around it
        $data = self::data($this->api->preview(new FakeServerRequest(body: ['content' => self::CSV, 'format' => 'csv'], headers: ['Content-Length' => '1400000'])));

        self::assertCount(2, $data['rows']);
    }

    public function testAMissingContentLengthIsNotARefusal(): void
    {
        self::assertSame(200, $this->api->preview(new FakeServerRequest(body: ['content' => self::CSV, 'format' => 'csv']))->getStatusCode());
    }

    public function testExportReturnsTheFileAsJson(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/b'],
            ['id' => 'r2', 'source' => '/c', 'target' => '/d'],
        ]);

        $data = self::data($this->api->export(new FakeServerRequest(['format' => 'csv', 'ids' => 'r2'])));

        self::assertSame(1, $data['exported']);
        self::assertStringContainsString('/c', $data['content']);
        self::assertStringNotContainsString('/a,', $data['content']);
        self::assertSame('.csv', substr($data['filename'], -4));
    }

    public function testExportOfAnUnknownFormatIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->export(new FakeServerRequest(['format' => 'docx'])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('unknown_format', $e->getValidationErrors()[0]['code']);
    }

    public function testSiteConfigShowsGravsRedirectsAndRoutes(): void
    {
        $this->useApp([], new SiteContext(siteRedirects: ['/old' => '/new'], siteRoutes: ['/alias' => '/real']));

        $data = self::data($this->api->siteConfig(new FakeServerRequest()));

        self::assertSame(['/old' => '/new'], $data['redirects']);
        self::assertSame(['/alias' => '/real'], $data['routes']);
        self::assertSame([], $data['settings']);
    }

    public function testSiteConfigImportTurnsThemIntoRules(): void
    {
        $this->useApp([], new SiteContext(siteRedirects: ['/old' => '/target']));

        $data = self::data($this->api->siteConfigImport(new FakeServerRequest(body: [])));

        self::assertSame(1, $data['created']);
        self::assertSame('/old', $this->app->rules()->all()[0]->source);
    }

    public function testSiteConfigImportWithNothingToImportIs422(): void
    {
        $e = self::thrownBy(fn () => $this->api->siteConfigImport(new FakeServerRequest(body: [])));

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('nothing_to_import', $e->getValidationErrors()[0]['code']);
    }

    public function testImportsNeedTheManagePermissionAndExportsTheReadPermission(): void
    {
        AbstractApiController::$granted = [BaseController::READ];

        self::assertSame(200, $this->api->export(new FakeServerRequest())->getStatusCode());
        foreach (['preview', 'commit', 'sitemap', 'siteConfigImport'] as $action) {
            self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->{$action}(new FakeServerRequest(body: ['content' => self::CSV]))));
        }

        AbstractApiController::$granted = [BaseController::MANAGE];
        foreach (['formats', 'export', 'siteConfig'] as $action) {
            self::assertInstanceOf(ForbiddenException::class, self::thrownBy(fn () => $this->api->{$action}(new FakeServerRequest())));
        }
    }
}
