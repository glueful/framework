# Middleware Cookbook

## Table of Contents

1. [Introduction](#introduction)
2. [RouteMiddleware Interface](#routemiddleware-interface)
3. [Built-in Middleware](#built-in-middleware)
4. [Creating Custom Middleware](#creating-custom-middleware)
5. [Middleware Parameters](#middleware-parameters)
6. [PSR-15 Compatibility](#psr-15-compatibility)
7. [Applying Middleware](#applying-middleware)
8. [Framework Middleware Examples](#framework-middleware-examples)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

## Introduction

The Glueful Framework features a powerful middleware system built around the native `RouteMiddleware` interface, designed for high performance and flexibility. The system supports parameter passing, PSR-15 compatibility, and enterprise-grade features like authentication, rate limiting, and security headers.

### Key Features

- **Native Interface**: Purpose-built `RouteMiddleware` interface optimized for the framework
- **Parameter Support**: Runtime parameters for configurable middleware behavior
- **PSR-15 Bridge**: Seamless integration with PSR-15 middleware ecosystem
- **Enterprise Ready**: Built-in middleware for authentication, security, and monitoring
- **Pipeline Architecture**: Efficient middleware pipeline with lazy resolution
- **Container Integration**: Full dependency injection support

## RouteMiddleware Interface

The core middleware contract provides flexibility with parameter passing while maintaining clean semantics:

```php
<?php
namespace Glueful\Routing;

use Symfony\Component\HttpFoundation\Request;

interface RouteMiddleware
{
    /**
     * Handle middleware processing
     *
     * @param Request $request The HTTP request being processed
     * @param callable $next Next handler in the pipeline - call $next($request) to continue
     * @param mixed ...$params Additional parameters from route or middleware config
     * @return Response|mixed Response object or data to be normalized
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed;
}
```

### Basic Implementation Pattern

```php
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExampleMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Pre-processing: modify request, check conditions, etc.
        $request->attributes->set('processed_at', time());
        
        // Call next middleware or final handler
        $response = $next($request);
        
        // Post-processing: modify response, add headers, log, etc.
        $response->headers->set('X-Processed-By', 'ExampleMiddleware');
        
        return $response;
    }
}
```

## Built-in Middleware

The framework includes enterprise-grade middleware components with comprehensive features:

### Built-in Aliases

- `auth`: Authentication check with multiple provider support
- `rate_limit[:max,window[,type]]`: Cache-backed rate limiting (types: ip|user|endpoint)
- `metrics`: Request metrics collection for monitoring
- `tracing`: Distributed tracing spans (OpenTelemetry compatible)
- `csrf`: CSRF protection for state-changing requests
- `security_headers`: Standard security headers (CSP/HSTS/CORS/etc.)
- `request_logging`: Request/response logging with configurable detail levels
- `lockdown`: Maintenance/lockdown mode with customizable rules
- `allow_ip`: IP allowlist for sensitive endpoints (e.g., health checks)
- `field_selection`: GraphQL-style field selection for REST APIs
- `admin_permission`: Admin privilege checking with role-based access

Aliases are registered in `src/Container/Providers/CoreProvider.php`.

### Authentication Middleware (`auth`)

Enterprise authentication with multiple providers:

```php
// Basic authentication check
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware('auth');

// Admin-only routes
$router->group(['prefix' => '/admin', 'middleware' => ['auth:admin']], function($router) {
    $router->get('/users', [AdminController::class, 'users']);
    $router->get('/settings', [AdminController::class, 'settings']);
});

// Specific provider authentication
$router->get('/api/data', [DataController::class, 'index'])
    ->middleware(['auth:jwt,api_key']);
```

**Features:**
- JWT token validation with expiration checking
- API key authentication
- Multi-provider support (JWT, API Key, LDAP, SAML)
- Event dispatching for auth success/failure
- Comprehensive logging with PSR logger
- Token refresh handling
- Admin privilege validation

### Rate Limiting Middleware (`rate_limit`)

Distributed rate limiting with multiple strategies:

```php
// Basic rate limiting (60 requests per minute by IP)
$router->get('/api/public', [PublicController::class, 'data'])
    ->middleware('rate_limit:60,60');

// User-based rate limiting (1000 requests per hour)
$router->get('/api/user-data', [UserController::class, 'data'])
    ->middleware(['auth', 'rate_limit:1000,3600,user']);

// Endpoint-specific limiting
$router->post('/api/upload', [UploadController::class, 'store'])
    ->middleware(['auth', 'rate_limit:10,60,endpoint']);

// IP-based limiting for public endpoints
$router->get('/api/search', [SearchController::class, 'query'])
    ->middleware('rate_limit:100,60,ip');
```

**Features:**
- Multiple limiting strategies: IP, user, endpoint
- Sliding window algorithm for accurate tracking
- Distributed limiting across multiple servers
- Adaptive rate limiting with ML-powered anomaly detection
- Configurable limits and time windows
- HTTP 429 responses with retry headers
- Event dispatching for limit exceeded events

### Security Headers Middleware (`security_headers`)

Comprehensive security header management:

```php
// Apply security headers to all routes
$router->group(['middleware' => ['security_headers']], function($router) {
    // All routes get security headers
});

// Custom security configuration
$router->get('/api/public', [PublicController::class, 'data'])
    ->middleware(['security_headers:strict']);
```

**Features:**
- Content Security Policy (CSP) with nonce generation
- HTTP Strict Transport Security (HSTS)
- X-Frame-Options, X-Content-Type-Options
- Referrer-Policy and Feature-Policy headers
- CORS handling with configurable policies
- Environment-specific configurations

### CSRF Protection (`csrf`)

Cross-Site Request Forgery protection:

```php
// CSRF protection for state-changing operations
$router->post('/account/update', [AccountController::class, 'update'])
    ->middleware(['auth', 'csrf']);

// CSRF for form submissions
$router->group(['middleware' => ['csrf']], function($router) {
    $router->post('/contact', [ContactController::class, 'submit']);
    $router->put('/profile', [ProfileController::class, 'update']);
    $router->delete('/account', [AccountController::class, 'delete']);
});
```

**Features:**
- Token-based CSRF validation
- Automatic token generation and validation
- Session and header-based token storage
- Configurable token lifetime
- Exception handling with user-friendly errors

## Creating Custom Middleware

### Simple Middleware

```php
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TimingMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $start = microtime(true);
        
        $response = $next($request);
        
        $duration = round((microtime(true) - $start) * 1000, 2);
        $response->headers->set('X-Response-Time', $duration . 'ms');
        
        return $response;
    }
}
```

### Middleware with Dependencies

```php
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use App\Services\AuditService;

class AuditMiddleware implements RouteMiddleware
{
    public function __construct(
        private LoggerInterface $logger,
        private AuditService $auditService
    ) {}
    
    public function handle(Request $request, callable $next): mixed
    {
        // Log request
        $this->logger->info('Request started', [
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'ip' => $request->getClientIp()
        ]);
        
        $response = $next($request);
        
        // Audit the action
        $this->auditService->recordAction([
            'user_id' => $request->attributes->get('user_id'),
            'action' => $request->attributes->get('_route'),
            'status' => $response->getStatusCode()
        ]);
        
        return $response;
    }
}
```

### Middleware with Conditional Logic

```php
class MaintenanceMiddleware implements RouteMiddleware
{
    public function __construct(
        private string $maintenanceFile = '/tmp/maintenance'
    ) {}
    
    public function handle(Request $request, callable $next): mixed
    {
        if ($this->isInMaintenanceMode() && !$this->isExemptRoute($request)) {
            return new Response('Service temporarily unavailable', 503, [
                'Retry-After' => 3600,
                'Content-Type' => 'application/json'
            ]);
        }
        
        return $next($request);
    }
    
    private function isInMaintenanceMode(): bool
    {
        return file_exists($this->maintenanceFile);
    }
    
    private function isExemptRoute(Request $request): bool
    {
        $exemptRoutes = ['/health', '/status'];
        return in_array($request->getPathInfo(), $exemptRoutes);
    }
}
```

## Middleware Parameters

The framework supports runtime parameters for configurable middleware behavior:

### Parameter Extraction

```php
class CacheMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Extract parameters with defaults
        $ttl = (int) ($params[0] ?? 300); // Default 5 minutes
        $tags = isset($params[1]) ? explode(',', $params[1]) : ['default'];
        $varyBy = (string) ($params[2] ?? 'path');
        
        $cacheKey = $this->generateCacheKey($request, $varyBy);
        
        // Check cache
        if ($cached = $this->cache->get($cacheKey)) {
            return new Response($cached);
        }
        
        $response = $next($request);
        
        // Store in cache with TTL and tags
        $this->cache->put($cacheKey, $response->getContent(), $ttl, $tags);
        
        return $response;
    }
}

// Usage with parameters
$router->get('/api/expensive-data', [DataController::class, 'expensive'])
    ->middleware('cache:600,data,user'); // 10min TTL, 'data' tag, vary by user
```

### Parameter Parsing Patterns

```php
class FlexibleMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $config = $this->parseParams($params);
        
        // Use parsed configuration
        if ($config['strict_mode']) {
            // Strict validation
        }
        
        return $next($request);
    }
    
    private function parseParams(array $params): array
    {
        $config = [
            'timeout' => 30,
            'strict_mode' => false,
            'allowed_types' => ['json', 'xml']
        ];
        
        foreach ($params as $param) {
            if (is_numeric($param)) {
                $config['timeout'] = (int) $param;
            } elseif ($param === 'strict') {
                $config['strict_mode'] = true;
            } elseif (str_contains($param, ',')) {
                $config['allowed_types'] = explode(',', $param);
            }
        }
        
        return $config;
    }
}

// Usage: ->middleware('flexible:60,strict,json,xml')
```

## PSR-15 Compatibility

The framework provides seamless PSR-15 middleware integration:

### Using PSR-15 Middleware

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Glueful\Http\Bridge\Psr15\Psr15AdapterFactory;

// Standard PSR-15 middleware
class Psr15CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }
}

// Register PSR-15 middleware in service provider
$psr15Middleware = new Psr15CorsMiddleware();
$wrappedMiddleware = Psr15AdapterFactory::wrap($psr15Middleware);
$container->set('psr15_cors', $wrappedMiddleware);

// Use in routes
$router->group(['middleware' => ['psr15_cors']], function($router) {
    // Routes with PSR-15 CORS middleware
});
```

### Bridge Configuration

```php
// Custom PSR-17 factory configuration
use Nyholm\Psr7\Factory\Psr17Factory;

$customFactoryProvider = function() {
    $factory = new Psr17Factory();
    return [$factory, $factory, $factory, $factory];
};

$wrappedMiddleware = Psr15AdapterFactory::wrap(
    $psr15Middleware,
    $customFactoryProvider
);
```

## Applying Middleware

### Container vs Direct Instantiation

The framework supports two approaches for applying middleware: using container-registered aliases (recommended for standard cases) and direct instantiation (for custom configurations).

#### Method 1: Container-Registered Middleware (String Aliases)

Use string aliases when framework defaults are sufficient:

```php
// Standard authentication using framework defaults
$router->get('/profile', [UserController::class, 'show'])
    ->middleware('auth');

// Multiple middleware with parameters
$router->post('/admin/users', [AdminController::class, 'createUser'])
    ->middleware(['auth:admin', 'csrf', 'rate_limit:10,60']);

// Group middleware
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->get('/settings', [SettingsController::class, 'index']);
});
```

#### Method 2: Direct Instantiation with Custom Configuration

Use direct instantiation when you need custom configuration:

```php
// Custom API authentication - API keys only, no expiration validation
$apiAuthMiddleware = new AuthMiddleware(
    authManager: null,
    container: null,
    providerNames: ['api_key'], // Only API keys for this endpoint
    options: [
        'validate_expiration' => false,  // Lenient for API
        'enable_events' => false,        // High performance
        'enable_logging' => true         // But track usage
    ]
);

$router->get('/api/public-data', [PublicApiController::class, 'getData'])
    ->middleware([$apiAuthMiddleware]);

// Different configuration for internal API
$internalAuthMiddleware = new AuthMiddleware(
    providerNames: ['jwt'],
    options: [
        'validate_expiration' => true,   // Strict for internal
        'enable_events' => true,
        'enable_logging' => true
    ]
);

$router->group(['middleware' => [$internalAuthMiddleware]], function($router) {
    $router->get('/internal/metrics', [InternalController::class, 'metrics']);
    $router->post('/internal/admin-action', [InternalController::class, 'adminAction'])
        ->middleware('admin'); // Can still mix with string aliases
});
```

#### Method 3: Mixed Usage (Recommended Pattern)

Combine both approaches strategically:

```php
// Use string aliases for standard cases
$router->group(['middleware' => ['auth']], function($router) {
    
    // Standard routes use framework defaults
    $router->get('/profile', [UserController::class, 'profile']);
    $router->get('/dashboard', [DashboardController::class, 'index']);
    
    // Special routes use custom middleware instances
    $router->get('/experimental-feature', [ExperimentalController::class, 'index'])
        ->middleware([
            new App\Middleware\FeatureFlagMiddleware('experimental_ui'),
            new App\Middleware\ABTestMiddleware()
        ]);
});
```

### Container Registration Internals

Understanding how container registration works helps you choose the right approach:

```php
// In CoreServiceProvider.php:
$container->register(AuthMiddleware::class)
    ->setArguments([new Reference(AuthenticationManager::class)])
    ->setPublic(true);

$container->setAlias('auth', AuthMiddleware::class)
    ->setPublic(true);

// When you use 'auth' string, the router:
// 1. Resolves 'auth' to AuthMiddleware::class
// 2. Container automatically injects AuthenticationManager
// 3. Creates instance with framework defaults:
//    - providerNames: ['jwt', 'api_key'] (default)
//    - options: all enabled (default)

// Equivalent to:
new AuthMiddleware(
    authManager: $container->get(AuthenticationManager::class),
    container: $container,
    providerNames: ['jwt', 'api_key'],  // framework default
    options: [
        'validate_expiration' => true,
        'enable_events' => true, 
        'enable_logging' => true
    ]
);
```

### Registering Custom Middleware in Container

For your own middleware that you want to use with string aliases:

```php
// In your ServiceProvider
class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register custom middleware
        $container->register(App\Middleware\CustomRateLimitMiddleware::class)
            ->setArguments([1000, 3600]) // maxRequests, windowSeconds
            ->setPublic(true);

        // Create string alias
        $container->setAlias('custom_rate_limit', App\Middleware\CustomRateLimitMiddleware::class)
            ->setPublic(true);
    }
}

// Then use with string:
$router->get('/api/data', [ApiController::class, 'getData'])
    ->middleware(['custom_rate_limit', 'auth']);
```

### Route-Level Middleware

```php
// Single middleware
$router->get('/protected', [SecureController::class, 'data'])
    ->middleware('auth');

// Multiple middleware (executed in order)
$router->post('/api/upload', [UploadController::class, 'store'])
    ->middleware(['auth', 'rate_limit:10,60', 'csrf']);

// Middleware with parameters
$router->get('/cached-data', [DataController::class, 'expensive'])
    ->middleware(['cache:300,data', 'rate_limit:100,60']);
```

### Group-Level Middleware

```php
// Apply middleware to entire groups
$router->group(['middleware' => ['auth', 'rate_limit:1000,3600']], function($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
});

// Nested groups inherit parent middleware
$router->group(['middleware' => ['auth']], function($router) {
    
    // User routes (inherits 'auth')
    $router->get('/user/data', [UserController::class, 'data']);
    
    // Admin routes (inherits 'auth', adds 'admin')
    $router->group(['prefix' => '/admin', 'middleware' => ['admin_permission']], function($router) {
        $router->get('/users', [AdminController::class, 'users']);
        $router->delete('/users/{id}', [AdminController::class, 'deleteUser']);
    });
});
```

### Global Middleware

```php
// Apply to all routes (in bootstrap or service provider)
$router->group(['middleware' => ['security_headers', 'metrics']], function($router) {
    // Load all application routes
    require __DIR__ . '/routes/api.php';
    require __DIR__ . '/routes/web.php';
});
```

## Framework Middleware Examples

Real-world examples from the framework's built-in routes demonstrate middleware best practices:

### Health Endpoints with Monitoring

```php
// Health check routes with appropriate rate limits
$router->group(['prefix' => '/health'], function(Router $router) {
    // Main health endpoint - generous limit for monitoring tools
    $router->get('/', [HealthController::class, 'index'])
        ->middleware('rate_limit:60,60'); // 60 requests per minute
    
    // Protected readiness endpoint
    $router->get('/ready', [HealthController::class, 'readiness'])
        ->middleware(['rate_limit:30,60', 'allow_ip']); // IP allowlist
    
    // Database health with lower limit
    $router->get('/database', [HealthController::class, 'database'])
        ->middleware('rate_limit:30,60');
});
```

### Authentication Routes with Security

```php
// Authentication endpoints with varying security levels
$router->group(['prefix' => '/auth'], function(Router $router) {
    // Login with strict rate limiting
    $router->post('/login', [AuthController::class, 'login'])
        ->middleware(['rate_limit:5,60', 'csrf']); // 5 attempts per minute
    
    // OTP verification with additional security
    $router->post('/verify-otp', [AuthController::class, 'verifyOtp'])
        ->middleware(['rate_limit:3,60', 'csrf']); // 3 attempts per minute
    
    // Token refresh requires existing auth
    $router->post('/refresh', [AuthController::class, 'refresh'])
        ->middleware(['auth', 'rate_limit:10,60']);
    
    // Logout (authenticated users only)
    $router->post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth');
});
```

### Resource API with Comprehensive Protection

```php
// RESTful resource endpoints with full middleware stack
$router->get('/{resource}', [ResourceController::class, 'index'])
    ->middleware([
        'auth',                    // Require authentication
        'rate_limit:100,60',      // 100 requests per minute
        'field_selection',        // GraphQL-style field selection
        'metrics'                 // Collect request metrics
    ]);

$router->post('/{resource}', [ResourceController::class, 'store'])
    ->middleware([
        'auth',                    // Require authentication
        'csrf',                   // CSRF protection
        'rate_limit:20,60',       // Lower limit for writes
        'admin_permission',       // Admin access required
        'request_logging'         // Log create operations
    ]);

$router->delete('/{resource}/{uuid}', [ResourceController::class, 'destroy'])
    ->middleware([
        'auth',                    // Require authentication
        'csrf',                   // CSRF protection
        'rate_limit:10,60',       // Strict limit for deletions
        'admin_permission',       // Admin access required
        'request_logging:full'    // Full request/response logging
    ]);
```

### When to Use Which Approach

Understanding when to use container aliases versus direct instantiation is key to effective middleware usage:

#### Use String Aliases ('auth') When:
✅ **Framework defaults are sufficient** - Standard authentication behavior meets your needs  
✅ **Clean, readable route definitions** - Keep routes simple and maintainable  
✅ **Standard authentication behavior** - Typical CRUD applications with common patterns  
✅ **Consistent configuration** - Same behavior across similar routes  

**Examples:**
- Basic user authentication for web applications
- Standard admin route protection
- Simple API endpoints with default security
- Standard CRUD operations

```php
// Clean and simple for standard cases
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/profile', [UserController::class, 'show']);
    $router->put('/profile', [UserController::class, 'update']);
});
```

#### Use Direct Instantiation (new AuthMiddleware()) When:
✅ **Custom configuration needed** - Different behavior per route or group  
✅ **Different routes need different auth behavior** - Multi-tenant or multi-API scenarios  
✅ **Custom authentication providers** - Beyond standard JWT/API key  
✅ **Runtime flexibility required** - Dynamic configuration based on context  
✅ **User-defined middleware** - Custom business logic middleware  

**Examples:**
- Multi-tenant applications (different auth per tenant)
- API gateways with custom rate limiting per client
- Development vs production configuration differences
- A/B testing different authentication flows
- Custom business logic middleware

```php
// Custom configuration for special cases
$tenantAuthMiddleware = new AuthMiddleware(
    providerNames: ['tenant_jwt'],
    options: [
        'validate_expiration' => true,
        'tenant_isolation' => true,
        'custom_claims' => ['tenant_id', 'permissions']
    ]
);

$router->group(['middleware' => [$tenantAuthMiddleware]], function($router) {
    // Tenant-specific routes
});
```

#### Mixed Usage Best Practice:
Use string aliases as the default, and direct instantiation for exceptions:

```php
// Standard authentication for most routes
$router->group(['middleware' => ['auth']], function($router) {
    
    // 90% of routes use standard auth
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->get('/settings', [SettingsController::class, 'index']);
    
    // 10% need custom behavior
    $router->get('/special-feature', [SpecialController::class, 'index'])
        ->middleware([
            new CustomFeatureMiddleware(['feature_flag' => 'new_ui']),
            new ABTestMiddleware(['experiment' => 'layout_v2'])
        ]);
});
```

## Best Practices

### 1. Middleware Ordering

Order middleware strategically for optimal performance and security:

```php
// Recommended order:
$router->group(['middleware' => [
    'security_headers',    // 1. Apply security headers early
    'rate_limit:100,60',  // 2. Rate limiting before expensive operations
    'auth',               // 3. Authentication check
    'csrf',               // 4. CSRF validation (requires auth context)
    'admin_permission',   // 5. Authorization checks
    'field_selection',    // 6. Query optimization
    'metrics',            // 7. Metrics collection
    'request_logging'     // 8. Logging (captures full context)
]], function($router) {
    // Protected routes
});
```

### 2. Parameter Configuration

Use clear, consistent parameter patterns:

```php
// Good: Clear parameter meanings
->middleware('rate_limit:100,60,user')  // max, window, type
->middleware('cache:300,api-data,user') // ttl, tag, vary-by

// Bad: Unclear parameters
->middleware('config:100,60,1,user')    // What do these numbers mean?
```

### 3. Error Handling

Provide meaningful error responses:

```php
class AuthMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            $user = $this->authService->authenticate($request);
        } catch (AuthenticationException $e) {
            return new JsonResponse([
                'error' => 'Authentication failed',
                'message' => 'Please provide valid credentials',
                'code' => 'AUTH_FAILED'
            ], 401);
        }
        
        $request->attributes->set('user', $user);
        return $next($request);
    }
}
```

### 4. Performance Considerations

```php
class CachingMiddleware implements RouteMiddleware
{
    private array $cache = []; // In-memory cache for single request
    
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $cacheKey = $this->getCacheKey($request);
        
        // Check in-memory cache first (fastest)
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Check distributed cache
        if ($cached = $this->redis->get($cacheKey)) {
            return $this->cache[$cacheKey] = unserialize($cached);
        }
        
        $response = $next($request);
        
        // Store in both caches
        $this->cache[$cacheKey] = $response;
        $this->redis->setex($cacheKey, 300, serialize($response));
        
        return $response;
    }
}
```

### 5. Testing Middleware

```php
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddlewareTest extends TestCase
{
    public function test_allows_requests_within_limit(): void
    {
        $middleware = new RateLimiterMiddleware(maxAttempts: 5, windowSeconds: 60);
        
        $request = Request::create('/test', 'GET');
        $next = fn($req) => new Response('OK');
        
        // First request should pass
        $response = $middleware->handle($request, $next);
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    public function test_blocks_requests_over_limit(): void
    {
        $middleware = new RateLimiterMiddleware(maxAttempts: 1, windowSeconds: 60);
        
        $request = Request::create('/test', 'GET');
        $next = fn($req) => new Response('OK');
        
        // First request passes
        $middleware->handle($request, $next);
        
        // Second request should be blocked
        $response = $middleware->handle($request, $next);
        $this->assertEquals(429, $response->getStatusCode());
    }
}
```

## Troubleshooting

### Common Issues

**Middleware not executing:**
- Check middleware is registered in service provider
- Verify middleware alias spelling
- Ensure middleware implements `RouteMiddleware` interface
- Check route definition syntax

**Parameters not working:**
- Verify parameter parsing in `handle()` method
- Check parameter order and types
- Use proper parameter extraction with defaults
- Test parameter values in isolation

**PSR-15 middleware failing:**
- Install required bridges: `composer require symfony/psr-http-message-bridge nyholm/psr7`
- Check PSR-17 factory availability
- Verify PSR-15 middleware implementation
- Review bridge adapter configuration

**Performance issues:**
- Profile middleware execution order
- Check for expensive operations in middleware
- Consider caching in middleware logic
- Optimize parameter parsing

### Debug Commands

```bash
# List registered middleware
php glueful middleware:list

# Test middleware on specific route
php glueful route:test GET /api/users --middleware

# Check middleware configuration
php glueful config:show middleware

# Profile middleware performance
php glueful debug:middleware --profile
```

### Debugging Middleware

```php
class DebugMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $start = microtime(true);
        
        // Log middleware entry
        logger()->debug('Middleware started', [
            'middleware' => static::class,
            'params' => $params,
            'request' => $request->getMethod() . ' ' . $request->getRequestUri()
        ]);
        
        try {
            $response = $next($request);
            
            logger()->debug('Middleware completed', [
                'middleware' => static::class,
                'duration' => round((microtime(true) - $start) * 1000, 2) . 'ms',
                'status' => method_exists($response, 'getStatusCode') 
                    ? $response->getStatusCode() 
                    : 'unknown'
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            logger()->error('Middleware failed', [
                'middleware' => static::class,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
```

## Advanced Authentication Middleware Examples

### Environment-Specific Configuration

```php
use Glueful\Routing\Middleware\AuthMiddleware;

// Development - verbose logging, no expiration validation
if (config('app.env') === 'development') {
    $devMiddleware = new AuthMiddleware(
        options: [
            'validate_expiration' => false,  // Skip expiration in dev
            'enable_events' => false,        // Skip events in dev
            'enable_logging' => true         // But keep logging for debugging
        ]
    );
    
    $router->group(['middleware' => [$devMiddleware]], function($router) {
        $router->get('/api/dev/test', 'DevController@test');
    });
}

// Production - full security features enabled
if (config('app.env') === 'production') {
    $prodMiddleware = new AuthMiddleware(
        providerNames: ['jwt', 'api_key'],
        options: [
            'validate_expiration' => true,   // Strict expiration checking
            'enable_events' => true,         // Full event dispatching
            'enable_logging' => true         // Comprehensive logging
        ]
    );
    
    $router->group(['middleware' => [$prodMiddleware]], function($router) {
        $router->get('/api/secure-data', 'SecureController@getData');
    });
}
```

### Multi-Tenant Authentication

```php
// Different tenants might use different authentication providers
$tenant1Middleware = new AuthMiddleware(
    providerNames: ['jwt'],  // Simple JWT for basic tenants
    options: ['validate_expiration' => true, 'enable_events' => true, 'enable_logging' => true]
);

$tenant2Middleware = new AuthMiddleware(
    providerNames: ['saml', 'ldap'],  // Enterprise auth for premium tenants
    options: ['validate_expiration' => true, 'enable_events' => true, 'enable_logging' => true]
);

$router->group([
    'prefix' => '/tenant/basic',
    'middleware' => [$tenant1Middleware]
], function($router) {
    $router->get('/data', 'TenantController@basicData');
});

$router->group([
    'prefix' => '/tenant/enterprise',
    'middleware' => [$tenant2Middleware]
], function($router) {
    $router->get('/data', 'TenantController@enterpriseData');
    $router->post('/admin-action', 'TenantController@adminAction')->middleware(['admin']);
});
```

### Feature-Specific Configurations

```php
// API Gateway - events disabled for performance, logging enabled for monitoring
$apiGatewayMiddleware = new AuthMiddleware(
    providerNames: ['api_key'],
    options: [
        'validate_expiration' => true,
        'enable_events' => false,    // Disable events for high-throughput
        'enable_logging' => true     // Keep logging for monitoring
    ]
);

$router->group([
    'prefix' => '/gateway',
    'middleware' => [$apiGatewayMiddleware]
], function($router) {
    $router->get('/data', 'GatewayController@getData');
    $router->post('/webhook', 'GatewayController@webhook');
});

// WebSocket/SSE endpoints - token expiration disabled (long-lived connections)
$websocketMiddleware = new AuthMiddleware(
    providerNames: ['jwt'],
    options: [
        'validate_expiration' => false,  // Allow longer-lived connections
        'enable_events' => true,
        'enable_logging' => false        // Reduce logging noise
    ]
);

$router->get('/api/websocket/connect', 'WebSocketController@connect')
    ->middleware([$websocketMiddleware]);
```

### Factory Pattern for Middleware Creation

```php
class AuthMiddlewareFactory
{
    public static function createForEnvironment(string $env): AuthMiddleware
    {
        $options = match($env) {
            'development' => [
                'validate_expiration' => false,
                'enable_events' => false,
                'enable_logging' => true
            ],
            'testing' => [
                'validate_expiration' => false,
                'enable_events' => false,
                'enable_logging' => false
            ],
            'staging' => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ],
            'production' => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ],
            default => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ]
        };

        return new AuthMiddleware(
            providerNames: ['jwt', 'api_key'],
            options: $options
        );
    }

    public static function createForTenant(string $tenantType): AuthMiddleware
    {
        $providerNames = match($tenantType) {
            'basic' => ['jwt'],
            'premium' => ['jwt', 'api_key'],
            'enterprise' => ['jwt', 'api_key', 'ldap', 'saml'],
            default => ['jwt']
        };

        return new AuthMiddleware(
            providerNames: $providerNames,
            options: [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ]
        );
    }
}

// Usage with factory
$envMiddleware = AuthMiddlewareFactory::createForEnvironment(config('app.env'));
$router->group(['middleware' => [$envMiddleware]], function($router) {
    $router->get('/api/user', 'UserController@index');
});
```

### Authentication Response Examples

```php
/*
 * SUCCESS RESPONSE (authenticated user):
 * HTTP 200 OK
 * Request continues to controller...
 * 
 * FAILURE RESPONSES:
 * 
 * 1. Missing token:
 * HTTP 401 Unauthorized
 * {
 *   "success": false,
 *   "message": "Authentication required",
 *   "code": 401,
 *   "error_code": "UNAUTHORIZED"
 * }
 * 
 * 2. Expired token (refresh available):
 * HTTP 401 Unauthorized  
 * {
 *   "success": false,
 *   "message": "Access token expired",
 *   "code": 401,
 *   "error_code": "TOKEN_EXPIRED",
 *   "refresh_available": true
 * }
 * 
 * 3. Session expired:
 * HTTP 401 Unauthorized
 * {
 *   "success": false,
 *   "message": "Session expired. Please log in again",
 *   "code": 401,
 *   "error_code": "SESSION_EXPIRED", 
 *   "refresh_available": false
 * }
 * 
 * 4. Admin access required:
 * HTTP 403 Forbidden
 * {
 *   "success": false,
 *   "message": "Admin access required",
 *   "code": 403,
 *   "error_code": "FORBIDDEN"
 * }
 */
```

### Authentication Event Listeners

```php
// Listen for authentication events in your application
Event::listen(HttpAuthSuccessEvent::class, function($event) {
    // Log successful authentication
    Log::info('User authenticated', [
        'user_id' => $event->getMetadata()['user_id'] ?? null,
        'provider' => $event->getMetadata()['provider'] ?? 'unknown',
        'path' => $event->getRequest()->getPathInfo()
    ]);
});

Event::listen(HttpAuthFailureEvent::class, function($event) {
    // Track failed authentication attempts
    Log::warning('Authentication failed', [
        'reason' => $event->getReason(),
        'ip' => $event->getRequest()->getClientIp(),
        'path' => $event->getRequest()->getPathInfo()
    ]);
});
```

## Conclusion

The Glueful middleware system provides a powerful, flexible foundation for building secure, performant applications. With the native `RouteMiddleware` interface, PSR-15 compatibility, and comprehensive built-in middleware, it handles both simple and complex requirements efficiently.

The framework's built-in middleware demonstrates enterprise-grade patterns for authentication, security, rate limiting, and monitoring that serve as excellent templates for custom middleware development.

For more examples and advanced usage, see the [middleware implementations](../../src/Routing/Middleware/) and [test suite](../../tests/Unit/Middleware/).
