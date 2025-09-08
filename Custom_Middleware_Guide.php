<?php

/**
 * Custom Middleware Development Guide for Next-Gen Router
 * 
 * Complete guide showing how framework users can create and use
 * custom middleware with the new routing system.
 */

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

// ============================================================================
// STEP 1: Creating Custom Middleware
// ============================================================================

/**
 * All custom middleware must implement the RouteMiddleware interface
 */
interface RouteMiddleware
{
    /**
     * Handle the middleware logic
     *
     * @param Request $request The HTTP request
     * @param callable $next Next handler in pipeline  
     * @param mixed ...$params Optional parameters from route definition
     * @return mixed Response (can be JsonResponse, array, string, etc.)
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed;
}

// ============================================================================
// EXAMPLE 1: Simple Request Logging Middleware
// ============================================================================

class RequestLoggerMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly bool $logBody = false,
        private readonly array $excludePaths = []
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $path = $request->getPathInfo();
        
        // Skip logging for excluded paths
        if (in_array($path, $this->excludePaths, true)) {
            return $next($request);
        }

        // Log request details
        $logData = [
            'method' => $request->getMethod(),
            'path' => $path,
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($this->logBody && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            $logData['body'] = $request->getContent();
        }

        error_log('API Request: ' . json_encode($logData));

        // Continue to next middleware/controller
        $response = $next($request);

        // Log response (optional)
        error_log('API Response: ' . $request->getMethod() . ' ' . $path . ' completed');

        return $response;
    }
}

// ============================================================================
// EXAMPLE 2: Rate Limiting Middleware
// ============================================================================

class RateLimitMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 3600,
        private readonly string $keyPrefix = 'rate_limit'
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $clientIp = $request->getClientIp();
        $key = $this->keyPrefix . ':' . $clientIp;
        
        // Check if we have a cache system available
        if (!function_exists('cache')) {
            // No cache available, skip rate limiting
            return $next($request);
        }

        // Get current request count
        $currentCount = (int)cache()->get($key, 0);
        
        if ($currentCount >= $this->maxRequests) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Rate limit exceeded. Try again later.',
                'code' => 429,
                'error_code' => 'RATE_LIMITED',
                'retry_after' => $this->windowSeconds
            ], 429, [
                'Retry-After' => $this->windowSeconds,
                'X-RateLimit-Limit' => $this->maxRequests,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => time() + $this->windowSeconds
            ]);
        }

        // Increment counter
        cache()->set($key, $currentCount + 1, $this->windowSeconds);

        // Add rate limit headers to response
        $response = $next($request);
        
        if ($response instanceof JsonResponse) {
            $response->headers->add([
                'X-RateLimit-Limit' => $this->maxRequests,
                'X-RateLimit-Remaining' => max(0, $this->maxRequests - $currentCount - 1),
                'X-RateLimit-Reset' => time() + $this->windowSeconds
            ]);
        }

        return $response;
    }
}

// ============================================================================
// EXAMPLE 3: CORS Middleware
// ============================================================================

class CorsMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly array $allowedOrigins = ['*'],
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private readonly int $maxAge = 86400
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $origin = $request->headers->get('Origin');
        
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse([], 200, $this->getCorsHeaders($origin));
        }

        // Continue to next middleware/controller
        $response = $next($request);

        // Add CORS headers to response
        if ($response instanceof JsonResponse) {
            foreach ($this->getCorsHeaders($origin) as $header => $value) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    private function getCorsHeaders(?string $origin): array
    {
        $headers = [
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age' => $this->maxAge,
        ];

        // Handle origin
        if (in_array('*', $this->allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif ($origin && in_array($origin, $this->allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }
}

// ============================================================================
// EXAMPLE 4: Input Validation Middleware  
// ============================================================================

class ValidateJsonMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly array $requiredFields = [],
        private readonly array $optionalFields = []
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Only validate JSON requests
        if (!$request->headers->contains('Content-Type', 'application/json')) {
            return $next($request);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON format',
                'code' => 400,
                'error_code' => 'INVALID_JSON'
            ], 400);
        }

        // Check required fields
        $missingFields = [];
        foreach ($this->requiredFields as $field) {
            if (!isset($data[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $missingFields),
                'code' => 400,
                'error_code' => 'MISSING_FIELDS',
                'missing_fields' => $missingFields
            ], 400);
        }

        // Add validated data to request attributes
        $request->attributes->set('validated_data', $data);

        return $next($request);
    }
}

// ============================================================================
// EXAMPLE 5: Database Transaction Middleware
// ============================================================================

class DatabaseTransactionMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Check if we have database connection available
        if (!class_exists('\\Glueful\\Database\\Connection')) {
            return $next($request);
        }

        $db = new \Glueful\Database\Connection();
        
        try {
            $db->beginTransaction();
            
            $response = $next($request);
            
            // Commit if successful
            $db->commit();
            
            return $response;
        } catch (\Exception $e) {
            // Rollback on any error
            $db->rollback();
            
            return new JsonResponse([
                'success' => false,
                'message' => 'Database transaction failed: ' . $e->getMessage(),
                'code' => 500,
                'error_code' => 'TRANSACTION_FAILED'
            ], 500);
        }
    }
}

// ============================================================================
// EXAMPLE 6: Response Caching Middleware
// ============================================================================

class ResponseCacheMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly int $ttl = 300,
        private readonly array $cacheableMethods = ['GET'],
        private readonly string $keyPrefix = 'response_cache'
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Only cache certain methods
        if (!in_array($request->getMethod(), $this->cacheableMethods, true)) {
            return $next($request);
        }

        // Check if caching is available
        if (!function_exists('cache')) {
            return $next($request);
        }

        // Generate cache key
        $cacheKey = $this->keyPrefix . ':' . md5($request->getUri());
        
        // Check cache
        $cachedResponse = cache()->get($cacheKey);
        if ($cachedResponse !== null) {
            $response = new JsonResponse();
            $response->setContent($cachedResponse);
            $response->headers->set('X-Cache', 'HIT');
            return $response;
        }

        // Get fresh response
        $response = $next($request);

        // Cache the response if it's successful
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            cache()->set($cacheKey, $response->getContent(), $this->ttl);
            $response->headers->set('X-Cache', 'MISS');
        }

        return $response;
    }
}

// ============================================================================
// STEP 2: Using Custom Middleware with Routes
// ============================================================================

// Basic usage - instantiate and add to route
$router->get('/api/logs', 'LogController@index')
    ->middleware([new RequestLoggerMiddleware()]);

// With constructor parameters
$router->post('/api/data', 'DataController@store')
    ->middleware([new RequestLoggerMiddleware(logBody: true, excludePaths: ['/health'])]);

// Multiple custom middleware
$router->group([
    'prefix' => '/api/v1',
    'middleware' => [
        new CorsMiddleware(),
        new RateLimitMiddleware(maxRequests: 1000, windowSeconds: 3600),
        new RequestLoggerMiddleware()
    ]
], function($router) {
    $router->get('/users', 'UserController@index');
    $router->post('/users', 'UserController@store')
        ->middleware([new ValidateJsonMiddleware(requiredFields: ['name', 'email'])]);
});

// Combining with built-in middleware
$router->group([
    'middleware' => [
        new AuthMiddleware(),  // Built-in auth
        new RateLimitMiddleware(),  // Custom rate limiting
        new RequestLoggerMiddleware()  // Custom logging
    ]
], function($router) {
    $router->get('/protected-data', 'ProtectedController@getData');
});

// ============================================================================
// STEP 3: Middleware with Parameters
// ============================================================================

class ParameterizedMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Access parameters passed from route definition
        $requiredRole = $params[0] ?? 'user';
        $permissions = $params[1] ?? [];

        error_log("Required role: $requiredRole, Permissions: " . implode(',', $permissions));

        // Your middleware logic here...
        
        return $next($request);
    }
}

// Using middleware with parameters
$router->get('/admin', 'AdminController@index')
    ->middleware([new ParameterizedMiddleware(), 'admin', ['read', 'write']]);

// ============================================================================
// STEP 4: Conditional Middleware
// ============================================================================

class ConditionalMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly callable $condition,
        private readonly RouteMiddleware $middleware
    ) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Only apply middleware if condition is met
        if (call_user_func($this->condition, $request)) {
            return $this->middleware->handle($request, $next, ...$params);
        }

        return $next($request);
    }
}

// Usage
$conditionalAuth = new ConditionalMiddleware(
    condition: fn($request) => !str_starts_with($request->getPathInfo(), '/public'),
    middleware: new AuthMiddleware()
);

$router->group(['middleware' => [$conditionalAuth]], function($router) {
    $router->get('/public/info', 'PublicController@info');  // No auth
    $router->get('/private/data', 'PrivateController@data'); // Auth required
});

// ============================================================================
// STEP 5: Middleware Factory Pattern
// ============================================================================

class MiddlewareFactory
{
    public static function createRateLimiter(string $tier): RateLimitMiddleware
    {
        return match($tier) {
            'free' => new RateLimitMiddleware(100, 3600),
            'premium' => new RateLimitMiddleware(1000, 3600),
            'enterprise' => new RateLimitMiddleware(10000, 3600),
            default => new RateLimitMiddleware(100, 3600)
        };
    }

    public static function createValidator(string $type): ValidateJsonMiddleware
    {
        return match($type) {
            'user' => new ValidateJsonMiddleware(['name', 'email'], ['phone', 'address']),
            'product' => new ValidateJsonMiddleware(['name', 'price'], ['description', 'category']),
            'order' => new ValidateJsonMiddleware(['user_id', 'items'], ['notes', 'coupon']),
            default => new ValidateJsonMiddleware()
        };
    }

    public static function createStandardApiStack(): array
    {
        return [
            new CorsMiddleware(),
            new RateLimitMiddleware(),
            new RequestLoggerMiddleware(),
            new AuthMiddleware()
        ];
    }
}

// Usage with factory
$router->group([
    'prefix' => '/api/premium',
    'middleware' => [
        MiddlewareFactory::createRateLimiter('premium'),
        ...MiddlewareFactory::createStandardApiStack()
    ]
], function($router) {
    $router->post('/users', 'UserController@store')
        ->middleware([MiddlewareFactory::createValidator('user')]);
});

// ============================================================================
// STEP 6: Advanced Middleware Examples
// ============================================================================

// A/B Testing Middleware
class ABTestingMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $variant = rand(0, 1) ? 'A' : 'B';
        $request->attributes->set('ab_variant', $variant);
        
        $response = $next($request);
        
        if ($response instanceof JsonResponse) {
            $response->headers->set('X-AB-Variant', $variant);
        }
        
        return $response;
    }
}

// Feature Flag Middleware  
class FeatureFlagMiddleware implements RouteMiddleware
{
    public function __construct(private readonly string $featureName) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (!$this->isFeatureEnabled($this->featureName)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Feature not available',
                'code' => 404,
                'error_code' => 'FEATURE_DISABLED'
            ], 404);
        }

        return $next($request);
    }

    private function isFeatureEnabled(string $feature): bool
    {
        // Check feature flags from config, database, or feature service
        return config("features.$feature", false);
    }
}

// Usage
$router->get('/beta-feature', 'BetaController@newFeature')
    ->middleware([new FeatureFlagMiddleware('beta_ui')]);

// ============================================================================
// STEP 7: Testing Custom Middleware
// ============================================================================

/**
 * Example PHPUnit test for custom middleware
 */
/*
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class RequestLoggerMiddlewareTest extends TestCase
{
    public function testMiddlewareLogsRequest(): void
    {
        $middleware = new RequestLoggerMiddleware();
        $request = Request::create('/test', 'GET');
        
        $nextCalled = false;
        $next = function($req) use (&$nextCalled) {
            $nextCalled = true;
            return new JsonResponse(['success' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function testRateLimitMiddleware(): void
    {
        $middleware = new RateLimitMiddleware(maxRequests: 1);
        $request = Request::create('/test', 'GET');
        $next = fn($req) => new JsonResponse(['success' => true]);

        // First request should pass
        $response1 = $middleware->handle($request, $next);
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request should be rate limited
        $response2 = $middleware->handle($request, $next);
        $this->assertEquals(429, $response2->getStatusCode());
    }
}
*/