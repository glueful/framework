# ApplicationContext & Framework Lifecycle - Developer Guide

**Version:** 1.22.0+
**Audience:** Framework users, extension developers, contributors

---

## Overview

Glueful uses `ApplicationContext` as the central state container for all framework services. This replaces global state (`$GLOBALS`) and enables:

- **Test isolation** - Each test can have its own isolated context
- **Multi-app support** - Multiple Framework instances in a single process
- **Long-running servers** - Proper state management for Swoole, RoadRunner, workers

**Key principle:** Always pass `ApplicationContext` to code that needs configuration, container services, or path resolution.

---

## Table of Contents

1. [ApplicationContext](#1-applicationcontext)
2. [Framework Boot Lifecycle](#2-framework-boot-lifecycle)
3. [Helper Functions](#3-helper-functions)
4. [Database Connections](#4-database-connections)
5. [Controllers & Services](#5-controllers--services)
6. [Console Commands](#6-console-commands)
7. [Extension Development](#7-extension-development)
8. [Request Lifecycle](#8-request-lifecycle)
9. [Testing](#9-testing)
10. [Long-Running Servers](#10-long-running-servers)
11. [Troubleshooting](#11-troubleshooting)

---

## 1. ApplicationContext

`ApplicationContext` holds all framework state for a single application instance:

```php
namespace Glueful\Bootstrap;

final class ApplicationContext
{
    // Core properties
    private string $basePath;           // Application root directory
    private string $environment;        // 'development', 'production', 'testing'
    private array $configPaths;         // Framework and application config directories

    // Initialized during boot
    private ?ContainerInterface $container;
    private ?ConfigurationLoader $configLoader;

    // Per-app state (survives requests in long-running servers)
    private array $configCache = [];

    // Per-request state (reset between requests)
    private array $requestState = [];
}
```

### Creating ApplicationContext

The framework creates context automatically during boot:

```php
// Standard boot - context created internally
$app = Framework::create('/path/to/app')->boot();
$context = $app->getContext();
```

For testing:

```php
$context = ApplicationContext::forTesting('/path/to/fixtures');
```

### Accessing Context

| Location | How to Get Context |
|----------|-------------------|
| Controllers | `$this->context` (injected via constructor) |
| Console Commands | `$this->getContext()` |
| Services (DI) | Constructor injection: `__construct(ApplicationContext $context)` |
| Anywhere with container | `$container->get(ApplicationContext::class)` |

---

## 2. Framework Boot Lifecycle

The framework boots in 7 phases:

```
┌─────────────────────────────────────────────────────────────────┐
│                    Framework::boot()                             │
├─────────────────────────────────────────────────────────────────┤
│ Phase 1: Environment      → Load .env, set environment          │
│ Phase 2: Configuration    → Create ConfigurationLoader          │
│ Phase 3: Container        → Build DI container with providers   │
│ Phase 4: Core Services    → Initialize logging, error handling  │
│ Phase 5: HTTP Layer       → Set up router, middleware           │
│ Phase 6: Lazy Services    → Register deferred service loading   │
│ Phase 7: Validation       → Startup checks (DB, cache, etc.)    │
└─────────────────────────────────────────────────────────────────┘
```

### Boot Sequence Detail

```php
public function boot(): Application
{
    // Create context first - all subsequent phases use it
    $this->context = new ApplicationContext(
        basePath: $this->basePath,
        environment: $this->environment,
        configPaths: [
            'framework' => dirname(__DIR__) . '/config',
            'application' => $this->configPath,
        ],
    );

    // Phase 1: Environment
    // - Loads .env file from basePath
    // - Sets $_ENV variables
    $this->initializeEnvironment($this->context);

    // Phase 2: Configuration
    // - Creates ConfigurationLoader
    // - Attaches to context (config loaded lazily on first access)
    $this->initializeConfiguration($this->context);

    // Phase 3: Container
    // - Registers all service providers
    // - Builds PSR-11 container
    // - Context is registered as a service
    $this->buildContainer($this->context);

    // Phase 4-7: Additional initialization...

    return new Application($this->context);
}
```

### When Is Context Available?

| Phase | Context | Container | Config | Services |
|-------|---------|-----------|--------|----------|
| Before boot | No | No | No | No |
| Phase 1-2 | Yes | No | Yes (via loader) | No |
| Phase 3+ | Yes | Yes | Yes | Yes |
| After boot | Yes | Yes | Yes | Yes |

---

## 3. Helper Functions

All helper functions require `ApplicationContext` as the first parameter:

### Function Signatures

```php
// Configuration
config(ApplicationContext $ctx, string $key, mixed $default = null): mixed

// Container access
container(ApplicationContext $ctx): ContainerInterface
app(ApplicationContext $ctx, ?string $id = null): mixed
service(ApplicationContext $ctx, string $id): mixed
has_service(ApplicationContext $ctx, string $id): bool

// Path resolution
base_path(ApplicationContext $ctx, string $path = ''): string
config_path(ApplicationContext $ctx, string $path = ''): string
storage_path(ApplicationContext $ctx, string $path = ''): string

// Authentication
auth(ApplicationContext $ctx): AuthenticationManager
```

### Usage Examples

```php
// Get config value
$appName = config($context, 'app.name', 'My App');

// Get nested config
$dbHost = config($context, 'database.pgsql.host');

// Resolve service from container
$cache = app($context, CacheStore::class);

// Build file path
$logPath = storage_path($context, 'logs/app.log');

// Check if service exists
if (has_service($context, MyService::class)) {
    $service = service($context, MyService::class);
}
```

---

## 4. Database Connections

### Creating Connections

Always use `Connection::fromContext()` to create database connections:

```php
// Correct - uses context for config resolution
$connection = Connection::fromContext($context);

// Also correct - with additional config overrides
$connection = Connection::fromContext($context, [
    'pgsql' => ['db' => 'custom_database']
]);
```

### Why Context Matters

The `Connection` class loads database configuration via:

1. `config($context, 'database')` - Full database config
2. Falls back to `env()` values if context unavailable

Without context, the connection cannot resolve:
- Database host, port, credentials
- Connection pooling settings
- Engine-specific configuration

### Common Patterns

```php
// In a service with DI
class UserRepository
{
    private Connection $db;

    public function __construct(ApplicationContext $context)
    {
        $this->db = Connection::fromContext($context);
    }

    public function findById(string $id): ?array
    {
        return $this->db->table('users')
            ->where(['uuid' => $id])
            ->first();
    }
}

// In a controller
class UserController extends BaseController
{
    public function index(): Response
    {
        $users = Connection::fromContext($this->context)
            ->table('users')
            ->get();

        return Response::success($users);
    }
}
```

---

## 5. Controllers & Services

### Controllers

All controllers extend `BaseController` which receives context via constructor:

```php
class MyController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?Request $request = null
    ) {
        parent::__construct($context, $repositoryFactory, $authManager, $request);
    }

    public function show(string $id): Response
    {
        // Access context
        $config = config($this->context, 'app.name');

        // Or use the getter
        $ctx = $this->getContext();

        return Response::success(['id' => $id]);
    }
}
```

### Services

Services should accept `ApplicationContext` via constructor injection:

```php
class NotificationService
{
    private ApplicationContext $context;
    private CacheStore $cache;
    private Connection $db;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        $this->cache = app($context, CacheStore::class);
        $this->db = Connection::fromContext($context);
    }

    public function send(string $userId, string $message): bool
    {
        $config = config($this->context, 'notifications');
        // ... implementation
    }
}
```

### Service Registration

Register services in a provider:

```php
class NotificationProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->container->set(NotificationService::class, function() {
            return new NotificationService($this->context);
        });

        // Or with autowiring (recommended)
        $this->autowire(NotificationService::class);
    }
}
```

---

## 6. Console Commands

### Accessing Context in Commands

All commands extending `BaseCommand` have access to context:

```php
class MyCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get context
        $context = $this->getContext();

        // Use helpers with context
        $dbConfig = config($context, 'database');

        // Create database connection
        $db = Connection::fromContext($context);

        // Get services
        $cache = app($context, CacheStore::class);

        return Command::SUCCESS;
    }
}
```

### Important: Always Pass Context

When calling other services from commands, always pass context:

```php
// Correct
HealthService::checkDatabase($this->getContext());
CacheHelper::createCacheInstance($this->getContext());
ConnectionValidator::performHealthCheck($this->getContext());

// Wrong - may cause config resolution failures
HealthService::checkDatabase();  // Missing context!
```

---

## 7. Extension Development

### Extension Service Provider

Extensions must accept context in `register()` and `boot()`:

```php
namespace MyExtension;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;

class MyExtensionProvider extends ServiceProvider
{
    public function register(ApplicationContext $context): void
    {
        // Register services
        $container = container($context);

        $container->set(MyService::class, function() use ($context) {
            return new MyService($context);
        });
    }

    public function boot(ApplicationContext $context): void
    {
        // Run after all providers registered
        $setting = config($context, 'myextension.setting');

        // Get event service for dispatching
        $events = container($context)->get(EventService::class);
        $events->dispatch(new MyExtensionBootedEvent());
    }
}
```

### Extension Configuration

Access extension config via context:

```php
// In config/myextension.php
return [
    'api_key' => env('MYEXT_API_KEY'),
    'enabled' => env('MYEXT_ENABLED', true),
];

// In extension code
$apiKey = config($context, 'myextension.api_key');
$enabled = config($context, 'myextension.enabled', true);
```

### Extension Routes

Routes receive context via the container:

```php
// routes/api.php in extension
$router->group(['prefix' => '/myext'], function($router) {
    $router->get('/status', function(Request $request) {
        $container = $request->attributes->get('container');
        $context = $container->get(ApplicationContext::class);

        return Response::success([
            'version' => config($context, 'myextension.version')
        ]);
    });
});
```

---

## 8. Request Lifecycle

### Per-Request State

Some state must be reset between requests (important for long-running servers):

| Class | Reset Method | What It Clears |
|-------|--------------|----------------|
| `SessionStore` | `resetRequestCache()` | Session data cache |
| `TokenManager` | `resetRequestCache()` | Token validation cache |
| `RequestContext` | `reset()` | Current request reference |
| `ApplicationContext` | `resetRequestState()` | Generic per-request state |

### RequestLifecycle Service

The `RequestLifecycle` service manages request boundaries:

```php
// Registered in CoreProvider
$lifecycle = $container->get(RequestLifecycle::class);

// At request start
$lifecycle->beginRequest();

// At request end
$lifecycle->endRequest();  // Calls all reset hooks
```

### Adding Custom Reset Hooks

If your service has per-request state:

```php
class MyService
{
    private array $requestCache = [];

    public function resetRequestCache(): void
    {
        $this->requestCache = [];
    }
}

// In your provider
$lifecycle = $container->get(RequestLifecycle::class);
$lifecycle->onEndRequest(function() use ($container) {
    if ($container->has(MyService::class)) {
        $container->get(MyService::class)->resetRequestCache();
    }
});
```

---

## 9. Testing

### Isolated Test Context

Each test should create its own isolated context:

```php
class MyServiceTest extends TestCase
{
    private ApplicationContext $context;
    private MyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create isolated test context
        $this->context = ApplicationContext::forTesting(__DIR__ . '/fixtures');

        // Boot framework with test context
        $app = Framework::createWithContext($this->context)->boot();

        // Get service
        $this->service = app($this->context, MyService::class);
    }

    protected function tearDown(): void
    {
        // No manual cleanup needed - context is garbage collected
        parent::tearDown();
    }

    public function testSomething(): void
    {
        $result = $this->service->doSomething();
        $this->assertTrue($result);
    }
}
```

### Mocking Configuration

```php
public function testWithCustomConfig(): void
{
    $context = ApplicationContext::forTesting(__DIR__ . '/fixtures');

    // Override specific config for test
    // (requires test fixtures in fixtures/config/)

    $value = config($context, 'myservice.setting');
    $this->assertEquals('expected', $value);
}
```

### Parallel Test Execution

With isolated contexts, tests can run in parallel:

```bash
vendor/bin/paratest --processes 8
```

---

## 10. Long-Running Servers

### Swoole Integration

```php
// swoole-server.php
$app = Framework::create(__DIR__)->boot();
$context = $app->getContext();
$lifecycle = $context->getContainer()->get(RequestLifecycle::class);

$server = new Swoole\HTTP\Server('0.0.0.0', 9501);

$server->on('request', function ($swooleRequest, $swooleResponse) use ($app, $lifecycle) {
    // Start request lifecycle
    $lifecycle->beginRequest();

    try {
        $request = convertSwooleRequest($swooleRequest);
        $response = $app->handle($request);
        sendSwooleResponse($swooleResponse, $response);
    } finally {
        // Always reset per-request state
        $lifecycle->endRequest();
    }
});

$server->start();
```

### RoadRunner Integration

```php
// rr-worker.php
$app = Framework::create(__DIR__)->boot();
$context = $app->getContext();
$lifecycle = $context->getContainer()->get(RequestLifecycle::class);
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

### Multi-App Support

Multiple isolated apps in one process:

```php
$app1 = Framework::create('/var/www/app1')->boot();
$app2 = Framework::create('/var/www/app2')->boot();

// Each has completely isolated:
// - ApplicationContext
// - Container
// - Configuration
// - Database connections
// - Cache

$response1 = $app1->handle($request1);
$response2 = $app2->handle($request2);
```

---

## 11. Troubleshooting

### "Argument #1 must be ApplicationContext"

**Problem:** Calling a helper without context.

**Solution:** Pass context as first argument:

```php
// Wrong
$value = config('app.name');

// Correct
$value = config($context, 'app.name');
```

### "Database connection failed" / Wrong database

**Problem:** `Connection` created without context, using wrong config.

**Solution:** Use `Connection::fromContext()`:

```php
// Wrong - config won't resolve
$db = new Connection();

// Correct
$db = Connection::fromContext($context);
```

### "Container not initialized"

**Problem:** Accessing container before boot completes.

**Solution:** Only access container after `Framework::boot()` returns:

```php
$app = Framework::create(__DIR__)->boot();  // Boot must complete
$container = container($app->getContext()); // Now safe
```

### "Config returns null"

**Problem:** Context has no ConfigurationLoader attached.

**Solution:** Ensure context comes from booted framework:

```php
// Wrong - raw context without loader
$context = new ApplicationContext('/path');
config($context, 'app.name');  // Returns null!

// Correct - context from framework boot
$app = Framework::create('/path')->boot();
config($app->getContext(), 'app.name');  // Works
```

### Extension not loading config

**Problem:** Extension config file not found.

**Solution:** Ensure config file exists and is named correctly:

```
extensions/my-extension/
├── config/
│   └── myextension.php    ← Must match config key
├── src/
│   └── MyExtensionProvider.php
└── extension.json
```

Access with: `config($context, 'myextension.key')`

---

## Quick Reference

### Getting Context

| Location | Method |
|----------|--------|
| Controller | `$this->context` or `$this->getContext()` |
| Command | `$this->getContext()` |
| Service (DI) | Constructor: `__construct(ApplicationContext $context)` |
| Provider | `$this->context` |
| Anywhere | `$container->get(ApplicationContext::class)` |

### Essential Patterns

```php
// Config access
$value = config($context, 'key', 'default');

// Service resolution
$service = app($context, MyService::class);

// Database connection
$db = Connection::fromContext($context);

// Path building
$path = base_path($context, 'storage/logs');

// Event dispatching
$events = container($context)->get(EventService::class);
$events->dispatch(new MyEvent());
```

---

## See Also

- [Global State Removal Implementation Plan](./global-state-removal.md) - Technical details
- [Extension Development Guide](../extensions.md) - Building extensions
- [Testing Guide](../testing.md) - Test patterns and best practices
