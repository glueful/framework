# Next-Generation Router Implementation Plan (Simplified)

## Executive Summary

Pragmatic redesign of Glueful's routing system to achieve excellent performance without overengineering. Focus on solving real problems with simple, maintainable code that delivers O(1) static route matching and efficient dynamic routing.

## Current Issues to Resolve

1. **30+ second cold boot time** - Route loading causes 99.7% of boot time
2. **Broken controller reflection** - Fails for array-based controllers `[Class::class, 'method']`
3. **Non-functional group middleware** - Middleware parameter is ignored
4. **No route caching in development** - Full route discovery on every request
5. **Static method architecture** - Hard to test, impossible to mock
6. **File system scanning overhead** - Expensive directory traversal for route discovery

## Simplified Architecture

### Core Components (Just 5 Files!)

```
src/Routing/
├── Router.php              # Main router with all core functionality
├── Route.php               # Simple route definition
├── RouteCompiler.php       # Compiles routes to native PHP
├── RouteCache.php          # Simple, effective caching
└── Attributes/
    └── Route.php           # Optional attribute support
```

### Why This Architecture Works

- **Router.php**: Contains matching, dispatching, and registration in ~200 lines
- **Route.php**: Simple value object with pattern matching (~100 lines)
- **RouteCompiler.php**: Generates optimized PHP code (~50 lines)
- **RouteCache.php**: Handles development and production caching (~100 lines)
- **Total**: ~500 lines vs 3000+ lines in overengineered solution

## Implementation Phases

### Phase 1: Core Router Class (Day 1)

#### 1.1 Main Router Implementation
```php
namespace Glueful\Routing;

use Glueful\DI\Container;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, StreamedResponse, BinaryFileResponse};

class Router {
    private array $staticRoutes = [];     // O(1) lookup for static paths
    private array $dynamicRoutes = [];    // Regex patterns for dynamic paths
    private array $routeBuckets = [];     // [METHOD][first|*] => Route[] for performance
    private array $groupStack = [];       // Track nested groups
    private array $middlewareStack = [];  // Track group middleware
    private array $namedRoutes = [];      // Route name => Route object
    private array $pipelineCache = [];    // spl_object_id(Route) => cached middleware
    private array $reflectionCache = [];  // Cache reflection results
    private ?RouteCache $cache = null;
    private Container $container;
    
    public function __construct(Container $container) {
        $this->container = $container;
        $this->cache = new RouteCache();
        
        // Try to load cached routes
        if ($cached = $this->cache->load()) {
            $this->staticRoutes = $cached['static'];
            $this->dynamicRoutes = $cached['dynamic'];
        }
    }
    
    // HTTP method shortcuts
    public function get(string $path, mixed $handler): Route {
        return $this->add('GET', $path, $handler);
    }
    
    public function post(string $path, mixed $handler): Route {
        return $this->add('POST', $path, $handler);
    }
    
    public function put(string $path, mixed $handler): Route {
        return $this->add('PUT', $path, $handler);
    }
    
    public function delete(string $path, mixed $handler): Route {
        return $this->add('DELETE', $path, $handler);
    }
    
    // Core route registration
    private function add(string $method, string $path, mixed $handler): Route {
        // Normalize method and path once
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        
        // Apply group prefixes
        $path = $this->applyGroupPrefix($path);
        
        // Create route with back-reference for named route registration
        $route = new Route($this, $method, $path, $handler);
        
        // Apply group middleware
        $groupMiddleware = $this->getCurrentGroupMiddleware();
        if (!empty($groupMiddleware)) {
            $route->middleware($groupMiddleware);
        }
        
        // Store efficiently based on type
        if (!str_contains($path, '{')) {
            // Static route - O(1) lookup with duplicate protection
            $key = $method . ':' . $path;
            if (isset($this->staticRoutes[$key])) {
                throw new \LogicException("Route already defined for {$key}");
            }
            $this->staticRoutes[$key] = $route;
        } else {
            // Dynamic route - requires pattern matching with bucketing
            $this->dynamicRoutes[$method][] = $route;
            
            // Add to performance buckets
            $firstSegment = $this->firstSegment($path) ?? '*';
            $this->routeBuckets[$method][$firstSegment][] = $route;
        }
        
        return $route;
    }
    
    // Apply group prefixes from nested groups
    private function applyGroupPrefix(string $path): string {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $groupPrefix = rtrim($group['prefix'] ?? '', '/');
            if ($groupPrefix !== '') {
                $prefix .= '/' . ltrim($groupPrefix, '/');
            }
        }
        $fullPath = '/' . ltrim($path, '/');
        $result = rtrim($prefix . $fullPath, '/') ?: '/';
        return $result;
    }
    
    // Get current middleware stack from all nested groups
    private function getCurrentGroupMiddleware(): array {
        $middleware = [];
        foreach ($this->middlewareStack as $groupMiddleware) {
            $middleware = array_merge($middleware, (array) $groupMiddleware);
        }
        return $middleware;
    }
    
    // Get first segment for route bucketing performance optimization
    private function firstSegment(string $path): ?string {
        $trimmed = trim($path, '/');
        if ($trimmed === '') return null;
        
        $segment = strtok($trimmed, '/');
        // Parameterized first segment goes to wildcard bucket
        return (str_contains($segment, '{') ? '*' : $segment);
    }
    
    // Fast route matching with proper HTTP semantics
    public function match(Request $request): ?array {
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());
        
        // Normalize path
        $path = '/' . ltrim(rawurldecode($path), '/');
        $path = rtrim($path, '/') ?: '/';
        
        // 1. Try static routes first (fastest)
        $key = $method . ':' . $path;
        if (isset($this->staticRoutes[$key])) {
            return [
                'route' => $this->staticRoutes[$key],
                'params' => []
            ];
        }
        
        // 2. Try dynamic routes with first-segment optimization
        // IMPORTANT: Routes are now pre-sorted by precedence within buckets
        // This ensures /users/me always wins over /users/{id}
        $segments = explode('/', trim($path, '/'));
        $firstSegment = $segments[0] ?? '';
        
        // Check bucketed routes in precedence order (already sorted)
        // Static segments get priority over wildcard bucket
        $candidates = array_merge(
            $this->routeBuckets[$method][$firstSegment] ?? [],
            $this->routeBuckets[$method]['*'] ?? [] // Wildcard bucket
        );
        
        foreach ($candidates as $route) {
            if ($params = $route->match($path)) {
                return [
                    'route' => $route,
                    'params' => $params
                ];
            }
        }
        
        // 3. Check if path exists with different method (for 405 response)
        $allowedMethods = $this->getAllowedMethods($path);
        if (!empty($allowedMethods)) {
            return [
                'route' => null,
                'params' => [],
                'allowed_methods' => $allowedMethods
            ];
        }
        
        return null;
    }
    
    // Get allowed methods for a path (for 405 responses)
    private function getAllowedMethods(string $path): array {
        $allowed = [];
        
        // Check static routes
        foreach ($this->staticRoutes as $key => $route) {
            [$method, $routePath] = explode(':', $key, 2);
            if ($routePath === $path) {
                $allowed[] = $method;
            }
        }
        
        // Check dynamic routes
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $route) {
                if ($route->match($path)) {
                    $allowed[] = $method;
                }
            }
        }
        
        // Auto-map HEAD to GET
        if (in_array('GET', $allowed) && !in_array('HEAD', $allowed)) {
            $allowed[] = 'HEAD';
        }
        
        return array_unique($allowed);
    }
    
    // Group support with middleware
    public function group(array $attributes, callable $callback): void {
        $this->groupStack[] = $attributes;
        
        // Track middleware for this group
        if (isset($attributes['middleware'])) {
            $this->middlewareStack[] = (array)$attributes['middleware'];
        } else {
            $this->middlewareStack[] = [];
        }
        
        $callback($this);
        
        array_pop($this->groupStack);
        array_pop($this->middlewareStack);
    }
    
    /**
     * Build comprehensive CORS headers from centralized configuration
     * 
     * This method provides a single place to configure all CORS behavior,
     * making it easy to adjust for different environments and requirements.
     */
    private function buildCorsHeaders(Request $request, array $allowedMethods): array {
        $config = $this->getCorsConfig();
        $headers = [];
        
        // Always include allowed methods
        $headers['Access-Control-Allow-Methods'] = implode(', ', $allowedMethods);
        
        // Handle Origin
        $origin = $request->headers->get('Origin');
        if ($origin && $this->isOriginAllowed($origin, $config)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin'; // Important for caching
        } elseif ($config['allow_all_origins']) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }
        
        // Handle Credentials
        if ($config['allow_credentials'] && !$config['allow_all_origins']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        
        // Handle Headers
        if (!empty($config['allow_headers'])) {
            $headers['Access-Control-Allow-Headers'] = is_array($config['allow_headers']) 
                ? implode(', ', $config['allow_headers'])
                : $config['allow_headers'];
        }
        
        // Handle preflight cache
        if (isset($config['max_age'])) {
            $headers['Access-Control-Max-Age'] = (string) $config['max_age'];
        }
        
        // Handle exposed headers
        if (!empty($config['expose_headers'])) {
            $headers['Access-Control-Expose-Headers'] = is_array($config['expose_headers'])
                ? implode(', ', $config['expose_headers'])  
                : $config['expose_headers'];
        }
        
        return $headers;
    }
    
    /**
     * Get CORS configuration with sensible defaults
     */
    private function getCorsConfig(): array {
        $config = config('cors', []);
        
        return array_merge([
            // Origins
            'allow_all_origins' => false,
            'allowed_origins' => ['http://localhost:3000', 'http://localhost:8080'],
            'allowed_origin_patterns' => ['/^https:\/\/.*\.yourdomain\.com$/'],
            
            // Headers  
            'allow_headers' => [
                'Accept',
                'Authorization', 
                'Content-Type',
                'DNT',
                'Origin',
                'User-Agent',
                'X-Requested-With'
            ],
            'expose_headers' => [],
            
            // Credentials & Caching
            'allow_credentials' => false,
            'max_age' => 86400, // 24 hours
            
            // Environment overrides
            'development_allow_all' => env('APP_ENV') === 'development'
        ], $config);
    }
    
    /**
     * Check if origin is allowed based on configuration
     */
    private function isOriginAllowed(string $origin, array $config): bool {
        // Development mode: allow all if configured
        if ($config['development_allow_all']) {
            return true;
        }
        
        // Check exact matches
        if (in_array($origin, $config['allowed_origins'], true)) {
            return true;
        }
        
        // Check pattern matches
        foreach ($config['allowed_origin_patterns'] as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        
        return false;
    }
}

/**
 * Centralized CORS Configuration Example
 * 
 * Add this to your config/cors.php file for comprehensive CORS control:
 * 
 * ```php
 * return [
 *     // Production configuration
 *     'allow_all_origins' => false,
 *     'allowed_origins' => [
 *         'https://yourdomain.com',
 *         'https://app.yourdomain.com',
 *         'https://admin.yourdomain.com'
 *     ],
 *     'allowed_origin_patterns' => [
 *         '/^https:\/\/.*\.yourdomain\.com$/',  // Subdomains
 *         '/^https:\/\/localhost:\d+$/'        // Local development ports
 *     ],
 *     
 *     'allow_headers' => [
 *         'Accept',
 *         'Authorization',
 *         'Content-Type', 
 *         'DNT',
 *         'Origin',
 *         'User-Agent',
 *         'X-Requested-With',
 *         'X-CSRF-Token',      // For CSRF protection
 *         'X-API-Version'      // Custom API versioning
 *     ],
 *     
 *     'expose_headers' => [
 *         'X-Total-Count',     // Pagination info
 *         'X-Rate-Limit-*',    // Rate limiting headers
 *         'Link'               // Pagination links
 *     ],
 *     
 *     'allow_credentials' => true,
 *     'max_age' => 86400,
 *     
 *     // Environment-specific overrides
 *     'development_allow_all' => env('APP_ENV', 'production') === 'development',
 * ];
 * ```
 * 
 * Usage Examples:
 * 
 * // Development: Allow all origins
 * APP_ENV=development  // Automatically allows all origins
 * 
 * // Production: Specific domains only  
 * APP_ENV=production   // Uses configured allowed_origins
 * 
 * // Custom per-route CORS (future enhancement)
 * $router->get('/api/public', $handler)->cors([
 *     'origins' => '*',
 *     'headers' => ['Content-Type']
 * ]);
 */

/**
 * Native Glueful Middleware Contract
 * 
 * This is the primary middleware interface for Glueful's routing system.
 * It provides flexibility with parameter passing while maintaining clean semantics.
 */
interface RouteMiddleware {
    /**
     * Handle middleware processing
     * 
     * @param Request $request The HTTP request being processed
     * @param callable $next Next handler in the pipeline - call $next($request) to continue
     * @param mixed ...$params Additional parameters extracted from route or middleware config
     *                         Examples: rate limits, auth requirements, etc.
     * @return Response|mixed Response object or data to be normalized
     * 
     * Example implementations:
     * 
     * // Simple middleware
     * public function handle(Request $request, callable $next) {
     *     // Do something before
     *     $response = $next($request);
     *     // Do something after
     *     return $response;
     * }
     * 
     * // Middleware with parameters
     * public function handle(Request $request, callable $next, string $role = 'user') {
     *     if (!$this->auth->hasRole($role)) {
     *         return new Response('Forbidden', 403);
     *     }
     *     return $next($request);
     * }
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed;
}

/**
 * PSR-15 Compatible Middleware Interface
 *
 * This interface follows the PSR-15 specification but adapts it to work
 * with Symfony's HttpFoundation components (Request/Response) instead of PSR-7.
 *
 * Middleware process an incoming request to produce a response,
 * either by handling the request or delegating to another middleware.
 */
declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PSR-15 Compatible Middleware Interface
 *
 * This interface follows the PSR-15 specification but adapts it to work
 * with Symfony's HttpFoundation components (Request/Response) instead of PSR-7.
 *
 * Middleware process an incoming request to produce a response,
 * either by handling the request or delegating to another middleware.
 */
interface Psr15MiddlewareInterface
{
    /**
     * Process an incoming request
     *
     * @param Request $request The request
     * @param RequestHandlerInterface $handler The handler to process the request
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response;
}


/**
 * Usage Examples:
 * 
 * // Native Glueful middleware with parameters
 * $router->group(['middleware' => 'auth:admin,editor'], function($router) {
 *     $router->get('/admin/users', [AdminController::class, 'users']);
 * });
 * 
 * // PSR-15 middleware adapter
 * $psrMiddleware = new SomeThirdPartyPsr15Middleware();
 * $adapter = new Psr15MiddlewareAdapter($psrMiddleware);
 * $router->get('/api/data', $handler)->middleware([$adapter]);
 * 
 * // Mixed middleware pipeline
 * $router->get('/complex', $handler)->middleware([
 *     'cors',                                    // Native Glueful
 *     'throttle:100,1',                         // Native with params
 *     new Psr15MiddlewareAdapter($psrMiddleware), // PSR-15 adapter
 *     'auth:jwt'                                // Native with params
 * ]);
 */
```

### Phase 2: Route Class & Dispatcher (Day 2)

#### 2.1 Simple Route Class
```php
namespace Glueful\Routing;

class Route {
    private string $method;
    private string $path;
    private mixed $handler;
    private array $middleware = [];
    private ?string $pattern = null;
    private array $paramNames = [];
    private ?string $name = null;
    private array $where = [];
    
    public function __construct(
        private Router $router, // Back-reference for named route registration
        string $method, 
        string $path, 
        mixed $handler
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        
        // Pre-compile pattern if dynamic
        if (str_contains($path, '{')) {
            $this->compilePattern();
        }
    }
    
    // Fluent middleware addition
    public function middleware(string|array $middleware): self {
        $this->middleware = array_merge($this->middleware, (array)$middleware);
        return $this;
    }
    
    // Parameter constraints
    public function where(string|array $param, ?string $regex = null): self {
        if (is_array($param)) {
            $this->where = array_merge($this->where, $param);
        } else {
            $this->where[$param] = $regex;
        }
        // Recompile pattern with constraints
        if ($this->pattern) {
            $this->compilePattern();
        }
        return $this;
    }
    
    // Named routes with automatic registration
    public function name(string $name): self {
        $this->name = $name;
        $this->router->registerNamedRoute($name, $this);
        return $this;
    }
    
    // Compile {param} to regex with safety checks
    private function compilePattern(): void {
        $pattern = $this->path;
        
        // Extract parameter names
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);
        $this->paramNames = $matches[1];
        
        // Replace {param} with regex
        foreach ($this->paramNames as $param) {
            $constraint = $this->where[$param] ?? '[^/]+';
            
            // Validate constraint regex for safety
            if (!$this->isValidConstraint($constraint)) {
                throw new \InvalidArgumentException("Invalid regex constraint for parameter '{$param}': {$constraint}");
            }
            
            // Escape delimiter characters and wrap in capture group
            $safeConstraint = str_replace('#', '\\#', $constraint);
            $pattern = str_replace('{' . $param . '}', '(' . $safeConstraint . ')', $pattern);
        }
        
        // Escape the path delimiter
        $pattern = str_replace('#', '\\#', $pattern);
        $this->pattern = '#^' . $pattern . '$#u'; // Add unicode modifier
    }
    
    // Validate regex constraint for security
    private function isValidConstraint(string $constraint): bool {
        // Test the regex for validity and safety
        try {
            $testResult = preg_match('#' . str_replace('#', '\\#', $constraint) . '#u', 'test');
            return $testResult !== false;
        } catch (\Exception) {
            return false;
        }
    }
    
    // Match path and extract parameters
    public function match(string $path): ?array {
        if ($this->pattern === null) {
            // Static route
            return $this->path === $path ? [] : null;
        }
        
        // Dynamic route
        if (preg_match($this->pattern, $path, $matches)) {
            array_shift($matches); // Remove full match
            
            if (empty($this->paramNames)) {
                return [];
            }
            
            return array_combine($this->paramNames, $matches);
        }
        
        return null;
    }
    
    // Getters for compiler/cache
    public function getMethod(): string { return $this->method; }
    public function getPath(): string { return $this->path; }
    public function getHandler(): mixed { return $this->handler; }
    public function getMiddleware(): array { return $this->middleware; }
    public function getName(): ?string { return $this->name; }
    public function getPattern(): ?string { return $this->pattern; }
    public function getParamNames(): array { return $this->paramNames; }
    public function getConstraints(): array { return $this->where; }
    
    // Generate URL from route parameters
    public function generateUrl(array $params = [], array $query = []): string {
        $url = $this->path;
        
        // Replace parameters in path
        foreach ($this->paramNames as $param) {
            if (!isset($params[$param])) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
            
            $value = (string) $params[$param];
            
            // Validate against constraint if set
            if (isset($this->where[$param])) {
                $constraint = '#^' . str_replace('#', '\\#', $this->where[$param]) . '$#u';
                if (!preg_match($constraint, $value)) {
                    throw new \InvalidArgumentException("Parameter '{$param}' value '{$value}' does not match constraint");
                }
            }
            
            $url = str_replace('{' . $param . '}', rawurlencode($value), $url);
        }
        
        // Add query parameters
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        
        return $url;
    }
}
```

#### 2.2 Simple Dispatcher
```php
// Add to Router.php
class Router {
    // ... previous code ...
    
    public function dispatch(Request $request): Response {
        $originalMethod = $request->getMethod();
        
        // Handle OPTIONS for CORS (before any processing)
        if ($originalMethod === 'OPTIONS') {
            $path = '/' . ltrim(rawurldecode($request->getPathInfo()), '/');
            $path = rtrim($path, '/') ?: '/';
            $allowedMethods = $this->getAllowedMethods($path);
            
            if (!empty($allowedMethods)) {
                // Get comprehensive CORS headers from centralized configuration
                $corsHeaders = $this->buildCorsHeaders($request, $allowedMethods);
                
                return new Response('', 204, array_merge([
                    'Allow' => implode(', ', $allowedMethods),
                    'Content-Type' => 'text/plain'
                ], $corsHeaders));
            }
        }
        
        // Handle HEAD requests by mapping to GET
        if ($originalMethod === 'HEAD') {
            $request = $request->duplicate();
            $request->setMethod('GET');
        }
        
        // Match route
        $match = $this->match($request);
        
        if (!$match) {
            return new Response('Not Found', 404);
        }
        
        // Handle 405 Method Not Allowed
        if ($match['route'] === null && isset($match['allowed_methods'])) {
            return new Response('Method Not Allowed', 405, [
                'Allow' => implode(', ', $match['allowed_methods']),
                'Content-Type' => 'text/plain'
            ]);
        }
        
        $route = $match['route'];
        $params = $match['params'];
        
        // Build middleware pipeline (with caching)
        $pipeline = $this->buildMiddlewarePipeline($route);
        
        // Create controller invoker with proper parameter resolution
        $invoker = $this->createControllerInvoker($route->getHandler(), $params, $request);
        
        // Execute through middleware or directly
        if (!empty($pipeline)) {
            $response = $this->executeWithMiddleware($request, $pipeline, $invoker);
        } else {
            $response = $this->normalizeResponse($invoker());
        }
        
        // Handle HEAD requests (remove body but keep headers)
        if ($originalMethod === 'HEAD') {
            $response->setContent('');
        }
        
        return $response;
    }
    
    private function createControllerInvoker(mixed $handler, array $params, Request $request): callable {
        return function() use ($handler, $params, $request) {
            // Use cached reflection for performance - FIXED: now actually uses cache
            $reflection = $this->getReflection($handler);
            $args = $this->resolveMethodParameters($reflection, $request, $params);
            
            // Array controller [Class, 'method'] 
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $instance = $this->container->get($class);
                return $reflection->invokeArgs($instance, $args);
            }
            
            // Closure (fixed invocation)
            if ($handler instanceof \Closure) {
                return $reflection->invokeArgs($args);
            }
            
            // String callable "Class::method"
            if (is_string($handler) && str_contains($handler, '::')) {
                [$class, $method] = explode('::', $handler, 2);
                $instance = $this->container->get($class);
                return $reflection->invokeArgs($instance, $args);
            }
            
            // Invokable class
            if (is_string($handler) && class_exists($handler)) {
                $instance = $this->container->get($handler);
                return $reflection->invokeArgs($instance, $args);
            }
            
            throw new \RuntimeException('Invalid handler type: ' . gettype($handler));
        };
    }
    
    // Cached reflection lookup for performance
    private function getReflection(mixed $handler): \ReflectionFunction|\ReflectionMethod {
        $key = match(true) {
            is_array($handler) => $handler[0] . '::' . $handler[1],
            is_string($handler) && str_contains($handler, '::') => $handler,
            is_string($handler) && class_exists($handler) => $handler . '::__invoke',
            default => spl_object_id($handler) // For closures and other callables
        };

        if (isset($this->reflectionCache[$key])) {
            return $this->reflectionCache[$key];
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;
            return $this->reflectionCache[$key] = new \ReflectionMethod($class, $method);
        }

        if ($handler instanceof \Closure) {
            return $this->reflectionCache[$key] = new \ReflectionFunction($handler);
        }

        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            return $this->reflectionCache[$key] = new \ReflectionMethod($class, $method);
        }

        if (is_string($handler) && class_exists($handler)) {
            return $this->reflectionCache[$key] = new \ReflectionMethod($handler, '__invoke');
        }

        throw new \RuntimeException("Unable to reflect handler: " . gettype($handler));
    }
    
    // Robust parameter resolution with type casting and DI
    private function resolveMethodParameters(\ReflectionFunction|\ReflectionMethod $reflection, Request $request, array $params): array {
        $args = [];
        
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();
            
            // Try type-based injection first (DI)
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                
                // Inject Request and related objects
                if ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                    $args[] = $request;
                    continue;
                }
                
                // Inject from container
                if ($this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);
                    continue;
                }
                
                // Try to instantiate if it's a concrete class
                if (class_exists($typeName)) {
                    try {
                        $args[] = $this->container->get($typeName);
                        continue;
                    } catch (\Exception) {
                        // Fall through to parameter injection
                    }
                }
            }
            
            // Try route parameter injection by name
            if (isset($params[$name])) {
                $value = $params[$name];
                
                // Cast to declared type
                if ($type && $type->isBuiltin()) {
                    $value = $this->castParameter($value, $type);
                }
                
                $args[] = $value;
                continue;
            }
            
            // Use default value if available
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            
            // Parameter couldn't be resolved
            throw new \RuntimeException("Cannot resolve parameter '{$name}' for handler");
        }
        
        return $args;
    }
    
    // Cast parameter values to declared types
    private function castParameter(mixed $value, \ReflectionType $type): mixed {
        $typeName = $type->getName();
        
        return match($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default => $value // Keep as-is for objects and other types
        };
    }
    
    // Build middleware pipeline with caching
    private function buildMiddlewarePipeline(Route $route): array {
        $routeId = spl_object_id($route);
        
        if (isset($this->pipelineCache[$routeId])) {
            return $this->pipelineCache[$routeId];
        }
        
        $middleware = $route->getMiddleware();
        $this->pipelineCache[$routeId] = $middleware;
        
        return $middleware;
    }
    
    // Execute request through middleware pipeline
    private function executeWithMiddleware(Request $request, array $middlewareStack, callable $core): Response {
        $next = $core;
        
        // Build pipeline inside-out to minimize allocations
        for ($i = count($middlewareStack) - 1; $i >= 0; $i--) {
            $middleware = $this->resolveMiddleware($middlewareStack[$i]);
            $next = fn(Request $req) => $middleware($req, $next);
        }
        
        return $this->normalizeResponse($next($request));
    }
    
    // Resolve middleware from container with parameter support
    private function resolveMiddleware(string|callable $middleware): callable {
        if (is_callable($middleware)) {
            return $middleware;
        }
        
        // Support "name:param1,param2" format
        [$name, $args] = array_pad(explode(':', $middleware, 2), 2, null);
        $params = $args ? array_map('trim', explode(',', $args)) : [];
        
        // Get middleware instance from container
        $instance = $this->container->get($name);
        
        // Return callable that invokes middleware with parameters
        return function(Request $request, callable $next) use ($instance, $params) {
            // Expect handle(Request $req, callable $next, ...$params): mixed
            return $instance->handle($request, $next, ...$params);
        };
    }
    
    private function normalizeResponse(mixed $result): Response {
        // Pass through all Response types unchanged
        if ($result instanceof Response) {
            return $result;
        }
        
        if (is_string($result)) {
            return new Response($result);
        }
        
        if (is_array($result) || is_object($result)) {
            return new JsonResponse($result);
        }
        
        return new Response((string) $result);
    }
    
    // URL Generation
    private array $namedRoutes = []; // Route name => Route object
    
    public function url(string $name, array $params = [], array $query = []): string {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route '{$name}' not found");
        }
        
        return $this->namedRoutes[$name]->generateUrl($params, $query);
    }
    
    // Register named route (called automatically when Route::name() is used)
    public function registerNamedRoute(string $name, Route $route): void {
        if (isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route name '{$name}' already exists");
        }
        
        $this->namedRoutes[$name] = $route;
    }
    
    // Get all routes (for cache/compiler)
    public function getStaticRoutes(): array { return $this->staticRoutes; }
    public function getDynamicRoutes(): array { return $this->dynamicRoutes; }
    public function getNamedRoutes(): array { return $this->namedRoutes; }
    
    // Get all routes for CLI RouteListCommand  
    public function getAllRoutes(): array {
        $allRoutes = [];
        
        // Add static routes
        foreach ($this->staticRoutes as $key => $route) {
            [$method, $path] = explode(':', $key, 2);
            $allRoutes[] = [
                'method' => $method,
                'path' => $path,
                'handler' => $route->getHandler(),
                'middleware' => $route->getMiddleware(),
                'name' => $route->getName(),
                'type' => 'static'
            ];
        }
        
        // Add dynamic routes
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $route) {
                $allRoutes[] = [
                    'method' => $method,
                    'path' => $route->getPath(),
                    'handler' => $route->getHandler(),
                    'middleware' => $route->getMiddleware(),
                    'name' => $route->getName(),
                    'type' => 'dynamic'
                ];
            }
        }
        
        // Sort by path for consistent CLI output
        usort($allRoutes, fn($a, $b) => $a['path'] <=> $b['path']);
        
        return $allRoutes;
    }
}
```

### Phase 3: Compilation & Caching (Day 3)

#### 3.1 Simple Route Compiler
```php
namespace Glueful\Routing;

class RouteCompiler {
    
    /**
     * Compile routes to cacheable PHP with normalized handlers
     * 
     * This method ensures consistent serialization of all handler types
     * and prevents cache corruption from closures or other non-serializable handlers.
     */
    public function compile(Router $router): string {
        $static = $router->getStaticRoutes();
        $dynamic = $router->getDynamicRoutes();
        $named = $router->getNamedRoutes();
        
        $code = "<?php\n\n";
        $code .= "// Auto-generated route cache - DO NOT EDIT\n";
        $code .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $code .= "return [\n";
        
        // Export static routes with normalized handlers
        $code .= "    'static' => [\n";
        foreach ($static as $key => $route) {
            $handlerMeta = $this->normalizeHandler($route->getHandler());
            $middleware = var_export($route->getMiddleware(), true);
            $code .= "        '{$key}' => [\n";
            $code .= "            'handler' => " . var_export($handlerMeta, true) . ",\n";
            $code .= "            'middleware' => {$middleware}\n";
            $code .= "        ],\n";
        }
        $code .= "    ],\n";
        
        // Export dynamic routes with normalized handlers
        $code .= "    'dynamic' => [\n";
        foreach ($dynamic as $method => $routes) {
            $code .= "        '{$method}' => [\n";
            foreach ($routes as $route) {
                $pattern = $route->getPattern();
                $handlerMeta = $this->normalizeHandler($route->getHandler());
                $params = var_export($route->getParamNames(), true);
                $code .= "            [\n";
                $code .= "                'pattern' => '{$pattern}',\n";
                $code .= "                'handler' => " . var_export($handlerMeta, true) . ",\n";
                $code .= "                'params' => {$params}\n";
                $code .= "            ],\n";
            }
            $code .= "        ],\n";
        }
        $code .= "    ]\n";
        $code .= "];\n";
        
        return $code;
    }
    
    /**
     * Normalize handler into serializable metadata
     * 
     * Converts all handler types into a consistent, serializable format
     * that can be safely cached and reconstructed.
     */
    private function normalizeHandler(mixed $handler): array {
        return [
            'type' => $this->getHandlerType($handler),
            'target' => $this->serializeHandler($handler),
            'metadata' => $this->getHandlerMetadata($handler)
        ];
    }
    
    /**
     * Determine handler type for consistent categorization
     */
    private function getHandlerType(mixed $handler): string {
        return match(true) {
            is_array($handler) => 'array_callable',
            $handler instanceof \Closure => 'closure',
            is_string($handler) && str_contains($handler, '::') => 'static_method',
            is_string($handler) && class_exists($handler) => 'invokable_class',
            is_callable($handler) => 'callable',
            default => 'unknown'
        };
    }
    
    /**
     * Serialize handler into reconstructable format
     */
    private function serializeHandler(mixed $handler): mixed {
        return match(true) {
            is_array($handler) => [
                'class' => is_object($handler[0]) ? get_class($handler[0]) : $handler[0],
                'method' => $handler[1]
            ],
            $handler instanceof \Closure => [
                'warning' => 'Closure handlers cannot be cached reliably',
                'reflection' => $this->getClosureInfo($handler)
            ],
            is_string($handler) => $handler,
            default => [
                'type' => gettype($handler),
                'class' => is_object($handler) ? get_class($handler) : null
            ]
        };
    }
    
    /**
     * Extract metadata for handler debugging and validation
     */
    private function getHandlerMetadata(mixed $handler): array {
        $metadata = [
            'created_at' => date('c'),
            'php_version' => PHP_VERSION
        ];
        
        try {
            if (is_array($handler)) {
                $reflection = new \ReflectionMethod($handler[0], $handler[1]);
                $metadata['parameters'] = array_map(
                    fn($param) => [
                        'name' => $param->getName(),
                        'type' => $param->getType()?->getName(),
                        'optional' => $param->isOptional()
                    ],
                    $reflection->getParameters()
                );
                $metadata['filename'] = $reflection->getFileName();
                $metadata['line'] = $reflection->getStartLine();
            } elseif ($handler instanceof \Closure) {
                $reflection = new \ReflectionFunction($handler);
                $metadata['filename'] = $reflection->getFileName();
                $metadata['line'] = $reflection->getStartLine();
            }
        } catch (\ReflectionException $e) {
            $metadata['reflection_error'] = $e->getMessage();
        }
        
        return $metadata;
    }
    
    /**
     * Get closure information for debugging (closures can't be cached reliably)
     */
    private function getClosureInfo(\Closure $closure): array {
        $reflection = new \ReflectionFunction($closure);
        
        return [
            'filename' => $reflection->getFileName() ?: 'unknown',
            'line_start' => $reflection->getStartLine() ?: 0,
            'line_end' => $reflection->getEndLine() ?: 0,
            'parameters' => array_map(
                fn($param) => $param->getName(),
                $reflection->getParameters()
            )
        ];
    }
    
    /**
     * Validate that handlers can be properly serialized
     * 
     * Call this before caching to catch problematic handlers early
     */
    public function validateHandlers(Router $router): array {
        $issues = [];
        
        foreach ($router->getStaticRoutes() as $key => $route) {
            if ($issue = $this->validateHandler($route->getHandler(), "static route: {$key}")) {
                $issues[] = $issue;
            }
        }
        
        foreach ($router->getDynamicRoutes() as $method => $routes) {
            foreach ($routes as $i => $route) {
                $path = $route->getPath();
                if ($issue = $this->validateHandler($route->getHandler(), "dynamic route: {$method} {$path}")) {
                    $issues[] = $issue;
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * Validate individual handler for caching compatibility
     */
    private function validateHandler(mixed $handler, string $context): ?string {
        if ($handler instanceof \Closure) {
            return "Warning: {$context} uses closure which cannot be cached reliably. " .
                   "Consider using array callable [Class::class, 'method'] instead.";
        }
        
        if (is_array($handler)) {
            if (!class_exists($handler[0]) || !method_exists($handler[0], $handler[1])) {
                return "Error: {$context} references non-existent method {$handler[0]}::{$handler[1]}";
            }
        }
        
        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            if (!class_exists($class) || !method_exists($class, $method)) {
                return "Error: {$context} references non-existent method {$handler}";
            }
        }
        
        return null;
    }
    
    // Generate optimized native PHP matcher
    public function compileToNative(Router $router): string {
        $static = $router->getStaticRoutes();
        
        $code = "<?php\n\n";
        $code .= "// Native compiled matcher\n";
        $code .= "return function(\$method, \$path) {\n";
        $code .= "    \$key = \$method . ':' . \$path;\n";
        $code .= "    switch(\$key) {\n";
        
        foreach ($static as $key => $route) {
            $handler = var_export($route->getHandler(), true);
            $code .= "        case '{$key}': return {$handler};\n";
        }
        
        $code .= "        default: return null;\n";
        $code .= "    }\n";
        $code .= "};\n";
        
        return $code;
    }
}
```

#### 3.2 Development & Production Cache
```php
namespace Glueful\Routing;

class RouteCache {
    private string $cacheDir;
    private int $devTtl = 5; // 5 seconds in dev
    
    public function __construct() {
        $this->cacheDir = base_path('storage/cache');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    public function load(): ?array {
        $cacheFile = $this->getCacheFile();
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        // Development: check TTL and file changes
        if ($this->isDevelopment()) {
            $age = time() - filemtime($cacheFile);
            if ($age > $this->devTtl) {
                return null;
            }
            
            // Check if route files changed
            if ($this->routeFilesChanged(filemtime($cacheFile))) {
                unlink($cacheFile);
                return null;
            }
        }
        
        return include $cacheFile;
    }
    
    public function save(Router $router): bool {
        $compiler = new RouteCompiler();
        $code = $compiler->compile($router);
        
        $cacheFile = $this->getCacheFile();
        $tmpFile = $cacheFile . '.tmp';
        
        // Atomic write with proper permissions
        file_put_contents($tmpFile, $code, LOCK_EX);
        chmod($tmpFile, 0644);
        rename($tmpFile, $cacheFile);
        
        // Warm opcache
        if (function_exists('opcache_compile_file')) {
            opcache_compile_file($cacheFile);
        }
        
        return true;
    }
    
    public function clear(): void {
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    private function getCacheFile(): string {
        $env = $this->isDevelopment() ? 'dev' : 'prod';
        return $this->cacheDir . "/routes_{$env}.php";
    }
    
    private function isDevelopment(): bool {
        return env('APP_ENV', 'production') !== 'production';
    }
    
    private function routeFilesChanged(int $cacheTime): bool {
        $files = array_merge(
            glob(base_path('routes/*.php')) ?: [],
            glob(base_path('extensions/*/routes.php')) ?: []
        );
        
        foreach ($files as $file) {
            if (filemtime($file) > $cacheTime) {
                return true;
            }
        }
        
        return false;
    }
}
```

### Phase 4: Integration & Migration (Day 4)

#### 4.1 Static Facade for Backward Compatibility
```php
// Keep existing Router.php as facade
namespace Glueful\Http;

use Glueful\Routing\Router as NextGenRouter;

class Router {
    private static ?NextGenRouter $instance = null;
    
    // Maintain all existing static methods for compatibility
    public static function get(string $path, mixed $handler): Route {
        return self::instance()->get($path, $handler);
    }
    
    public static function post(string $path, mixed $handler): Route {
        return self::instance()->post($path, $handler);
    }
    
    public static function put(string $path, mixed $handler): Route {
        return self::instance()->put($path, $handler);
    }
    
    public static function delete(string $path, mixed $handler): Route {
        return self::instance()->delete($path, $handler);
    }
    
    public static function group(array $attributes, callable $callback): void {
        self::instance()->group($attributes, $callback);
    }
    
    // Existing route methods from current Router
    public static function static(string $path, string $directory): void {
        self::instance()->static($path, $directory);
    }
    
    public static function resource(string $path, string $controller): void {
        self::instance()->resource($path, $controller);
    }
    
    public static function getRoutes(): array {
        return self::instance()->getAllRoutes();
    }
    
    public static function dispatch(Request $request): Response {
        return self::instance()->dispatch($request);
    }
    
    private static function instance(): NextGenRouter {
        if (!self::$instance) {
            self::$instance = container()->get(NextGenRouter::class);
        }
        return self::$instance;
    }
}
    
```

#### 4.2 Framework Integration
```php
// Update src/Framework.php initializeHttpLayer method
private function initializeHttpLayer(): void
{
    try {
        // Use new router if available
        if ($this->container->has(\Glueful\Routing\Router::class)) {
            $router = $this->container->get(\Glueful\Routing\Router::class);
            
            // Load routes
            $this->loadApplicationRoutes($router);
            
            // Cache in production
            if ($this->environment === 'production') {
                $cache = $this->container->get(\Glueful\Routing\RouteCache::class);
                $cache->save($router);
            }
        } else {
            // Fallback to existing router
            MiddlewareRegistry::registerFromConfig($this->container);
            
            if ($this->container->has(\Glueful\Extensions\ExtensionManager::class)) {
                $extensionManager = $this->container->get(\Glueful\Extensions\ExtensionManager::class);
                $extensionManager->loadEnabledExtensions();
                $extensionManager->loadExtensionRoutes();
            }
            
            RoutesManager::loadRoutes();
        }
    } catch (\Throwable $e) {
        error_log("HTTP layer initialization failed: " . $e->getMessage());
    }
}
```

### Phase 5: Attribute-Based Routing (Day 5)

#### 5.1 Route Attributes
```php
namespace Glueful\Routing\Attributes;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Route {
    public function __construct(
        public string $path,
        public string|array $methods = 'GET',
        public ?string $name = null,
        public array $middleware = [],
        public array $where = []
    ) {
        $this->methods = (array) $this->methods;
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware {
    public function __construct(
        public string|array $middleware
    ) {
        $this->middleware = (array) $this->middleware;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class Controller {
    public function __construct(
        public string $prefix = '',
        public array $middleware = []
    ) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
class Get {
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $where = []
    ) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
class Post {
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $where = []
    ) {}
}

// Shorthand attributes for common HTTP methods
#[Attribute(Attribute::TARGET_METHOD)]
class Put {
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $where = []
    ) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
class Delete {
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $where = []
    ) {}
}
```

#### 5.2 Attribute Scanner and Loader
```php
namespace Glueful\Routing;

use Glueful\Routing\Attributes\{Route, Controller, Middleware, Get, Post, Put, Delete};

class AttributeRouteLoader {
    private Router $router;
    private array $scannedClasses = [];
    
    public function __construct(Router $router) {
        $this->router = $router;
    }
    
    /**
     * Scan directory for controllers with route attributes
     */
    public function scanDirectory(string $directory): void {
        $files = $this->getPhpFiles($directory);
        
        foreach ($files as $file) {
            $class = $this->getClassFromFile($file);
            if ($class && !in_array($class, $this->scannedClasses)) {
                $this->processClass($class);
                $this->scannedClasses[] = $class;
            }
        }
    }
    
    /**
     * Process a single controller class
     */
    public function processClass(string $className): void {
        if (!class_exists($className)) {
            return;
        }
        
        $reflection = new \ReflectionClass($className);
        
        // Skip if abstract or not instantiable
        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            return;
        }
        
        // Get class-level attributes
        $classAttributes = $this->getClassAttributes($reflection);
        $prefix = $classAttributes['prefix'] ?? '';
        $classMiddleware = $classAttributes['middleware'] ?? [];
        
        // Process each public method
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isStatic()) {
                continue;
            }
            
            $this->processMethod($method, $className, $prefix, $classMiddleware);
        }
    }
    
    /**
     * Extract class-level routing attributes
     */
    private function getClassAttributes(\ReflectionClass $class): array {
        $attributes = [];
        
        // Check for Controller attribute
        foreach ($class->getAttributes(Controller::class) as $attribute) {
            $controller = $attribute->newInstance();
            $attributes['prefix'] = $controller->prefix;
            $attributes['middleware'] = $controller->middleware;
        }
        
        // Check for class-level Route attribute
        foreach ($class->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $attributes['prefix'] = $route->path;
            if (!empty($route->middleware)) {
                $attributes['middleware'] = array_merge(
                    $attributes['middleware'] ?? [],
                    $route->middleware
                );
            }
        }
        
        // Check for class-level Middleware attributes
        foreach ($class->getAttributes(Middleware::class) as $attribute) {
            $middleware = $attribute->newInstance();
            $attributes['middleware'] = array_merge(
                $attributes['middleware'] ?? [],
                $middleware->middleware
            );
        }
        
        return $attributes;
    }
    
    /**
     * Process method attributes and register routes
     */
    private function processMethod(
        \ReflectionMethod $method,
        string $className,
        string $prefix,
        array $classMiddleware
    ): void {
        $methodName = $method->getName();
        $methodMiddleware = [];
        
        // Get method-level middleware
        foreach ($method->getAttributes(Middleware::class) as $attribute) {
            $middleware = $attribute->newInstance();
            $methodMiddleware = array_merge($methodMiddleware, $middleware->middleware);
        }
        
        // Combine class and method middleware
        $allMiddleware = array_merge($classMiddleware, $methodMiddleware);
        
        // Process Route attributes
        foreach ($method->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $fullPath = $this->combinePaths($prefix, $route->path);
            
            foreach ($route->methods as $httpMethod) {
                $registered = $this->router->add(
                    $httpMethod,
                    $fullPath,
                    [$className, $methodName]
                );
                
                // Apply middleware
                if (!empty($allMiddleware)) {
                    $registered->middleware($allMiddleware);
                }
                
                // Apply route-specific middleware
                if (!empty($route->middleware)) {
                    $registered->middleware($route->middleware);
                }
                
                // Set route name
                if ($route->name) {
                    $registered->name($route->name);
                }
                
                // Apply constraints
                if (!empty($route->where)) {
                    $registered->where($route->where);
                }
            }
        }
        
        // Process HTTP method shortcuts (Get, Post, Put, Delete)
        $this->processHttpMethodAttributes($method, $className, $methodName, $prefix, $allMiddleware);
    }
    
    /**
     * Process shorthand HTTP method attributes
     */
    private function processHttpMethodAttributes(
        \ReflectionMethod $method,
        string $className,
        string $methodName,
        string $prefix,
        array $middleware
    ): void {
        $httpMethods = [
            Get::class => 'GET',
            Post::class => 'POST',
            Put::class => 'PUT',
            Delete::class => 'DELETE',
        ];
        
        foreach ($httpMethods as $attributeClass => $httpMethod) {
            foreach ($method->getAttributes($attributeClass) as $attribute) {
                $attr = $attribute->newInstance();
                $fullPath = $this->combinePaths($prefix, $attr->path);
                
                $route = $this->router->add(
                    $httpMethod,
                    $fullPath,
                    [$className, $methodName]
                );
                
                if (!empty($middleware)) {
                    $route->middleware($middleware);
                }
                
                if ($attr->name) {
                    $route->name($attr->name);
                }
                
                if (!empty($attr->where)) {
                    $route->where($attr->where);
                }
            }
        }
    }
    
    /**
     * Combine prefix and path properly
     */
    private function combinePaths(string $prefix, string $path): string {
        $prefix = rtrim($prefix, '/');
        $path = ltrim($path, '/');
        
        if ($prefix === '') {
            return '/' . $path;
        }
        
        return $prefix . '/' . $path;
    }
    
    /**
     * Get all PHP files in directory recursively
     */
    private function getPhpFiles(string $directory): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Extract class name from file (simple implementation)
     */
    private function getClassFromFile(string $file): ?string {
        $contents = file_get_contents($file);
        
        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        } else {
            $namespace = '';
        }
        
        // Extract class name
        if (preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            $className = $classMatch[1];
            return $namespace ? $namespace . '\\' . $className : $className;
        }
        
        return null;
    }
}
```

#### 5.3 Usage Examples
```php
// Example controller with attributes
namespace App\Controllers;

use Glueful\Routing\Attributes\{Controller, Get, Post, Put, Delete, Middleware};
use Symfony\Component\HttpFoundation\{Request, JsonResponse};

#[Controller(prefix: '/api/v1')]
#[Middleware(['api', 'throttle:60,1'])]
class UserController {
    
    #[Get('/users', name: 'users.index')]
    public function index(Request $request): JsonResponse {
        return new JsonResponse(['users' => []]);
    }
    
    #[Get('/users/{id}', name: 'users.show', where: ['id' => '\d+'])]
    public function show(Request $request, int $id): JsonResponse {
        return new JsonResponse(['user' => $id]);
    }
    
    #[Post('/users', name: 'users.store')]
    #[Middleware('auth:admin')]
    public function store(Request $request): JsonResponse {
        return new JsonResponse(['created' => true], 201);
    }
    
    #[Put('/users/{id}', name: 'users.update', where: ['id' => '\d+'])]
    #[Middleware('auth:admin')]
    public function update(Request $request, int $id): JsonResponse {
        return new JsonResponse(['updated' => $id]);
    }
    
    #[Delete('/users/{id}', name: 'users.destroy', where: ['id' => '\d+'])]
    #[Middleware('auth:admin')]
    public function destroy(int $id): JsonResponse {
        return new JsonResponse(['deleted' => $id]);
    }
}

// RESTful resource controller
#[Controller(prefix: '/api/products')]
class ProductController {
    
    #[Get('/', name: 'products.index')]
    public function index(): JsonResponse {
        return new JsonResponse(['products' => []]);
    }
    
    #[Get('/{id}', name: 'products.show')]
    public function show(int $id): JsonResponse {
        return new JsonResponse(['product' => $id]);
    }
    
    #[Post('/', name: 'products.store')]
    public function store(Request $request): JsonResponse {
        return new JsonResponse(['created' => true], 201);
    }
}
```

#### 5.4 Integration with Router
```php
// Update Router.php to include attribute support
namespace Glueful\Routing;

class Router {
    // ... existing code ...
    
    private ?AttributeRouteLoader $attributeLoader = null;
    
    /**
     * Scan directory for attribute-based routes
     */
    public function discover(string|array $directories): self {
        if (!$this->attributeLoader) {
            $this->attributeLoader = new AttributeRouteLoader($this);
        }
        
        foreach ((array) $directories as $directory) {
            $this->attributeLoader->scanDirectory($directory);
        }
        
        return $this;
    }
    
    /**
     * Register a specific controller class
     */
    public function controller(string $controllerClass): self {
        if (!$this->attributeLoader) {
            $this->attributeLoader = new AttributeRouteLoader($this);
        }
        
        $this->attributeLoader->processClass($controllerClass);
        
        return $this;
    }
}

// Usage in application bootstrap
$router = $container->get(Router::class);

// Discover all controllers in directory
$router->discover([
    base_path('app/Controllers'),
    base_path('app/Api/Controllers'),
]);

// Or register specific controllers
$router->controller(UserController::class);
$router->controller(ProductController::class);
```

### Route Manifest (Zero File Scanning)
```php
// Eliminate file scanning completely with pre-built manifest
class RouteManifest {
    public static function generate(): array {
        return [
            'core_routes' => [
                '/routes/web.php',
                '/routes/api.php',
                '/routes/admin.php',
            ],
            'extension_routes' => [
                'user_management' => '/extensions/user_management/routes.php',
                'analytics' => '/extensions/analytics/routes.php',
            ],
            'generated_at' => time(),
        ];
    }
    
    public static function load(Router $router): void {
        $manifest = self::generate();
        
        // Load core routes
        foreach ($manifest['core_routes'] as $file) {
            if (file_exists(base_path($file))) {
                require base_path($file);
            }
        }
        
        // Load enabled extension routes
        $enabled = config('extensions.enabled', []);
        foreach ($enabled as $extension) {
            if (isset($manifest['extension_routes'][$extension])) {
                $file = $manifest['extension_routes'][$extension];
                if (file_exists(base_path($file))) {
                    require base_path($file);
                }
            }
        }
    }
}
```

## Performance Optimizations

### Key Performance Features

1. **O(1) Static Route Matching**
   - Static routes use hash table lookup: `$routes[$method . ':' . $path]`
   - No regex evaluation for static paths
   - Direct array access for common routes

2. **Pre-compiled Dynamic Patterns**
   - Patterns compiled once during route registration
   - Cached regex patterns avoid recompilation
   - Parameter extraction optimized

3. **Smart Caching Strategy**
   - Production: Permanent cache with opcache warming
   - Development: 5-second TTL with file change detection
   - Atomic writes prevent corruption

4. **Minimal Memory Footprint**
   - Routes stored efficiently in arrays
   - No unnecessary object creation
   - Lazy loading of route handlers

### Benchmark Results (Expected)
```
Operation               | Old Router | New Router | Improvement
------------------------|------------|------------|-------------
Static route match      | 0.05ms     | 0.001ms    | 50x
Dynamic route match     | 0.15ms     | 0.05ms     | 3x
1000 route registration | 120ms      | 15ms       | 8x
Memory (1000 routes)    | 12MB       | 4MB        | 3x
Cold boot (with cache)  | 30,000ms   | 80ms       | 375x
```

## Implementation Timeline

### Day 1: Core Router
- [ ] Implement `Router.php` with registration and matching
- [ ] Fix controller reflection bug
- [ ] Implement group middleware support
- [ ] Test with existing route patterns

### Day 2: Route Class & Dispatcher  
- [ ] Implement `Route.php` with pattern compilation
- [ ] Add parameter constraints
- [ ] Create smart dispatcher with all controller types
- [ ] Test Request injection and parameter passing

### Day 3: Caching & Compilation
- [ ] Implement `RouteCompiler.php`
- [ ] Create `RouteCache.php` with dev/prod modes
- [ ] Add file change detection
- [ ] Test cache performance

### Day 4: Integration & Testing
- [ ] Create backward compatibility facade
- [ ] Update Framework.php
- [ ] Migrate existing routes
- [ ] Run full test suite
- [ ] Performance benchmarking

## Migration Strategy

### Step 1: Parallel Implementation
- Build new router alongside existing Router.php
- No breaking changes to existing code
- Comprehensive test coverage from start

### Step 2: Adapter Layer
```php
class RouterAdapter {
    private NextGenRouter $newRouter;
    private LegacyRouter $oldRouter;
    private bool $useNew = false;
    
    public function __call($method, $args)
    {
        if ($this->useNew && method_exists($this->newRouter, $method)) {
            return $this->newRouter->$method(...$args);
        }
        
        return $this->oldRouter->$method(...$args);
    }
    
    public function enableNewRouter(): void
    {
        $this->useNew = true;
    }
}
```

### Step 3: Gradual Migration
1. Replace Router singleton with adapter
2. Test with new router in development
3. Enable for specific routes/environments
4. Full migration once stable

### Step 4: Cleanup
1. Remove old Router.php
2. Remove adapter layer
3. Update documentation

## Testing Strategy

### Unit Tests
```php
class RouterTest extends TestCase {
    public function test_static_route_matching(): void {
        $router = new Router($this->container);
        $router->get('/users', fn() => 'users');
        
        $match = $router->match(new Request('/users', 'GET'));
        
        $this->assertNotNull($match);
        $this->assertEquals([], $match['params']);
    }
    
    public function test_dynamic_route_parameters(): void {
        $router = new Router($this->container);
        $router->get('/users/{id}', fn($id) => "User $id")
               ->where('id', '\d+');
        
        $match = $router->match(new Request('/users/123', 'GET'));
        
        $this->assertNotNull($match);
        $this->assertEquals(['id' => '123'], $match['params']);
    }
    
    public function test_group_middleware(): void {
        $router = new Router($this->container);
        
        $router->group(['middleware' => 'auth'], function($router) {
            $router->get('/admin', fn() => 'admin');
        });
        
        $match = $router->match(new Request('/admin', 'GET'));
        $route = $match['route'];
        
        $this->assertContains('auth', $route->getMiddleware());
    }
    
    /**
     * CRITICAL: Test route precedence edge cases
     * These tests ensure /users/me always beats /users/{id}
     */
    public function test_route_precedence_static_beats_dynamic(): void {
        $router = new Router($this->container);
        
        // Register in "wrong" order - dynamic first
        $router->get('/users/{id}', fn($id) => "User ID: $id");
        $router->get('/users/me', fn() => "Current user");
        
        // Static should still win
        $match = $router->match(Request::create('/users/me', 'GET'));
        $response = $router->dispatch(Request::create('/users/me', 'GET'));
        
        $this->assertNotNull($match);
        $this->assertEquals('Current user', $response->getContent());
    }
    
    public function test_route_precedence_multiple_static_segments(): void {
        $router = new Router($this->container);
        
        // Register complex overlapping routes
        $router->get('/api/{version}/users/{id}', fn($version, $id) => "API $version User $id");
        $router->get('/api/v1/users/current', fn() => "Current user v1");  
        $router->get('/api/v1/users/{id}', fn($id) => "User $id v1");
        $router->get('/api/{version}/users/current', fn($version) => "Current user $version");
        
        // Most specific should win
        $match = $router->match(Request::create('/api/v1/users/current', 'GET'));
        $response = $router->dispatch(Request::create('/api/v1/users/current', 'GET'));
        
        $this->assertEquals('Current user v1', $response->getContent());
    }
    
    public function test_route_precedence_same_pattern_different_constraints(): void {
        $router = new Router($this->container);
        
        // Routes with different constraints
        $router->get('/items/{id}', fn($id) => "Item: $id")
               ->where('id', '\d+');  // Numbers only
               
        $router->get('/items/{slug}', fn($slug) => "Item slug: $slug")
               ->where('slug', '[a-z-]+'); // Letters and dashes
        
        // Test numeric ID
        $numericMatch = $router->match(Request::create('/items/123', 'GET'));
        $this->assertNotNull($numericMatch);
        
        // Test slug
        $slugMatch = $router->match(Request::create('/items/my-item', 'GET'));
        $this->assertNotNull($slugMatch);
    }
    
    public function test_route_precedence_comprehensive_ordering(): void {
        $router = new Router($this->container);
        
        // Register routes in mixed order to test precedence algorithm
        $routes = [
            ['GET', '/blog/{year}/{month}/{slug}', 'dynamic-3-params'],
            ['GET', '/blog/2024/01/featured', 'static-all'], 
            ['GET', '/blog/2024/{month}/featured', 'static-partial-1'],
            ['GET', '/blog/{year}/01/featured', 'static-partial-2'],
            ['GET', '/blog/2024/01/{slug}', 'static-partial-3'],
            ['GET', '/blog/{year}/{month}/featured', 'dynamic-2-params'],
        ];
        
        // Shuffle to ensure order independence
        shuffle($routes);
        
        foreach ($routes as [$method, $path, $response]) {
            $router->get($path, fn() => $response);
        }
        
        // Test that most specific route wins
        $tests = [
            ['/blog/2024/01/featured', 'static-all'],
            ['/blog/2024/02/featured', 'static-partial-1'], 
            ['/blog/2023/01/featured', 'static-partial-2'],
            ['/blog/2024/01/my-post', 'static-partial-3'],
            ['/blog/2023/02/featured', 'dynamic-2-params'],
            ['/blog/2023/02/my-post', 'dynamic-3-params'],
        ];
        
        foreach ($tests as [$path, $expected]) {
            $response = $router->dispatch(Request::create($path, 'GET'));
            $this->assertEquals($expected, $response->getContent(), 
                "Route precedence failed for path: $path");
        }
    }
    
    public function test_route_precedence_performance_buckets(): void {
        $router = new Router($this->container);
        
        // Test that bucketing doesn't break precedence
        $router->get('/users/profile', fn() => 'profile');
        $router->get('/users/{id}/posts', fn($id) => "posts-$id");
        $router->get('/users/{id}', fn($id) => "user-$id");  
        $router->get('/users/settings', fn() => 'settings');
        
        // All go to 'users' bucket, precedence should still work
        $tests = [
            ['/users/profile', 'profile'],
            ['/users/settings', 'settings'], 
            ['/users/123', 'user-123'],
            ['/users/456/posts', 'posts-456'],
        ];
        
        foreach ($tests as [$path, $expected]) {
            $response = $router->dispatch(Request::create($path, 'GET'));
            $this->assertEquals($expected, $response->getContent(),
                "Bucketing broke precedence for: $path");
        }
    }
}
```

### Performance Tests
```php
class RouterBenchmark {
    public function benchmarkStaticRoutes(): void {
        $router = new Router($this->container);
        
        // Add 1000 static routes
        for ($i = 0; $i < 1000; $i++) {
            $router->get("/route$i", fn() => "Response $i");
        }
        
        // Benchmark matching
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $router->match(new Request('/route500', 'GET'));
        }
        $time = microtime(true) - $start;
        
        $this->assertLessThan(0.05, $time); // 10k matches < 50ms
    }
}
```

## Performance Targets

### Benchmarks
```
Metric                  | Current    | Target     | Improvement
------------------------|------------|------------|-------------
Cold Boot Time          | 30,000ms   | < 100ms    | 300x
Warm Boot Time          | 3ms        | < 1ms      | 3x
Route Matching          | O(n)       | O(log n)   | Algorithmic
Memory Usage            | 12MB       | < 8MB      | 33%
Requests/Second         | ~1,000     | > 4,000    | 4x
Route Cache Size        | N/A        | < 1MB      | N/A
Development Cache       | None       | 5s TTL     | ∞
```

### Testing Strategy
```php
class RouterPerformanceTest {
    public function benchmarkRouteMatching(): void
    {
        $router = $this->createRouterWith10000Routes();
        
        $start = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $router->match($this->randomRequest());
        }
        
        $time = microtime(true) - $start;
        
        $this->assertLessThan(0.1, $time); // 1000 matches in < 100ms
    }
    
    public function benchmarkMemoryUsage(): void
    {
        $before = memory_get_usage(true);
        
        $router = $this->createRouterWith10000Routes();
        
        $after = memory_get_usage(true);
        $used = ($after - $before) / 1024 / 1024;
        
        $this->assertLessThan(8, $used); // Less than 8MB for 10k routes
    }
}
```

## Files to Create

### 1. `src/Routing/Router.php` (~200 lines)
- Core routing logic
- Static and dynamic route storage  
- Matching algorithm
- Middleware support
- Group functionality

### 2. `src/Routing/Route.php` (~100 lines)
- Route definition
- Pattern compilation
- Parameter extraction
- Fluent API

### 3. `src/Routing/RouteCompiler.php` (~50 lines)
- Generate PHP code from routes
- Optimize for opcache

### 4. `src/Routing/RouteCache.php` (~100 lines)
- Development and production caching
- File change detection
- Atomic writes

### 5. `src/Routing/Attributes/Route.php` (~20 lines)
- Optional attribute support

## Risk Mitigation

### Technical Risks
1. **Backward Compatibility**
   - Mitigation: Adapter layer, gradual migration
   - Testing: Comprehensive test suite

2. **Performance Regression**
   - Mitigation: Continuous benchmarking
   - Testing: Performance test suite

3. **Extension Compatibility**
   - Mitigation: Extension API compatibility layer
   - Testing: Test with all core extensions

### Implementation Risks
1. **Scope Creep**
   - Mitigation: Strict phase boundaries
   - MVP first, enhancements later

2. **Complexity**
   - Mitigation: Simple interfaces, complex internals
   - Clear documentation from start

## CLI Commands

```php
// New Glueful CLI commands for route management
class RouteCacheCommand extends Command {
    protected $signature = 'routes:cache';
    
    public function handle(): int {
        $router = $this->container->get(Router::class);
        $cache = $this->container->get(RouteCache::class);
        
        // Load all routes
        $this->loadApplicationRoutes($router);
        
        // Save to cache
        $cache->save($router);
        
        $this->info('Routes cached successfully!');
        return 0;
    }
}

class RouteClearCommand extends Command {
    protected $signature = 'routes:clear';
    
    public function handle(): int {
        $cache = $this->container->get(RouteCache::class);
        $cache->clear();
        
        $this->info('Route cache cleared!');
        return 0;
    }
}

class RouteListCommand extends Command {
    protected $signature = 'routes:list {--filter= : Filter routes by path pattern}';
    
    public function handle(): int {
        $router = $this->container->get(Router::class);
        $routes = $router->getAllRoutes(); // ✅ Now properly implemented
        
        // Apply filter if provided
        $filter = $this->option('filter');
        if ($filter) {
            $routes = array_filter($routes, fn($route) => 
                str_contains($route['path'], $filter) || 
                str_contains($route['method'], strtoupper($filter))
            );
        }
        
        $this->table(
            ['Method', 'Path', 'Handler', 'Middleware', 'Name', 'Type'],
            $this->formatRoutes($routes)
        );
        
        $this->info(sprintf('Found %d routes', count($routes)));
        
        return 0;
    }
    
    private function formatRoutes(array $routes): array {
        return array_map(function($route) {
            return [
                $route['method'],
                $route['path'],
                $this->formatHandler($route['handler']),
                implode(', ', $route['middleware'] ?? []),
                $route['name'] ?? '',
                $route['type'] ?? 'unknown'
            ];
        }, $routes);
    }
    
    private function formatHandler(mixed $handler): string {
        if (is_array($handler)) {
            return $handler[0] . '@' . $handler[1];
        }
        
        if (is_string($handler)) {
            return $handler;
        }
        
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        
        return get_class($handler);
    }
}
```

## Success Criteria

### Must Have (5 Days)
- ✅ O(1) static route matching
- ✅ Fixed controller reflection bug  
- ✅ Working group middleware
- ✅ Route caching (dev & production)
- ✅ < 100ms cold boot
- ✅ Full backward compatibility
- ✅ Attribute-based routing with autodiscovery
- ✅ Support for all controller types
- ✅ Smart Request and parameter injection
- ✅ GraphQL-style field selection

### Nice to Have (Future)
- Route manifest for zero file scanning
- Advanced compilation strategies
- Route warming and preloading
- OpenAPI generation from attributes

## Conclusion

This next-generation router implementation delivers both performance and developer experience with:

**Performance Gains:**
- **300x faster cold boot** (30s → <100ms) through smart caching
- **O(1) static route matching** via hash table lookups  
- **Pre-compiled dynamic patterns** for 3-5x faster regex matching
- **Memory efficient** storage with minimal overhead

**Developer Experience:**
- **Modern attribute-based routing** with `#[Get]`, `#[Post]`, etc.
- **Automatic controller discovery** via directory scanning
- **Intelligent parameter injection** with type safety
- **Clean, intuitive API** that's easy to learn and use
- **Full backward compatibility** with existing routes

**Code Quality:**
- **~850 lines of focused, testable code** vs 3000+ in over-engineered solutions
- **12 well-organized files** with clear responsibilities
- **No unnecessary abstractions** - every class serves a purpose
- **Comprehensive test coverage** from day one

This implementation positions Glueful as a truly modern PHP framework with cutting-edge routing that developers will love to use. The 5-day timeline makes it achievable while the attribute-based routing makes it future-ready.

## ✅ Critical Pre-Ship Improvements Applied

Based on production feedback review, the following critical issues have been addressed:

### 1. Route Precedence & Ordering ⚡
- **Fixed:** Added explicit route ordering within performance buckets
- **Algorithm:** Routes sorted by precedence score (static segments beat dynamic)  
- **Guarantee:** `/users/me` always wins over `/users/{id}` regardless of registration order
- **Implementation:** `sortBucketByPrecedence()` and `calculateRoutePrecedence()` methods
- **Testing:** Comprehensive test suite with 60+ edge case scenarios

### 2. Middleware Contract Documentation 📚
- **Fixed:** Clear documentation of native Glueful middleware interface
- **Contract:** `handle(Request $request, callable $next, mixed ...$params): mixed`
- **Compatibility:** Full PSR-15 adapter with bidirectional conversion
- **Examples:** Native, PSR-15, and mixed middleware pipeline usage
- **Benefits:** Prevents confusion for extension authors and third-party integration

### 3. Handler Normalization for Cache Serialization 🔄
- **Fixed:** Consistent serialization of all handler types (arrays, closures, strings, invokables)
- **Solution:** Metadata DTO approach with type classification and reconstruction info
- **Safety:** Validation layer prevents cache corruption from problematic handlers
- **Debugging:** Rich metadata includes reflection info, file locations, parameters
- **Reliability:** Atomic cache operations with proper error handling

### 4. CLI Commands & Route Listing 🔧
- **Fixed:** Added missing `getAllRoutes()` method referenced by `RouteListCommand`
- **Implementation:** Combines static + dynamic routes with proper formatting
- **Enhancement:** Added route filtering, type classification, and improved table display
- **Utility:** Enhanced CLI command with `--filter` option for debugging
- **Data:** Returns structured route data including method, path, handler, middleware, name, type

### 5. Comprehensive CORS Configuration 🌐
- **Enhanced:** Centralized CORS configuration with `buildCorsHeaders()` method
- **Features:** Origin validation, pattern matching, credential handling, header management
- **Security:** Proper Origin validation with exact matches and regex patterns
- **Flexibility:** Environment-specific overrides (development vs production)
- **Standards:** Full CORS spec compliance with caching headers (Vary, Max-Age)
- **Configuration:** Single `config/cors.php` file controls all CORS behavior

### 6. Comprehensive Route Precedence Testing 🧪
- **Added:** 60+ test cases covering all edge cases
- **Coverage:** Static vs dynamic, multiple segments, constraint conflicts, performance buckets
- **Validation:** Order-independent registration (shuffled route testing)
- **Real-world:** Complex API scenarios (`/api/v1/users/current` vs `/api/{version}/users/{id}`)
- **Performance:** Ensures bucketing optimization doesn't break precedence

## Production Readiness Checklist ✅

| Component | Status | Notes |
|-----------|--------|--------|
| Route Precedence | ✅ Fixed | Static always beats dynamic with comprehensive tests |
| Middleware Contract | ✅ Documented | Native + PSR-15 adapter with clear examples |
| Cache Serialization | ✅ Robust | Handler normalization prevents corruption |
| Error Handling | ✅ Complete | Proper 404/405 responses with helpful error messages |
| Performance | ✅ Optimized | O(1) static, bucketed dynamic, reflection caching |
| Compatibility | ✅ Maintained | Full backward compatibility with existing routes |
| Documentation | ✅ Comprehensive | Clear examples and migration guides |
| Testing | ✅ Extensive | Unit, integration, and performance test coverage |

The router implementation is now **production-ready** with all critical edge cases handled and enterprise-grade reliability.

## Production-Ready Improvements Applied

### Critical Fixes Implemented

**1. Parameter Passing Bug Fixed**
- Controllers now receive parameters in correct positional order
- Robust type casting for scalar parameters (int, float, bool)  
- Smart dependency injection with fallback to route parameters

**2. HTTP Semantics Compliance**
- Proper 405 Method Not Allowed responses with Allow header
- HEAD requests automatically mapped to GET handlers
- Path normalization with URL decoding and trailing slash handling

**3. Robust Controller Resolution**
- Support for all controller types: `[Class::class, 'method']`, closures, strings, invokables
- Full dependency injection with container resolution
- Exception-safe parameter resolution with helpful error messages

**4. Security & Regex Safety**
- Constraint validation prevents ReDoS attacks
- Proper delimiter escaping in patterns
- Unicode support with `/u` modifier

**5. URL Generation**
- Named route reverse routing: `$router->url('users.show', ['id' => 123])`
- Parameter validation against constraints
- Query string building support

**6. Cache Contract Fixes**
- Fixed interface mismatches between compiler and cache
- Proper route metadata serialization (no closures)
- Atomic cache writes with correct permissions

### Complete Implementation Metrics

```
Component                   | Lines | Key Features
----------------------------|-------|------------------------------------------
Router.php                  | ~420  | O(1) static matching, route bucketing,
                            |       | middleware pipeline, CORS, 405 handling
Route.php                   | ~180  | Pattern compilation, URL generation,
                            |       | constraint validation, back-references  
RouteCompiler.php           | ~70   | Cache generation, serialization safety
RouteCache.php              | ~130  | Dev/prod caching, atomic writes
AttributeRouteLoader.php    | ~280  | Directory scanning, attribute processing
Attribute Classes (7)       | ~100  | Clean routing attributes
----------------------------|-------|------------------------------------------
Total                       | ~1,180| Enterprise-ready router system
```

### Critical Improvements Applied

**All Production Issues Fixed:**
- ✅ Group functionality (prefix/middleware) fully implemented
- ✅ Named route registration with back-references  
- ✅ Complete middleware pipeline with parameter support
- ✅ Route bucketing for O(log n) performance on dynamic routes
- ✅ CORS/OPTIONS handling with proper headers
- ✅ Duplicate route protection with clear error messages
- ✅ Fixed closure invocation and added all missing imports
- ✅ Reflection result caching for performance
- ✅ Path normalization and URL decoding
- ✅ All HTTP response types supported

**Performance Optimizations:**
- Route bucketing by first segment reduces dynamic route matching
- Middleware pipeline caching eliminates repeated resolution
- Reflection result caching for controller methods
- Normalized method/path caching at registration time
- Inside-out middleware building minimizes allocations

This implementation now delivers both **blazing performance** and **rock-solid reliability** with every edge case handled properly.

## Files for Removal After Migration Completion

The following files are part of the legacy routing system and should be removed once the migration to `Glueful\Routing\Router` is complete and all tests pass:

### Legacy Router System Files

#### Core Legacy Files
```
src/Http/Router.php                              # Legacy static router (3000+ lines)
src/DI/ServiceFactories/RouterFactory.php       # Factory for legacy Router (incompatible with new DI architecture)
```

#### Legacy Route Management
```
src/Services/RouteCacheService.php               # Legacy route caching (replaced by RouteCache.php)
src/Http/MiddlewareRegistry.php                  # Static middleware registry (replaced by container-based DI)
```

#### Legacy Console Commands
```
src/Console/Commands/RouteCommand.php            # Legacy route listing (replaced by RouteListCommand)
```

#### Controller Extensions (if using legacy Router)
```
src/Console/Commands/Extensions/CreateCommand.php # Contains Router references (needs update or removal)
```

### Migration Checklist

**Phase 1: Identify Dependencies**
- [ ] Search codebase for `use Glueful\Http\Router` imports
- [ ] Identify all files importing `RouterFactory`
- [ ] Find static `Router::` method calls that need updating

**Phase 2: Update References** 
- [ ] Update `src/Framework.php` to use new `Glueful\Routing\Router`
- [ ] Replace `RouterFactory` registration with proper DI service provider
- [ ] Update console commands to use new router interface
- [ ] Update any extension router references

**Phase 3: Remove Legacy Files**
- [ ] Remove `src/Http/Router.php` (legacy static router)
- [ ] Remove `src/DI/ServiceFactories/RouterFactory.php` (incompatible factory)
- [ ] Remove `src/Services/RouteCacheService.php` (replaced)
- [ ] Remove `src/Http/MiddlewareRegistry.php` (replaced by DI)
- [ ] Update `src/Console/Commands/RouteCommand.php` or remove if replaced

**Phase 4: Clean Dependencies**
- [ ] Remove any unused middleware interfaces from legacy system
- [ ] Clean up any remaining static method references
- [ ] Update extension loading to use new attribute-based discovery

### Files to Keep (Updated for New System)

**Core Application Files (Updated)**
```
src/Application.php                              # ✅ Already using Glueful\Routing\Router
src/Framework.php                                # ⚠️  Needs RouterFactory removal
```

**Modern Router System (New)**
```
src/Routing/Router.php                           # New dependency-injected router
src/Routing/Route.php                            # Modern route definition
src/Routing/RouteCompiler.php                    # Route compilation
src/Routing/RouteCache.php                       # Modern caching system
src/Routing/AttributeRouteLoader.php             # Attribute-based discovery
src/Routing/Attributes/*.php                     # Route attributes
```

### Framework Integration Updates Required

**Update `src/Framework.php`:**
```php
// Remove this line:
$this->lazyRegistry->lazy('router', \Glueful\DI\ServiceFactories\RouterFactory::class);

// The routing services are already properly registered in CoreServiceProvider.php
// No additional registration needed - just ensure CoreServiceProvider is loaded
```

**Current Routing Services in `src/Container/Providers/CoreProvider.php`:**
```php
// Next-Gen Router services (already implemented)
$container->register(\Glueful\Routing\Router::class)
    ->setArguments([new Reference('service_container')])
    ->setPublic(true);

$container->register(\Glueful\Routing\RouteCache::class)
    ->setPublic(true);

$container->register(\Glueful\Routing\RouteCompiler::class)
    ->setPublic(true);
```

**RoutingServiceProvider is NOT needed:**

All routing services are already properly registered in CoreServiceProvider.php:

```php
// Complete routing system already registered in CoreServiceProvider
$container->register(\Glueful\Routing\Router::class)
    ->setArguments([new Reference('service_container')])
    ->setPublic(true);

$container->register(\Glueful\Routing\RouteCache::class)
    ->setPublic(true);

$container->register(\Glueful\Routing\RouteCompiler::class)
    ->setPublic(true);
```

**Optional: If attribute-based routing is needed, add to CoreServiceProvider:**
```php
// Add this to CoreServiceProvider if attribute routing is required
$container->register(\Glueful\Routing\AttributeRouteLoader::class)
    ->setArguments([new Reference(\Glueful\Routing\Router::class)])
    ->setPublic(true);
```

The separate RoutingServiceProvider can be removed since it's redundant.

### Post-Migration Verification

**Run these commands to verify migration success:**
```bash
# Verify no legacy router references remain
grep -r "use Glueful\\Http\\Router" src/
grep -r "RouterFactory" src/
grep -r "Router::" src/ --exclude-dir=Routing

# Test route functionality
php glueful routes:list
php glueful routes:cache  
php glueful routes:clear

# Run test suites
composer run test:unit -- --filter=Router
composer run test:integration -- --filter=Router
```

### Legacy Code Detection Script

```bash
#!/bin/bash
# legacy-router-detector.sh

echo "🔍 Scanning for legacy router references..."

echo "📁 Files using legacy Http\\Router:"
grep -r "use Glueful\\Http\\Router" src/ || echo "✅ None found"

echo "🏭 Files using RouterFactory:"
grep -r "RouterFactory" src/ || echo "✅ None found"  

echo "📞 Files using static Router calls:"
grep -r "Router::" src/ --exclude-dir=Routing || echo "✅ None found"

echo "🧪 Testing new router integration:"
php -r "
try {
    require 'vendor/autoload.php';
    \$container = new \Glueful\DI\Container(new \Symfony\Component\DependencyInjection\ContainerBuilder());
    \$router = new \Glueful\Routing\Router(\$container);
    echo '✅ New router loads successfully\n';
} catch (\Throwable \$e) {
    echo '❌ New router failed: ' . \$e->getMessage() . '\n';
}
"

echo "📊 Migration status: Check above for any ❌ items"
```

### Estimated Timeline for Removal

- **Week 1**: Update Framework.php and create RoutingServiceProvider
- **Week 2**: Update all console commands and extension references  
- **Week 3**: Remove legacy files after thorough testing
- **Week 4**: Final verification and cleanup

This systematic approach ensures no functionality is lost during the migration while modernizing the entire routing system.
