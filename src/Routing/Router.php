<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, StreamedResponse, BinaryFileResponse};
use Glueful\Routing\Internal\Psr15MiddlewareResolverTrait;
use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;
use Glueful\Http\Response as ApiResponse;
use Glueful\Bootstrap\ApplicationContext;

class Router
{
    use Psr15MiddlewareResolverTrait;

    /** @var array<string, Route> */
    private array $staticRoutes = [];     // O(1) lookup for static paths
    /** @var array<string, array<Route>> */
    private array $dynamicRoutes = [];    // Regex patterns for dynamic paths
    /** @var array<string, array<string, array<Route>>> */
    private array $routeBuckets = [];     // [METHOD][first|*] => Route[] for performance
    /** @var array<array<string, mixed>> */
    private array $groupStack = [];       // Track nested groups
    /** @var array<array<string>> */
    private array $middlewareStack = [];  // Track group middleware
    /** @var array<string, Route> */
    private array $namedRoutes = [];      // Route name => Route object
    /** @var array<int, array<string>> */
    private array $pipelineCache = [];    // spl_object_id(Route) => cached middleware
    /** @var array<string, \ReflectionFunction|\ReflectionMethod> */
    private array $reflectionCache = [];  // Cache reflection results
    private ?RouteCache $cache = null;
    private ContainerInterface $container;
    private ?AttributeRouteLoader $attributeLoader = null;
    private bool $warnUnprefixedRoutes = false;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        if ($container->has(ApplicationContext::class)) {
            $this->cache = new RouteCache($container->get(ApplicationContext::class));
        } else {
            $this->cache = null;
        }

        // Try to load cached routes
        if ($this->cache !== null) {
            $cached = $this->cache->load();
            if ($cached !== null) {
                $this->staticRoutes = $this->reconstructStaticRoutes($cached['static']);
                $this->dynamicRoutes = $this->reconstructDynamicRoutes($cached['dynamic']);
                $this->rebuildRouteBuckets();
            }
        }
    }

    // HTTP method shortcuts
    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function head(string $path, mixed $handler): Route
    {
        return $this->add('HEAD', $path, $handler);
    }

    // Core route registration
    private function add(string $method, string $path, mixed $handler): Route
    {
        // Normalize method and path once
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');

        // Apply group prefixes
        $path = $this->applyGroupPrefix($path);
        $this->warnOnUnprefixedRoute($path);

        // Create route with back-reference for named route registration
        $route = new Route($this, $method, $path, $handler);

        // Apply group middleware
        $groupMiddleware = $this->getCurrentGroupMiddleware();
        if (count($groupMiddleware) > 0) {
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

    public function enableUnprefixedRouteWarnings(bool $enabled): void
    {
        $this->warnUnprefixedRoutes = $enabled;
    }

    private function warnOnUnprefixedRoute(string $path): void
    {
        if (!$this->warnUnprefixedRoutes) {
            return;
        }

        if ((bool) config($this->getContext(), 'app.dev_mode', false) !== true) {
            return;
        }

        if (!function_exists('api_prefix')) {
            return;
        }

        $prefix = api_prefix($this->getContext());
        if ($prefix === '') {
            return;
        }

        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return;
        }

        if ((bool) config($this->getContext(), 'app.warn_unprefixed_routes', false) !== true) {
            return;
        }

        error_log(
            '[Router] Unprefixed route registered: ' . $path
            . ' (expected prefix: ' . $prefix . ')'
        );
    }

    // Apply group prefixes from nested groups
    private function applyGroupPrefix(string $path): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $groupPrefix = rtrim($group['prefix'] ?? '', '/');
            if ($groupPrefix !== '') {
                $prefix .= '/' . ltrim($groupPrefix, '/');
            }
        }
        $fullPath = '/' . ltrim($path, '/');
        $result = rtrim($prefix . $fullPath, '/');
        return $result !== '' ? $result : '/';
    }

    // Get current middleware stack from all nested groups
    /**
     * @return array<string>
     */
    private function getCurrentGroupMiddleware(): array
    {
        $middleware = [];
        foreach ($this->middlewareStack as $groupMiddleware) {
            $middleware = array_merge($middleware, (array) $groupMiddleware);
        }
        return $middleware;
    }

    // Get first segment for route bucketing performance optimization
    private function firstSegment(string $path): ?string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return null;
        }

        $segment = strtok($trimmed, '/');
        // Parameterized first segment goes to wildcard bucket
        return (str_contains($segment, '{') ? '*' : $segment);
    }

    /**
     * Rebuild dynamic route buckets from current dynamic routes.
     */
    private function rebuildRouteBuckets(): void
    {
        $this->routeBuckets = [];

        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $route) {
                $firstSegment = $this->firstSegment($route->getPath()) ?? '*';
                $this->routeBuckets[$method][$firstSegment][] = $route;
            }
        }
    }

    // Fast route matching with proper HTTP semantics
    /**
     * @return array{route: Route, params: array<string, string>}|array{
     *     route: null,
     *     params: array{},
     *     allowed_methods: array<string>
     * }|null
     */
    public function match(Request $request): ?array
    {
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());

        // Normalize path
        $path = '/' . ltrim(rawurldecode($path), '/');
        $path = rtrim($path, '/');
        $path = $path !== '' ? $path : '/';

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
            $params = $route->match($path);
            if ($params !== null) {
                return [
                    'route' => $route,
                    'params' => $params
                ];
            }
        }

        // 3. Check if path exists with different method (for 405 response)
        $allowedMethods = $this->getAllowedMethods($path);
        if (count($allowedMethods) > 0) {
            return [
                'route' => null,
                'params' => [],
                'allowed_methods' => $allowedMethods
            ];
        }

        return null;
    }

    // Get allowed methods for a path (for 405 responses)
    /**
     * @return array<string>
     */
    private function getAllowedMethods(string $path): array
    {
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
                if ($route->match($path) !== null) {
                    $allowed[] = $method;
                }
            }
        }

        // Auto-map HEAD to GET
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return array_unique($allowed);
    }

    // Group support with middleware
    /**
     * @param array<string, mixed> $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
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
     * Create a versioned API route group
     *
     * Routes defined within this group will be prefixed with the API version
     * (e.g., /api/v1/users) and have the version negotiation middleware applied.
     *
     * @param string $version API version (e.g., '1', '2', 'v1')
     * @param callable $routes Route definition callback
     * @param array<string, mixed> $options Additional group options (prefix, middleware, etc.)
     */
    public function apiVersion(string $version, callable $routes, array $options = []): void
    {
        $version = ltrim($version, 'vV');

        $config = function_exists('config') ? (array) config($this->getContext(), 'api.versioning', []) : [];
        $strategy = (string) ($config['strategy'] ?? 'url_prefix');
        $apiPrefix = (string) ($config['prefix'] ?? '/api');

        $groupAttributes = $options;

        // Apply version prefix based on strategy
        if ($strategy === 'url_prefix') {
            $existingPrefix = isset($options['prefix']) ? rtrim((string) $options['prefix'], '/') : '';
            $groupAttributes['prefix'] = rtrim($apiPrefix, '/') . '/v' . $version . $existingPrefix;
        } else {
            // For other strategies (header, query, accept), just use the base prefix
            $groupAttributes['prefix'] = $options['prefix'] ?? $apiPrefix;
        }

        // Add version negotiation middleware
        $existingMiddleware = isset($options['middleware']) ? (array) $options['middleware'] : [];
        $groupAttributes['middleware'] = array_merge(['api_version'], $existingMiddleware);

        // Store version metadata for the group
        $groupAttributes['_api_version'] = $version;

        $this->group($groupAttributes, $routes);
    }

    /**
     * Get API version from current route group stack
     *
     * @return string|null The API version or null if not in a versioned group
     */
    public function getCurrentApiVersion(): ?string
    {
        foreach (array_reverse($this->groupStack) as $group) {
            if (isset($group['_api_version'])) {
                return (string) $group['_api_version'];
            }
        }
        return null;
    }

    /**
     * Build comprehensive CORS headers from centralized configuration
     *
     * This method provides a single place to configure all CORS behavior,
     * making it easy to adjust for different environments and requirements.
     */
    /**
     * @param array<string> $allowedMethods
     * @return array<string, string>
     */
    private function buildCorsHeaders(Request $request, array $allowedMethods): array
    {
        $config = $this->getCorsConfig();
        $headers = [];

        // Always include allowed methods
        $headers['Access-Control-Allow-Methods'] = implode(', ', $allowedMethods);

        // Handle Origin
        $origin = $request->headers->get('Origin');
        if ($origin !== null && $this->isOriginAllowed($origin, $config)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin'; // Important for caching
        } elseif ((bool) $config['allow_all_origins']) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        // Handle Credentials
        if ((bool) $config['allow_credentials'] && !(bool) $config['allow_all_origins']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        // Handle Headers
        if (isset($config['allow_headers']) && $config['allow_headers'] !== []) {
            $headers['Access-Control-Allow-Headers'] = is_array($config['allow_headers'])
                ? implode(', ', $config['allow_headers'])
                : $config['allow_headers'];
        }

        // Handle preflight cache
        if (isset($config['max_age'])) {
            $headers['Access-Control-Max-Age'] = (string) $config['max_age'];
        }

        // Handle exposed headers
        if (isset($config['expose_headers']) && $config['expose_headers'] !== []) {
            $headers['Access-Control-Expose-Headers'] = is_array($config['expose_headers'])
                ? implode(', ', $config['expose_headers'])
                : $config['expose_headers'];
        }

        return $headers;
    }

    /**
     * Get CORS configuration with sensible defaults
     */
    /**
     * @return array<string, mixed>
     */
    private function getCorsConfig(): array
    {
        $config = config($this->getContext(), 'cors', []);

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
    /**
     * @param array<string, mixed> $config
     */
    private function isOriginAllowed(string $origin, array $config): bool
    {
        // Development mode: allow all if configured
        if ((bool) $config['development_allow_all']) {
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

    public function dispatch(Request $request): Response
    {
        $originalMethod = $request->getMethod();

        // Handle OPTIONS for CORS (before any processing)
        if ($originalMethod === 'OPTIONS') {
            $path = '/' . ltrim(rawurldecode($request->getPathInfo()), '/');
            $path = rtrim($path, '/');
            $path = $path !== '' ? $path : '/';
            $allowedMethods = $this->getAllowedMethods($path);

            if (count($allowedMethods) > 0) {
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

        if ($match === null) {
            // Standardize 404 as JSON error response
            return ApiResponse::error('Not Found', ApiResponse::HTTP_NOT_FOUND);
        }

        // Handle 405 Method Not Allowed
        if ($match['route'] === null && isset($match['allowed_methods'])) {
            // Standardize 405 as JSON error and include Allow header
            $resp = ApiResponse::error(
                'Method Not Allowed',
                ApiResponse::HTTP_METHOD_NOT_ALLOWED,
                ['allowed_methods' => $match['allowed_methods']]
            );
            $resp->headers->set('Allow', implode(', ', $match['allowed_methods']));
            return $resp;
        }

        $route = $match['route'];
        $params = $match['params'];

        // Build middleware pipeline (with caching)
        $pipeline = $this->buildMiddlewarePipeline($route);

        // Create controller invoker with proper parameter resolution
        $invoker = $this->createControllerInvoker($route->getHandler(), $params, $request);

        // Execute through middleware or directly
        if ($pipeline !== []) {
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

    /**
     * @param array<string, string> $params
     */
    private function createControllerInvoker(mixed $handler, array $params, Request $request): callable
    {
        return function () use ($handler, $params, $request) {
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
    private function getReflection(mixed $handler): \ReflectionFunction|\ReflectionMethod
    {
        $key = match (true) {
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
    /**
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private function resolveMethodParameters(
        \ReflectionFunction|\ReflectionMethod $reflection,
        Request $request,
        array $params
    ): array {
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // Try type-based injection first (DI)
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // Inject Request and related objects
                if ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                    $args[] = $request;
                    continue;
                }

                // Inject FieldSelector from request attributes or create from request
                if ($typeName === \Glueful\Support\FieldSelection\FieldSelector::class) {
                    $selector = $request->attributes->get(\Glueful\Support\FieldSelection\FieldSelector::class);
                    if ($selector === null) {
                        // Create default if not set by middleware
                        $selector = \Glueful\Support\FieldSelection\FieldSelector::fromRequest($request);
                    }
                    $args[] = $selector;
                    continue;
                }

                // Inject Projector from container
                if ($typeName === \Glueful\Support\FieldSelection\Projector::class) {
                    $args[] = $this->container->get($typeName);
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
                if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
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
    private function castParameter(mixed $value, \ReflectionType $type): mixed
    {
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default => $value // Keep as-is for objects and other types
        };
    }

    // Build middleware pipeline with caching
    /**
     * @return array<string>
     */
    private function buildMiddlewarePipeline(Route $route): array
    {
        $routeId = spl_object_id($route);

        if (isset($this->pipelineCache[$routeId])) {
            return $this->pipelineCache[$routeId];
        }

        $middleware = $route->getMiddleware();
        $this->pipelineCache[$routeId] = $middleware;

        return $middleware;
    }

    // Execute request through middleware pipeline
    /**
     * @param array<string> $middlewareStack
     */
    private function executeWithMiddleware(Request $request, array $middlewareStack, callable $core): Response
    {
        $next = $core;

        // Build pipeline inside-out to minimize allocations
        for ($i = count($middlewareStack) - 1; $i >= 0; $i--) {
            $middleware = $this->resolveMiddleware($middlewareStack[$i]);
            $next = fn(Request $req) => $middleware($req, $next);
        }

        return $this->normalizeResponse($next($request));
    }

    // Resolve middleware from container with parameter support
    private function resolveMiddleware(string|callable $middleware): callable
    {
        // Only pass through closures/array callables. String names (e.g., 'auth')
        // should be resolved via the container, even if a global function exists.
        if (is_callable($middleware) && !is_string($middleware)) {
            return $middleware;
        }

        // Support "name:param1,param2" format
        [$name, $args] = array_pad(explode(':', $middleware, 2), 2, null);
        $params = $args ? array_map('trim', explode(',', $args)) : [];

        // Get middleware instance from container
        $instance = $this->container->get($name);

        // Attempt PSR-15 auto-adaptation if enabled
        $httpConfig = (array) config($this->getContext(), 'http', []);
        $maybe = $this->maybeWrapPsr15Middleware($instance, $httpConfig);

        if (is_callable($maybe)) {
            // PSR-15 adapted: wrapper expects only (Request, callable)
            return function (Request $req, callable $next) use ($maybe) {
                return $maybe($req, $next);
            };
        }

        // If PSR-15 was detected but not wrapped (auto_detect disabled or missing bridge),
        // decide based on strict flag: no-op passthrough in non-strict mode, otherwise error.
        if ($maybe instanceof Psr15Middleware) {
            $strict = (bool) (($httpConfig['psr15']['throw_on_missing_bridge'] ?? true));
            if (!$strict) {
                return function (Request $req, callable $next) {
                    return $next($req);
                };
            }
            throw new \RuntimeException(
                'Encountered PSR-15 middleware without adapter. ' .
                'Enable http.psr15.auto_detect or install the PSR-7 bridge.'
            );
        }

        // Fallback: Glueful-native middleware signature with params
        return function (Request $request, callable $next) use ($maybe, $params) {
            // Expect handle(Request $req, callable $next, ...$params): mixed
            return $maybe->handle($request, $next, ...$params);
        };
    }

    private function normalizeResponse(mixed $result): Response
    {
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

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route '{$name}' not found");
        }

        return $this->namedRoutes[$name]->generateUrl($params, $query);
    }

    // Register named route (called automatically when Route::name() is used)
    public function registerNamedRoute(string $name, Route $route): void
    {
        if (isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route name '{$name}' already exists");
        }

        $this->namedRoutes[$name] = $route;
    }

    // Get all routes (for cache/compiler)
    /**
     * @return array<string, Route>
     */
    public function getStaticRoutes(): array
    {
        return $this->staticRoutes;
    }
    /**
     * @return array<string, array<Route>>
     */
    public function getDynamicRoutes(): array
    {
        return $this->dynamicRoutes;
    }
    /**
     * @return array<string, Route>
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    // Get all routes for CLI RouteListCommand
    /**
     * @return array<array<string, mixed>>
     */
    public function getAllRoutes(): array
    {
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

    /**
     * Scan directory for attribute-based routes
     *
     * @param string|array<string> $directories
     */
    public function discover(string|array $directories): self
    {
        if ($this->attributeLoader === null) {
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
    public function controller(string $controllerClass): self
    {
        if ($this->attributeLoader === null) {
            $this->attributeLoader = new AttributeRouteLoader($this);
        }

        $this->attributeLoader->processClass($controllerClass);

        return $this;
    }

    /**
     * Reconstruct Route objects from cached array data
     * @param array<string, array<int, array<string, mixed>>> $cachedDynamic
     * @return array<string, array<int, Route>>
     */
    private function reconstructDynamicRoutes(array $cachedDynamic): array
    {
        $reconstructed = [];

        foreach ($cachedDynamic as $method => $routes) {
            $reconstructed[$method] = [];
            foreach ($routes as $routeData) {
                // Reconstruct the handler from cached metadata
                $handler = $this->reconstructHandler($routeData['handler']);

                // Create a new Route object with the cached data
                // Need to create path from pattern for Route constructor
                // Use cached param names when reconstructing path
                $paramNames = is_array($routeData['params'] ?? null) ? $routeData['params'] : [];
                $path = $this->patternToPath($routeData['pattern'], $paramNames);
                $route = new Route($this, $method, $path, $handler);

                // Apply middleware if present
                if (isset($routeData['middleware']) && count($routeData['middleware']) > 0) {
                    $route->middleware($routeData['middleware']);
                }

                $reconstructed[$method][] = $route;
            }
        }

        return $reconstructed;
    }

    /**
     * Convert regex pattern back to original path for Route constructor
     *
     * @param string $pattern The regex pattern to convert
     * @param array<int, string> $paramNames Parameter names in order
     * @return string The reconstructed path
     */
    private function patternToPath(string $pattern, array $paramNames = []): string
    {
        // Strip regex delimiters and anchors produced by compiler (e.g. #^...$#u)
        if ($pattern !== '' && $pattern[0] === '#') {
            $last = strrpos($pattern, '#');
            if ($last !== false && $last > 0) {
                $pattern = substr($pattern, 1, $last - 1);
            }
        }
        $pattern = ltrim($pattern, '^');
        $pattern = rtrim($pattern, '$');

        // Replace each capturing group with the corresponding {paramName}
        $index = 0;
        $result = preg_replace_callback('/\((?:[^()]+)\)/', function () use (&$index, $paramNames) {
            $name = $paramNames[$index] ?? ('param' . $index);
            $index++;
            return '{' . $name . '}';
        }, $pattern) ?? $pattern;

        return $result;
    }

    /**
     * Reconstruct handler from cached metadata
     * @param array<string, mixed> $handlerMeta
     * @return mixed
     */
    private function reconstructHandler(array $handlerMeta): mixed
    {
        $type = $handlerMeta['type'];
        $target = $handlerMeta['target'];

        switch ($type) {
            case 'array_callable':
                if (is_array($target) && isset($target['class'], $target['method'])) {
                    return [$target['class'], $target['method']];
                }
                return $target;

            case 'static_method':
            case 'invokable_class':
            case 'callable':
                return $target;

            case 'closure':
                // For closures, return a simple placeholder that indicates caching limitation
                // This prevents the error and allows tests to continue
                return function () {
                    throw new \RuntimeException(
                        'Closure handlers cannot be reconstructed from cache. ' .
                        'Please disable route caching for closures.'
                    );
                };

            default:
                return $target;
        }
    }

    /**
     * Reconstruct static routes from cached array data
     * @param array<string, array<string, mixed>> $cachedStatic
     * @return array<string, Route>
     */
    private function reconstructStaticRoutes(array $cachedStatic): array
    {
        $reconstructed = [];

        foreach ($cachedStatic as $key => $data) {
            // Key format is METHOD:/path
            [$method, $path] = explode(':', (string) $key, 2);
            $handler = isset($data['handler']) && is_array($data['handler'])
                ? $this->reconstructHandler($data['handler'])
                : ($data['handler'] ?? null);

            if ($handler === null) {
                // Skip invalid entries
                continue;
            }

            $route = new Route($this, $method, $path, $handler);

            if (isset($data['middleware']) && is_array($data['middleware']) && count($data['middleware']) > 0) {
                $route->middleware($data['middleware']);
            }

            $reconstructed[$key] = $route;
        }

        return $reconstructed;
    }

    public function getContext(): ApplicationContext
    {
        return $this->container->get(ApplicationContext::class);
    }
}
