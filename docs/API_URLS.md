# API URL Configuration

This guide explains how to configure API URLs in Glueful Framework.

## Quick Start

### 1. Set Environment Variables

Choose your URL pattern in `.env`:

```env
# Pattern A: Dedicated subdomain (recommended for public APIs)
BASE_URL=https://api.example.com
API_USE_PREFIX=false
API_VERSION_IN_PATH=true
API_VERSION=1
# Result: https://api.example.com/v1/auth/login

# Pattern B: Path prefix (traditional)
BASE_URL=https://example.com
API_USE_PREFIX=true
API_PREFIX=/api
API_VERSION_IN_PATH=true
API_VERSION=1
# Result: https://example.com/api/v1/auth/login

# Pattern C: No versioning (simple APIs)
BASE_URL=https://api.example.com
API_USE_PREFIX=false
API_VERSION_IN_PATH=false
# Result: https://api.example.com/auth/login
```

### 2. Use `api_prefix()` in Your Routes

**Important:** Framework routes are automatically prefixed, but your application routes in `routes/api.php` need to use the helper:

```php
// routes/api.php

$router->group(['prefix' => api_prefix()], function ($router) {
    // Your API routes here - they will get the configured prefix
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/users', [UserController::class, 'store']);
});
```

## Helper Functions

### `api_prefix()`

Returns the configured API prefix for use in route definitions.

```php
api_prefix();
// Returns: '/api/v1' (with default config)
// Returns: '/v1' (with API_USE_PREFIX=false)
// Returns: '' (with both disabled)
```

**Use in routes:**
```php
$router->group(['prefix' => api_prefix()], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});
```

### `api_url()`

Generates a full API URL for a given path.

```php
api_url('/auth/login');
// Returns: 'https://api.example.com/v1/auth/login'

api_url('/users/123');
// Returns: 'https://api.example.com/v1/users/123'

api_url();
// Returns: 'https://api.example.com/v1' (base API URL)
```

**Use in responses, redirects, documentation:**
```php
return response()->json([
    'links' => [
        'self' => api_url('/users/' . $user->id),
        'posts' => api_url('/users/' . $user->id . '/posts'),
    ]
]);
```

### `is_api_path()`

Checks if a URL path matches the API prefix.

```php
is_api_path('/api/v1/users');  // true (with default config)
is_api_path('/admin/dashboard');  // false
```

## Route Types

The framework handles different route types differently:

| Route Type | Location | Auto-Prefixed | Example |
|------------|----------|---------------|---------|
| Framework API | `routes/auth.php`, `routes/resource.php` | Yes | `/api/v1/auth/login` |
| Framework Public | `routes/health.php`, `routes/docs.php` | No | `/health`, `/docs` |
| Application | `routes/api.php` | **No** - use `api_prefix()` | Your choice |

## Examples

### Basic Application Routes

```php
// routes/api.php

use App\Controllers\UserController;
use App\Controllers\PostController;

$router->group(['prefix' => api_prefix()], function ($router) {
    // Users
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/users', [UserController::class, 'store']);
    $router->put('/users/{id}', [UserController::class, 'update']);
    $router->delete('/users/{id}', [UserController::class, 'destroy']);

    // Posts
    $router->get('/posts', [PostController::class, 'index']);
    $router->get('/posts/{id}', [PostController::class, 'show']);
});
```

### Multiple API Versions

```php
// routes/api.php

use App\Controllers\V1\UserController as UserControllerV1;
use App\Controllers\V2\UserController as UserControllerV2;

// Version 1
$router->group(['prefix' => '/api/v1'], function ($router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});

// Version 2 (new response format)
$router->group(['prefix' => '/api/v2'], function ($router) {
    $router->get('/users', [UserControllerV2::class, 'index']);
});
```

### Mixed Routes (API + Webhooks)

```php
// routes/api.php

use App\Controllers\UserController;
use App\Controllers\WebhookController;

// Versioned API routes
$router->group(['prefix' => api_prefix()], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});

// Webhooks - fixed URLs (external services expect stable endpoints)
$router->post('/webhooks/stripe', [WebhookController::class, 'stripe']);
$router->post('/webhooks/github', [WebhookController::class, 'github']);
```

## Configuration Reference

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `BASE_URL` | `http://localhost` | Base URL of your application |
| `API_VERSION` | `1` | Current API version number |
| `API_USE_PREFIX` | `true` | Whether to add `/api` prefix |
| `API_PREFIX` | `/api` | The prefix to use (when enabled) |
| `API_VERSION_IN_PATH` | `true` | Whether to include `/v1` in URL |

## URL Pattern Comparison

| Pattern | Config | URL Structure |
|---------|--------|---------------|
| **Subdomain** | `API_USE_PREFIX=false` | `api.example.com/v1/users` |
| **Path Prefix** | `API_USE_PREFIX=true` | `example.com/api/v1/users` |
| **No Version** | `API_VERSION_IN_PATH=false` | `api.example.com/users` |

## Best Practices

1. **Always use `api_prefix()`** in your application routes for consistency
2. **Don't hardcode** `/api/v1` - use the helper so config changes work automatically
3. **Use `api_url()`** when generating URLs in responses (HATEOAS links)
4. **Keep webhooks outside** the versioned prefix - external services expect stable URLs
5. **For public APIs**, prefer the subdomain pattern (`api.example.com/v1/...`)
