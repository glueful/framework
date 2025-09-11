<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Glueful\Services\ApiMetricsService;
use Symfony\Component\HttpFoundation\Request;

class MetricsMiddleware implements RouteMiddleware
{
    public function __construct(private ApiMetricsService $metrics)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $start = microtime(true);
        $result = $next($request);

        try {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $status = 200;
            if (is_object($result) && method_exists($result, 'getStatusCode')) {
                /** @var int $status */
                $status = (int) $result->getStatusCode();
            }

            $this->metrics->recordMetricAsync([
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'response_time' => $durationMs,
                'status_code' => $status,
                'ip' => $request->getClientIp(),
                'timestamp' => time(),
                'request_id' => function_exists('request_id') ? request_id() : null,
            ]);
        } catch (\Throwable) {
            // Never let metrics impact the request
        }

        // Normalize non-standard return types to Glueful Response for consistency
        if (is_string($result)) {
            return new Response(['message' => $result]);
        }

        return $result;
    }
}
