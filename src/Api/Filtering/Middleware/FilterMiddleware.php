<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Middleware;

use Glueful\Api\Filtering\Exceptions\InvalidFilterException;
use Glueful\Api\Filtering\FilterParser;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for parsing filter, sort, and search parameters
 *
 * This middleware parses query parameters and stores them in request attributes
 * for use by controllers. Controllers can then use QueryFilter classes for
 * advanced filtering with field whitelisting and custom filter methods.
 *
 * Parsed data stored in request attributes:
 * - _filters: array of ParsedFilter objects
 * - _sorts: array of ParsedSort objects
 * - _search: search query string or null
 * - _search_fields: array of requested search fields or null
 */
final class FilterMiddleware implements RouteMiddleware
{
    private FilterParser $parser;
    private ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        $maxDepth = $this->getConfig('max_depth', 3);
        $maxFilters = $this->getConfig('max_filters', 20);
        $this->parser = new FilterParser(
            (int) $maxDepth,
            (int) $maxFilters
        );
    }

    /**
     * Handle the request
     *
     * @param Request $request The HTTP request
     * @param callable $next The next middleware
     * @param mixed ...$params Additional parameters
     * @return Response|mixed
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Check if filtering is enabled
        $enabled = $this->getConfig('enabled', true);
        if ($enabled !== true && $enabled !== 1 && $enabled !== '1') {
            return $next($request);
        }

        // Fast path: no filter/sort/search params
        $hasFilterParams = $request->query->has('filter')
            || $request->query->has('sort')
            || $request->query->has('search');

        if (!$hasFilterParams) {
            return $next($request);
        }

        try {
            // Parse filters
            $filters = $this->parser->parseFilters($request);

            // Parse sorts
            $sorts = $this->parser->parseSorts($request);

            // Parse search
            $search = $this->parser->parseSearch($request);
            $searchFields = $this->parser->parseSearchFields($request);

            // Store parsed data in request attributes for controllers
            $request->attributes->set('_filters', $filters);
            $request->attributes->set('_sorts', $sorts);
            $request->attributes->set('_search', $search);
            $request->attributes->set('_search_fields', $searchFields);

            return $next($request);
        } catch (InvalidFilterException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Get configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($this->context, "api.filtering.{$key}", $default);
        }

        return $default;
    }

    /**
     * Create error response
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     * @return JsonResponse
     */
    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => 'FILTER_ERROR',
            ],
        ], $status);
    }
}
