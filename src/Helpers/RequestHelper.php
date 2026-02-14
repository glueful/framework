<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request Helper
 *
 * Static utility methods for common request operations.
 * Provides backwards compatibility while transitioning from custom Request class.
 */
class RequestHelper
{
    private static ?ApplicationContext $context = null;

    public static function setContext(?ApplicationContext $context): void
    {
        self::$context = $context;
    }

    /**
     * Resolve Request from the DI container instead of creating from globals.
     */
    private static function resolveRequest(): Request
    {
        if (self::$context !== null && self::$context->hasContainer()) {
            return self::$context->getContainer()->get(Request::class);
        }

        throw new \RuntimeException(
            'Request cannot be resolved without ApplicationContext. '
            . 'Call RequestHelper::setContext() first.'
        );
    }
    /**
     * Get request data (POST/PUT/PATCH) with automatic JSON parsing
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return array<string, mixed>
     */
    public static function getRequestData(?Request $request = null): array
    {
        $request = $request ?? self::resolveRequest();
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $request->getContent();
            return ($content !== '' && $content !== false) ? (json_decode($content, true) ?? []) : [];
        }

        return $request->request->all();
    }

    /**
     * Get PUT/PATCH data with automatic JSON parsing
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return array<string, mixed>
     */
    public static function getPutData(?Request $request = null): array
    {
        $request = $request ?? self::resolveRequest();
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $request->getContent();
            return ($content !== '' && $content !== false) ? (json_decode($content, true) ?? []) : [];
        }

        // For form-encoded PUT data
        $content = $request->getContent();
        parse_str($content, $data);
        return $data;
    }

    /**
     * Check if the current request is for admin endpoints
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return bool
     */
    public static function isAdminRequest(?Request $request = null, ?ApplicationContext $context = null): bool
    {
        $request = $request ?? self::resolveRequest();
        $requestUri = $request->getRequestUri();

        // Check if URL path contains /admin segment
        if (str_contains($requestUri, '/admin')) {
            return true;
        }

        // Check for admin API endpoints using configured prefix
        $resolvedContext = $context ?? self::$context;
        if ($resolvedContext !== null) {
            $apiPrefix = api_prefix($resolvedContext);
            if ($apiPrefix !== '' && str_contains($requestUri, $apiPrefix . '/admin')) {
                return true;
            }
        }

        // Fallback check for /api/admin
        if (str_contains($requestUri, '/api/admin')) {
            return true;
        }

        // Check for admin-specific query parameter
        if ($request->query->get('admin') === 'true') {
            return true;
        }

        // Check for requests with admin token in header
        if ($request->headers->has('X-Admin-Access') || $request->headers->has('X-ADMIN-ACCESS')) {
            return true;
        }

        return false;
    }
}
