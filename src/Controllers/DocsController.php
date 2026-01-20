<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Documentation Controller
 *
 * Serves API documentation files (OpenAPI spec and documentation UI).
 * Documentation must be generated using: php glueful generate:openapi --ui
 */
class DocsController extends BaseController
{
    /**
     * Serve the documentation UI (index.html)
     *
     * @param Request $request HTTP request
     * @return Response|BinaryFileResponse
     */
    public function index(Request $request): Response|BinaryFileResponse
    {
        if (!$this->isDocsEnabled()) {
            return Response::error('API documentation is disabled in production', 404);
        }

        $docsPath = $this->getDocsPath('index.html');

        if (!file_exists($docsPath)) {
            return Response::error(
                'Documentation not generated. Run: php glueful generate:openapi --ui',
                404
            );
        }

        $response = new BinaryFileResponse($docsPath);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'index.html');

        // Cache for 1 hour in non-production environments
        if (env('APP_ENV') !== 'production') {
            $response->headers->set('Cache-Control', 'public, max-age=3600');
        }

        return $response;
    }

    /**
     * Serve the OpenAPI specification (openapi.json)
     *
     * @param Request $request HTTP request
     * @return Response|BinaryFileResponse
     */
    public function openapi(Request $request): Response|BinaryFileResponse
    {
        if (!$this->isDocsEnabled()) {
            return Response::error('API documentation is disabled in production', 404);
        }

        $openapiPath = $this->getDocsPath('openapi.json');

        if (!file_exists($openapiPath)) {
            return Response::error(
                'OpenAPI specification not generated. Run: php glueful generate:openapi',
                404
            );
        }

        $response = new BinaryFileResponse($openapiPath);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'openapi.json');

        // Allow CORS for API documentation consumers
        $response->headers->set('Access-Control-Allow-Origin', '*');

        // Cache for 1 hour in non-production environments
        if (env('APP_ENV') !== 'production') {
            $response->headers->set('Cache-Control', 'public, max-age=3600');
        }

        return $response;
    }

    /**
     * Check if documentation is enabled
     *
     * Documentation is disabled in production by default for security.
     *
     * @return bool True if docs are enabled
     */
    private function isDocsEnabled(): bool
    {
        return (bool) config('documentation.enabled', config('app.api_docs_enabled', true));
    }

    /**
     * Get the path to a documentation file
     *
     * @param string $filename The filename to retrieve
     * @return string Full path to the file
     */
    private function getDocsPath(string $filename): string
    {
        $docsDir = config('documentation.paths.output', base_path('docs'));
        return rtrim($docsDir, '/') . '/' . $filename;
    }
}
