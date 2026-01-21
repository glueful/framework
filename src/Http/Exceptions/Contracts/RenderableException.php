<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Contracts;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renderable Exception Interface
 *
 * Implement this interface on custom exceptions to provide
 * custom rendering logic. The exception handler will call
 * the render() method instead of using default rendering.
 *
 * This allows exceptions to control their own response format,
 * which is useful for domain-specific error responses.
 *
 * @example
 * class QuotaExceededException extends HttpException implements RenderableException
 * {
 *     public function render(?Request $request = null): Response
 *     {
 *         return new Response([
 *             'success' => false,
 *             'error' => [
 *                 'code' => 'QUOTA_EXCEEDED',
 *                 'limit' => $this->limit,
 *                 'used' => $this->used,
 *             ],
 *         ], 429);
 *     }
 * }
 */
interface RenderableException
{
    /**
     * Render the exception into an HTTP response
     *
     * @param Request|null $request The current request (if available)
     * @return Response The HTTP response
     */
    public function render(?Request $request = null): Response;
}
