# Glueful Framework - Potential Improvements & Additions

> A comprehensive roadmap for making Glueful a leading PHP framework for building fast, efficient modern APIs.

## Executive Summary

Glueful Framework is already well-architected with strong foundations in routing (O(1) lookups), caching (distributed with failover), queuing (auto-scaling), and security. This document outlines strategic improvements to make it competitive with Laravel, Symfony, and API Platform for production REST API development.

---

## Architecture Philosophy: Core vs Extensions

Glueful follows a **lean core, rich ecosystem** philosophy. The core framework should remain focused on essential API functionality, while advanced features are provided as optional extensions.

### Core Framework Responsibilities
- Routing, request/response handling
- Database connectivity and query building
- Authentication basics (JWT, sessions)
- Caching and queuing infrastructure
- Validation and error handling
- Configuration and dependency injection

### Existing Official Extensions
Several extensions are already published and available:

| Extension | Package | Description |
|-----------|---------|-------------|
| **Aegis** | `glueful/aegis` | Role-Based Access Control (RBAC) |
| **Email Notification** | `glueful/email-notification` | Email via Symfony Mailer |
| **Entrada** | `glueful/entrada` | Social Login & SSO (OAuth/OIDC) |
| **Notiva** | `glueful/notiva` | Push notifications (FCM, APNs, Web Push) |
| **Payvia** | `glueful/payvia` | Payment gateways (Stripe, Paystack, Flutterwave) |
| **Runiva** | `glueful/runiva` | Server runtimes (RoadRunner, Swoole, FrankenPHP) |

### Planned Extension Candidates
Features that should be implemented as extensions:
- **Observability** - OpenTelemetry, Prometheus metrics, APM integrations
- **Advanced Security** - OAuth2 server, MFA/TOTP, WebAuthn
- **Search** - Elasticsearch, Meilisearch, Algolia adapters

This approach keeps the core framework lightweight and allows teams to opt-in to features they need.

---

## Priority 1: Critical Features for Modern APIs

### 1.1 ORM / Active Record Layer

**Current State:** Query builder only, no model hydration or relationships.

**Proposed Addition:**
```php
// Example usage
$user = User::find($uuid);
$posts = $user->posts()->where('status', 'published')->get();

// Eager loading to prevent N+1
$users = User::with(['posts', 'posts.comments'])->paginate(25);

// Model definition
class User extends Model
{
    protected string $table = 'users';

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
```

**Implementation Path:**
- [ ] Create `Model` base class with CRUD operations
- [ ] Add relationship types: `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`
- [ ] Implement eager loading with `with()` method
- [ ] Add model events: `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`
- [ ] Support soft deletes via trait
- [ ] Add query scopes for reusable query logic

**Impact:** High - Most requested feature for rapid API development

---

### 1.2 Request Validation with Attributes

**Current State:** Validation exists but requires manual integration.

**Proposed Addition:**
```php
#[Post('/users')]
#[Validate([
    'email' => 'required|email|unique:users',
    'password' => 'required|min:8|password_strength',
    'name' => 'required|string|max:255'
])]
public function store(ValidatedRequest $request): Response
{
    // $request->validated() returns only validated data
    $user = User::create($request->validated());
    return Response::created($user);
}

// Or using Form Request classes
class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', new UniqueRule('users')],
            'password' => ['required', new PasswordStrength()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
        ];
    }
}
```

**Implementation Path:**
- [ ] Create `#[Validate]` attribute for inline validation
- [ ] Create `FormRequest` base class for complex validation
- [ ] Add automatic validation in middleware pipeline
- [ ] Return standardized validation error responses (422)
- [ ] Support custom validation rules via classes

**Impact:** High - Reduces boilerplate significantly

---

### 1.3 API Resource Transformers

**Current State:** Manual array transformation for responses.

**Proposed Addition:**
```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'name' => $this->name,
            'created_at' => $this->created_at->toIso8601String(),
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'links' => [
                'self' => route('users.show', $this->uuid),
            ],
        ];
    }
}

// Usage
return UserResource::make($user);
return UserResource::collection($users)->additional(['meta' => ['version' => '1.0']]);
```

**Implementation Path:**
- [ ] Create `JsonResource` base class
- [ ] Support conditional attributes with `when()`, `whenLoaded()`
- [ ] Add collection support with pagination metadata
- [ ] Support resource wrapping (`data` key)
- [ ] Add `additional()` for extra response data

**Impact:** High - Consistent API responses with less code

---

### 1.4 Exception Handler with HTTP Mapping ✅ IMPLEMENTED

**Current State:** ~~Basic exception handling without status code mapping.~~ **Fully implemented in v1.10.0**

**Implementation:**
```php
// Automatic exception to HTTP response mapping
use Glueful\Http\Exceptions\Handler;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Exceptions\Domain\ModelNotFoundException;

// Typed HTTP exceptions with automatic status codes
throw new NotFoundException('User not found');           // 404
throw new UnauthorizedException('Token required');       // 401
throw new TooManyRequestsException(retryAfter: 60);     // 429

// Model not found with context
throw (new ModelNotFoundException())->setModel(User::class, $id);

// Custom exception rendering via interface
class QuotaExceededException extends HttpException implements RenderableException
{
    public function render(?Request $request): Response
    {
        return new Response([
            'success' => false,
            'error' => ['code' => 'QUOTA_EXCEEDED', 'limit' => $this->limit],
        ], 429);
    }
}

// Middleware integration
$router->group(['middleware' => ['exception', 'auth']], function ($router) {
    // All routes have exception handling
});
```

**Implementation Path:**
- [x] Create `ExceptionHandlerInterface` contract
- [x] Create `RenderableException` interface for custom rendering
- [x] Create base `HttpException` class with status code and headers
- [x] Create Client exceptions (4xx): BadRequest, Unauthorized, Forbidden, NotFound, MethodNotAllowed, Conflict, UnprocessableEntity, TooManyRequests
- [x] Create Server exceptions (5xx): InternalServer, ServiceUnavailable, GatewayTimeout
- [x] Create Domain exceptions: ModelNotFound, Authentication, Authorization, TokenExpired
- [x] Create main `Handler` class with HTTP mapping, don't-report list, custom renderers
- [x] Add environment-aware error detail (hide in production)
- [x] Create `ExceptionMiddleware` for route integration
- [x] Register in `ExceptionProvider` service provider

**Location:** `src/Http/Exceptions/`, `src/Http/Middleware/ExceptionMiddleware.php`

**Impact:** Medium - Better error handling and debugging

---

## Priority 2: Developer Experience Improvements

### 2.1 Artisan-Style Make Commands

**Current State:** Limited generation commands.

**Proposed Addition:**
```bash
php glueful make:model User --migration --factory --resource
php glueful make:controller UserController --resource --api
php glueful make:request CreateUserRequest
php glueful make:resource UserResource
php glueful make:middleware RateLimitMiddleware
php glueful make:event UserCreated
php glueful make:listener SendWelcomeEmail
php glueful make:job ProcessPayment
php glueful make:rule UniqueEmail
php glueful make:test UserTest --unit
```

**Implementation Path:**
- [ ] Create stub templates for each component type
- [ ] Add `make:model` with `--migration`, `--factory`, `--resource` flags
- [ ] Add `make:controller` with `--resource`, `--api` flags
- [ ] Add `make:request` for form requests
- [ ] Add `make:resource` for API transformers
- [ ] Add `make:test` with `--unit`, `--feature` flags

**Impact:** High - Faster scaffolding

---

### 2.2 Database Factories & Seeders

**Current State:** No factory pattern for test data.

**Proposed Addition:**
```php
// Factory definition
class UserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'password' => Hash::make('password'),
            'status' => 'active',
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'admin']);
    }
}

// Usage in tests
$user = User::factory()->create();
$users = User::factory()->count(10)->create();
$admin = User::factory()->admin()->create();

// Seeder
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->count(50)->create();
        Post::factory()->count(200)->recycle(User::all())->create();
    }
}
```

**Implementation Path:**
- [ ] Create `Factory` base class with Faker integration
- [ ] Add `Seeder` base class with dependency resolution
- [ ] Add `php glueful db:seed` command
- [ ] Support factory states and relationships
- [ ] Add `recycle()` for reusing related models

**Impact:** High - Essential for testing

---

### 2.3 Interactive CLI Wizards

**Current State:** Commands require flags, no interactive mode.

**Proposed Addition:**
```bash
$ php glueful make:model

 What should the model be named?:
 > User

 Would you like to create a migration? (yes/no) [yes]:
 > yes

 Would you like to create a factory? (yes/no) [yes]:
 > yes

 Would you like to create a resource controller? (yes/no) [no]:
 > yes

Creating model: app/Models/User.php
Creating migration: database/migrations/2026_01_20_create_users_table.php
Creating factory: database/factories/UserFactory.php
Creating controller: app/Http/Controllers/UserController.php

All done!
```

**Implementation Path:**
- [ ] Add interactive prompts to make commands
- [ ] Support default values with `[default]` syntax
- [ ] Add confirmation prompts for destructive operations
- [ ] Add progress bars for long operations
- [ ] Support `--no-interaction` flag for CI

**Impact:** Medium - Better onboarding experience

---

### 2.4 Real-Time Development Server

**Current State:** Basic PHP built-in server.

**Proposed Addition:**
```bash
php glueful serve --port=8000 --watch

# Features:
# - Auto-reload on file changes
# - Request logging with colors
# - Performance timing per request
# - Queue worker integration
# - Scheduled task runner
```

**Implementation Path:**
- [ ] Add file watcher using `inotify` or polling
- [ ] Colorized request/response logging
- [ ] Show request duration and memory usage
- [ ] Integrate with queue worker in development
- [ ] Add `--open` flag to open browser

**Impact:** Medium - Better development workflow

---

## Priority 3: API-Specific Features

### 3.1 API Versioning Strategy

**Current State:** Basic URL versioning.

**Proposed Addition:**
```php
// Multiple versioning strategies
$router->apiVersion('v1', function (Router $router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});

$router->apiVersion('v2', function (Router $router) {
    $router->get('/users', [UserControllerV2::class, 'index']);
});

// Header-based versioning
// Accept: application/vnd.api+json; version=2

// Query parameter versioning
// GET /users?api_version=2

// Deprecation support
#[Deprecated(version: 'v1', sunset: '2026-06-01', replacement: '/v2/users')]
public function index(): Response { }
```

**Implementation Path:**
- [ ] Support URL prefix, header, and query versioning
- [ ] Add version negotiation middleware
- [ ] Support version deprecation with Sunset header
- [ ] Add version documentation in OpenAPI
- [ ] Version-specific rate limits

**Impact:** Medium - Important for API evolution

---

### 3.2 Webhooks System

**Current State:** No webhook support.

**Proposed Addition:**
```php
// Define webhooks
Webhook::subscribe('user.created', 'https://example.com/webhooks');

// Dispatch webhooks
Webhook::dispatch('user.created', [
    'user' => $user->toArray(),
    'timestamp' => now()->toIso8601String(),
]);

// Webhook configuration
class WebhookConfig
{
    public int $maxRetries = 5;
    public array $backoffMinutes = [1, 5, 30, 120, 720];
    public string $signatureHeader = 'X-Webhook-Signature';
    public string $signatureAlgorithm = 'sha256';
}

// Webhook delivery tracking
$delivery = WebhookDelivery::find($id);
$delivery->status; // 'pending', 'delivered', 'failed'
$delivery->attempts;
$delivery->response_code;
$delivery->next_retry_at;
```

**Implementation Path:**
- [ ] Create webhook subscription storage
- [ ] Add webhook dispatch queue job
- [ ] Implement retry with exponential backoff
- [ ] Add HMAC signature verification
- [ ] Create delivery tracking and logs
- [ ] Add webhook testing endpoint

**Impact:** Medium - Common API requirement

---

### 3.3 API Rate Limiting Enhancements

**Current State:** Good rate limiting but limited granularity.

**Proposed Addition:**
```php
// Per-route rate limits
#[RateLimit(attempts: 60, perMinutes: 1)]
#[RateLimit(attempts: 1000, perHours: 1)]
public function index(): Response { }

// Tiered rate limits by user plan
#[RateLimit(tier: 'free', attempts: 100, perDay: 1)]
#[RateLimit(tier: 'pro', attempts: 10000, perDay: 1)]
#[RateLimit(tier: 'enterprise', attempts: 'unlimited')]
public function search(): Response { }

// Rate limit headers
// X-RateLimit-Limit: 60
// X-RateLimit-Remaining: 45
// X-RateLimit-Reset: 1640000000
// Retry-After: 30 (when limited)

// Cost-based rate limiting
#[RateLimitCost(cost: 10)] // This endpoint costs 10 of your 1000 daily quota
public function expensiveOperation(): Response { }
```

**Implementation Path:**
- [ ] Add `#[RateLimit]` attribute for routes
- [ ] Support tiered limits by user attribute
- [ ] Add rate limit headers to responses
- [ ] Support cost-based rate limiting
- [ ] Add rate limit dashboard/metrics

**Impact:** Medium - Better API governance

---

### 3.4 Search & Filtering DSL

**Current State:** Basic field filtering.

**Proposed Addition:**
```php
// Query DSL
GET /users?filter[status]=active&filter[created_at][gte]=2026-01-01&sort=-created_at

// Advanced filtering
GET /posts?filter[title][contains]=PHP&filter[tags][any]=laravel,symfony

// Full-text search integration
GET /posts?search=modern+php+api&search_fields=title,body

// Filter configuration
class UserFilter extends QueryFilter
{
    public function status(string $value): void
    {
        $this->query->where('status', $value);
    }

    public function createdAfter(string $date): void
    {
        $this->query->where('created_at', '>=', $date);
    }

    #[Searchable]
    public array $searchableFields = ['name', 'email', 'bio'];
}

// Elasticsearch integration
class PostSearch extends SearchableModel
{
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'tags' => $this->tags->pluck('name'),
        ];
    }
}
```

**Implementation Path:**
- [ ] Create filter query string parser
- [ ] Add `QueryFilter` base class
- [ ] Support comparison operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `any`, `all`
- [ ] Add Elasticsearch/Meilisearch adapter
- [ ] Support sorting with `-field` for descending

**Impact:** High - Essential for data-heavy APIs

---

## Priority 4: Observability & Monitoring

> ⚠️ **Extension Recommendation:** These features should be implemented as optional extensions rather than core framework components. This keeps the core lean while allowing teams to opt-in to observability features they need.
>
> Suggested extension: `glueful/observability` or separate packages like `glueful/opentelemetry`, `glueful/prometheus`

### 4.1 Request Tracing (OpenTelemetry)

**Current State:** Basic logging only.
**Recommended:** Implement as `glueful/opentelemetry` extension.

**Proposed Addition:**
```php
// Automatic tracing
// Every request gets a trace ID propagated through all services

// Custom spans
$span = Tracer::start('process-payment');
try {
    $result = $this->paymentGateway->charge($amount);
    $span->setStatus(SpanStatus::OK);
} catch (Throwable $e) {
    $span->setStatus(SpanStatus::ERROR);
    $span->recordException($e);
    throw $e;
} finally {
    $span->end();
}

// Automatic instrumentation
// - Database queries with timing
// - HTTP client calls
// - Cache operations
// - Queue job processing
// - External API calls
```

**Implementation Path (as Extension):**
- [ ] Create `glueful/opentelemetry` Composer package
- [ ] Integrate OpenTelemetry PHP SDK
- [ ] Auto-instrument database, cache, HTTP client via service provider
- [ ] Add trace context propagation middleware
- [ ] Support Jaeger, Zipkin, Datadog exporters
- [ ] Add `#[Traced]` attribute for custom spans
- [ ] Provide configuration via `config/opentelemetry.php`

**Impact:** High - Essential for production debugging (opt-in)

---

### 4.2 Metrics Dashboard

**Current State:** Metrics collection exists but no visualization.
**Recommended:** Implement as `glueful/prometheus` extension.

**Proposed Addition:**
```php
// Prometheus metrics endpoint
GET /metrics

# HELP http_requests_total Total HTTP requests
# TYPE http_requests_total counter
http_requests_total{method="GET",path="/users",status="200"} 12345

# HELP http_request_duration_seconds HTTP request duration
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{le="0.1"} 9000
http_request_duration_seconds_bucket{le="0.5"} 11000

// Built-in metrics
- Request count by endpoint, method, status
- Request duration percentiles (p50, p95, p99)
- Database query count and duration
- Cache hit/miss ratio
- Queue job processing time
- Memory usage
- Active connections
```

**Implementation Path (as Extension):**
- [ ] Create `glueful/prometheus` Composer package
- [ ] Add Prometheus metrics exporter
- [ ] Register `/metrics` endpoint via service provider
- [ ] Auto-collect HTTP request metrics via middleware
- [ ] Add database query metrics via query listener
- [ ] Add cache metrics via cache event listeners
- [ ] Support custom metrics registration
- [ ] Provide Grafana dashboard templates

**Impact:** Medium - Important for operations (opt-in)

---

### 4.3 Health Check Enhancements

**Current State:** Basic health checks exist.
**Recommended:** Enhance in core framework (essential for container orchestration).

**Proposed Addition:**
```php
// Kubernetes-ready health checks
GET /health/live   -> Am I running? (liveness probe)
GET /health/ready  -> Can I serve traffic? (readiness probe)
GET /health/startup -> Have I finished starting? (startup probe)

// Detailed health with dependencies
GET /health/detailed
{
    "status": "healthy",
    "checks": {
        "database": {"status": "healthy", "latency_ms": 2},
        "redis": {"status": "healthy", "latency_ms": 1},
        "queue": {"status": "healthy", "pending_jobs": 45},
        "disk": {"status": "healthy", "free_gb": 120},
        "memory": {"status": "warning", "used_percent": 85}
    },
    "version": "1.9.2",
    "uptime_seconds": 86400
}
```

**Implementation Path (Core Enhancement):**
- [ ] Add `/health/live`, `/health/ready`, `/health/startup` endpoints
- [ ] Support custom health checks via `HealthCheckInterface`
- [ ] Add dependency health aggregation
- [ ] Support health check timeouts
- [ ] Add degraded state support
- [ ] Kubernetes/Docker-ready out of the box

**Impact:** Medium - Essential for container orchestration (core feature)

---

## Priority 5: Security Enhancements

> ⚠️ **Extension Recommendation:** Advanced authentication features (MFA, OAuth2 Server) should be implemented as optional extensions. Basic security (CSRF, rate limiting, input validation) remains in core.
>
> Suggested extensions: `glueful/mfa`, `glueful/oauth2-server` (already started at `extensions/OAuthServer`)

### 5.1 Multi-Factor Authentication

**Current State:** No MFA support.
**Recommended:** Implement as `glueful/mfa` extension.

**Proposed Addition:**
```php
// TOTP (Google Authenticator, Authy)
$secret = MFA::generateSecret();
$qrCode = MFA::qrCodeUrl($user->email, $secret);

// Verify TOTP code
if (MFA::verify($secret, $request->input('code'))) {
    $user->enableMFA($secret);
}

// Recovery codes
$recoveryCodes = MFA::generateRecoveryCodes(8);

// WebAuthn (hardware keys, biometrics)
$options = WebAuthn::createOptions($user);
$credential = WebAuthn::verify($request->input('credential'));
```

**Implementation Path (as Extension):**
- [ ] Create `glueful/mfa` Composer package
- [ ] Add TOTP implementation (RFC 6238)
- [ ] Generate QR codes for authenticator apps
- [ ] Add recovery codes system
- [ ] Support WebAuthn for passwordless
- [ ] Add MFA enforcement middleware
- [ ] Provide database migrations for MFA tables
- [ ] Include example controllers and views

**Impact:** High - Security requirement for many APIs (opt-in)

---

### 5.2 OAuth2 Server

**Current State:** JWT authentication only. Social login available via `glueful/entrada`.
**Recommended:** Implement as dedicated OAuth2 authorization server extension (for APIs that need to BE an OAuth provider).

**Proposed Addition:**
```php
// OAuth2 authorization server
// Support grant types:
// - Authorization Code (web apps)
// - Client Credentials (machine-to-machine)
// - Refresh Token
// - PKCE (mobile/SPA)

// Client registration
$client = OAuthClient::create([
    'name' => 'Mobile App',
    'redirect_uris' => ['myapp://callback'],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'scopes' => ['read:users', 'write:posts'],
]);

// Scope-based authorization
#[RequireScope('write:posts')]
public function store(): Response { }
```

**Implementation Path (as Extension):**
> **Note:** `glueful/entrada` handles OAuth CLIENT flows (login with Google, etc.). This is for OAuth SERVER functionality (your API becomes an OAuth provider).

- [ ] Create `glueful/oauth2-server` Composer package
- [ ] Implement OAuth2 authorization server (using League OAuth2 Server)
- [ ] Support Authorization Code + PKCE flow
- [ ] Support Client Credentials flow
- [ ] Add scope management
- [ ] Create token introspection endpoint
- [ ] Provide database migrations for OAuth tables
- [ ] Add management CLI commands (`oauth:client:create`, etc.)

**Impact:** High - Required when your API needs to authorize third-party apps (opt-in)

---

### 5.3 API Key Management

**Current State:** Basic API key support.
**Recommended:** Enhance in core framework (not an extension - builds on existing functionality).

**Proposed Addition:**
```php
// API key with scopes and expiration
$apiKey = ApiKey::create([
    'name' => 'Production Key',
    'scopes' => ['read:*', 'write:posts'],
    'expires_at' => now()->addYear(),
    'rate_limit' => 10000,
    'allowed_ips' => ['192.168.1.0/24'],
]);

// Key rotation
$newKey = $apiKey->rotate(gracePeriodHours: 24);

// Usage tracking
$apiKey->usage; // Last 30 days usage stats
$apiKey->lastUsedAt;

// Key prefix for identification
// gf_live_a1b2c3d4e5f6... (production)
// gf_test_x9y8z7w6v5u4... (test)
```

**Implementation Path (Core Enhancement):**
- [ ] Add API key scopes
- [ ] Add IP allowlist per key
- [ ] Add key expiration
- [ ] Add usage tracking
- [ ] Add key rotation with grace period
- [ ] Add key prefix for environment identification

**Impact:** Medium - Better API key management (core feature)

---

## Priority 6: Performance Optimizations

### 6.1 Response Caching

**Current State:** Manual caching required.

**Proposed Addition:**
```php
// Automatic response caching
#[CacheResponse(ttl: 3600, tags: ['users'])]
public function index(): Response { }

// Conditional caching with ETag
#[ETag]
public function show(string $uuid): Response
{
    $user = User::find($uuid);
    return Response::ok($user); // ETag auto-generated from content
}

// Cache invalidation
Cache::tags(['users'])->flush();

// Stale-while-revalidate
#[CacheResponse(ttl: 60, staleWhileRevalidate: 300)]
public function stats(): Response { }
```

**Implementation Path:**
- [ ] Add `#[CacheResponse]` attribute
- [ ] Implement ETag generation and validation
- [ ] Support `If-None-Match` header
- [ ] Add stale-while-revalidate support
- [ ] Integrate with CDN cache purging

**Impact:** High - Major performance improvement

---

### 6.2 Database Query Optimization

**Current State:** Query builder with basic optimization.

**Proposed Addition:**
```php
// Automatic N+1 detection
// In development, throws exception or logs warning when N+1 detected

// Query analysis
$query->explain(); // Returns query execution plan

// Index suggestions
php glueful db:optimize
// Analyzes slow query log and suggests indexes

// Read/Write splitting
// Automatically route reads to replicas
$users = User::onReplica()->where('status', 'active')->get();

// Query result caching
$users = User::query()
    ->where('status', 'active')
    ->cache(ttl: 3600, tags: ['users'])
    ->get();
```

**Implementation Path:**
- [ ] Add N+1 query detection in development
- [ ] Add query explain support
- [ ] Create index suggestion command
- [ ] Support read/write connection splitting
- [ ] Add query-level result caching

**Impact:** High - Database is often the bottleneck

---

### 6.3 Async Operations

**Current State:** Fiber-based async exists.

**Proposed Addition:**
```php
// Parallel API calls
$results = Async::parallel([
    'users' => fn() => $this->userService->getAll(),
    'posts' => fn() => $this->postService->getRecent(),
    'stats' => fn() => $this->statsService->getDashboard(),
]);

// Async with timeout
$result = Async::timeout(5000, fn() => $this->slowService->process());

// Deferred responses (long polling)
return Response::deferred(function (DeferredResponse $response) {
    while (!$this->hasUpdate()) {
        Async::sleep(1000);
    }
    $response->send($this->getUpdate());
});

// Server-Sent Events
return Response::stream(function () {
    while (true) {
        yield "data: " . json_encode($this->getEvent()) . "\n\n";
        Async::sleep(1000);
    }
});
```

**Implementation Path:**
- [ ] Add `Async::parallel()` for concurrent operations
- [ ] Add timeout support
- [ ] Add SSE response helper
- [ ] Add WebSocket support
- [ ] Add long-polling helpers

**Impact:** Medium - Better handling of slow operations

---

## Implementation Timeline

### Core Framework

#### Phase 1 (Q1 2026) - Foundation
- [ ] ORM / Active Record Layer
- [ ] Request Validation with Attributes
- [ ] API Resource Transformers
- [x] Exception Handler with HTTP Mapping ✅ (v1.10.0)
- [ ] Database Factories & Seeders

#### Phase 2 (Q2 2026) - Developer Experience
- [ ] Make Commands (model, controller, etc.)
- [ ] Interactive CLI Wizards
- [ ] Real-Time Development Server
- [ ] Search & Filtering DSL

#### Phase 3 (Q3 2026) - API Features
- [ ] API Versioning Strategy
- [ ] Webhooks System
- [ ] Rate Limiting Enhancements
- [ ] Response Caching

### Official Extensions

#### Already Published ✅
- [x] `glueful/aegis` - Role-Based Access Control (RBAC)
- [x] `glueful/email-notification` - Email notifications via Symfony Mailer
- [x] `glueful/entrada` - Social Login & SSO (OAuth/OIDC)
- [x] `glueful/notiva` - Push notifications (FCM, APNs, Web Push)
- [x] `glueful/payvia` - Payment gateways (Stripe, Paystack, Flutterwave)
- [x] `glueful/runiva` - Server runtimes (RoadRunner, Swoole, FrankenPHP)

#### Planned Extensions
- [ ] `glueful/oauth2-server` - OAuth2 authorization server (BE an OAuth provider)
- [ ] `glueful/mfa` - TOTP, WebAuthn, recovery codes
- [ ] `glueful/opentelemetry` - Request tracing, distributed tracing
- [ ] `glueful/prometheus` - Metrics export, Grafana dashboards
- [ ] `glueful/elasticsearch` - Elasticsearch adapter
- [ ] `glueful/meilisearch` - Meilisearch adapter

---

## Conclusion

These improvements would position Glueful as a comprehensive framework for building production-grade REST APIs, competing directly with Laravel for rapid development while maintaining superior performance characteristics.

### Design Principles

1. **Lean Core** - Keep the core framework focused on essential API functionality
2. **Rich Ecosystem** - Advanced features as opt-in extensions via Composer
3. **Developer Productivity** - ORM, validation, transformers in core
4. **API Best Practices** - Versioning, webhooks, rate limiting in core
5. **Production Ready** - Observability, advanced security as extensions
6. **Modern Standards** - OpenAPI 3.1, PSR compliance throughout

### Extension Architecture Benefits

- **Smaller attack surface** - Only load what you need
- **Faster startup** - Core remains lightweight
- **Independent versioning** - Extensions can evolve at their own pace
- **Community contributions** - Clear extension API for third-party packages
- **Testing isolation** - Extensions tested independently

### Proven Ecosystem

With 6 official extensions already published (Aegis, Email Notification, Entrada, Notiva, Payvia, Runiva), the extension architecture is validated and production-ready. This ecosystem covers:
- **Authentication** - Social login, SSO (Entrada)
- **Authorization** - RBAC (Aegis)
- **Notifications** - Email, push (Email Notification, Notiva)
- **Payments** - Multi-gateway support (Payvia)
- **Performance** - High-performance runtimes (Runiva)

Each feature builds on Glueful's existing strengths while addressing gaps identified through competitive analysis with Laravel, Symfony, and modern API frameworks. The extension model follows successful patterns from ecosystems like Symfony bundles and Laravel packages.
