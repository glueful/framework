# API URL Consistency Implementation Plan

## Problem Statement

The framework has inconsistent API URL handling:

1. **Config says one thing, routes do another:**
   - `config/app.php` → `urls.api` = `{BASE_URL}/api/v1`
   - Actual routes: `/auth/login`, `/{resource}` (no prefix)

2. **Hardcoded `/api/` checks don't match actual routes:**
   - `LockdownMiddleware:402` → `str_starts_with($path, '/api/')`
   - `RequestHelper:75` → `str_contains($requestUri, '/api/admin')`
   - These checks never match because routes don't have `/api/` prefix

3. **Versioning system exists but isn't used:**
   - `Router::apiVersion()` method exists
   - `API_PREFIX` config exists
   - Neither is applied to framework routes

4. **Developer confusion:**
   - Config suggests API is at `/api/v1/auth/login`
   - Reality: API is at `/auth/login`

---

## Goals

1. **Consistency**: Config values should match actual routing behavior
2. **Flexibility**: Support both patterns:
   - Subdomain: `api.example.com/v1/auth/login`
   - Path prefix: `example.com/api/v1/auth/login`
3. **Modern standards**: Follow patterns used by Stripe, OpenAI, GitHub
4. **Single source of truth**: One config controls the URL structure

---

## Proposed URL Patterns

### Pattern A: Dedicated API Subdomain (Recommended for public APIs)
```
BASE_URL = https://api.example.com
API_PREFIX = ""  (empty - subdomain indicates API)

Result: https://api.example.com/v1/auth/login
```

### Pattern B: Path Prefix (Traditional)
```
BASE_URL = https://example.com
API_PREFIX = "/api"

Result: https://example.com/api/v1/auth/login
```

### Pattern C: No Versioning (Simple APIs)
```
BASE_URL = https://api.example.com
API_PREFIX = ""
API_VERSIONING = false

Result: https://api.example.com/auth/login
```

---

## Integration with Existing Systems

### Versioning System (`src/Api/Versioning/`)
The framework has an existing versioning system that:
- **UrlPrefixResolver**: Parses version from URLs like `/api/v1/users` using `config('api.versioning.resolver_options.url_prefix.prefix')`
- **VersionManager**: Manages version negotiation, deprecation, sunset dates
- **VersionNegotiationMiddleware**: Adds version headers to responses

**Key insight**: The versioning system parses incoming URLs but doesn't prefix routes. We need to ensure:
1. Route prefixing uses the SAME config as version parsing
2. No duplicate config values

### CDN URL Usage
The `urls.cdn` config IS used by the storage system:
```php
// config/storage.php:13
'base_url' => config('app.urls.cdn'),
```
**This must be preserved.**

### Documentation Generators
`config('app.urls.api')` is used by:
- `CommentsDocGenerator.php:443` - OpenAPI server URL
- `DocGenerator.php:611` - OpenAPI server URL

**These must be updated to use the new helper.**

---

## Implementation Tasks

### Phase 1: Configuration Consolidation

#### Task 1.1: Update `config/app.php`
Keep `urls.cdn`, make `urls.api` dynamically computed:

```php
// Before
'urls' => [
    'base' => env('BASE_URL', 'http://localhost'),
    'cdn' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/storage/cdn/',
    'api' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/api/v' . env('API_VERSION', '1'),
    'docs' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/docs/',
],

// After - remove 'api' (will use api_url() helper instead)
'urls' => [
    'base' => env('BASE_URL', 'http://localhost'),
    'cdn' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/storage/cdn/',
    'docs' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/docs/',
],
```

#### Task 1.2: Update `config/api.php` - Consolidate Versioning Config
**DO NOT create duplicate config.** Extend the existing `versioning` section:

```php
'versioning' => [
    // Existing config...
    'default' => env('API_VERSION', '1'),
    'supported' => [],
    'deprecated' => [],
    'strategy' => env('API_VERSION_STRATEGY', 'url_prefix'),
    'strict' => env('API_VERSION_STRICT', false),
    'resolvers' => ['url_prefix', 'header', 'query', 'accept'],

    // NEW: Route prefixing options (uses same prefix as version resolver)
    'prefix' => env('API_PREFIX', '/api'),  // Already exists, keep it

    // NEW: Control whether to apply prefix to routes
    'apply_prefix_to_routes' => env('API_USE_PREFIX', true),

    // NEW: Control whether version appears in URL path
    'version_in_path' => env('API_VERSION_IN_PATH', true),

    'resolver_options' => [
        'url_prefix' => [
            'prefix' => env('API_PREFIX', '/api'),  // Uses same env var
            'priority' => 100,
        ],
        // ... rest unchanged
    ],
],
```

**Important**: The `prefix` config is now shared between:
- Route prefixing (new)
- Version parsing (existing UrlPrefixResolver)

#### Task 1.3: Create `api_url()` and `api_prefix()` helper functions
Single source of truth for generating API URLs, using consolidated config:

```php
// src/Helpers/functions.php

/**
 * Generate a full API URL for a given path
 *
 * @param string $path Route path (e.g., '/auth/login')
 * @return string Full URL (e.g., 'https://api.example.com/v1/auth/login')
 */
function api_url(string $path = ''): string
{
    $baseUrl = rtrim(config('app.urls.base', 'http://localhost'), '/');
    $prefix = api_prefix();

    $url = $baseUrl . $prefix;

    if (!empty($path)) {
        $url .= '/' . ltrim($path, '/');
    }

    return $url;
}

/**
 * Get the API route prefix (for use in route definitions)
 * Uses the consolidated versioning config.
 *
 * @return string Prefix (e.g., '/api/v1' or '/v1' or '')
 */
function api_prefix(): string
{
    $versionConfig = config('api.versioning', []);
    $parts = [];

    // Add prefix if configured (e.g., "/api")
    $applyPrefix = $versionConfig['apply_prefix_to_routes'] ?? true;
    $prefix = $versionConfig['prefix'] ?? '/api';

    if ($applyPrefix && !empty($prefix)) {
        $parts[] = rtrim($prefix, '/');
    }

    // Add version if configured (e.g., "/v1")
    $versionInPath = $versionConfig['version_in_path'] ?? true;
    $version = $versionConfig['default'] ?? '1';

    if ($versionInPath) {
        $parts[] = '/v' . $version;
    }

    return implode('', $parts) ?: '';
}

/**
 * Check if a path is an API route (starts with API prefix)
 *
 * @param string $path URL path to check
 * @return bool True if path is an API route
 */
function is_api_path(string $path): bool
{
    $prefix = api_prefix();
    if (empty($prefix)) {
        return true; // All routes are API routes if no prefix
    }
    return str_starts_with($path, $prefix);
}
```

---

### Phase 2: Route Loading with Prefix

#### Task 2.1: Update `RouteManifest::load()`
Apply the configured prefix to API routes using the `api_prefix()` helper:

```php
// src/Routing/RouteManifest.php

public static function load(Router $router): void
{
    if (self::$loaded) {
        return;
    }
    self::$loaded = true;

    $manifest = self::generate();
    $frameworkPath = dirname(dirname(__DIR__));

    // Get API prefix from helper (uses consolidated versioning config)
    $fullPrefix = function_exists('api_prefix') ? api_prefix() : '/api/v1';

    // Load framework API routes with prefix
    $router->group(['prefix' => $fullPrefix], function (Router $router) use ($manifest, $frameworkPath) {
        foreach ($manifest['api_routes'] as $file) {
            $frameworkFile = $frameworkPath . $file;
            if (file_exists($frameworkFile)) {
                require $frameworkFile;
            }
        }
    });

    // Load public routes WITHOUT API prefix (health, docs)
    foreach ($manifest['public_routes'] as $file) {
        $frameworkFile = $frameworkPath . $file;
        if (file_exists($frameworkFile)) {
            require $frameworkFile;
        }
    }

    // Load application routes (may have their own prefix configuration)
    foreach ($manifest['core_routes'] as $file) {
        if (file_exists(base_path($file))) {
            require base_path($file);
        }
    }
}
```

#### Task 2.2: Separate API routes from non-API routes
Some routes (like health checks, docs) might not need the API prefix:

```php
// src/Routing/RouteManifest.php

public static function generate(): array
{
    return [
        // Routes that get the API prefix
        'api_routes' => [
            '/routes/auth.php',
            '/routes/resource.php',
        ],
        // Routes that don't get the API prefix (public endpoints)
        'public_routes' => [
            '/routes/health.php',
            '/routes/docs.php',
        ],
        // Application routes (loaded without automatic prefix)
        'core_routes' => [
            '/routes/api.php',
        ],
        'generated_at' => time(),
    ];
}
```

---

### Phase 3: Fix Hardcoded Checks

#### Task 3.1: Update `LockdownMiddleware`
Replace hardcoded `/api/` check with `is_api_path()` helper:

```php
// src/Routing/Middleware/LockdownMiddleware.php

private function isWebRequest(Request $request): bool
{
    $path = $this->getRequestPath($request);

    // Use helper to check if this is an API path
    if (function_exists('is_api_path') && is_api_path($path)) {
        return false;
    }

    // Fallback check for when helper isn't loaded
    if (str_starts_with($path, '/api/')) {
        return false;
    }

    // ... rest of the method (Accept header checks, etc.)
}
```

#### Task 3.2: Update `RequestHelper`
Replace hardcoded `/api/admin` check:

```php
// src/Helpers/RequestHelper.php

public static function isAdminRequest(Request $request): bool
{
    $requestUri = $request->getRequestUri();

    // Check for admin path patterns
    if (str_contains($requestUri, '/admin')) {
        return true;
    }

    // Check for admin API endpoints using configured prefix
    if (function_exists('api_prefix')) {
        $apiPrefix = api_prefix();
        if (str_contains($requestUri, $apiPrefix . '/admin')) {
            return true;
        }
    }

    // ... rest of checks (headers, query params)
}
```

#### Task 3.3: Update `ValidateCommand`
```php
// src/Console/Commands/Fields/ValidateCommand.php

private function isApiRoute(string $path): bool
{
    if (function_exists('is_api_path')) {
        return is_api_path($path);
    }
    // Fallback
    return str_starts_with($path, '/api/') || str_contains($path, 'api.');
}
```

---

### Phase 4: Update Documentation System

#### Task 4.1: Update `config/documentation.php`
Use the helper function for server URL:

```php
'servers' => [
    [
        'url' => api_url(),
        'description' => env('API_SERVER_DESCRIPTION', 'API Server'),
    ],
],
```

#### Task 4.2: Update `CommentsDocGenerator.php`
Replace hardcoded URL construction with helper:

```php
// src/Support/Documentation/CommentsDocGenerator.php

// Before (line 443)
'url' => rtrim(config('app.urls.api'), '/') . '/' . config('app.api_version'),

// After
'url' => api_url(),
```

Also update line 1182 with the same change.

#### Task 4.3: Update `DocGenerator.php`
Replace fallback URL construction with helper:

```php
// src/Support/Documentation/DocGenerator.php

// Before (line 609-614)
'servers' => config('documentation.servers', [
    [
        'url' => rtrim(config('app.urls.api', ''), '/'),
        'description' => 'API Server'
    ]
]),

// After
'servers' => config('documentation.servers', [
    [
        'url' => api_url(),
        'description' => 'API Server'
    ]
]),
```

---

### Phase 5: Environment Variable Presets

#### Task 5.1: Create `.env.example` presets

```env
#=============================================================================
# API URL Configuration
#=============================================================================
# Choose your pattern:

# Pattern A: Dedicated subdomain (recommended for public APIs)
# BASE_URL=https://api.example.com
# API_USE_PREFIX=false
# API_VERSION_IN_PATH=true
# Result: https://api.example.com/v1/auth/login

# Pattern B: Path prefix (traditional)
# BASE_URL=https://example.com
# API_USE_PREFIX=true
# API_PREFIX=/api
# API_VERSION_IN_PATH=true
# Result: https://example.com/api/v1/auth/login

# Pattern C: No versioning (simple)
# BASE_URL=https://api.example.com
# API_USE_PREFIX=false
# API_VERSION_IN_PATH=false
# Result: https://api.example.com/auth/login

#=============================================================================
# Current Configuration (defaults to Pattern B)
#=============================================================================
BASE_URL=http://localhost
API_USE_PREFIX=true
API_PREFIX=/api
API_VERSION_IN_PATH=true
API_VERSION=1
```

---

## File Changes Summary

| File | Change Type | Description |
|------|-------------|-------------|
| `config/app.php` | Modify | Remove `urls.api` (keep `urls.cdn`, `urls.docs`, `urls.base`) |
| `config/api.php` | Modify | Add `apply_prefix_to_routes`, `version_in_path` to existing `versioning` |
| `src/Helpers/functions.php` | Modify | Add `api_url()`, `api_prefix()`, `is_api_path()` helpers |
| `src/Routing/RouteManifest.php` | Modify | Apply prefix when loading routes, separate api/public routes |
| `src/Routing/Middleware/LockdownMiddleware.php` | Modify | Use `is_api_path()` helper |
| `src/Helpers/RequestHelper.php` | Modify | Use `api_prefix()` helper |
| `src/Console/Commands/Fields/ValidateCommand.php` | Modify | Use `is_api_path()` helper |
| `config/documentation.php` | Modify | Use `api_url()` helper |
| `src/Support/Documentation/CommentsDocGenerator.php` | Modify | Use `api_url()` helper (lines 443, 1182) |
| `src/Support/Documentation/DocGenerator.php` | Modify | Use `api_url()` helper (line 611) |
| `.env.example` | Modify | Add URL configuration presets |
| `docs/API_URLS.md` | Create | Documentation for URL configuration |

---

## Testing Plan

### Unit Tests
1. Test `api_url()` helper with various configurations
2. Test `api_prefix()` helper with various configurations
3. Test `is_api_path()` helper with various configurations
4. Test route loading applies correct prefix

### Integration Tests
1. Test Pattern A: `api.example.com/v1/auth/login`
2. Test Pattern B: `example.com/api/v1/auth/login`
3. Test Pattern C: `api.example.com/auth/login`
4. Test middleware correctly identifies API vs web requests
5. Test documentation generates correct server URLs

### Versioning System Integration Tests
1. Test `UrlPrefixResolver` parses version correctly with configured prefix
2. Test route prefix matches version resolver prefix
3. Test version negotiation works with new URL patterns
4. Test deprecation/sunset headers work correctly

---

## Rollout Plan

1. **Phase 1-2**: Core changes (config + route loading)
2. **Phase 3**: Fix hardcoded checks
3. **Phase 4-5**: Documentation and presets

All phases should be implemented together as a single release.

---

## Example Results

### After Implementation

**Pattern A (Subdomain):**
```env
BASE_URL=https://api.example.com
API_USE_PREFIX=false
API_VERSION_IN_PATH=true
API_VERSION=1
```
```
https://api.example.com/v1/auth/login
https://api.example.com/v1/users
https://api.example.com/v1/users/123
```

**Pattern B (Path Prefix):**
```env
BASE_URL=https://example.com
API_USE_PREFIX=true
API_PREFIX=/api
API_VERSION_IN_PATH=true
API_VERSION=1
```
```
https://example.com/api/v1/auth/login
https://example.com/api/v1/users
https://example.com/api/v1/users/123
```

**Pattern C (No Version):**
```env
BASE_URL=https://api.example.com
API_USE_PREFIX=false
API_VERSION_IN_PATH=false
```
```
https://api.example.com/auth/login
https://api.example.com/users
https://api.example.com/users/123
```
