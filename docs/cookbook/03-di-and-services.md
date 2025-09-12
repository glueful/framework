# Dependency Injection and Services Cookbook

## Table of Contents

1. [Introduction](#introduction)
2. [Container Basics](#container-basics)
3. [Service Registration](#service-registration)
4. [Dependency Injection Patterns](#dependency-injection-patterns)
5. [Service Providers](#service-providers)
6. [Service Factories](#service-factories)
7. [Container Compilation](#container-compilation)
8. [Testing with DI](#testing-with-di)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

## Introduction

The Glueful Framework features a powerful dependency injection container built on top of Symfony's DI component, providing enterprise-grade service management with automatic resolution, service providers, factories, and container compilation for optimal performance.

### Key Features

- **PSR-11 Compatible**: Standard container interface for interoperability
- **Symfony DI Foundation**: Battle-tested dependency injection with advanced features
- **Automatic Resolution**: Constructor dependency injection with type-hinting
- **Service Providers**: Organized service registration with lifecycle management
- **Service Factories**: Dynamic service creation with configuration
- **Container Compilation**: Optimized compiled container for production performance
- **Service Tags**: Advanced service organization and batch processing
- **Runtime Instances**: Override services at runtime for testing and customization

## Container Basics

### Getting Services

The framework provides multiple ways to access services from the container:

```php
// Global helper function (recommended for simplicity)
$logger = container()->get(Psr\Log\LoggerInterface::class);
$cache = container()->get(Glueful\Cache\CacheStore::class);
$router = container()->get(Glueful\Routing\Router::class);

// Direct container access
$container = app()->getContainer();
$database = $container->get('database');
$queryBuilder = $container->get(Glueful\Database\QueryBuilder::class);

// Optional service retrieval
$service = container()->getOptional(Optional\Service::class);
if ($service !== null) {
    // Service exists and was retrieved
}
```

```php
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(App\\Services\\UserService::class)
        ->public(true)
    ->toArray();
```
### Service Resolution

The container supports multiple resolution strategies:

```php
// Interface to implementation resolution
$logger = container()->get(Psr\Log\LoggerInterface::class);
// Resolves to Monolog\Logger or configured implementation

// Class name resolution
$userService = container()->get(App\Services\UserService::class);
// Automatic constructor injection

// Service alias resolution
$cache = container()->get('cache.store');
// Resolves to Glueful\Cache\CacheStore

// Factory-created services
$database = container()->get('database');
// Created by DatabaseFactory with configuration
```

```php
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(ServiceA::class)
        ->args('@'.ServiceB::class)
        // If the container supports lazy flags, set it via DSL/schema
    ->toArray();
```
### Container State and Compilation

```php
$container = container();

// Check if container is compiled (production optimization)
if ($container->isCompiled()) {
    // Container is frozen and optimized
    // No new services can be registered
}

// Check service existence
if ($container->has(CustomService::class)) {
    $service = $container->get(CustomService::class);
}
```

```php
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(Psr\\Log\\LoggerInterface::class)
        ->factory([LoggerFactory::class, 'create'])
        ->public(true)
    ->toArray();
```
## Service Registration

### Recommended: Compile-time via services() + DSL

For production, define services in your provider’s static `services()` method. Use the lightweight DSL to produce the same array shape the compiler consumes. This keeps containers immutable in production and improves performance.

```php
use Glueful\\Extensions\\ServiceProvider;
use Glueful\\DI\\DSL\\Def;
use Glueful\\DI\\DSL\\Utils as U;

final class AppServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return Def::create()
            ->service(App\\Services\\UserService::class)
                ->args(U::ref(App\\Repositories\\UserRepository::class), U::ref(Psr\\Log\\LoggerInterface::class))
                ->public(true)
            ->bind(App\\Contracts\\PaymentInterface::class, App\\Services\\PaymentService::class)
            ->service(App\\Services\\MailService::class)
                ->factory([App\\Factories\\MailFactory::class, 'create'])
                ->call('setFrom', [U::param('mail.from')])
                ->tag('mail.sender', ['priority' => 50])
                ->shared(true)
                ->public(true)
            ->toArray();
    }

    // Use register() for runtime config merging or environment checks, not for
    // defining production services (those belong in static services()).
    public function register(): void
    {
        $this->mergeConfig('mail', require base_path('config/mail.php'));
    }
}
```

Key points:
- Use `U::ref('id')` to reference other services and `U::param('key')` for parameters.
- `bind(Interface, Impl)` ensures a concrete service for `Impl` and attaches the alias to it.
- Avoid mutating the container at runtime in production; rely on static `services()`.

### Application Service Registration (legacy runtime)

You may still see examples using runtime registration APIs (e.g., `$container->register()` or provider helpers) in development or tests. Prefer the compile-time `services()` approach above for production. If you choose runtime registration (dev/test), ensure it doesn’t run in production.

### Service Aliases

Create convenient aliases for services:

```php
use Glueful\DI\DSL\Def;

return Def::create()
  // String alias for easier access
  ->service(App\Services\PaymentService::class)
    ->alias('payment')
    ->public(true)

  // Interface alias (bind interface to implementation)
  ->bind(App\Contracts\PaymentInterface::class, App\Services\PaymentService::class)

  ->toArray();
```

### Service Configuration

Configure services with parameters and options:

```php
use Glueful\DI\DSL\Def;
use Glueful\DI\DSL\Utils as U;

public static function services(): array {
  return Def::create()
    // Register with scalar arguments
    ->service(App\Services\CacheService::class)
      ->args(3600, 'app_cache', U::ref('cache.store'))
      ->public(true)

    // Register with method calls for property injection
    ->service(App\Services\MailService::class)
      ->call('setFrom', [U::param('mail.from')])
      ->call('setReplyTo', [U::param('mail.reply_to')])
      ->public(true)

    ->toArray();
}
```

## Dependency Injection Patterns

### Constructor Injection (Recommended)

The most common and preferred pattern:

```php
use Psr\Log\LoggerInterface;
use App\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger,
        private string $defaultRole = 'user'
    ) {}

    public function createUser(array $data): User
    {
        $this->logger->info('Creating user', ['email' => $data['email']]);
        
        $user = $this->repository->create(array_merge($data, [
            'role' => $this->defaultRole
        ]));
        
        return $user;
    }
}

// Registration via compile-time DSL (services())
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(UserService::class)
        ->args(
            '@'.UserRepository::class,
            '@'.LoggerInterface::class,
            'member' // Override default role
        )
        ->public(true)
    ->toArray();
```

### Interface Dependencies

Design with interfaces for flexibility:

```php
// Define contract
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): bool;
}

// Implementation
class RedisCache implements CacheInterface
{
    public function __construct(private \Redis $redis) {}
    
    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }
    
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->redis->setex($key, $ttl, serialize($value));
    }
}

// Service using interface
class ProductService
{
    public function __construct(
        private CacheInterface $cache,
        private ProductRepository $repository
    ) {}
    
    public function getProduct(int $id): Product
    {
        $cacheKey = "product:{$id}";
        
        if ($cached = $this->cache->get($cacheKey)) {
            return unserialize($cached);
        }
        
        $product = $this->repository->find($id);
        $this->cache->set($cacheKey, serialize($product));
        
        return $product;
    }
}

// Register interface binding via DSL
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(RedisCache::class)
        ->args('@redis')
        ->public(true)
    ->bind(CacheInterface::class, RedisCache::class)
    ->toArray();
```

### Optional Dependencies

Handle optional dependencies gracefully:

```php
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ApiClient
{
    private LoggerInterface $logger;
    
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function makeRequest(string $endpoint): array
    {
        $this->logger->info('Making API request', ['endpoint' => $endpoint]);
        
        // HTTP client logic here
        return [];
    }
}

// Registration with optional logger via DSL
// Because the constructor provides a default `?LoggerInterface $logger = null`,
// we omit the optional third argument here.
use Glueful\\DI\\DSL\\Def;
use Glueful\\DI\\DSL\\Utils as U;
return Def::create()
    ->service(ApiClient::class)
        ->args(U::param('api.base_url'), U::param('api.key'))
        ->public(true)
    ->toArray();
```

## Service Providers

Service providers organize related service registrations:

Enabling your application providers
- App providers: configure in `config/serviceproviders.php` under `enabled` or `dev_only`.
- Vendor extensions: configure in `config/extensions.php` and via Composer discovery.
- For strict environments, you can set an allow-list `only` in either file to control exactly what loads.

### Basic Service Provider

```php
use Glueful\Extensions\ServiceProvider;
use Glueful\DI\DSL\Def;

final class UserServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return Def::create()
            // Repository
            ->service(App\Repositories\UserRepository::class)
                ->args('@database')
                ->public(true)

            // Service with dependencies
            ->service(App\Services\UserService::class)
                ->args('@'.App\Repositories\UserRepository::class, '@'.Psr\Log\LoggerInterface::class)
                ->public(true)

            // Controller
            ->service(App\Controllers\UserController::class)
                ->args('@'.App\Services\UserService::class)
                ->public(true)

            // Aliases
            ->service(App\Services\UserService::class)->alias('user.service')
            ->service(App\Repositories\UserRepository::class)->alias('user.repository')

            ->toArray();
    }
}
```

### Service Provider with Configuration

```php
use Glueful\\Extensions\\ServiceProvider;
use Glueful\\DI\\DSL\\Def;
use Glueful\\DI\\DSL\\Utils as U;
use Psr\\Log\\LoggerInterface;

final class PaymentServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return Def::create()
            ->service(App\\Payment\\StripeGateway::class)
                ->args(U::param('stripe.secret_key'), U::param('stripe.webhook_secret'), '@'.LoggerInterface::class)
                ->public(true)
            ->service(App\\Payment\\PayPalGateway::class)
                ->args(U::param('paypal.client_id'), U::param('paypal.client_secret'), U::param('paypal.mode'))
                ->public(true)
            ->service(App\\Payment\\PaymentGatewayFactory::class)
                ->args('@'.App\\Payment\\StripeGateway::class, '@'.App\\Payment\\PayPalGateway::class)
                ->public(true)
            ->service(App\\Services\\PaymentService::class)
                ->args('@'.App\\Payment\\PaymentGatewayFactory::class, '@'.App\\Repositories\\PaymentRepository::class)
                ->public(true)
            ->toArray();
    }
}
```

### Framework Services (Read-Only Reference)

The framework automatically registers these services for you - **do not modify framework service providers**:

**Available Framework Services:**
- `Psr\Log\LoggerInterface` - PSR-3 logger implementation
- `Glueful\Cache\CacheStore` - Cache service (via `'cache.store'` alias)
- `Glueful\Routing\Router` - HTTP router
- `Glueful\Database\Connection` - Database connection (via `'database'` alias)
- `Glueful\Database\QueryBuilder` - Query builder
- Middleware services: `'auth'`, `'rate_limit'`, `'csrf'`, etc.

**Using Framework Services in Your Application:**


```php
use Glueful\\Extensions\\ServiceProvider;
use Glueful\\DI\\DSL\\Def;

final class AppServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return Def::create()
            ->service(App\\Services\\ReportService::class)
                ->args('@database', '@cache.store', '@'.Psr\\Log\\LoggerInterface::class)
                ->public(true)
            ->toArray();
    }
}
```

## Service Factories

Factories provide dynamic service creation with configuration:

### Basic Service Factory

```php
use Glueful\Bootstrap\ConfigurationCache;

class EmailServiceFactory
{
    public static function create(): EmailServiceInterface
    {
        $config = ConfigurationCache::get('mail', []);
        
        return match ($config['driver'] ?? 'smtp') {
            'smtp' => new SmtpEmailService(
                $config['smtp']['host'],
                $config['smtp']['port'],
                $config['smtp']['username'],
                $config['smtp']['password']
            ),
            'sendmail' => new SendmailEmailService($config['sendmail']['path']),
            'log' => new LogEmailService(container()->get(LoggerInterface::class)),
            default => throw new \InvalidArgumentException("Unsupported mail driver: {$config['driver']}")
        };
    }
}

// Register factory in service provider (DSL)
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(EmailServiceInterface::class)
        ->factory([EmailServiceFactory::class, 'create'])
        ->public(true)
    ->toArray();
```

### Configuration-Driven Factory

```php
class CacheStoreFactory
{
    public static function create(): CacheInterface
    {
        $config = ConfigurationCache::get('cache', []);
        $driver = $config['driver'] ?? 'array';
        
        return match ($driver) {
            'redis' => self::createRedisDriver($config['redis'] ?? []),
            'memcached' => self::createMemcachedDriver($config['memcached'] ?? []),
            'file' => self::createFileDriver($config['file'] ?? []),
            'array' => new ArrayCacheDriver(),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
        };
    }
    
    private static function createRedisDriver(array $config): RedisCacheDriver
    {
        $redis = new \Redis();
        $redis->connect(
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 6379
        );
        
        if (isset($config['password'])) {
            $redis->auth($config['password']);
        }
        
        if (isset($config['database'])) {
            $redis->select($config['database']);
        }
        
        return new RedisCacheDriver($redis, $config['prefix'] ?? '');
    }
    
    private static function createMemcachedDriver(array $config): MemcachedCacheDriver
    {
        $memcached = new \Memcached();
        
        $servers = $config['servers'] ?? [['127.0.0.1', 11211]];
        foreach ($servers as $server) {
            $memcached->addServer($server[0], $server[1]);
        }
        
        return new MemcachedCacheDriver($memcached);
    }
    
    private static function createFileDriver(array $config): FileCacheDriver
    {
        $path = $config['path'] ?? storage_path('cache');
        
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        return new FileCacheDriver($path);
    }
}
```

### Factory with Dependencies

```php
class DatabaseFactory
{
    public static function create(): Connection
    {
        $config = ConfigurationCache::get('database', []);
        $logger = container()->getOptional(LoggerInterface::class);
        
        $connection = new Connection([
            'driver' => $config['driver'] ?? 'mysql',
            'host' => $config['host'] ?? 'localhost',
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => $config['charset'] ?? 'utf8mb4',
            'options' => $config['options'] ?? []
        ]);
        
        if ($logger) {
            $connection->setLogger($logger);
        }
        
        // Enable query logging in development
        if (env('APP_ENV') === 'development') {
            $connection->enableQueryLog();
        }
        
        return $connection;
    }
}
```

## Container Compilation

Container compilation optimizes performance for production:

### Development vs Production

```php
use Glueful\DI\ContainerFactory;
use Glueful\DI\ContainerCompiler;

// Development mode - dynamic container
$container = ContainerFactory::create([
    new CoreServiceProvider(),
    new UserServiceProvider(),
    new PaymentServiceProvider()
]);

// Production mode - compiled container
if (env('APP_ENV') === 'production') {
    $compiler = new ContainerCompiler();
    $compiled = $compiler->compile($container, [
        'cache_dir' => storage_path('container'),
        'class_name' => 'CompiledContainer',
        'namespace' => 'App\\DI'
    ]);
    
    $container = $compiled;
}
```

### Compilation Benefits

- **Performance**: 10-50x faster service resolution
- **Memory**: Reduced memory usage through optimization
- **Validation**: Compile-time service validation
- **Caching**: Service definitions cached as PHP code

```php
// Before compilation - dynamic resolution
$service = $container->get(UserService::class);
// Requires: alias lookup + dependency resolution + instantiation

// After compilation - direct instantiation
$service = $container->get(UserService::class);
// Direct: return new UserService($this->getUserRepository(), $this->getLogger());
```

### Container Dumping

```php
// Dump container for production
$dumper = new PhpDumper($containerBuilder);
$compiledCode = $dumper->dump([
    'class' => 'CompiledContainer',
    'namespace' => 'App\\DI',
    'file' => storage_path('container/CompiledContainer.php')
]);

file_put_contents(storage_path('container/CompiledContainer.php'), $compiledCode);

// Load compiled container
require storage_path('container/CompiledContainer.php');
$container = new \App\DI\CompiledContainer();
```

## Testing with DI

Dependency injection makes testing much easier:

### Service Mocking

```php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class UserServiceTest extends TestCase
{
    private UserRepository|MockObject $repository;
    private LoggerInterface|MockObject $logger;
    private UserService $service;
    
    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new UserService($this->repository, $this->logger);
    }
    
    public function test_create_user_logs_creation(): void
    {
        $userData = ['email' => 'test@example.com', 'name' => 'Test User'];
        $expectedUser = new User($userData);
        
        $this->repository->expects($this->once())
            ->method('create')
            ->with($userData + ['role' => 'user'])
            ->willReturn($expectedUser);
        
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Creating user', ['email' => 'test@example.com']);
        
        $result = $this->service->createUser($userData);
        
        $this->assertSame($expectedUser, $result);
    }
}
```

### Container Testing

```php
class ContainerTest extends TestCase
{
    private Container $container;
    
    protected function setUp(): void
    {
        $containerBuilder = new ContainerBuilder();
        $serviceProvider = new UserServiceProvider();
        $serviceProvider->register($containerBuilder);
        
        $this->container = new Container($containerBuilder);
    }
    
    public function test_user_service_is_registered(): void
    {
        $this->assertTrue($this->container->has(UserService::class));
        $this->assertTrue($this->container->has('user.service'));
    }
    
    public function test_user_service_has_dependencies(): void
    {
        $service = $this->container->get(UserService::class);
        
        $this->assertInstanceOf(UserService::class, $service);
        // Dependencies are automatically injected
    }
    
    public function test_can_override_service_for_testing(): void
    {
        $mockService = $this->createMock(UserService::class);
        $this->container->set(UserService::class, $mockService);
        
        $retrieved = $this->container->get(UserService::class);
        $this->assertSame($mockService, $retrieved);
    }
}
```

### Integration Testing

```php
class UserControllerIntegrationTest extends TestCase
{
    public function test_create_user_endpoint(): void
    {
        // Create application with container
        $app = Framework::create(getcwd())->boot();
        $container = $app->getContainer();
        
        // Override services for testing
        $mockRepository = $this->createMock(UserRepository::class);
        $mockRepository->method('create')->willReturn(new User(['id' => 1]));
        
        $container->set(UserRepository::class, $mockRepository);
        
        // Test the endpoint
        $response = $app->handle(Request::create('/api/users', 'POST', [], [], [], [], 
            json_encode(['email' => 'test@example.com'])
        ));
        
        $this->assertEquals(201, $response->getStatusCode());
    }
}
```

## Best Practices

### 1. Dependency Injection Principles

```php
// ✅ Good: Constructor injection with interfaces
class OrderService
{
    public function __construct(
        private PaymentInterface $payment,
        private NotificationInterface $notification,
        private LoggerInterface $logger
    ) {}
}

// ❌ Bad: Service location pattern
class OrderService
{
    public function processOrder(Order $order): void
    {
        $payment = container()->get('payment'); // Service location
        $payment->charge($order->getTotal());
    }
}

// ✅ Good: Explicit dependencies
class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private TemplateEngine $templates
    ) {}
}

// ❌ Bad: Hidden dependencies
class EmailService
{
    public function sendEmail(): void
    {
        $mailer = container()->get(MailerInterface::class); // Hidden dependency
    }
}
```

### 2. Service Design

```php
// ✅ Good: Single responsibility, clear dependencies
class UserRegistrationService
{
    public function __construct(
        private UserRepository $repository,
        private PasswordHasher $hasher,
        private EmailService $emailService,
        private EventDispatcher $events
    ) {}
    
    public function register(array $userData): User
    {
        $userData['password'] = $this->hasher->hash($userData['password']);
        $user = $this->repository->create($userData);
        
        $this->emailService->sendWelcomeEmail($user);
        $this->events->dispatch(new UserRegistered($user));
        
        return $user;
    }
}

// ❌ Bad: Too many responsibilities
class UserService
{
    // Does user management, email, payments, logging, etc.
    // Should be split into focused services
}
```

### 3. Interface Segregation

```php
// ✅ Good: Focused interfaces
interface UserRepositoryInterface
{
    public function find(int $id): ?User;
    public function create(array $data): User;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}

interface UserNotificationInterface
{
    public function sendWelcomeEmail(User $user): void;
    public function sendPasswordResetEmail(User $user, string $token): void;
}

// ❌ Bad: Fat interface
interface UserManagerInterface
{
    public function find(int $id): ?User;
    public function create(array $data): User;
    public function sendEmail(User $user, string $template): void;
    public function hashPassword(string $password): string;
    public function generateApiKey(): string;
    // ... many more unrelated methods
}
```

### 4. Service Provider Organization

Organize your application service providers by domain, not by technical layers:

```php
use Glueful\\Extensions\\ServiceProvider;
use Glueful\\DI\\DSL\\Def;

final class UserServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return Def::create()
            // Repositories
            ->service(App\\Repositories\\UserRepository::class)
                ->args('@database')
                ->public(true)

            // Services
            ->service(App\\Services\\UserService::class)
                ->args('@'.App\\Repositories\\UserRepository::class, '@'.Psr\\Log\\LoggerInterface::class)
                ->public(true)

            // Middleware as a service (if applicable)
            ->service(App\\Middleware\\UserPermissionMiddleware::class)
                ->args('@'.App\\Services\\UserService::class)
                ->public(true)
            ->service(App\\Middleware\\UserPermissionMiddleware::class)->alias('user_permission')

            ->toArray();
    }
}
```

### 5. Configuration Management

```php
// ✅ Good: Configuration injection
class PaymentService
{
    public function __construct(
        private PaymentGateway $gateway,
        private string $merchantId,
        private bool $sandboxMode,
        private LoggerInterface $logger
    ) {}
}

// Registration with configuration (DSL)
use Glueful\\DI\\DSL\\Def;
use Glueful\\DI\\DSL\\Utils as U;
return Def::create()
    ->service(PaymentService::class)
        ->args('@'.PaymentGateway::class, U::param('payment.merchant_id'), U::param('payment.sandbox'), '@'.LoggerInterface::class)
        ->public(true)
    ->toArray();

// ❌ Bad: Configuration access in service
class PaymentService
{
    public function processPayment(): void
    {
        $config = ConfigurationCache::get('payment'); // Direct config access
        $merchantId = $config['merchant_id'];
    }
}
```

## Troubleshooting

### Common Issues

**Service not found:**
```php
// Error: Service 'App\Services\UserService' not found
// Solution: Register in service provider
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(App\\Services\\UserService::class)
        ->public(true)
    ->toArray();
```

**Circular dependency:**
```php
// Error: Circular reference detected
// Problem: Service A depends on Service B, which depends on Service A
// Solution: Refactor to remove circular dependency (or use lazy proxies if supported)
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(ServiceA::class)
        ->args('@'.ServiceB::class)
        ->public(true)
    ->toArray();
```

**Missing dependencies:**
```php
// Error: Cannot resolve parameter $logger
// Solution: Register the dependency in services() via DSL
use Glueful\\DI\\DSL\\Def;
return Def::create()
    ->service(Psr\\Log\\LoggerInterface::class)
        ->factory([LoggerFactory::class, 'create'])
        ->public(true)
    ->toArray();
```

### Debug Commands

```bash
# Debug and inspect the DI container services
# List all registered services
php glueful di:container:debug --services

# Inspect specific service details (definition, dependencies, tags, aliases)
php glueful di:container:debug UserService

# Show service tags
php glueful di:container:debug --tags

# Show service aliases  
php glueful di:container:debug --aliases

# Analyze service graph
php glueful di:container:debug --graph

# Validate container configuration and service definitions
php glueful di:container:validate

# Validate specific service only
php glueful di:container:validate --service=UserService

# Test actual service instantiation (may have side effects)
php glueful di:container:validate --check-instantiation

# Check for circular dependencies
php glueful di:container:validate --check-circular

# Compile the DI container for production optimization
php glueful di:container:compile

# Compile with debug information (slower but with debugging features)
php glueful di:container:compile --debug

# Compile to specific directory
php glueful di:container:compile --output-dir=storage/cache/container
```

### Debug Service Resolution

```php
// Enable container debugging
$container = ContainerFactory::create($providers, [
    'debug' => true,
    'track_references' => true
]);

// Check service registration
if (!$container->has(UserService::class)) {
    throw new \Exception('UserService not registered');
}

// Debug service creation
try {
    $service = $container->get(UserService::class);
} catch (\Exception $e) {
    logger()->error('Service creation failed', [
        'service' => UserService::class,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    throw $e;
}
```

## Conclusion

The Glueful dependency injection container provides a robust foundation for building maintainable, testable applications. By leveraging constructor injection, service providers, factories, and container compilation, you can create flexible architectures that scale from simple applications to complex enterprise systems.

The framework's DI system promotes SOLID principles, makes testing easier, and provides the performance optimizations needed for production environments through container compilation.

For more examples and advanced usage, see the [service provider implementations](../../src/DI/ServiceProviders/) and [service factory examples](../../src/DI/ServiceFactories/).
