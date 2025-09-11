# Phase Two Implementation Overview

Based on the Enterprise Readiness Roadmap, **Phase 2 (Weeks 3-4)** focuses on **Observability Hardening** with four main components:

## 🏥 **1. Health Endpoints Hardening**

**Goal**: Secure and standardize health monitoring
- **Liveness endpoint**: Simple `/healthz` for basic "is alive" checks
- **Readiness endpoint**: `/ready` with detailed dependency checks  
- **Security**: IP allowlist and/or authentication for detailed endpoints
- **Rate limiting**: Prevent health endpoint abuse

**Implementation would include**:
- New `AllowIpMiddleware` for IP-based access control
- Enhanced health routes with different security levels
- Configuration in `config/security.php` for allowlists

### Enhanced Health Routes

**File**: `routes/health.php` (append)
```php
// Simple liveness check - no auth required
$router->get('/healthz', fn() => new \Glueful\Http\Response(['status' => 'ok']))
       ->middleware('rate_limit:60,60');

// Detailed readiness check - protected
$router->get('/ready', [\Glueful\Controllers\HealthController::class, 'readiness'])
       ->middleware(['rate_limit:30,60', 'allow_ip']);
```

### AllowIpMiddleware Implementation

**File**: `src/Routing/Middleware/AllowIpMiddleware.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;

class AllowIpMiddleware implements \Glueful\Routing\RouteMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $allow = (array) config('security.health_ip_allowlist', []);
        $ip = $request->getClientIp();
        
        if ($allow && !in_array($ip, $allow, true)) {
            return Response::forbidden('Health endpoint restricted');
        }
        
        return $next($request);
    }
}
```

### Security Configuration

**File**: `config/security.php` (additions)
```php
return [
    // ... existing config
    
    'health_ip_allowlist' => explode(',', env('HEALTH_IP_ALLOWLIST', '')),
    'health_auth_required' => env('HEALTH_AUTH_REQUIRED', false),
    
    // ... rest of config
];
```

## 📊 **2. Logging Processors Standardization** 

**Goal**: Consistent structured logging across all channels
- **Standardized fields**: request_id, user_id, environment, version
- **Log shipping**: Documentation for centralized log aggregation
- **Framework integration**: Ensure all core components use standard processor

**Implementation would include**:
- Enhanced `StandardLogProcessor` integration
- Service provider updates to attach processors to all channels
- Documentation for production log shipping setup

### Service Provider Integration

**File**: `src/DI/ServiceProviders/CoreServiceProvider.php` (✅ **Implemented**)
```php
// Enhanced user resolution with multiple authentication sources
$userIdResolver = function (): ?string {
    try {
        // Priority 1: Request context (middleware-set user)
        if (isset($GLOBALS['container'])) {
            $container = $GLOBALS['container'];
            if ($container->has('request')) {
                $request = $container->get('request');
                if ($request && $request->attributes->has('user')) {
                    $user = $request->attributes->get('user');
                    if (is_object($user)) {
                        // Try multiple user ID methods
                        foreach (['getId', 'id', 'getUuid', 'uuid'] as $method) {
                            if (method_exists($user, $method)) {
                                $id = $user->$method();
                                if (is_scalar($id)) {
                                    return (string) $id;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Priority 2: AuthenticationService current user
        if (function_exists('has_service') && has_service(\Glueful\Auth\AuthenticationService::class)) {
            $authService = app(\Glueful\Auth\AuthenticationService::class);
            if (method_exists($authService, 'getCurrentUser')) {
                $user = $authService->getCurrentUser();
                if ($user && method_exists($user, 'getId')) {
                    return (string) $user->getId();
                }
            }
        }

        // Priority 3: JWT token user (for API authentication)
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
                if (function_exists('has_service') && has_service(\Glueful\Auth\TokenManager::class)) {
                    $tokenManager = app(\Glueful\Auth\TokenManager::class);
                    if (method_exists($tokenManager, 'validateToken')) {
                        $payload = $tokenManager->validateToken($token);
                        if (is_array($payload) && isset($payload['sub'])) {
                            return (string) $payload['sub'];
                        }
                        if (is_array($payload) && isset($payload['user_id'])) {
                            return (string) $payload['user_id'];
                        }
                    }
                }
            }
        }

        // Priority 4: Session fallback (existing + enhanced)
        if (isset($_SESSION['user_uuid']) && is_string($_SESSION['user_uuid'])) {
            return $_SESSION['user_uuid'];
        }
        if (isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }

        // Priority 5: Laravel-style auth() helper (future compatibility)
        if (function_exists('auth')) {
            $auth = auth();
            if ($auth && $auth->check()) {
                $id = $auth->id();
                return is_scalar($id) ? (string) $id : null;
            }
        }

    } catch (\Throwable) {
        // Fail silently to prevent logging system disruption
    }
    
    return null;
};

$logger->pushProcessor(new \Glueful\Logging\StandardLogProcessor($env, $version, $userIdResolver));
```

### Laravel-Style Authentication Helper

**File**: `src/helpers.php` (✅ **Implemented**)
```php
if (!function_exists('auth')) {
    /**
     * Get authentication guard instance
     * 
     * Provides Laravel-style auth() helper for consistent authentication access.
     * Returns a wrapper around Glueful's authentication system.
     *
     * @param string|null $guard Guard name (currently unused, for future multi-guard support)
     * @return \Glueful\Auth\AuthenticationGuard|null
     */
    function auth(?string $guard = null): ?\Glueful\Auth\AuthenticationGuard
    {
        try {
            if (has_service(\Glueful\Auth\AuthenticationGuard::class)) {
                return app(\Glueful\Auth\AuthenticationGuard::class);
            }
            
            // Fallback: create guard from existing services
            if (has_service(\Glueful\Auth\AuthenticationService::class)) {
                return new \Glueful\Auth\AuthenticationGuard(
                    app(\Glueful\Auth\AuthenticationService::class)
                );
            }
        } catch (\Throwable) {
            // Ignore errors in auth helper
        }
        
        return null;
    }
}
```

### Authentication Guard Wrapper

**File**: `src/Auth/AuthenticationGuard.php` (✅ **Implemented**)
```php
<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Laravel-style authentication guard wrapper
 * 
 * Provides familiar auth() interface while using Glueful's authentication system.
 * Enables consistent user access patterns across the framework.
 */
class AuthenticationGuard
{
    private ?object $currentUser = null;
    private bool $userResolved = false;

    public function __construct(
        private AuthenticationService $authService
    ) {}

    /**
     * Check if user is authenticated
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get current authenticated user
     */
    public function user(): ?object
    {
        if (!$this->userResolved) {
            $this->currentUser = $this->resolveUser();
            $this->userResolved = true;
        }
        
        return $this->currentUser;
    }

    /**
     * Get current user ID
     */
    public function id(): mixed
    {
        $user = $this->user();
        if (!$user) {
            return null;
        }

        // Try multiple ID methods
        foreach (['getId', 'id', 'getUuid', 'uuid'] as $method) {
            if (method_exists($user, $method)) {
                return $user->$method();
            }
        }

        return null;
    }

    /**
     * Check if user is guest (not authenticated)
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    // ... implementation details
}
```

### Standardized Log Fields

The enhanced `StandardLogProcessor` now supports **comprehensive user tracking** across all authentication methods:

- ✅ `environment`: Current environment (dev/staging/prod)
- ✅ `version`: Application version
- ✅ `request_id`: Unique request identifier
- ✅ **`user_id`**: Current authenticated user (**ALL auth types supported**)
- ✅ `timestamp`: ISO 8601 formatted timestamp
- ✅ `memory_usage`: Current memory usage
- ✅ `process_id`: Current process ID

### Authentication Support Matrix

| Authentication Type | User ID Source | Status |
|---|---|---|
| **Middleware Auth** | `$request->attributes->get('user')` | ✅ **Priority 1** |
| **Service Auth** | `AuthenticationService::getCurrentUser()` | ✅ **Priority 2** |
| **JWT/API Auth** | `Bearer` token payload (`sub`, `user_id`) | ✅ **Priority 3** |
| **Session Auth** | `$_SESSION['user_uuid/user_id']` | ✅ **Priority 4** |
| **Laravel Auth** | `auth()->id()` helper | ✅ **Priority 5** |

## 📈 **3. Metrics Integration**

**Goal**: Request-level metrics collection and export
- **MetricsMiddleware**: Automatic duration/status tracking per request
- **Async recording**: Queue metrics to avoid response latency impact
- **Aggregate export**: Admin endpoints or CLI commands for metrics
- **KPI tracking**: Response time, error rates, throughput per route

**Implementation would include**:
- New `MetricsMiddleware` using existing `ApiMetricsService`
- Protected admin endpoints for metrics exposure
- Integration with existing route groups via middleware

### MetricsMiddleware Implementation

**File**: `src/Routing/Middleware/MetricsMiddleware.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Services\ApiMetricsService;
use Symfony\Component\HttpFoundation\Request;

final class MetricsMiddleware implements \Glueful\Routing\RouteMiddleware
{
    public function __construct(private ApiMetricsService $metrics) {}

    public function handle(Request $request, callable $next): mixed
    {
        $t = microtime(true);
        $resp = $next($request);
        $dt = (microtime(true) - $t) * 1000;

        $this->metrics->recordMetricAsync([
            'endpoint' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'response_time' => (int) round($dt),
            'status_code' => method_exists($resp, 'getStatusCode') ? $resp->getStatusCode() : 200,
            'ip' => $request->getClientIp(),
            'timestamp' => time(),
        ]);

        return $resp;
    }
}
```

### Route Integration

**File**: `routes/resource.php` (example usage)
```php
// Apply metrics collection to API routes
$router->group(['middleware' => ['metrics']], function(\Glueful\Routing\Router $router) {
    // ... existing routes will now be automatically tracked
});
```

### Protected Metrics Endpoints

**File**: `routes/admin.php` (example)
```php
// Protected admin endpoints for metrics export
$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'allow_ip']], function($router) {
    $router->get('/metrics', [AdminMetricsController::class, 'index'])
           ->middleware('rate_limit:10,60'); // 10 req/min
    
    $router->get('/metrics/export', [AdminMetricsController::class, 'export'])
           ->middleware('rate_limit:5,60');
});
```

## 🔍 **4. Tracing Hooks (Vendor-Agnostic)**

**Goal**: Pluggable distributed tracing with support for multiple APM providers
- **Vendor-agnostic**: Support OpenTelemetry, Datadog, New Relic, and other APMs
- **Optional setup**: Only enabled when configured
- **TracingMiddleware**: Wraps requests in vendor-neutral spans
- **Semantic attributes**: HTTP method, route, status, user context
- **Error tracking**: Automatic error status on 5xx responses

**Implementation includes**:
- Core: Tracer abstraction layer (`TracerInterface`, `SpanInterface`, `SpanBuilderInterface`)
- Core: `NoopTracer` as default fallback implementation
- Core: `TracingMiddleware` using the abstraction
- Core: Configuration schema in `config/observability.php`
- Extensions: Adapter implementations (OpenTelemetry, Datadog, New Relic)
- Extensions: `TracingServiceProvider` for driver selection and registration

### Tracer Abstraction Layer

**File**: `src/Observability/Tracing/TracerInterface.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface TracerInterface
{
    public function startSpan(string $name, array $attrs = []): SpanBuilderInterface;
}
```

**File**: `src/Observability/Tracing/SpanBuilderInterface.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface SpanBuilderInterface
{
    public function setAttribute(string $key, mixed $value): self;
    public function setParent(?SpanInterface $parent): self;
    public function startSpan(): SpanInterface;
}
```

**File**: `src/Observability/Tracing/SpanInterface.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

interface SpanInterface
{
    public function setAttribute(string $key, mixed $value): void;
    public function end(): void;
}
```

### Observability Configuration

**File**: `config/observability.php` (new)
```php
<?php

return [
    'tracing' => [
        'enabled' => env('TRACING_ENABLED', false),
        'driver' => env('TRACING_DRIVER', 'noop'), // 'otel', 'datadog', 'newrelic', 'noop'
        'service_name' => env('TRACING_SERVICE_NAME', 'glueful-app'),
        'service_version' => env('TRACING_SERVICE_VERSION', '1.0.0'),
        
        // Driver-specific configs
        'drivers' => [
            'otel' => [
                'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'grpc'),
            ],
            'datadog' => [
                'agent_host' => env('DD_AGENT_HOST', 'localhost'),
                'agent_port' => env('DD_TRACE_AGENT_PORT', 8126),
            ],
            'newrelic' => [
                'app_name' => env('NEW_RELIC_APP_NAME'),
                'license_key' => env('NEW_RELIC_LICENSE_KEY'),
            ],
        ],
    ]
];
```

### TracingMiddleware Implementation

**File**: `src/Routing/Middleware/TracingMiddleware.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Observability\Tracing\TracerInterface;

final class TracingMiddleware implements \Glueful\Routing\RouteMiddleware
{
    public function __construct(private TracerInterface $tracer) {}

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        $builder = $this->tracer->startSpan('http.request', [
            'http.method' => $request->getMethod(),
            'http.route' => $request->attributes->get('_route') ?? $request->getPathInfo(),
            'user_agent' => $request->headers->get('User-Agent'),
            'net.peer.ip' => $request->getClientIp(),
        ]);
        
        if (function_exists('request_id')) {
            $builder->setAttribute('glueful.request_id', request_id());
        }
        
        $span = $builder->startSpan();
        
        try {
            $resp = $next($request);
            
            if (is_object($resp) && method_exists($resp, 'getStatusCode')) {
                $span->setAttribute('http.status_code', $resp->getStatusCode());
            }
            
            if (isset($_SESSION['user_uuid'])) {
                $span->setAttribute('enduser.id', $_SESSION['user_uuid']);
            }
            
            return $resp;
        } finally {
            $span->end();
        }
    }
}
```

### TracingServiceProvider

**File**: `src/DI/ServiceProviders/TracingServiceProvider.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Container;
use Glueful\DI\ServiceProviderInterface;
use Glueful\Observability\Tracing\TracerInterface;
use Glueful\Observability\Tracing\Adapters\{
    NoopTracer,
    OtelTracerAdapter,
    DatadogTracerAdapter,
    NewRelicTracerAdapter
};
use Symfony\Component\DependencyInjection\ContainerBuilder;

class TracingServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        $config = config('observability.tracing', []);
        
        if (!($config['enabled'] ?? false)) {
            $container->register(TracerInterface::class, NoopTracer::class);
            return;
        }

        $driver = $config['driver'] ?? 'noop';
        $driverConfig = $config['drivers'][$driver] ?? [];
        
        // Register the appropriate tracer adapter based on driver
        $container->register(TracerInterface::class, function () use ($driver, $driverConfig, $config) {
            return match ($driver) {
                'otel' => new OtelTracerAdapter(
                    $config['service_name'],
                    $config['service_version'],
                    $driverConfig
                ),
                'datadog' => new DatadogTracerAdapter(
                    $config['service_name'],
                    $config['service_version'],
                    $driverConfig
                ),
                'newrelic' => new NewRelicTracerAdapter(
                    $config['service_name'],
                    $config['service_version'],
                    $driverConfig
                ),
                default => new NoopTracer(),
            };
        });
        
        // Register TracingMiddleware with alias
        $container->register(\Glueful\Routing\Middleware\TracingMiddleware::class)
            ->addArgument($container->get(TracerInterface::class));
        $container->setAlias('tracing', \Glueful\Routing\Middleware\TracingMiddleware::class);
    }

    public function boot(Container $container): void
    {
        // Initialize tracer if needed
        if ($container->has(TracerInterface::class)) {
            $tracer = $container->get(TracerInterface::class);
            // Perform any runtime initialization
        }
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'tracing';
    }
}
```

### Core NoopTracer Implementation

**File**: `src/Observability/Tracing/NoopTracer.php` (Core - default fallback)
```php
<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing;

class NoopTracer implements TracerInterface
{
    public function startSpan(string $name, array $attrs = []): SpanBuilderInterface
    {
        return new NoopSpanBuilder($attrs);
    }
}

class NoopSpan implements SpanInterface
{
    public function setAttribute(string $key, mixed $value): void {}
    public function end(): void {}
}

class NoopSpanBuilder implements SpanBuilderInterface
{
    public function __construct(private array $attrs = []) {}
    
    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }
    
    public function setParent(?SpanInterface $parent): self
    {
        return $this;
    }
    
    public function startSpan(): SpanInterface
    {
        return new NoopSpan();
    }
}
```

### Extension Adapter Implementations

**File**: `src/Observability/Tracing/Adapters/OtelTracerAdapter.php`
```php
<?php

declare(strict_types=1);

namespace Glueful\Observability\Tracing\Adapters;

use Glueful\Observability\Tracing\{TracerInterface, SpanInterface, SpanBuilderInterface};
use OpenTelemetry\API\Trace\TracerInterface as OtelTracerInterface;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;

class OtelTracerAdapter implements TracerInterface
{
    private ?OtelTracerInterface $otelTracer = null;
    
    public function __construct(
        private string $serviceName,
        private string $serviceVersion,
        private array $config
    ) {
        if (class_exists(TracerProviderFactory::class)) {
            $factory = new TracerProviderFactory();
            $tracerProvider = $factory->create();
            $this->otelTracer = $tracerProvider->getTracer(
                $this->serviceName,
                $this->serviceVersion
            );
        }
    }
    
    public function startSpan(string $name, array $attrs = []): SpanBuilderInterface
    {
        if (!$this->otelTracer) {
            return new NoopSpanBuilder($attrs);
        }
        return new OtelSpanBuilder($this->otelTracer->spanBuilder($name), $attrs);
    }
}

class OtelSpanBuilder implements SpanBuilderInterface
{
    public function __construct(
        private $otelSpanBuilder,
        private array $attrs = []
    ) {
        foreach ($attrs as $key => $value) {
            $this->otelSpanBuilder->setAttribute($key, $value);
        }
    }
    
    public function setAttribute(string $key, mixed $value): self
    {
        $this->otelSpanBuilder->setAttribute($key, $value);
        return $this;
    }
    
    public function setParent(?SpanInterface $parent): self
    {
        // OpenTelemetry handles parent via context
        return $this;
    }
    
    public function startSpan(): SpanInterface
    {
        return new OtelSpan($this->otelSpanBuilder->startSpan());
    }
}

class OtelSpan implements SpanInterface
{
    public function __construct(private $otelSpan) {}
    
    public function setAttribute(string $key, mixed $value): void
    {
        $this->otelSpan->setAttribute($key, $value);
    }
    
    public function end(): void
    {
        $this->otelSpan->end();
    }
}
```

## 🎯 **Key Characteristics of Phase 2**

1. **Non-breaking**: All additions are optional and configurable
2. **Production-ready**: Focus on security, performance, and reliability
3. **Vendor-agnostic**: Support for multiple APM providers (OpenTelemetry, Datadog, New Relic)
4. **Enterprise-focused**: IP allowlists, rate limiting, audit trails
5. **Performance-conscious**: Async metrics, minimal overhead

## 🚦 **CI Observability Gates**

### Health Check Smoke Tests

**File**: `.github/workflows/test.yml` (add step)
```yaml
- name: Health endpoints smoke test
  run: |
    # Start test server in background
    php glueful serve --port=8080 &
    SERVER_PID=$!
    sleep 2
    
    # Test liveness endpoint
    curl -f http://localhost:8080/healthz || exit 1
    
    # Test readiness endpoint (may require auth in production)
    curl -f http://localhost:8080/ready || echo "Readiness check may require auth"
    
    # Cleanup
    kill $SERVER_PID
```

### Security Audit Integration

```yaml
- name: Security audit (production profile)
  run: |
    if [ -f vendor/bin/glueful ]; then
      php vendor/bin/glueful security:check --production || exit 1
    else
      echo "Glueful CLI not installed - skip security audit"
    fi
```

### Benchmark Reporting

```yaml
- name: Benchmarks (report only)
  run: |
    if [ -f tools/bench/bench.php ]; then
      php tools/bench/bench.php | tee build/logs/bench.txt
    else
      echo "Bench script missing; see docs/CI_TEST_BENCHMARK_HARNESS.md" | tee build/logs/bench.txt
    fi

- name: Upload benchmark results
  uses: actions/upload-artifact@v3
  with:
    name: benchmark-results
    path: build/logs/bench.txt
```

### Performance Budget Enforcement (Optional)

```yaml
- name: Enforce dispatch latency budget
  run: |
    if [ -f build/logs/bench.txt ]; then
      BUDGET=1500 # ms for 1000 dispatches example
      ACTUAL=$(grep -Eo 'Dispatch x1000: [0-9]+\.?[0-9]*' build/logs/bench.txt | awk '{print $3}')
      echo "Actual: $ACTUAL ms (budget $BUDGET ms)"
      awk -v a="$ACTUAL" -v b="$BUDGET" 'BEGIN{ if (a>b) exit 1 }'
    fi
```

## 🧪 **Integration Tests**

### Rate Limiter Test

**File**: `tests/Integration/RateLimiterTest.php`
```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;

final class RateLimiterTest extends TestCase
{
    public function test_rate_limit_blocks_after_threshold(): void
    {
        $app = Framework::create(getcwd())->boot();
        $router = $app->getContainer()->get(\Glueful\Routing\Router::class);
        
        $router->group(['middleware' => ['rate_limit:2,60']], function($r) {
            $r->get('/limited', fn() => new \Glueful\Http\Response(['ok' => true]));
        });
        
        $req = fn() => $router->dispatch(Request::create('/limited', 'GET'));
        
        $this->assertSame(200, $req()->getStatusCode());
        $this->assertSame(200, $req()->getStatusCode());
        $this->assertSame(429, $req()->getStatusCode());
    }
}
```

## 📋 **Deliverables**

- Health endpoints secured and documented
- Structured logging standardized across framework
- Request metrics automatically collected
- Optional distributed tracing capability
- Sample dashboards and KPI definitions
- CI gates for observability smoke tests
- Integration tests for rate limiting and security
- Performance benchmarking with optional budget enforcement

## 📊 **KPI Definitions**

### Core Metrics to Track

1. **Response Time**: P50, P95, P99 latency per route group
2. **Error Rate**: 4xx/5xx responses as percentage of total requests
3. **Throughput**: Requests per second, grouped by endpoint
4. **Health Status**: Uptime percentage of `/healthz` and `/ready` endpoints
5. **Rate Limit Hits**: Number of 429 responses per time window

### Sample Dashboard Queries

```text
# Prometheus/Grafana examples
rate(http_requests_total[5m])                    # RPS
histogram_quantile(0.95, http_request_duration)  # P95 latency
rate(http_requests_total{status=~"5.."}[5m])    # Error rate
```

This phase transforms Glueful from development-ready to **production enterprise-ready** with comprehensive observability and monitoring capabilities that enterprise teams require for reliable operations.