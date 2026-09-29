<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Api;

use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Import and export: /redirects/import/..., /redirects/export, /redirects/site-config....
 * Bodies carry the file as text in JSON; the size limit is `import.max_mb`.
 */
final class ImportExportApiController extends BaseController
{
    public function formats(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->importExport()->formats()));
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $this->guardSize($request);

            return ApiResponse::create($this->app()->importExport()->preview($this->body($request)));
        });
    }

    public function commit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $this->guardSize($request);

            return ApiResponse::create($this->app()->importExport()->commit($this->body($request)));
        });
    }

    public function sitemap(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, function () use ($request): ResponseInterface {
            $this->guardSize($request);

            return ApiResponse::create($this->app()->importExport()->sitemap($this->body($request)));
        });
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->importExport()->export($this->query($request))));
    }

    public function siteConfig(ServerRequestInterface $request): ResponseInterface
    {
        return $this->read($request, fn (): ResponseInterface => ApiResponse::create($this->app()->importExport()->siteConfig()));
    }

    public function siteConfigImport(ServerRequestInterface $request): ResponseInterface
    {
        return $this->manage($request, fn (): ResponseInterface => ApiResponse::create($this->app()->importExport()->importSiteConfig($this->body($request))));
    }

    /**
     * Refuses an oversized body from the Content-Length header before any parsing work; the service checks the
     * decoded content again. JSON and base64 add about a third on top of the file size.
     */
    private function guardSize(ServerRequestInterface $request): void
    {
        $length = (int) $request->getHeaderLine('Content-Length');
        $max = $this->app()->importExport()->maxBytes();
        if ($length > (int) ($max * 4 / 3) + 65536) {
            throw new ApiException(413, 'Payload Too Large', sprintf('The request is %d bytes; imports are limited to %d MB.', $length, intdiv($max, 1048576)));
        }
    }
}
