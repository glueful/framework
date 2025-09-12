# Routing Cookbook

## Table of Contents

1. [Introduction](#introduction)
2. [Basic Routing](#basic-routing)
3. [Route Parameters](#route-parameters)
4. [Route Groups](#route-groups)
5. [Middleware](#middleware)
6. [Attribute-Based Routing](#attribute-based-routing)
7. [Field Selection](#field-selection)
8. [Advanced Features](#advanced-features)
9. [Performance Optimization](#performance-optimization)
10. [Best Practices](#best-practices)

## Introduction

The Glueful Framework features a high-performance router designed for enterprise applications with O(1) static route lookup, intelligent route bucketing for dynamic routes, comprehensive middleware pipeline, and advanced features like GraphQL-style field selection.

### Key Features

- **O(1) Static Route Performance**: Hash table lookup for static routes
- **Intelligent Route Bucketing**: Dynamic routes grouped by first path segment
- **Route Caching**: Compiled route cache with opcache integration
- **Middleware Pipeline**: Enhanced middleware system with runtime parameters
- **Attribute-Based Routing**: PHP 8 attributes for declarative route definitions
- **Field Selection**: GraphQL-style field selection for REST APIs
- **CORS Support**: Built-in configurable CORS handling

## Basic Routing

### Defining Routes

Routes are defined in the `routes/` directory and loaded automatically by the framework.

```php
use Glueful\Routing\Router;
use Glueful\Http\Response;

// Basic GET route
$router->get('/users', function() {
    return new Response(['users' => []]);
});

// POST route with controller
$router->post('/users', [UserController::class, 'store']);

// PUT route
$router->put('/users/{id}', [UserController::class, 'update']);

// DELETE route
$router->delete('/users/{id}', [UserController::class, 'destroy']);

// HEAD route (automatic for GET routes)
$router->head('/users', [UserController::class, 'check']);
```

### Response Types

The router supports various response types:

```php
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// JSON response (default)
$router->get('/api/data', fn() => new Response(['data' => 'value']));

// Explicit JSON response
$router->get('/api/json', fn() => new JsonResponse(['json' => true]));

// Streamed response
$router->get('/stream', fn() => new StreamedResponse(function() {
    echo "Streaming data...";
}));

// File download
$router->get('/download/{file}', fn($file) => new BinaryFileResponse("/path/to/{$file}"));
```

## Route Parameters

### Basic Parameters

Route parameters are defined using curly braces:

```php
// Single parameter
$router->get('/users/{id}', function(int $id) {
    return new Response(['user_id' => $id]);
});

// Multiple parameters
$router->get('/posts/{post}/comments/{comment}', function(int $post, int $comment) {
    return new Response([
        'post_id' => $post,
        'comment_id' => $comment
    ]);
});

// Optional parameters (with default values in handler)
$router->get('/search/{query}', function(string $query = 'all') {
    return new Response(['searching' => $query]);
});
```

### Parameter Constraints

Apply regex constraints to route parameters:

```php
// Numeric constraint
$router->get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '\d+');

// Multiple constraints
$router->get('/posts/{year}/{month}/{slug}', [PostController::class, 'show'])
    ->where([
        'year' => '\d{4}',
        'month' => '\d{2}',
        'slug' => '[a-z0-9-]+'
    ]);

// UUID constraint
$router->get('/api/resources/{uuid}', [ResourceController::class, 'show'])
    ->where('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
```

### Dependency Injection in Route Handlers

The router automatically resolves dependencies from the container:

```php
use Psr\Log\LoggerInterface;
use App\Services\UserService;

$router->get('/users/{id}', function(
    int $id,
    UserService $service,
    LoggerInterface $logger
) {
    $logger->info("Fetching user", ['id' => $id]);
    $user = $service->find($id);
    return new Response(['user' => $user]);
});
```

## Route Groups

Group routes to share common attributes like prefixes and middleware:

### Basic Groups

```php
// API version grouping
$router->group(['prefix' => '/api/v1'], function(Router $router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/posts', [PostController::class, 'index']);
});

// Result: /api/v1/users and /api/v1/posts
```

### Nested Groups

```php
$router->group(['prefix' => '/api'], function(Router $router) {
    
    // Version 1
    $router->group(['prefix' => '/v1'], function(Router $router) {
        $router->get('/users', [V1\UserController::class, 'index']);
    });
    
    // Version 2 with middleware
    $router->group(['prefix' => '/v2', 'middleware' => ['auth']], function(Router $router) {
        $router->get('/users', [V2\UserController::class, 'index']);
    });
});
```

### Group Middleware

```php
// Apply middleware to all routes in group
$router->group(['middleware' => ['auth', 'rate_limit:60,60']], function(Router $router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
});

// Admin routes with multiple middleware
$router->group([
    'prefix' => '/admin',
    'middleware' => ['auth', 'admin', 'log_activity']
], function(Router $router) {
    $router->get('/users', [AdminController::class, 'users']);
    $router->get('/settings', [AdminController::class, 'settings']);
});
```

## Middleware

### Implementing Middleware

All middleware must implement the `RouteMiddleware` interface:

```php
use Glueful\Routing\Middleware\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Pre-processing
        $request->attributes->set('custom_data', 'value');
        
        // Call next middleware or handler
        $response = $next($request);
        
        // Post-processing
        $response->headers->set('X-Custom-Header', 'Value');
        
        return $response;
    }
}
```

### Middleware with Parameters

```php
class RateLimitMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, ...$params): Response
    {
        $maxAttempts = (int) ($params[0] ?? 60);
        $windowSeconds = (int) ($params[1] ?? 60);
        
        // Rate limiting logic
        if ($this->tooManyAttempts($request, $maxAttempts, $windowSeconds)) {
            return new Response(['error' => 'Too many requests'], 429);
        }
        
        return $next($request);
    }
}

// Usage
$router->get('/api/resource', $handler)
    ->middleware('rate_limit:100,60'); // 100 requests per 60 seconds
```

### Global vs Route Middleware

```php
// Register middleware in service provider
$container->set('auth', AuthMiddleware::class);
$container->set('cors', CorsMiddleware::class);
$container->set('rate_limit', RateLimitMiddleware::class);

// Apply globally to all routes
$router->group(['middleware' => ['cors']], function($router) {
    // All application routes
});

// Apply to specific routes
$router->get('/public', $handler); // No middleware
$router->get('/private', $handler)->middleware(['auth']); // Auth required
```

## Attribute-Based Routing

Use PHP 8 attributes for cleaner controller-based routing:

### Controller Attributes

```php
use Glueful\Routing\Attributes\{Controller, Get, Post, Put, Delete};
use Glueful\Routing\Attributes\Fields;
use Glueful\Http\Response;

#[Controller(prefix: '/api/users', middleware: ['auth'])]
class UserController
{
    #[Get('/', name: 'users.index')]
    public function index(): Response
    {
        return new Response(['users' => []]);
    }
    
    #[Get('/{id}', where: ['id' => '\d+'], name: 'users.show')]
    #[Fields(allowed: ['id', 'name', 'email', 'posts', 'posts.comments'])]
    public function show(int $id): Response
    {
        return new Response(['user' => ['id' => $id]]);
    }
    
    #[Post('/', middleware: ['validate:user'])]
    public function store(Request $request): Response
    {
        // Create user
        return new Response(['created' => true], 201);
    }
    
    #[Put('/{id}', where: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        // Update user
        return new Response(['updated' => true]);
    }
    
    #[Delete('/{id}', where: ['id' => '\d+'])]
    public function destroy(int $id): Response
    {
        // Delete user
        return new Response(null, 204);
    }
}
```

### Loading Attribute Routes

```php
// In bootstrap or service provider
$loader = new AttributeRouteLoader($container);
$loader->load([
    'App\\Controllers\\' => '/path/to/controllers',
]);
```

## Field Selection

Enable GraphQL-style field selection for REST APIs to prevent over-fetching and N+1 queries:

### Basic Field Selection

```php
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Routing\Attributes\{Get, Fields};

class UserController
{
    #[Get('/users/{id}')]
    #[Fields(allowed: ['id', 'name', 'email', 'posts', 'posts.comments'], strict: true)]
    public function show(int $id, FieldSelector $selector): array
    {
        $user = $this->userRepo->findAsArray($id);
        
        // Conditional loading based on requested fields
        if ($selector->requested('posts')) {
            $user['posts'] = $this->postRepo->findByUser($id);
            
            if ($selector->requested('posts.comments')) {
                // Batch load comments to prevent N+1
                $postIds = array_column($user['posts'], 'id');
                $comments = $this->commentRepo->findByPosts($postIds);
                // Attach comments to posts...
            }
        }
        
        return $user; // Middleware applies projection automatically
    }
}
```

### Request Formats

```bash
# REST-style syntax
GET /users/123?fields=id,name,email&expand=posts.title,posts.comments.text

# GraphQL-style syntax
GET /users/123?fields=user(id,name,posts(title,comments(text)))

# Wildcard selection
GET /users/123?fields=*&expand=posts.comments
```

### Field Selection Middleware

The `FieldSelectionMiddleware` automatically handles field projection:

```php
// Enable for specific routes
$router->group(['middleware' => ['field_selection']], function($router) {
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->get('/posts', [PostController::class, 'index']);
});
```

### Preventing N+1 Queries with Expanders

```php
use Glueful\Support\FieldSelection\Projector;

// Register expanders for batch loading
$projector = new Projector();

$projector->register('posts', function($context, $node, $data) {
    $userIds = array_column($data, 'id');
    return $this->postRepo->findByUsers($userIds);
});

$projector->register('comments', function($context, $node, $data) {
    $postIds = array_column($data, 'id');
    return $this->commentRepo->findByPosts($postIds);
});
```

## Advanced Features

### Named Routes

```php
// Define named routes
$router->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');

// Generate URLs from names
$url = $router->url('users.show', ['id' => 123]); // /users/123

// With query parameters
$url = $router->url('users.show', ['id' => 123], ['tab' => 'posts']); // /users/123?tab=posts
```

### Route Model Binding

```php
use App\Models\User;

// Automatic model resolution
$router->get('/users/{user}', function(User $user) {
    return new Response(['user' => $user->toArray()]);
});

// Custom binding logic
$router->bind('user', function($value) {
    return User::where('slug', $value)->firstOrFail();
});
```

### CORS Configuration

```php
// Configure CORS in config/cors.php
return [
    'allowed_origins' => ['https://app.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => ['X-Total-Count', 'Link'],
    'max_age' => 86400,
    'supports_credentials' => true,
];

// Apply CORS middleware
$router->group(['middleware' => ['cors']], function($router) {
    // API routes
});
```

### Route Caching

Routes are automatically cached in production for optimal performance:

```bash
# Clear route cache during deployment
php glueful route:clear

# Warm route cache
php glueful route:cache

# View cached routes
php glueful route:list
```

### Fallback Routes

```php
// Catch-all for 404 pages
$router->fallback(function() {
    return new Response(['error' => 'Not Found'], 404);
});

// API fallback
$router->group(['prefix' => '/api'], function($router) {
    // API routes...
    
    $router->fallback(function() {
        return new JsonResponse(['error' => 'API endpoint not found'], 404);
    });
});
```

## Performance Optimization

### Route Organization

The router uses several optimization techniques:

1. **Static Route Hash Table**: O(1) lookup for routes without parameters
2. **Route Bucketing**: Dynamic routes grouped by first segment
3. **Compiled Patterns**: Regex patterns pre-compiled and cached
4. **Reflection Caching**: Method/parameter reflection cached

### Optimization Tips

```php
// 1. Place static routes before dynamic ones (automatic with bucketing)
$router->get('/users/popular', $handler);  // Checked first
$router->get('/users/{id}', $handler);     // Checked second

// 2. Use specific constraints to fail fast
$router->get('/users/{id}', $handler)->where('id', '\d+');

// 3. Group related routes
$router->group(['prefix' => '/api/v1'], function($router) {
    // All v1 routes share the prefix
});

// 4. Use route caching in production
// Routes are compiled to native PHP arrays
```

### Benchmarks

The router achieves:
- **Static routes**: O(1) hash table lookup (~0.001ms)
- **Dynamic routes**: Optimized regex matching (~0.01ms)
- **Route compilation**: One-time cost, cached indefinitely
- **Middleware pipeline**: Lazy resolution, minimal overhead

## Best Practices

### 1. Route Organization

```php
// Organize routes by feature
routes/
├── api.php           # API routes
├── web.php           # Web routes
├── admin.php         # Admin routes
└── webhooks.php      # Webhook endpoints

// Load in bootstrap
foreach (['api', 'web', 'admin', 'webhooks'] as $file) {
    require __DIR__ . "/routes/{$file}.php";
}
```

### 2. RESTful Conventions

```php
// Follow REST conventions
$router->get('/users', [UserController::class, 'index']);       // List
$router->get('/users/{id}', [UserController::class, 'show']);   // Show
$router->post('/users', [UserController::class, 'store']);      // Create
$router->put('/users/{id}', [UserController::class, 'update']); // Update
$router->delete('/users/{id}', [UserController::class, 'destroy']); // Delete
```

### 3. API Versioning

```php
// Version via URL path
$router->group(['prefix' => '/api/v1'], function($router) {
    // Version 1 routes
});

$router->group(['prefix' => '/api/v2'], function($router) {
    // Version 2 routes
});

// Version via header (in middleware)
class ApiVersionMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $version = $request->headers->get('API-Version', 'v1');
        $request->attributes->set('api_version', $version);
        return $next($request);
    }
}
```

### 4. Security Best Practices

```php
// Always authenticate sensitive routes
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
});

// Rate limit public endpoints
$router->get('/api/search', $handler)
    ->middleware('rate_limit:10,60'); // 10 requests per minute

// Validate input
$router->post('/api/users', $handler)
    ->middleware('validate:user_create');

// CSRF protection for state-changing operations
$router->post('/actions/delete', $handler)
    ->middleware('csrf');
```

### 5. Error Handling

```php
// Custom error responses
$router->fallback(function(Request $request) {
    if (str_starts_with($request->getPathInfo(), '/api/')) {
        return new JsonResponse(['error' => 'Not Found'], 404);
    }
    return new Response('Page not found', 404);
});

// Method not allowed
$router->handleMethodNotAllowed(function($allowedMethods) {
    return new Response([
        'error' => 'Method not allowed',
        'allowed' => $allowedMethods
    ], 405);
});
```

### 6. Testing Routes

```php
use PHPUnit\Framework\TestCase;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;

class RouteTest extends TestCase
{
    public function test_user_route(): void
    {
        $app = Framework::create(getcwd())->boot();
        $router = $app->getContainer()->get(Router::class);
        
        $response = $router->dispatch(
            Request::create('/api/users/123', 'GET')
        );
        
        $this->assertEquals(200, $response->getStatusCode());
    }
}
```

## Troubleshooting

### Common Issues

**Route not found (404)**
- Check route registration order
- Verify path prefixes in groups
- Clear route cache: `php glueful route:clear`

**Method not allowed (405)**
- Verify HTTP method matches route definition
- Check if OPTIONS is needed for CORS

**Middleware not executing**
- Ensure middleware is registered in container
- Check middleware parameter syntax
- Verify group middleware inheritance

**Parameter injection failing**
- Type-hint parameters correctly
- Register bindings in container
- Check parameter names match route placeholders

### Debug Commands

```bash
# List all routes
php glueful route:list

# Filter routes
php glueful route:list --path=/api
php glueful route:list --name=users

# Clear route cache
php glueful route:clear

# Debug specific route
php glueful route:match GET /api/users/123
```

## Framework Endpoints Reference

The Glueful Framework includes several built-in endpoint groups that demonstrate real-world routing patterns:

### Health Monitoring Routes (`routes/health.php`)

```php
// System health with monitoring middleware  
$router->group(['prefix' => '/health'], function (Router $router) {
    // Main health check - high rate limit for monitoring tools
    $router->get('/', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->index();
    })->middleware('rate_limit:60,60'); // 60 requests per minute
    
    // Specific component checks with lower limits
    $router->get('/database', function (Request $request) {
        $healthController = container()->get(HealthController::class);
        return $healthController->database();
    })->middleware('rate_limit:30,60'); // 30 requests per minute
    
    $router->get('/cache', [HealthController::class, 'cache'])
        ->middleware('rate_limit:30,60');
        
    $router->get('/extensions', [HealthController::class, 'extensions'])
        ->middleware('rate_limit:20,60');
});
```

### Authentication Routes (`routes/auth.php`)

```php
// Authentication endpoints with varying rate limits
$router->group(['prefix' => '/auth'], function (Router $router) {
    // Login with strict rate limiting
    $router->post('/login', function (Request $request) {
        $authController = container()->get(AuthController::class);
        return $authController->login();
    })->middleware('rate_limit:5,60'); // 5 attempts per minute
    
    // Email verification
    $router->post('/verify-email', [AuthController::class, 'verifyEmail']);
    
    // OTP verification with additional security
    $router->post('/verify-otp', [AuthController::class, 'verifyOtp'])
        ->middleware('rate_limit:3,60'); // 3 attempts per minute
    
    // Token refresh and logout
    $router->post('/refresh', [AuthController::class, 'refresh'])
        ->middleware(['auth', 'rate_limit:10,60']);
    
    $router->post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth');
});
```

### Generic Resource Routes (`routes/resource.php`)

```php
// RESTful resource endpoints with authentication
// List resources with pagination
$router->get('/{resource}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0]];
    $queryParams = $request->query->all();
    return $resourceController->get($params, $queryParams);
})->middleware(['auth', 'rate_limit:100,60']); // 100 requests per minute

// Get single resource by UUID
$router->get('/{resource}/{uuid}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0], 'uuid' => $segments[1]];
    $queryParams = $request->query->all();
    return $resourceController->getSingle($params, $queryParams);
})->middleware(['auth', 'rate_limit:200,60']); // Higher limit for single reads

// Create resource
$router->post('/{resource}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Implementation...
})->middleware(['auth', 'rate_limit:20,60']); // 20 creates per minute

// Update resource
$router->put('/{resource}/{uuid}', [ResourceController::class, 'update'])
    ->middleware(['auth', 'rate_limit:20,60']);

// Delete resource
$router->delete('/{resource}/{uuid}', [ResourceController::class, 'delete'])
    ->middleware(['auth', 'rate_limit:10,60']); // Lower limit for destructive operations
```

### Key Patterns from Framework Routes

**Rate Limiting Strategy:**
- Authentication endpoints: Very restrictive (3-5 requests/minute)
- Health checks: Generous for monitoring (60 requests/minute)
- Read operations: High limits (100-200 requests/minute)  
- Write operations: Moderate limits (10-20 requests/minute)

**Security Patterns:**
- All resource operations require authentication
- Destructive operations have the lowest rate limits
- OTP and login attempts are heavily restricted
- Health endpoints allow higher volume for monitoring

**URL Structure:**
- Grouped by feature (`/auth`, `/health`)
- RESTful patterns for resources (`/{resource}`, `/{resource}/{id}`)
- Clear hierarchy and predictable paths

## Conclusion

The Glueful routing system provides a powerful, performant foundation for building enterprise APIs and web applications. With features like O(1) static route matching, intelligent middleware pipelines, and GraphQL-style field selection, it handles both simple and complex routing requirements efficiently.

The framework's built-in endpoints demonstrate best practices for authentication, health monitoring, and resource management that you can use as patterns for your own applications.

For more examples and advanced usage, see the [example application](../../examples/) and [test suite](../../tests/Unit/Routing/).