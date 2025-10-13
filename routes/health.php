<?php

use Glueful\Routing\Router;
use Glueful\Controllers\HealthController;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router Router instance injected by RouteManifest::load() */

// Health check routes - organized by access level
$router->group(['prefix' => '/health'], function (Router $router) {
    /**
     * @route GET /health
     * @summary System Health Check
     * @description Get overall system health status including database, cache, extensions, and configuration
     * @tag Health
     * @response 200 application/json "System health check completed" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     status:string="Overall health status (ok|warning|error)",
     *     timestamp:string="ISO timestamp of check",
     *     version:string="Application version",
     *     environment:string="Application environment",
     *     checks:{
     *       database:{
     *         status:string="Database status",
     *         message:string="Database status message",
     *         driver:string="Database driver name",
     *         migrations_applied:integer="Number of applied migrations"
     *       },
     *       cache:{
     *         status:string="Cache status",
     *         message:string="Cache status message",
     *         driver:string="Cache driver name"
     *       },
     *       extensions:{
     *         status:string="Extensions status",
     *         message:string="Extensions status message",
     *         loaded:array="List of loaded extensions"
     *       },
     *       config:{
     *         status:string="Configuration status",
     *         message:string="Configuration status message",
     *         environment:string="Application environment"
     *       }
     *     }
     *   }
     * }
     * @response 503 application/json "System health check failed" {
     *   success:boolean="false",
     *   message:string="Error message",
     *   data:{
     *     status:string="error",
     *     timestamp:string="ISO timestamp of check",
     *     checks:object="Individual check results with error details"
     *   }
     * }
     */
    $router->get('/', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->index();
    })->middleware('rate_limit:60,60'); // 60 requests per minute - high limit for monitoring

    /**
     * @route GET /health/database
     * @summary Database Health Check
     * @description Check database connectivity and functionality using QueryBuilder abstraction
     * @tag Health
     * @response 200 application/json "Database is healthy" {
     *   success:boolean="true",
     *   message:string="Database health check completed",
     *   data:{
     *     status:string="Database status (ok|warning|error)",
     *     message:string="Database status message",
     *     driver:string="Database driver name",
     *     migrations_applied:integer="Number of applied migrations",
     *     connectivity_test:boolean="Connectivity test result"
     *   }
     * }
     * @response 503 application/json "Database is unhealthy" {
     *   success:boolean="false",
     *   message:string="Database health check failed",
     *   data:{
     *     status:string="error",
     *     message:string="Error message",
     *     type:string="Error type"
     *   }
     * }
     */
    $router->get('/database', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->database();
    })->middleware('rate_limit:30,60'); // 30 requests per minute

    /**
     * @route GET /health/cache
     * @summary Cache Health Check
     * @description Check cache connectivity and functionality
     * @tag Health
     * @response 200 application/json "Cache is healthy" {
     *   success:boolean="true",
     *   message:string="Cache health check completed",
     *   data:{
     *     status:string="Cache status (ok|disabled|error)",
     *     message:string="Cache status message",
     *     driver:string="Cache driver name",
     *     operations:string="Operations status"
     *   }
     * }
     * @response 503 application/json "Cache is unhealthy" {
     *   success:boolean="false",
     *   message:string="Cache health check failed",
     *   data:{
     *     status:string="error",
     *     message:string="Error message"
     *   }
     * }
     */
    $router->get('/cache', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->cache();
    })->middleware('rate_limit:30,60'); // 30 requests per minute

    /**
     * @route GET /health/detailed
     * @summary Detailed Health Metrics
     * @description Get comprehensive health metrics with detailed system information
     * @tag Health
     * @requiresAuth true
     * @response 200 application/json "Detailed health metrics retrieved successfully" {
     *   success:boolean="true",
     *   message:string="Detailed health check completed",
     *   data:{
     *     status:string="Overall detailed status",
     *     timestamp:string="ISO timestamp",
     *     system:{
     *       memory_usage:object="Memory usage statistics",
     *       cpu_info:object="CPU information",
     *       disk_usage:object="Disk usage statistics",
     *       process_info:object="Process information"
     *     },
     *     performance:{
     *       response_times:object="Average response times",
     *       error_rates:object="Error rate statistics",
     *       throughput:object="Request throughput metrics"
     *     },
     *     dependencies:{
     *       database:object="Detailed database metrics",
     *       cache:object="Detailed cache metrics",
     *       external_services:object="External service health"
     *     }
     *   }
     * }
     * @response 403 "Insufficient permissions for detailed health metrics"
     * @response 503 "System health check failed"
     */
    $router->get('/detailed', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->detailed();
    })->middleware(['auth', 'rate_limit:10,60']); // Authenticated, 10 requests per minute

    /**
     * @route GET /health/middleware
     * @summary Middleware Pipeline Health
     * @description Check the health and status of the middleware pipeline
     * @tag Health
     * @requiresAuth true
     * @response 200 application/json "Middleware health check completed" {
     *   success:boolean="true",
     *   message:string="Middleware health check completed",
     *   data:{
     *     status:string="Middleware pipeline status",
     *     timestamp:string="ISO timestamp",
     *     pipeline:{
     *       registered:array="List of registered middleware",
     *       active:array="List of active middleware",
     *       performance:object="Middleware performance metrics",
     *       errors:array="Any middleware errors or issues"
     *     },
     *     security:{
     *       auth_middleware:object="Authentication middleware status",
     *       rate_limiting:object="Rate limiting middleware status",
     *       csrf_protection:object="CSRF protection status"
     *     }
     *   }
     * }
     * @response 403 "Insufficient permissions for middleware health"
     * @response 503 "Middleware health check failed"
     */
    $router->get('/middleware', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->middleware();
    })->middleware(['auth', 'rate_limit:20,60']); // Authenticated, 20 requests per minute

    /**
     * @route GET /health/response-api
     * @summary Response API Health
     * @description Check the health and performance of the Response API system
     * @tag Health
     * @requiresAuth true
     * @response 200 application/json "Response API health check completed" {
     *   success:boolean="true",
     *   message:string="Response API health check completed",
     *   data:{
     *     status:string="Response API status",
     *     timestamp:string="ISO timestamp",
     *     performance:{
     *       average_response_time:number="Average response time in milliseconds",
     *       success_rate:number="Percentage of successful responses",
     *       error_rate:number="Percentage of error responses",
     *       throughput:number="Requests per second"
     *     },
     *     formats:{
     *       json:object="JSON response format health",
     *       xml:object="XML response format health",
     *       csv:object="CSV response format health"
     *     },
     *     caching:{
     *       hit_rate:number="Cache hit rate percentage",
     *       response_caching:object="Response caching health"
     *     }
     *   }
     * }
     * @response 403 "Insufficient permissions for Response API health"
     * @response 503 "Response API health check failed"
     */
    $router->get('/response-api', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->responseApi();
    })->middleware(['auth', 'rate_limit:15,60']); // Authenticated, 15 requests per minute

    /**
     * @route GET /health/queue
     * @summary Queue Health
     * @description Get queue sizes, worker activity, and simple readiness signals
     * @tag Health
     * @response 200 application/json "Queue health status" {
     *   status:string="healthy|degraded|error",
     *   queues:object="Aggregated queue stats (pending, delayed, reserved, failed)",
     *   workers:{active:integer, details:array},
     *   reserved:integer,
     *   issues:array
     * }
     */
    $router->get('/queue', function (Request $request) {
        $controller = container()->get(HealthController::class);
        return $controller->queue();
    })->middleware('rate_limit:20,60');
});

/**
 * @route GET /healthz
 * @summary Liveness Check
 * @description Simple liveness check for load balancers and orchestration systems
 * @tag Health
 * @response 200 application/json "Service is alive" {
 *   status:string="ok"
 * }
 */
$router->get('/healthz', fn() => new \Glueful\Http\Response(['status' => 'ok']))
       ->middleware('rate_limit:60,60');

/**
 * @route GET /ready
 * @summary Readiness Check
 * @description Detailed readiness check to determine if service is ready to handle traffic
 * @tag Health
 * @requiresAuth false
 * @security IP allowlist required
 * @response 200 application/json "Service is ready" {
 *   success:boolean="true",
 *   message:string="Service readiness check completed",
 *   data:{
 *     status:string="Readiness status (ready|not_ready)",
 *     timestamp:string="ISO timestamp of check",
 *     dependencies:{
 *       database:object="Database readiness status",
 *       cache:object="Cache readiness status",
 *       external_services:object="External service dependencies"
 *     },
 *     health_score:number="Overall health score (0-100)"
 *   }
 * }
 * @response 503 application/json "Service is not ready" {
 *   success:boolean="false",
 *   message:string="Service readiness check failed",
 *   data:{
 *     status:string="not_ready",
 *     timestamp:string="ISO timestamp of check",
 *     issues:array="List of issues preventing readiness"
 *   }
 * }
 * @response 403 "Access restricted - IP not in allowlist"
 */
$router->get('/ready', [\Glueful\Controllers\HealthController::class, 'readiness'])
       ->middleware(['rate_limit:30,60', 'allow_ip']);
