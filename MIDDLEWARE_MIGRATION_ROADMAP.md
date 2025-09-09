# Middleware Migration Roadmap

This document outlines the plan for migrating existing HTTP middleware to the new next-generation router system.

## 📋 Future Middleware Migration Plan

### ✅ Already Ported:

- **AuthenticationMiddleware** → `/src/Routing/Middleware/AuthMiddleware.php` (✅ Complete with enterprise features)
- **RateLimiterMiddleware** → `/src/Routing/Middleware/RateLimiterMiddleware.php` (✅ Complete with adaptive & distributed limiting)
- **CSRFMiddleware** → `/src/Routing/Middleware/CSRFMiddleware.php` (✅ Complete with enhanced security features)
- **SecurityHeadersMiddleware** → `/src/Routing/Middleware/SecurityHeadersMiddleware.php` (✅ Complete with CSP, HSTS, and advanced headers)
- **AdminPermissionMiddleware** → `/src/Routing/Middleware/AdminPermissionMiddleware.php` (✅ Complete with multi-level security, comprehensive audit trails & event system)
- **RequestResponseLoggingMiddleware** → `/src/Routing/Middleware/RequestResponseLoggingMiddleware.php` (✅ Complete with structured logging, security filtering & performance metrics, registered as `'request_logging'`)
- **LockdownMiddleware** → `/src/Routing/Middleware/LockdownMiddleware.php` (✅ Complete with emergency lockdown, IP blocking & maintenance pages, registered as `'lockdown'`)

### 🔄 Middleware to Port Later:

| Current HTTP Middleware       | Priority | Complexity | Notes                                     |
|-------------------------------|----------|------------|-------------------------------------------|
| ~~LoggingMiddleware.php~~     | ❌ Skip  | N/A        | **Not actual middleware** - HTTP client logger utility |
| ~~CacheControlMiddleware.php~~ | ❌ Skip  | N/A        | **Broken & redundant** - Missing PSR-15 imports, superseded by ResponseCachingTrait |
| ~~PermissionMiddleware.php~~  | ❌ Skip  | N/A        | **Broken & superseded** - Missing PSR-15 imports, replaced by AdminPermissionMiddleware + AuthorizationTrait |
| ~~LockdownMiddleware.php~~    | ✅ Complete | Medium     | **Migrated** - Emergency lockdown with IP blocking & maintenance pages |
| ~~ApiVersionMiddleware.php~~  | ❌ Skip  | N/A        | **Unused & tightly coupled** - No active usage, calls deprecated Router::setVersion(), better handled via route parameters |
| ~~ApiMetricsMiddleware.php~~  | ❌ Skip  | N/A        | **Broken PSR-15 imports & unused** - Missing MiddlewareInterface/RequestHandlerInterface imports, superseded by RequestResponseLoggingMiddleware |
| ~~MemoryTrackingMiddleware.php~~ | ❌ Skip  | N/A        | **Broken PSR-15 imports & redundant** - Missing MiddlewareInterface/RequestHandlerInterface imports, superseded by RequestResponseLoggingMiddleware memory tracking |
| ~~DeprecationMiddleware.php~~ | ❌ Skip  | N/A        | **Well-implemented but unused** - No registration, missing config dependencies, better handled at application level |
| ~~RetryMiddleware.php~~       | ❌ Skip  | N/A        | **Not actual middleware** - HTTP client utility class, not PSR-15 middleware. Incorrectly categorized |
| ~~EdgeCacheMiddleware.php~~   | ❌ Skip  | N/A        | **Broken PSR-15 imports & redundant** - Missing MiddlewareInterface/RequestHandlerInterface imports, superseded by ResponseCachingTrait edge cache functionality |

## 🏗️ Migration Pattern:

Each middleware will need to:

1. **Change Interface**: `MiddlewareInterface` → `RouteMiddleware`
2. **Update Method**: `process(Request, RequestHandlerInterface)` → `handle(Request, callable, ...$params)`
3. **Adapt Logic**: PSR-15 patterns → Next-gen router patterns
4. **Add Features**: Enhanced error handling, configurable options
5. **Container Registration**: Add to CoreServiceProvider for string aliases

## 💡 Benefits of Future Migration:

- **Consistent Interface**: All middleware use same RouteMiddleware interface
- **Better Performance**: Optimized for next-gen router
- **Enhanced Features**: Leverage new router capabilities
- **String Aliases**: Clean `->middleware(['rate_limit', 'csrf'])` syntax
- **Configurable Options**: Runtime customization like our AuthMiddleware
- **Type Safety**: Better PHPStan compliance

## 📖 Reference Implementation

The **AuthMiddleware** serves as the reference implementation showing how to properly port middleware with enterprise features. When it's time to port the others, we'll follow the same pattern we established! 🚀

## 🔧 Recent Improvements & Fixes

### AdminPermissionMiddleware (Latest Update)
**Completed comprehensive code quality improvements:**
- ✅ **Code Standards**: All PSR-12 coding standards violations resolved
- ✅ **Static Analysis**: All PHPStan warnings and errors fixed
- ✅ **Event System**: Created missing `AdminAccessEvent` and `AdminSecurityViolationEvent` classes
- ✅ **Type Safety**: Fixed mixed type issues and strict comparison problems
- ✅ **Code Cleanup**: Removed unused constants and imports
- ✅ **Parameter Usage**: Addressed unused parameter warnings in placeholder methods

**Key Features Enhanced:**
- Multi-level admin role verification with security profiles
- Enhanced security logging with structured event dispatching
- IP whitelist/blacklist support with CIDR notation
- Session validation and elevated authentication
- Permission-based access control with context awareness
- Rate limiting for admin access attempts
- Comprehensive audit trail for all admin operations
- Emergency lockdown support and time-based access restrictions
- Multi-factor authentication integration with session management

### RequestResponseLoggingMiddleware (New Implementation)
**Created comprehensive HTTP logging middleware:**
- ✅ **Proper Middleware**: Full RouteMiddleware implementation for next-gen router
- ✅ **Structured Logging**: Request/response logging with correlation IDs and metadata
- ✅ **Security Features**: Automatic redaction of sensitive headers, fields, and PII
- ✅ **Performance Monitoring**: Slow request detection and memory usage tracking
- ✅ **Configurable Options**: Flexible logging modes, levels, and body size limits
- ✅ **Privacy Compliance**: IP anonymization and GDPR-friendly logging options

**Key Features Implemented:**
- Configurable logging modes (request-only, response-only, both)
- Security-aware sensitive data filtering and redaction
- Performance metrics with slow request detection
- Structured logging with correlation IDs for request tracking
- Environment-aware configuration and stack trace inclusion
- Memory profiling and timing measurements
- Multiple static factory methods for common use cases
- Fallback logger implementation for resilience
- **Service Provider Integration:** Registered in CoreServiceProvider with `'request_logging'` alias

### ApiVersionMiddleware (Skipped Analysis)
**Analysis revealed critical issues requiring skip:**
- ❌ **No Active Usage**: Not registered in service providers, no actual route usage found
- ❌ **Tight Coupling**: Directly calls deprecated `\Glueful\Http\Router::setVersion()` method (line 96)
- ❌ **Missing Configuration**: Relies on non-existent `config('app.versioning')` settings
- ❌ **Router Dependency**: Tightly coupled to current router architecture being replaced

**Critical Problems Found:**
- Implements proper PSR-15 interface but never actually used in application
- Direct static method call to current router breaks encapsulation
- Configuration-dependent initialization without fallback handling
- Would require significant refactoring to work with new router

**Superior Alternative - Route Parameters:**
```php
// Instead of middleware, use route parameters in new router
$router->get('/api/{version}/users/{id}', fn($version, $id) => "API $version User $id");

// Or use route groups with version prefixes  
$router->group(['prefix' => '/api/v1'], function($router) {
    $router->get('/users/{id}', [UserController::class, 'show']);
});

// Advanced versioning with constraints
$router->get('/api/{version}/users/{id}', [UserController::class, 'show'])
    ->where('version', 'v[1-3]');
```

**Key Advantages of Route-Based Versioning:**
- No middleware overhead in request pipeline
- Native router support with parameter constraints
- Better performance through compiled route matching
- Cleaner separation of concerns
- Type-safe version parameters passed to controllers
- No configuration dependencies or static coupling

### ApiMetricsMiddleware (Skipped Analysis)
**Analysis revealed critical issues requiring skip:**
- ❌ **Broken PSR-15 Implementation**: Missing imports for `MiddlewareInterface` and `RequestHandlerInterface` 
- ❌ **Compilation Failures**: Would fail to compile if actually used (references undefined interfaces)
- ❌ **No Active Usage**: Not registered in service providers, no actual route usage found
- ❌ **Poor Design Patterns**: Uses static properties and shutdown functions for state management

**Critical Problems Found:**
- Claims PSR-15 compatibility but missing required interface imports (lines 15, 39)
- Uses static properties `$metricData` and `$startTime` making it non-thread-safe
- Shutdown function approach is unreliable for consistent metrics collection
- Direct service instantiation in constructor breaks dependency injection principles
- Only mentioned in documentation examples, never actually implemented in routes

**Superior Alternative - RequestResponseLoggingMiddleware:**
```php
// Already implemented and working in the new router system
$router->get('/api/users/{id}', [UserController::class, 'show'])
    ->middleware(['request_logging']); // Comprehensive metrics + logging

// Provides superior functionality:
// - Request/response timing and performance metrics  
// - Endpoint usage tracking with correlation IDs
// - Error rate monitoring with structured logging
// - Memory usage and slow request detection
// - Security-aware data filtering and redaction
// - Proper PSR-15 implementation with RouteMiddleware interface
```

**Key Advantages of RequestResponseLoggingMiddleware:**
- **Working Implementation**: Proper PSR-15/RouteMiddleware compliance
- **Already Integrated**: Registered in CoreServiceProvider with `'request_logging'` alias
- **Comprehensive Metrics**: Includes everything ApiMetricsMiddleware attempted plus more
- **Better Architecture**: Thread-safe, DI-compliant, no static state
- **Production Ready**: Security filtering, performance monitoring, structured logging
- **No Migration Overhead**: Already ported to new router system

### MemoryTrackingMiddleware (Skipped Analysis)
**Analysis revealed critical issues requiring skip:**
- ❌ **Broken PSR-15 Implementation**: Missing imports for `MiddlewareInterface` and `RequestHandlerInterface`
- ❌ **Compilation Failures**: Would fail to compile if actually used (references undefined interfaces)
- ❌ **No Active Usage**: Referenced in MiddlewareRegistry but not registered in service providers
- ❌ **Redundant Functionality**: RequestResponseLoggingMiddleware already provides superior memory tracking

**Critical Problems Found:**
- Claims PSR-15 compatibility but missing required interface imports (lines 16, 56)
- Referenced in MiddlewareRegistry for potential instantiation but never actually registered
- Only mentioned in documentation examples, no actual route usage detected
- MemoryManager dependency exists but middleware itself is broken

**Functionality Overlap Analysis:**
```php
// MemoryTrackingMiddleware provides:
// - Memory usage tracking with thresholds
// - Peak memory monitoring  
// - Sample rate limiting (1% by default)
// - Memory usage headers in responses

// RequestResponseLoggingMiddleware already provides:
// - Memory usage tracking (memory_usage_mb)
// - Peak memory monitoring (memory_peak_mb)
// - Better integration with structured logging
// - Security-aware logging with correlation IDs
// - Performance metrics including timing
// - Already ported to new router system
```

**Superior Alternative - RequestResponseLoggingMiddleware:**
```php
// Already implemented and working in the new router system
$router->get('/api/users/{id}', [UserController::class, 'show'])
    ->middleware(['request_logging']); // Includes comprehensive memory tracking

// Automatic memory monitoring in logs:
[2024-01-15 10:30:45] request.INFO: HTTP Response [] {
    "memory_usage_mb": 4.25,
    "memory_peak_mb": 6.1,
    "duration_ms": 145.67,
    "correlation_id": "req_abc123"
}
```

**Key Advantages of RequestResponseLoggingMiddleware:**
- **Working Implementation**: Proper PSR-15/RouteMiddleware compliance
- **Already Integrated**: Registered in CoreServiceProvider with `'request_logging'` alias
- **Comprehensive Monitoring**: Memory + timing + security + correlation tracking
- **Better Architecture**: No sampling issues, consistent monitoring
- **Production Ready**: Structured logging, security filtering, performance analysis
- **No Migration Overhead**: Already ported to new router system

### DeprecationMiddleware (Skipped Analysis)
**Analysis revealed well-implemented but impractical middleware:**
- ✅ **Proper Implementation**: Correct PSR-15 interface usage with framework's custom interfaces
- ❌ **No Active Usage**: Not registered in service providers, no actual route usage found
- ❌ **Missing Configuration**: Depends on non-existent config files (`api.deprecated_routes`, `logging.framework.log_deprecations`)
- ❌ **Framework vs Application Concern**: API deprecation better handled at application level

**Implementation Quality Assessment:**
```php
// ✅ Proper interface usage
use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\RequestHandlerInterface;

// ✅ Well-structured features:
// - Pattern matching for deprecated routes
// - Comprehensive logging with structured data
// - HTTP headers for client notification (X-API-Deprecated, Warning)
// - Configurable enablement and route definitions
```

**Critical Issues Found:**
- Configuration dependencies don't exist in the codebase:
  - `config('api.deprecated_routes', [])` - no `config/api.php` file
  - `config('logging.framework.log_deprecations', true)` - not found in `config/logging.php`
- Not registered in CoreServiceProvider or any service provider
- No actual usage in routes or middleware pipeline
- Would require significant setup to be functional

**Better Alternative - Application-Level Deprecation:**
```php
// Better approach: Handle in controllers or route attributes
#[Deprecated(since: '1.2.0', removal: '2.0.0', replacement: '/api/v2/users')]
class UserV1Controller extends BaseController
{
    public function index(): Response
    {
        // Add deprecation headers in controller
        return response()->json($data)->withHeaders([
            'X-API-Deprecated' => 'true',
            'X-API-Deprecated-Since' => '1.2.0',
            'Warning' => '299 - "This endpoint is deprecated"'
        ]);
    }
}

// Or use route-level deprecation in new router
$router->get('/api/v1/users', [UserV1Controller::class, 'index'])
    ->deprecated('1.2.0', '2.0.0', '/api/v2/users');
```

**Key Reasons for Skipping:**
- **Configuration Overhead**: Would need significant config setup to be useful
- **Framework Scope**: API deprecation is application concern, not framework middleware concern
- **New Router Benefits**: Better handled via route attributes or controller logic
- **No Current Need**: Not used anywhere, indicates low priority
- **Clean Migration**: Removing unused middleware simplifies router migration

### RetryMiddleware (Skipped Analysis - Incorrect Categorization)
**Analysis revealed this is NOT actual middleware:**
- ❌ **Not PSR-15 Middleware**: No `MiddlewareInterface` implementation, no `process()` method
- ❌ **Wrong Classification**: HTTP client utility class, not request/response middleware  
- ✅ **Actually Functional**: Used correctly in `ApiClientBuilder` for outbound HTTP requests
- ❌ **No Router Relevance**: Has nothing to do with routing or incoming request processing

**What RetryMiddleware Actually Is:**
```php
// ❌ NOT this (PSR-15 middleware):
class RetryMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandlerInterface $handler): Response
}

// ✅ Actually this (HTTP client utility):
class RetryMiddleware {
    public static function create(HttpClientInterface $client, array $config): RetryableHttpClient
    public static function createApiRetryStrategy(): GenericRetryStrategy
    // ... more static factory methods
}
```

**Correct Usage (HTTP Client Utility):**
```php
// Used in ApiClientBuilder for OUTBOUND HTTP requests:
class ApiClientBuilder {
    public function retries(int $maxRetries, array $config = []): self {
        // Store retry configuration for later use with RetryMiddleware
        $this->options['_retry_config'] = $config;
    }
    
    public function buildWithRetries(): Client {
        $client = $this->build();
        return RetryMiddleware::create($client, $this->getRetryConfig());
    }
}
```

**Key Functionality Analysis:**
- **HTTP Client Wrapper**: Creates `RetryableHttpClient` with Symfony's retry strategies
- **Configuration Factory**: Provides pre-configured retry strategies for different use cases:
  - API calls (429, 5xx status codes)
  - Webhook deliveries (conservative retry)
  - Payment gateways (very conservative, no 4xx retries)
  - External services (comprehensive retry)
- **Validation**: Includes config validation for retry parameters

**Why This Was Incorrectly Categorized:**
- **Name Confusion**: "Middleware" in the class name doesn't make it PSR-15 middleware
- **Directory Location**: Placed in `Http/Middleware/` directory but serves different purpose
- **Scope Mismatch**: Handles outbound HTTP client requests, not inbound request pipeline

**Recommendation: Remove from Migration Entirely**

This should be **completely removed** from middleware migration roadmap because:
- **Not Middleware**: It's an HTTP client utility, period
- **Already Functional**: Works correctly with existing HTTP client system  
- **No Migration Needed**: Has nothing to do with router middleware pipeline
- **Correct Classification**: Belongs in HTTP client utilities documentation

**Action Required**: Move documentation to HTTP client utilities section, remove from middleware migration.

### EdgeCacheMiddleware (Skipped Analysis)
**Analysis revealed critical issues requiring skip:**
- ❌ **Broken PSR-15 Implementation**: Missing imports for `MiddlewareInterface` and `RequestHandlerInterface`
- ❌ **Compilation Failures**: Would fail to compile if actually used (references undefined interfaces)
- ❌ **No Active Usage**: Referenced in MiddlewareRegistry but not registered in service providers
- ❌ **Redundant Functionality**: ResponseCachingTrait already provides superior edge cache functionality

**Critical Problems Found:**
- Claims PSR-15 compatibility but missing required interface imports (lines 17, 38)
- Referenced in MiddlewareRegistry for potential instantiation but never actually registered
- EdgeCacheService dependency exists and is registered, but middleware itself has import issues
- Global middleware approach is inferior to controller-level caching with business logic

**Functionality Overlap Analysis:**
```php
// EdgeCacheMiddleware provides (but broken):
// - Automatic cache headers for all responses
// - Route-based caching via EdgeCacheService
// - CDN integration through generateCacheHeaders()

// ResponseCachingTrait already provides (working):
// - edgeCacheResponse() method for CDN headers
// - Route pattern-based cache configuration
// - Business logic integration for intelligent caching
// - ETag generation and cache validation
// - Permission-based cache duration adjustment
// - Cache invalidation by tags and dependencies
```

**Superior Alternative - ResponseCachingTrait:**
```php
// Already implemented and working in controllers
class ApiController extends BaseController
{
    use ResponseCachingTrait;
    
    public function getUsers(): Response
    {
        $response = Response::success($users);
        
        // Add edge cache headers with business logic awareness
        return $this->edgeCacheResponse($response, 'api.users.index', 3600);
    }
    
    // Automatic features:
    // - CDN integration via EdgeCacheService
    // - Route pattern-based configuration
    // - Permission-aware cache control
    // - ETag validation and 304 responses
    // - Cache invalidation on data changes
}
```

**Key Advantages of ResponseCachingTrait:**
- **Working Implementation**: No PSR-15 import issues, fully functional
- **Business Logic Awareness**: Controllers can make intelligent caching decisions
- **Better Performance**: No middleware overhead, direct cache control
- **Comprehensive Features**: ETag, validation, invalidation, CDN integration
- **Permission Integration**: Cache duration based on user permissions
- **Already in Use**: Functional in existing controllers throughout codebase

**Why Global Middleware is Wrong Approach:**
- **One-size-fits-all**: Cannot handle different caching needs per endpoint
- **No Business Context**: Cannot make intelligent caching decisions
- **Performance Overhead**: Processes every response regardless of cacheability
- **Less Flexibility**: Cannot adjust cache behavior based on request context

### CacheControlMiddleware (Skipped Analysis)
**Analysis revealed critical issues requiring skip:**
- ❌ **Broken Implementation**: Missing PSR-15 imports (`MiddlewareInterface`, `RequestHandlerInterface`)
- ❌ **Zero Usage**: Not registered in service providers, no actual route usage found
- ❌ **Redundant Functionality**: `ResponseCachingTrait` already provides superior cache control features

**Critical Problems Found:**
- Claims PSR-15 compatibility but missing required interface imports
- Would fail to compile if actually used anywhere
- Only mentioned in documentation examples, never actually implemented
- No middleware registration or route usage detected in entire codebase

**Superior Alternative - ResponseCachingTrait:**
```php
// Controllers get comprehensive cache control via trait
class UserController extends BaseController 
{
    use ResponseCachingTrait;
    
    public function show(string $id): Response
    {
        return $this->withCacheHeaders(
            Response::success($data),
            [
                'public' => true,
                'max_age' => 3600,
                's_maxage' => 3600,
                'must_revalidate' => true,
                'etag' => true,
                'vary' => ['Accept', 'Authorization']
            ]
        );
    }
}
```

**Key Advantages of ResponseCachingTrait:**
- Business logic awareness for intelligent caching decisions
- Permission-based cache duration adjustment
- ETag generation and validation with 304 responses  
- CDN integration with Surrogate-Key headers
- Cache invalidation by tags and dependencies
- Fragment caching for partial responses
- Performance metrics tracking
- No middleware overhead in request pipeline

## 🎯 Migration Priority Explanation:

### 🔥 High Priority (Security & Core Functionality)
These middleware are critical for security and core application functionality:
- Admin permissions, rate limiting, security headers, CSRF protection

### ⚡ Medium Priority (Operations & Performance)
Important for production operations and performance:
- Logging, caching, permissions, emergency controls

### 🔧 Low Priority (Features & Monitoring)  
Nice-to-have features that can be migrated last:
- Versioning, metrics, deprecation warnings, retry logic

## 📝 Migration Checklist Template

For each middleware migration, follow this checklist:

- [ ] Change interface from `MiddlewareInterface` to `RouteMiddleware`
- [ ] Update method signature: `process()` → `handle()`
- [ ] Adapt middleware logic for new router patterns
- [ ] Add configurable options array support
- [ ] Implement enhanced error handling with structured responses
- [ ] Add comprehensive PHPDoc annotations
- [ ] Register in CoreServiceProvider with string alias
- [ ] Write usage examples and documentation
- [ ] Add unit tests
- [ ] Verify PHPStan compliance
- [ ] Update existing route definitions to use new middleware

## 🔄 Migration Status Tracking

| Middleware | Status | Assigned | Target Date | Notes |
|------------|--------|----------|-------------|-------|
| AuthMiddleware | ✅ Complete | - | - | Reference implementation with enterprise features |
| RateLimiterMiddleware | ✅ Complete | - | - | Full implementation with adaptive & distributed limiting |
| CSRFMiddleware | ✅ Complete | - | - | Enhanced security with Origin validation & stateless tokens |
| SecurityHeadersMiddleware | ✅ Complete | - | - | CSP, HSTS, cross-origin policies & security profiles |
| AdminPermissionMiddleware | ✅ Complete | - | - | Multi-level security, IP restrictions, MFA, comprehensive audit & event system integration |
| RequestResponseLoggingMiddleware | ✅ Complete | - | - | **New Implementation:** Structured HTTP request/response logging with security filtering & performance metrics. **Registered:** `'request_logging'` alias |
| ~~LoggingMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Not actual middleware - HTTP client utility. Replaced with RequestResponseLoggingMiddleware |
| ~~CacheControlMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Broken implementation with missing PSR-15 imports, zero usage. Superseded by ResponseCachingTrait |
| ~~PermissionMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Broken PSR-15 implementation, zero usage. Superseded by AdminPermissionMiddleware + AuthorizationTrait |
| LockdownMiddleware | ✅ Complete | - | - | **Migrated:** Emergency lockdown, IP blocking, maintenance pages & severity-based restrictions. **Registered:** `'lockdown'` alias |
| ~~ApiVersionMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Unused middleware with tight coupling to deprecated Router::setVersion(). Better handled via new router's native route parameters |
| ~~ApiMetricsMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Broken PSR-15 imports, no active usage. Functionality superseded by RequestResponseLoggingMiddleware |
| ~~MemoryTrackingMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Broken PSR-15 imports, no active usage. Memory tracking already provided by RequestResponseLoggingMiddleware |
| ~~DeprecationMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Well-implemented but unused, missing config dependencies. Better handled at application level |
| ~~RetryMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Not actual middleware - HTTP client utility class. Incorrectly categorized in migration |
| ~~EdgeCacheMiddleware~~ | ❌ Skipped | - | - | **Analysis:** Broken PSR-15 imports, no active usage. Edge cache functionality already provided by ResponseCachingTrait |