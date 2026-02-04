# Glueful Framework - Potential Improvements & Additions

> A comprehensive roadmap for making Glueful a leading PHP framework for building fast, efficient modern APIs.

## Executive Summary

Glueful Framework is already well-architected with strong foundations in routing (O(1) lookups), caching (distributed with failover), queuing (auto-scaling), and security. This document outlines strategic improvements to make it competitive with Laravel, Symfony, and API Platform for production REST API development.

> **Status Update (February 2026):** Phases 1-3 are now **COMPLETE**. The framework includes ORM with relationships, request validation with attributes, API resource transformers, comprehensive scaffold commands, real-time development server, GraphQL-style field selection, API versioning, webhooks system, and enhanced rate limiting.

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

### 1.1 ORM / Active Record Layer ✅ IMPLEMENTED

**Current State:** ~~Query builder only, no model hydration or relationships.~~ **Fully implemented.**

**Implementation:**
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
- [x] Create `Model` base class with CRUD operations
- [x] Add relationship types: `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`
- [x] Implement eager loading with `with()` method
- [x] Add model events: `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`
- [x] Support soft deletes via trait
- [x] Add query scopes for reusable query logic

**Location:** `src/Database/Model/`

**Impact:** High - Most requested feature for rapid API development

---

### 1.2 Request Validation with Attributes ✅ IMPLEMENTED

**Current State:** ~~Validation exists but requires manual integration.~~ **Fully implemented with attributes and FormRequest classes.**

**Implementation:**
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
- [x] Create `#[Validate]` attribute for inline validation
- [x] Create `FormRequest` base class for complex validation
- [x] Add automatic validation in middleware pipeline
- [x] Return standardized validation error responses (422)
- [x] Support custom validation rules via classes (`php glueful scaffold:rule`)

**Location:** `src/Validation/`, `src/Http/FormRequest.php`

**Impact:** High - Reduces boilerplate significantly

---

### 1.3 API Resource Transformers ✅ IMPLEMENTED

**Current State:** ~~Manual array transformation for responses.~~ **Fully implemented with `JsonResource`, `ModelResource`, and `ResourceCollection`.**

**Implementation:**
```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['uuid'],
            'email' => $this->resource['email'],
            'name' => $this->resource['name'],
            'posts' => $this->whenLoaded('posts'),
        ];
    }
}

// Usage - single resource
return UserResource::make($user)->toResponse();

// Usage - collection with pagination
return UserResource::collection($result['data'])
    ->withPaginationFrom($result)
    ->withLinks('/api/users')
    ->toResponse();
```

**Implementation Path:**
- [x] Create `JsonResource` base class
- [x] Support conditional attributes with `when()`, `whenLoaded()`, `whenCounted()`, `mergeWhen()`
- [x] Add collection support with pagination metadata (`ResourceCollection`, `PaginatedResourceResponse`)
- [x] Support resource wrapping (`data` key)
- [x] Add `additional()` for extra response data
- [x] Add `ModelResource` for ORM-aware resources
- [x] Scaffold command: `php glueful scaffold:resource`

**Location:** `src/Http/Resources/`

**Documentation:** See `docs/RESOURCES.md` for complete documentation.

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

### 2.1 Scaffold Commands ✅ IMPLEMENTED

**Current State:** ~~Limited generation commands.~~ **Comprehensive scaffold commands available.**

**Implementation:**
```bash
# Model and Controller scaffolding
php glueful scaffold:model User --fillable=name,email --migration
php glueful scaffold:controller UserController --resource
php glueful scaffold:request CreateUserRequest
php glueful scaffold:resource UserResource --model

# Middleware and Filter scaffolding
php glueful scaffold:middleware RateLimitMiddleware
php glueful scaffold:middleware Admin/AuthMiddleware  # Nested namespace
php glueful scaffold:filter UserFilter

# Queue job scaffolding
php glueful scaffold:job ProcessPayment
php glueful scaffold:job SendNewsletter --queue=emails --tries=5 --backoff=120

# Event scaffolding
php glueful event:create UserRegistered
php glueful event:listener SendWelcomeEmail --event=App\\Events\\UserRegisteredEvent

# Validation rule scaffolding
php glueful scaffold:rule UniqueEmail
php glueful scaffold:rule PasswordStrength --params=minLength,requireNumbers

# Test scaffolding
php glueful scaffold:test UserServiceTest              # Unit test (default)
php glueful scaffold:test UserApiTest --feature        # Feature test
php glueful scaffold:test PaymentTest --methods=testCharge,testRefund
```

**Implementation Path:**
- [x] Create stub templates for each component type
- [x] Add `scaffold:model` with `--migration`, `--fillable` flags
- [x] Add `scaffold:controller` with `--resource` flag
- [x] Add `scaffold:request` for form requests
- [x] Add `scaffold:resource` for API transformers (with `--model`, `--collection`)
- [x] Add `scaffold:middleware` with nested namespace support
- [x] Add `scaffold:filter` for query filters
- [x] Add `scaffold:job` with queue options
- [x] Add `event:create` and `event:listener`
- [x] Add `scaffold:rule` for validation rules
- [x] Add `scaffold:test` with `--unit`, `--feature` flags

**Location:** `src/Console/Commands/Scaffold/`, `src/Console/Commands/Event/`

**Impact:** High - Faster scaffolding

---

### 2.2 Database Factories & Seeders ✅ IMPLEMENTED

**Current State:** ~~No factory pattern for test data.~~ **Fully implemented with Factory and Seeder classes.**

**Implementation:**
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
- [x] Create `Factory` base class with Faker integration
- [x] Add `Seeder` base class with dependency resolution
- [x] Add `php glueful db:seed` command
- [x] Support factory states and relationships
- [x] Add `recycle()` for reusing related models
- [x] Scaffold commands: `php glueful scaffold:factory`, `php glueful scaffold:seeder`

**Location:** `src/Database/Factory/`, `src/Database/Seeder/`

**Impact:** High - Essential for testing

---

### 2.3 Interactive CLI Wizards ✅ IMPLEMENTED

**Current State:** ~~Commands require flags, no interactive mode.~~ **Interactive prompts available in scaffold commands.**

**Implementation:**
```bash
$ php glueful scaffold:model

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
- [x] Add interactive prompts to scaffold commands
- [x] Support default values with `[default]` syntax
- [x] Add confirmation prompts for destructive operations
- [x] Add progress bars for long operations
- [x] Support `--no-interaction` flag for CI

**Impact:** Medium - Better onboarding experience

---

### 2.4 Real-Time Development Server ✅ IMPLEMENTED

**Current State:** ~~Basic PHP built-in server.~~ **Enhanced development server with file watching and developer tools.**

**Implementation:**
```bash
# Development server with watch mode
php glueful serve --port=8000 --watch

# Quick development server (watch enabled by default)
php glueful dev:server

# Test watcher - runs tests on file changes
php glueful test:watch --command="composer test" --interval=500

# Developer diagnostic tools
php glueful doctor              # Quick local health checks
php glueful env:sync            # Sync .env.example from config
php glueful route:debug         # Dump resolved routes
php glueful cache:inspect       # Inspect cache driver + extensions

# Features:
# - Auto-reload on file changes (FileWatcher)
# - Request logging with colors
# - Performance timing per request
# - Queue worker integration (--queue flag)
# - Browser auto-open (--open flag)
```

**Implementation Path:**
- [x] Add file watcher using polling (`FileWatcher` class)
- [x] Colorized request/response logging
- [x] Show request duration and memory usage
- [x] Integrate with queue worker in development (`--queue` flag)
- [x] Add `--open` flag to open browser
- [x] Add `dev:server` wrapper command
- [x] Add `test:watch` for TDD workflow
- [x] Add developer diagnostic tools (`doctor`, `env:sync`, `route:debug`, `cache:inspect`)

**Location:** `src/Console/Commands/ServeCommand.php`, `src/Console/Commands/Dev/`, `src/Console/Commands/Test/`, `src/Console/Commands/System/`, `src/Console/Commands/Route/`, `src/Development/Watcher/`

**Impact:** Medium - Better development workflow

---

## Priority 3: API-Specific Features

### 3.1 API Versioning Strategy ✅ IMPLEMENTED

**Current State:** ~~Basic URL versioning.~~ **Flexible API URL patterns with versioning support.**

**Implementation:**
```php
// Multiple versioning strategies via configuration
// Pattern A: Subdomain (api.example.com/v1/users)
// Pattern B: Path prefix (example.com/api/v1/users)

// Environment configuration
API_USE_PREFIX=true
API_PREFIX=/api
API_VERSION_IN_PATH=true

// In routes/api.php
$router->group(['prefix' => api_prefix($context)], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});

// Helper functions
api_prefix($context)           // Returns route prefix (e.g., /api/v1)
api_url($context, '/path')     // Returns full URL
is_api_path($context, $path)   // Checks if path matches API prefix

// Version management CLI
php glueful api:version:list       # List API versions
php glueful api:version:deprecate  # Deprecate a version
```

**Implementation Path:**
- [x] Support URL prefix and subdomain versioning
- [x] Add version configuration via environment
- [x] Support version deprecation with CLI commands
- [x] Add version documentation in OpenAPI
- [x] Helper functions for API URL generation

**Location:** `src/Http/`, `src/Console/Commands/Api/`

**Documentation:** See `docs/API_URLS.md` for complete documentation.

**Impact:** Medium - Important for API evolution

---

### 3.2 Webhooks System ✅ IMPLEMENTED

**Current State:** ~~No webhook support.~~ **Fully implemented webhook system with delivery tracking and retries.**

**Implementation:**
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

// CLI commands
php glueful webhook:list    # List configured webhooks
php glueful webhook:test    # Test webhook endpoint
php glueful webhook:retry   # Retry failed deliveries
```

**Implementation Path:**
- [x] Create webhook subscription storage
- [x] Add webhook dispatch queue job
- [x] Implement retry with exponential backoff
- [x] Add HMAC signature verification
- [x] Create delivery tracking and logs
- [x] Add webhook testing endpoint
- [x] CLI commands for management

**Location:** `src/Webhook/`, `src/Console/Commands/Webhook/`

**Impact:** Medium - Common API requirement

---

### 3.3 API Rate Limiting Enhancements ✅ IMPLEMENTED

**Current State:** ~~Good rate limiting but limited granularity.~~ **Enhanced rate limiting with attributes and tiered support.**

**Implementation:**
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

// Rate limit headers automatically added
// X-RateLimit-Limit: 60
// X-RateLimit-Remaining: 45
// X-RateLimit-Reset: 1640000000
// Retry-After: 30 (when limited)

// Cost-based rate limiting
#[RateLimitCost(cost: 10)] // This endpoint costs 10 of your 1000 daily quota
public function expensiveOperation(): Response { }
```

**Implementation Path:**
- [x] Add `#[RateLimit]` attribute for routes
- [x] Support tiered limits by user attribute
- [x] Add rate limit headers to responses
- [x] Support cost-based rate limiting
- [x] Adaptive rate limiting algorithms

**Location:** `src/Security/RateLimiting/`, `src/Routing/Attributes/`

**Impact:** Medium - Better API governance

---

### 3.4 Search & Filtering DSL ✅ IMPLEMENTED

**Current State:** ~~Basic field filtering.~~ **GraphQL-style field selection with REST API enhancement.**

**Implementation:**
```php
// Dual syntax support - REST-style
GET /users?fields=id,name,email&expand=posts.comments

// GraphQL-style nested syntax
GET /users?fields=user(id,name,posts(title,comments(text)))

// Wildcard with expand
GET /users?fields=*&expand=posts.comments

// Filter configuration with scaffold command
php glueful scaffold:filter UserFilter

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
}

// Route-level field restrictions with attributes
#[Get('/users/{id}')]
#[Fields(allowed: ['id', 'name', 'email', 'posts', 'posts.comments'], strict: true)]
public function getUser(int $id, FieldSelector $selector): array
{
    $user = $this->userRepo->findAsArray($id);

    if ($selector->requested('posts')) {
        $user['posts'] = $this->userRepo->findPostsForUser($id);
    }

    return $user; // Middleware applies field projection
}
```

**Implementation Path:**
- [x] Create field selection query parser (`FieldSelector`)
- [x] Add `QueryFilter` base class with scaffold command
- [x] Support GraphQL-style nested field syntax
- [x] Add `#[Fields]` attribute for route-level whitelisting
- [x] Add `Projector` for efficient data projection
- [x] Add `FieldSelectionMiddleware` for automatic processing
- [x] N+1 prevention with expander system
- [x] Configurable depth limits, field counts, item limits

**Location:** `src/Support/FieldSelection/`, `src/Routing/Middleware/FieldSelectionMiddleware.php`

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

### 6.1 Response Caching ✅ IMPLEMENTED

**Current State:** ~~Manual caching required.~~ **Automatic response caching with attributes.**

**Implementation:**
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
- [x] Add `#[CacheResponse]` attribute
- [x] Implement ETag generation and validation
- [x] Support `If-None-Match` header
- [x] Add stale-while-revalidate support
- [x] Integrate with CDN cache purging

**Location:** `src/Cache/`, `src/Routing/Attributes/`

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

#### Phase 1 (Q1 2026) - Foundation ✅ COMPLETE
- [x] ORM / Active Record Layer ✅
- [x] Request Validation with Attributes ✅
- [x] API Resource Transformers ✅ (`JsonResource`, `ModelResource`, `ResourceCollection`)
- [x] Exception Handler with HTTP Mapping ✅ (v1.10.0)
- [x] Database Factories & Seeders ✅

#### Phase 2 (Q2 2026) - Developer Experience ✅ COMPLETE
- [x] Scaffold Commands ✅ (`scaffold:model`, `scaffold:controller`, `scaffold:request`, `scaffold:resource`, `scaffold:middleware`, `scaffold:filter`, `scaffold:job`, `scaffold:rule`, `scaffold:test`, `event:create`, `event:listener`)
- [x] Interactive CLI Wizards ✅
- [x] Real-Time Development Server ✅ (`serve --watch`, `dev:server`, `test:watch`)
- [x] Search & Filtering DSL ✅ (GraphQL-style field selection with `FieldSelector`, `Projector`, `#[Fields]` attribute)

#### Phase 3 (Q3 2026) - API Features ✅ COMPLETE
- [x] API Versioning Strategy ✅ (URL prefix, subdomain, path-based via `api_prefix()`)
- [x] Webhooks System ✅ (`WebhookRetryCommand`, `WebhookTestCommand`, `WebhookListCommand`)
- [x] Rate Limiting Enhancements ✅
- [x] Response Caching ✅
- [x] Developer Tools ✅ (`doctor`, `env:sync`, `route:debug`, `cache:inspect`)

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

With Phases 1-3 complete, Glueful is now a comprehensive framework for building production-grade REST APIs, competing directly with Laravel for rapid development while maintaining superior performance characteristics.

### Completed Milestones

| Phase | Focus | Status |
|-------|-------|--------|
| Phase 1 | Foundation (ORM, Validation, Resources, Exceptions) | ✅ Complete |
| Phase 2 | Developer Experience (Scaffold, CLI, Dev Server) | ✅ Complete |
| Phase 3 | API Features (Versioning, Webhooks, Rate Limiting, Caching) | ✅ Complete |
| Phase 4+ | Observability & Advanced Security | Planned as Extensions |

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
