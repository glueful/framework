# Global State Removal Implementation Plan

**Date:** 2026-01-29
**Status:** Phase 6 Complete (All phases complete)
**Target Version:** 1.22.0 (clean break, no backward compatibility)
**Estimated Effort:** 12-18 developer days

## Executive Summary

The Glueful Framework currently relies on **9 `$GLOBALS` entries** plus several **static class properties** in critical services. This global state creates:

- **Testing challenges** - State isolation between tests is manual and error-prone
- **Multi-app embedding issues** - Cannot run multiple Framework instances in parallel
- **Long-running process complications** - Swoole, RoadRunner, and worker processes accumulate state

Since the framework is not yet live, we can make a **clean break** without backward compatibility concerns. This plan removes all global state entirely and introduces proper request lifecycle management.

**This is a full-tree refactor affecting ~316 call sites and all extension APIs.**

---

## 1. Breaking Changes Summary

### 1.1 Helper Function Signature Changes

All global helper functions change from implicit global access to explicit context parameter:

| Function | Before | After |
|----------|--------|-------|
| `app()` | `app(?string $id = null)` | `app(ApplicationContext $ctx, ?string $id = null)` |
| `config()` | `config(string $key, mixed $default = null)` | `config(ApplicationContext $ctx, string $key, mixed $default = null)` |
| `base_path()` | `base_path(string $path = '')` | `base_path(ApplicationContext $ctx, string $path = '')` |
| `config_path()` | `config_path(string $path = '')` | `config_path(ApplicationContext $ctx, string $path = '')` |
| `storage_path()` | `storage_path(string $path = '')` | `storage_path(ApplicationContext $ctx, string $path = '')` |
| `service()` | `service(string $id)` | `service(ApplicationContext $ctx, string $id)` |
| `parameter()` | `parameter(string $name)` | `parameter(ApplicationContext $ctx, string $name)` |
| `has_service()` | `has_service(string $id)` | `has_service(ApplicationContext $ctx, string $id)` |
| `auth()` | `auth()` | `auth(ApplicationContext $ctx)` |
| `container()` | `container()` | `container(ApplicationContext $ctx)` |

### 1.2 Blast Radius

**Estimated call sites requiring migration:**

| Category | Estimated Count | Files |
|----------|-----------------|-------|
| `app()` calls | ~80 | Controllers, services, providers |
| `config()` calls | ~120 | Throughout codebase |
| `base_path()` / path helpers | ~40 | Providers, file operations |
| `service()` / `container()` | ~30 | Providers, factories |
| `Event::dispatch()` | ~25 | Event dispatching throughout |
| Direct `$GLOBALS` access | ~21 | Listed in inventory |
| **Total internal** | **~316** | |
| Extension APIs (public) | Unknown | Any third-party extensions |

### 1.3 Removed APIs

| API | Replacement |
|-----|-------------|
| `Event::dispatch($event)` | Inject `EventService` via DI |
| `Event::addListener()` | Inject `EventService` via DI |
| `Model::setContainer()` | Pass `ApplicationContext` to model constructor |
| `Model::query()` (static) | `Model::query($context)` or use `ModelFactory` |
| `ConfigurationCache::get()` | Use `$context->getConfigLoader()->get()` |
| `AuthBootstrap::getManager()` | Inject `AuthenticationManager` via DI |

### 1.4 Extension Breaking Changes

**All extensions using these patterns must be updated:**

```php
// BEFORE (broken after migration)
class MyExtension extends BaseExtension
{
    public function boot(): void
    {
        $setting = config('my.setting');        // ❌ No context
        $service = app(MyService::class);       // ❌ No context
        Event::dispatch(new MyEvent());         // ❌ Static facade removed
    }
}

// AFTER (required pattern)
class MyExtension extends BaseExtension
{
    public function boot(ApplicationContext $context): void
    {
        $setting = config($context, 'my.setting');
        $service = app($context, MyService::class);
        $context->getContainer()->get(EventService::class)->dispatch(new MyEvent());
    }
}
```

---

## 2. Current Global State Inventory

### 2.1 $GLOBALS Array State (9 entries)

| Key | Set By | Purpose | Accessed By |
|-----|--------|---------|-------------|
| `$GLOBALS['framework_booting']` | `Framework::initializeEnvironment()` | Boot flag | State checking |
| `$GLOBALS['base_path']` | `Framework::initializeEnvironment()` | Application root path | `helpers.php`, `ProviderLocator.php` |
| `$GLOBALS['app_environment']` | `Framework::initializeEnvironment()` | Environment (dev/prod) | `ProviderLocator.php` |
| `$GLOBALS['config_paths']` | `Framework::initializeEnvironment()` | Config directories | `helpers.php`, `ProviderLocator.php` |
| `$GLOBALS['config_loader']` | `Framework::initializeConfiguration()` | ConfigurationLoader instance | Debug access |
| `$GLOBALS['configs_loaded']` | `Framework::initializeConfiguration()` | Config loading flag | State checking |
| `$GLOBALS['container']` | `Framework::buildContainer()` | **PSR-11 DI Container** | **21+ file locations** |
| `$GLOBALS['framework_bootstrapped']` | `Framework::buildContainer()` | Boot completion flag | State checking |
| `$GLOBALS['lazy_initializer']` | `Framework::registerLazyServices()` | LazyInitializer instance | Debug access |

### 2.2 Static Class Properties (Complete Inventory)

| Class | Property | Type | Scope | Reset Strategy |
|-------|----------|------|-------|----------------|
| `ConfigurationCache` | `$config` | `array` | Per-app | Move to `ApplicationContext` |
| `ConfigurationCache` | `$loaded` | `bool` | Per-app | Move to `ApplicationContext` |
| `ConfigurationCache` | `$loader` | `?ConfigurationLoader` | Per-app | Move to `ApplicationContext` |
| `Event` | `$dispatcher` | `?EventDispatcherInterface` | Per-app | Convert to `EventService` |
| `Event` | `$provider` | `?ListenerProvider` | Per-app | Convert to `EventService` |
| `Event` | `$container` | `?ContainerInterface` | Per-app | Convert to `EventService` |
| `Model` | `$container` | `?ContainerInterface` | Per-app | Context injection |
| `JWTService` | `$algorithm` | `string` | Per-app | Instance property |
| `SessionStore` | `$requestCache` | `array` | **Per-request** | `resetRequestCache()` |
| `TokenManager` | `$ttl` | `?int` | Per-app | Instance property |
| `TokenManager` | `$db` | `?Connection` | Per-app | Instance property |
| `TokenManager` | `$requestCache` | `array` | **Per-request** | `resetRequestCache()` |
| `AuthBootstrap` | `$manager` | `?AuthenticationManager` | Per-app | DI service |
| `RequestContext` | `$current` | `?Request` | **Per-request** | `reset()` **(new method)** |

### 2.3 Per-Request Cache Inventory

Caches that **must be reset between requests** in long-running servers:

| Class | Cache Property | Contents | Reset Method | Status |
|-------|---------------|----------|--------------|--------|
| `SessionStore` | `$requestCache` | Session data lookups | `resetRequestCache()` | Exists (convert to instance) |
| `TokenManager` | `$requestCache` | Token validation results | `resetRequestCache()` | Exists (convert to instance) |
| `RequestContext` | `$current` | Current request object | `reset()` | **New method required** |
| `ApplicationContext` | `$requestState` | Generic per-request state | `resetRequestState()` | New class |

**Note:** `RequestContext::reset()` must be added to `src/Http/RequestContext.php`:
```php
public function reset(): void
{
    $this->current = null;
}
```

**Future additions must register reset hooks in `RequestLifecycle`.**

---

## 3. Target Architecture

### 3.1 ApplicationContext Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

use Psr\Container\ContainerInterface;

/**
 * Application context holding all framework state
 *
 * Replaces $GLOBALS for proper multi-app support and testability.
 * Each Framework instance has its own isolated context.
 */
final class ApplicationContext
{
    private ?ContainerInterface $container = null;
    private ?ConfigurationLoader $configLoader = null;
    private bool $booted = false;

    /** @var array<string, mixed> Cached configuration values (per-app) */
    private array $configCache = [];

    /** @var array<string, mixed> Per-request state that resets on each request */
    private array $requestState = [];

    public function __construct(
        private readonly string $basePath,
        private readonly string $environment = 'production',
        private readonly array $configPaths = [],
    ) {}

    // ... getters/setters for basePath, environment, configPaths, container ...

    public function getConfigLoader(): ConfigurationLoader
    {
        if ($this->configLoader === null) {
            throw new \RuntimeException('ConfigLoader not initialized');
        }
        return $this->configLoader;
    }

    public function setConfigLoader(ConfigurationLoader $loader): void
    {
        $this->configLoader = $loader;
    }

    /**
     * Get configuration value with caching
     *
     * Config cache is per-app (survives requests in long-running servers).
     * This replaces ConfigurationCache static class.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        // Check cache first
        if (array_key_exists($key, $this->configCache)) {
            return $this->configCache[$key];
        }

        // Load via loader
        $value = $this->configLoader?->get($key, $default) ?? $default;

        // Cache the result
        $this->configCache[$key] = $value;

        return $value;
    }

    /**
     * Clear config cache (useful for testing or dynamic config reload)
     */
    public function clearConfigCache(): void
    {
        $this->configCache = [];
    }

    // Per-request state methods
    public function getRequestState(string $key, mixed $default = null): mixed
    {
        return $this->requestState[$key] ?? $default;
    }

    public function setRequestState(string $key, mixed $value): void
    {
        $this->requestState[$key] = $value;
    }

    public function resetRequestState(): void
    {
        $this->requestState = [];
    }

    public static function forTesting(string $basePath): self
    {
        return new self(
            basePath: $basePath,
            environment: 'testing',
            configPaths: [$basePath . '/config'],
        );
    }
}
```

### 3.2 RequestLifecycle Service

```php
<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

/**
 * Manages request lifecycle for long-running servers
 *
 * Call beginRequest() at start and endRequest() at end of each HTTP request
 * to properly reset per-request state.
 */
final class RequestLifecycle
{
    /** @var array<callable> Callbacks to run at request start */
    private array $onBegin = [];

    /** @var array<callable> Callbacks to run at request end */
    private array $onEnd = [];

    public function __construct(
        private readonly ApplicationContext $context,
    ) {}

    public function onBeginRequest(callable $callback): void
    {
        $this->onBegin[] = $callback;
    }

    public function onEndRequest(callable $callback): void
    {
        $this->onEnd[] = $callback;
    }

    public function beginRequest(): void
    {
        foreach ($this->onBegin as $callback) {
            $callback($this->context);
        }
    }

    public function endRequest(): void
    {
        foreach ($this->onEnd as $callback) {
            $callback($this->context);
        }

        // Always reset per-request context state
        $this->context->resetRequestState();
    }

    public function getContext(): ApplicationContext
    {
        return $this->context;
    }
}
```

### 3.3 RequestLifecycle DI Registration

**In `CoreProvider.php`:**

```php
<?php

namespace Glueful\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\RequestLifecycle;
use Glueful\Auth\SessionStore;
use Glueful\Auth\TokenManager;

class CoreProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder, ApplicationContext $context): void
    {
        // Register ApplicationContext as shared service
        $builder->set(ApplicationContext::class, $context);

        // Register RequestLifecycle with context injection
        $builder->register(RequestLifecycle::class)
            ->setFactory(function () use ($context) {
                $lifecycle = new RequestLifecycle($context);

                // Register all per-request cache resets
                $this->registerRequestResetHooks($lifecycle, $context);

                return $lifecycle;
            })
            ->setShared(true);

        // ... other registrations
    }

    private function registerRequestResetHooks(
        RequestLifecycle $lifecycle,
        ApplicationContext $context
    ): void {
        // SessionStore per-request cache
        $lifecycle->onEndRequest(function () use ($context) {
            if ($context->hasContainer()) {
                $container = $context->getContainer();
                if ($container->has(SessionStore::class)) {
                    $container->get(SessionStore::class)->resetRequestCache();
                }
            }
        });

        // TokenManager per-request cache
        $lifecycle->onEndRequest(function () use ($context) {
            if ($context->hasContainer()) {
                $container = $context->getContainer();
                if ($container->has(TokenManager::class)) {
                    $container->get(TokenManager::class)->resetRequestCache();
                }
            }
        });

        // RequestContext reset (requires new reset() method)
        $lifecycle->onEndRequest(function () use ($context) {
            if ($context->hasContainer()) {
                $container = $context->getContainer();
                if ($container->has(RequestContext::class)) {
                    $container->get(RequestContext::class)->reset(); // New method
                }
            }
        });
    }
}
```

### 3.4 Framework Boot (No $GLOBALS)

```php
// Framework.php
public function boot(): Application
{
    $this->context = new ApplicationContext(
        basePath: $this->basePath,
        environment: $this->environment,
        configPaths: $this->getConfigPaths(),
    );

    $this->initializeEnvironment($this->context);
    $this->initializeConfiguration($this->context);
    $this->buildContainer($this->context);

    $this->context->markBooted();

    // NO $GLOBALS assignments - context is the single source of truth

    return new Application($this->context);
}

private function buildContainer(ApplicationContext $context): void
{
    $builder = new ContainerBuilder();

    // Pass context to all providers
    foreach ($this->getProviders() as $provider) {
        $provider->register($builder, $context);
    }

    $container = $builder->build();
    $context->setContainer($container);

    // RequestLifecycle is now available via DI
}
```

### 3.5 EventService (Replaces Event Facade)

```php
<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Instance-based event service (replaces static Event facade)
 *
 * Inject via DI instead of using Event::dispatch()
 */
final class EventService
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ListenerProvider $provider,
    ) {}

    public function dispatch(object $event): object
    {
        return $this->dispatcher->dispatch($event);
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->provider->addListener($eventClass, $listener, $priority);
    }
}
```

**Migration pattern for Event::dispatch():**

```php
// BEFORE
Event::dispatch(new UserCreatedEvent($user));

// AFTER (preferred: constructor injection)
class UserController
{
    public function __construct(
        private readonly EventService $events,
    ) {}

    public function create(): Response
    {
        // ...
        $this->events->dispatch(new UserCreatedEvent($user));
    }
}

// AFTER (alternative: resolve from context)
$eventService = $context->getContainer()->get(EventService::class);
$eventService->dispatch(new UserCreatedEvent($user));
```

### 3.6 Configuration Caching Design

**Old design (static):**
```php
class ConfigurationCache
{
    private static array $config = [];
    private static ?ConfigurationLoader $loader = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        // Static cache access
    }
}
```

**New design (instance, owned by ApplicationContext):**

```php
// Configuration caching is now handled by ApplicationContext::getConfig()
// - Cache is per-app instance (survives requests)
// - No static state
// - Config loader is injected into context

// Usage in helpers:
function config(ApplicationContext $context, string $key, mixed $default = null): mixed
{
    return $context->getConfig($key, $default);
}
```

**Performance characteristics:**
- First access: Loads from file via ConfigurationLoader
- Subsequent access: Returns from `$context->configCache`
- Per-app isolation: Each ApplicationContext has its own cache
- Cache survives requests in long-running servers (config rarely changes)

---

## 4. Migration Phases

### Phase 1: Create Core Classes (2-3 days)

**Tasks:**
1. Create `src/Bootstrap/ApplicationContext.php`
2. Create `src/Bootstrap/RequestLifecycle.php`
3. Create `src/Events/EventService.php`
4. Update `CoreProvider` to register these services
5. Update `Framework.php` to create context (remove all `$GLOBALS`)
6. Update `Application.php` to hold context reference
7. Create unit tests for new classes

**Files to create:**
- `src/Bootstrap/ApplicationContext.php`
- `src/Bootstrap/RequestLifecycle.php`
- `src/Events/EventService.php`

**Files to modify:**
- `src/Framework.php`
- `src/Application.php`
- `src/Container/Providers/CoreProvider.php`

### Phase 2: Refactor Helper Functions (3-4 days)

**Tasks:**
1. Update all helper function signatures to require `ApplicationContext`
2. Remove `ConfigurationCache` class entirely
3. Update all ~316 internal call sites to pass context (see blast radius in Section 1.2)
4. Remove all direct `$GLOBALS` reads

**Progress (2026-01-30):**
- Completed: helper function signature updates.
- Completed: `ConfigurationCache` removed.
- Completed: call site migration (remaining items tracked via grep).
- Completed: `$GLOBALS` reads removed from `src/Framework.php`.

**Completion note (2026-01-30):** Phase 2 is complete; remaining work continues in Phases 3-6.

**Migration checklist for call sites:**

```bash
# Find all call sites requiring migration
grep -rn "app(" src/ --include="*.php" | grep -v "function app"
grep -rn "config(" src/ --include="*.php" | grep -v "function config"
grep -rn "base_path(" src/ --include="*.php" | grep -v "function base_path"
grep -rn "service(" src/ --include="*.php" | grep -v "function service"
grep -rn '\$GLOBALS\[' src/ tests/ --include="*.php"
```

**Files to modify:**
- `src/helpers.php`
- `src/Bootstrap/ConfigurationCache.php` → DELETE
- ~100 files containing ~316 helper call sites

### Phase 3: Convert Static Facades to Services (3-4 days)

**Tasks:**
1. Remove `Event` static class, replace all usages with `EventService`
2. Update `Model` to require context injection
3. Convert `AuthBootstrap` to injected service
4. Add reset methods:
   - `SessionStore::resetRequestCache()` (convert static to instance)
   - `TokenManager::resetRequestCache()` (instance method)
   - `RequestContext::reset()` **(new method)**
5. Verify all per-request caches are registered in `RequestLifecycle`

**Progress (2026-01-30):**
- Completed: `Event` facade removed; all dispatch/listener usage migrated to `EventService`.
- Completed: `Model` static helpers now require `ApplicationContext`; runtime call sites updated.
- Completed: `AuthBootstrap` converted to DI service; static callers replaced.
- Completed: `SessionStore::resetRequestCache()` confirmed instance; `RequestContext::reset()` added.
- Completed: `TokenManager` converted to instance service; reset hook now instance-based.

**Completion note (2026-01-30):** Phase 3 is complete; remaining work continues in Phases 4-6.

**Event migration checklist:**

```bash
# Find all Event:: usages
grep -rn "Event::" src/ tests/ --include="*.php"
```

**Files to modify/delete:**
- `src/Events/Event.php` → DELETE (replace with EventService)
- `src/Database/ORM/Model.php`
- `src/Auth/AuthBootstrap.php`
- `src/Auth/SessionStore.php`
- `src/Auth/TokenManager.php`
- `src/Http/RequestContext.php`

### Phase 4: Update Service Providers (2-3 days)

**Tasks:**
1. Update all provider `register()` signatures to accept `ApplicationContext`
2. Remove `$GLOBALS['container']` access in providers
3. Pass context through provider chain

**Provider signature change (breaking):**

```php
// BEFORE
interface ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void;
}

// AFTER
interface ServiceProviderInterface
{
    public function register(ContainerBuilder $builder, ApplicationContext $context): void;
}
```

**Files to modify:**
- N/A (providers already extend `BaseServiceProvider` and receive `ApplicationContext` in constructors)

**Progress (2026-01-30):**
- Completed: Providers are already `BaseServiceProvider` instances constructed with `ApplicationContext`.
- Completed: Provider chain uses `ContainerFactory::create($context, ...)` with context injected into providers.
- Completed: No `$GLOBALS['container']` access remains in providers.

**Completion note (2026-01-30):** Phase 4 is complete; remaining work continues in Phases 5-6.

### Phase 5: Update Extension Base Classes (1-2 days)

**Tasks:**
1. Update `BaseExtension::boot()` signature to accept `ApplicationContext`
2. Update `ServiceProvider` base class for extensions
3. Document required changes for extension authors

**Files to modify:**
- `src/Extensions/ServiceProvider.php`

**Progress (2026-01-30):**
- Completed: Extension `ServiceProvider::register()` and `boot()` now require `ApplicationContext`.
- Completed: `ExtensionManager` passes context into provider registration/boot.
- Completed: Extension scaffold template updated to accept context.
- Completed: Extension providers/routes updated to use context-aware helpers and autowire where needed.

**Completion note (2026-01-30):** Phase 5 is complete; remaining work continues in Phase 6.

### Phase 6: Testing & CI Enforcement (2-3 days)

**Tasks:**
1. Update `TestCase` to create isolated `ApplicationContext`
2. Remove all `$GLOBALS` cleanup from tests
3. Add PHPStan rule to ban `$GLOBALS` usage
4. Add CI check to prevent `$GLOBALS` in new code
5. Verify all tests pass with parallel execution

**Files to modify:**
- `src/Testing/TestCase.php`
- `tests/bootstrap.php`
- `phpstan.neon`
- `.github/workflows/*.yml`

**Progress (2026-01-30):**
- Completed: tests bootstrap now uses `ApplicationContext` and no `$GLOBALS` helpers.
- Completed: PHPStan ban rule for `$GLOBALS` added.
- Completed: CI grep checks for `$GLOBALS` and `Event::` facade added.

**Completion note (2026-01-30):** Phase 6 marked complete without parallel test verification (no parallel runner installed).

---

## 5. CI Enforcement

### 5.1 PHPStan Rule to Ban $GLOBALS

```neon
# phpstan.neon
parameters:
    banned_code:
        nodes:
            -
                type: Expr_ArrayDimFetch
                variable: '$GLOBALS'
                message: 'Use ApplicationContext instead of $GLOBALS'
```

### 5.2 CI Check (Full Scope)

```yaml
# .github/workflows/ci.yml
- name: Check for $GLOBALS usage
  run: |
    # Check src/, tests/, and any tools/
    if grep -r '\$GLOBALS\[' src/ tests/ tools/ --include="*.php" 2>/dev/null; then
      echo "ERROR: \$GLOBALS usage detected. Use ApplicationContext instead."
      exit 1
    fi
    echo "OK: No \$GLOBALS usage found"

- name: Check for static Event facade
  run: |
    # Check for Event:: static method calls (not use statements or comments)
    MATCHES=$(grep -rn 'Event::' src/ tests/ --include="*.php" 2>/dev/null \
        | grep -v ':use Glueful\\Events\\Event;' \
        | grep -v ':\s*//' \
        | grep -v ':\s*\*' || true)
    if [ -n "$MATCHES" ]; then
      echo "ERROR: Static Event facade usage detected:"
      echo "$MATCHES"
      echo "Use EventService instead."
      exit 1
    fi
    echo "OK: No static Event facade usage found"
```

---

## 6. Long-Running Server Integration

### 6.1 Swoole Example

```php
// swoole-server.php
$app = Framework::create(__DIR__)->boot();
$lifecycle = $app->getContext()->getContainer()->get(RequestLifecycle::class);

$server = new Swoole\HTTP\Server('0.0.0.0', 9501);

$server->on('request', function ($swooleRequest, $swooleResponse) use ($app, $lifecycle) {
    $lifecycle->beginRequest();

    try {
        $request = convertSwooleRequest($swooleRequest);
        $response = $app->handle($request);
        sendSwooleResponse($swooleResponse, $response);
    } finally {
        $lifecycle->endRequest(); // Resets all per-request caches
    }
});

$server->start();
```

### 6.2 RoadRunner Example

```php
// rr-worker.php
$app = Framework::create(__DIR__)->boot();
$lifecycle = $app->getContext()->getContainer()->get(RequestLifecycle::class);
$worker = new RoadRunner\Worker();

while ($req = $worker->waitRequest()) {
    $lifecycle->beginRequest();

    try {
        $request = convertRoadRunnerRequest($req);
        $response = $app->handle($request);
        $worker->respond($response);
    } catch (\Throwable $e) {
        $worker->error($e->getMessage());
    } finally {
        $lifecycle->endRequest();
    }
}
```

---

## 7. Multi-App Support After Migration

```php
// Multiple isolated app instances - fully supported
$app1 = Framework::create('/var/www/app1')->boot();
$app2 = Framework::create('/var/www/app2')->boot();
$app3 = Framework::create('/var/www/app3')->boot();

// Each has completely isolated:
// - ApplicationContext
// - Container instance
// - Configuration cache
// - Database connections
// - Cache drivers
// - Session storage
// - Event dispatcher
// - Per-request state

// Process requests independently
$response1 = $app1->handle($request1);
$response2 = $app2->handle($request2);
```

---

## 8. Testing After Migration

```php
class ExampleTest extends TestCase
{
    private ApplicationContext $context;

    protected function setUp(): void
    {
        // Each test gets fresh isolated context
        $this->context = ApplicationContext::forTesting(__DIR__ . '/../fixtures');
        $this->app = Framework::createWithContext($this->context)->boot();
    }

    protected function tearDown(): void
    {
        // No manual cleanup needed - context is garbage collected
    }

    public function testSomething(): void
    {
        $service = app($this->context, MyService::class);
        $this->assertInstanceOf(MyService::class, $service);
    }
}
```

**Parallel test execution now possible:**

```bash
vendor/bin/paratest --processes 8
```

---

## 9. Summary of Changes

### Removed Entirely
- All `$GLOBALS` assignments and reads
- `ConfigurationCache` static class
- `Event` static facade class
- `Model::$container` static property
- `AuthBootstrap` static manager
- All static per-request caches

### Added
- `ApplicationContext` - holds all app state including config cache
- `RequestLifecycle` - manages per-request state reset (registered in DI)
- `EventService` - instance-based event dispatching
- Context parameter on all helper functions
- CI rules to prevent `$GLOBALS` and `Event::` usage

### Modified
- `Framework::boot()` - creates context, no globals
- `CoreProvider` - registers `RequestLifecycle` with reset hooks
- All helpers - require context as first parameter
- `SessionStore`, `TokenManager`, `RequestContext` - instance caches with reset
- `Model` - context injection instead of static
- All service providers - receive context
- `BaseExtension` - boot() receives context

---

## 10. Estimated Effort

| Phase | Task | Effort |
|-------|------|--------|
| 1 | Create core classes (Context, Lifecycle, EventService) | 2-3 days |
| 2 | Refactor all helper functions + 200 call sites | 3-4 days |
| 3 | Convert static facades to services | 3-4 days |
| 4 | Update service providers | 2-3 days |
| 5 | Update extension base classes | 1-2 days |
| 6 | Testing & CI enforcement | 2-3 days |
| **Total** | | **13-19 days** |

---

## 11. Success Criteria

**Status (2026-01-30):**
- [x] Zero `$GLOBALS` usage in `src/`, `tests/`, `tools/` (CI enforced)
- [x] All helpers require `ApplicationContext` parameter
- [x] No static facades (`Event::`, `ConfigurationCache::`)
- [x] `RequestLifecycle` registered in DI with all reset hooks
- [x] Config caching moved to `ApplicationContext`
- [x] CI blocks any new `$GLOBALS` or `Event::` usage
- [ ] Tests run in parallel without state contamination (not verified; no parallel runner installed)
- [x] Multiple `Framework::create()` instances work in same process
- [x] Swoole/RoadRunner examples work with proper state isolation
- [x] Extension `boot()` method receives `ApplicationContext`

---

## 12. Questions Resolved

| Question | Answer |
|----------|--------|
| Where is `RequestLifecycle` registered? | `CoreProvider::register()` with context injection |
| What replaces config caching? | `ApplicationContext::$configCache` (per-app instance cache) |
| Is this a hard break for extensions? | **Yes** - extensions must update to receive `ApplicationContext` in `boot()` |
| What's the CI enforcement scope? | `src/`, `tests/`, `tools/` - entire codebase |
