<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Response helper for fluent API
 *
 * Provides Laravel-style response() helper functionality that works
 * with Glueful's Response class. Supports method chaining and common
 * response types used by extensions and service providers.
 */
class ResponseHelper
{
    /**
     * Add a header to the response for method chaining
     * Note: This should not be called directly - use response($content)->header()
     */
    public function header(string $key, string $value): Response
    {
        // This shouldn't be called directly, but we provide it for type compatibility
        return (new Response())->header($key, $value);
    }

    /**
     * Create a not found response (404)
     */
    public function notFound(string $message = 'Resource not found'): Response
    {
        return Response::notFound($message);
    }

    /**
     * Create a JSON response
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($data, $status, $headers);
    }

    /**
     * Create a file response for serving static assets
     * Returns a wrapper that supports method chaining with header()
     *
     * @param array<string, string> $headers
     */
    public function file(
        string $path,
        ?string $name = null,
        array $headers = [],
        string $disposition = 'inline'
    ): FileResponseWrapper {
        $response = new BinaryFileResponse($path, 200, $headers, true, $disposition);

        if ($name !== null) {
            $response->setContentDisposition($disposition, $name);
        }

        return new FileResponseWrapper($response);
    }

    /**
     * Create a forbidden response (403)
     *
     * @param array<string, mixed> $data
     */
    public function forbidden(array $data = [], string $message = 'Forbidden'): Response
    {
        if (count($data) > 0) {
            return new Response($data, 403);
        }

        return Response::forbidden($message);
    }

    /**
     * Create a bad request response (400)
     *
     * @param array<string, mixed> $data
     */
    public function badRequest(array $data = [], string $message = 'Bad Request'): Response
    {
        if (count($data) > 0) {
            return new Response($data, 400);
        }

        return Response::error($message, 400);
    }
}
