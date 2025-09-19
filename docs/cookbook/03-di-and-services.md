# Dependency Injection and Services Cookbook

## Table of Contents

1. Introduction
2. Container Basics
3. Service Registration
4. Dependency Injection Patterns
5. Service Providers
6. Service Factories
7. Container Compilation
8. Testing with DI
9. Best Practices
10. Troubleshooting

## Introduction

Glueful ships a lightweight, fast PSR‑11 container tailored to the framework. It supports constructor autowiring, explicit factories, aliases, tagging, and a PHP code generator that compiles definitions for production. This document reflects the current container implementation used by the framework.

Highlights:
- PSR‑11 compatible interface
- Constructor autowiring with an Inject attribute
- Simple array DSL for app/extension providers
- Aliases and interface bindings
- Tags and tag‑based iterators
- Lazy warmup groups (background/request‑time)
- Compile‑to‑PHP container for production (best‑effort, with fallback)

## Container Basics

### Getting services

```php
// Using helpers
$logger = app(Psr\Log\LoggerInterface::class);
$cache  = app(Glueful\Cache\CacheStore::class);
$router = app(Glueful\Routing\Router::class);

// PSR-11 directly
$c = container();
$database = $c->get('database'); // some services are string IDs

// Optional access pattern
if (has_service(Glueful\Auth\AuthenticationService::class)) {
    $auth = app(Glueful\Auth\AuthenticationService::class);
}
```

Notes:
- There is no `getOptional()`; use `has_service()` as a guard.
- `parameter('key')` reads parameters via the ParamBag/config if exposed.

### Resolution semantics

- Class name: `app(Foo\Bar::class)` autowires constructor dependencies.
- Aliases: string IDs (e.g., `'cache.store'`, `'database'`) resolve to services.
- Interfaces: bind via alias so `app(Interface::class)` returns the implementation.
- Parameters: inject via `#[Glueful\Container\Autowire\Inject(param: 'key')]` when autowiring, or read via `parameter('key')` inside a factory.

### Tags and tagged iterators

Services may be tagged. Each tag is exposed as a container service of the same name that resolves to an array of instances ordered by `priority` descending. Example: `app('my.tag')` returns an array of tagged services.

Special lazy warmup tags:
- `lazy.background` — warmed after the first response returns
- `lazy.request_time` — warmed during first request processing

CLI: `php glueful di:lazy:status [--warm-background] [--warm-request]`

## Service Registration

Providers should define services in a static `services()` method (preferred) that returns a simple array DSL. Advanced users may provide a static `defs()` that returns typed definitions; if both exist, `defs()` is used.

### The services() array DSL

Supported keys per service entry:
- `class` string: concrete class (if omitted and the ID is a FQCN, the ID is used)
- `autowire` bool: constructor autowiring (default false; set true for class autowire)
- `factory` callable|string|array: one of `fn(Container $c) => ...`, `'Class::method'`, `[ClassName::class, 'method']`, or `['@service.id','method']`
- `arguments` array: constructor args; strings beginning with `@` are treated as service references
- `shared` bool: singleton when true (default true). `singleton` or `bind` keys map to `shared` as shorthands
- `alias` string|array: create alias IDs that resolve to this service
- `tags` array: list of tag names or maps like `['name' => 'tag.name', 'priority' => 10]`

Notes:
- Anonymous Closures in `factory` are allowed in development but rejected by the production compiler; prefer class/method factories for production.
- Use `#[Inject]` for parameter/config injection with autowiring.

Example:

```php
use Glueful\Extensions\ServiceProvider;
use Psr\Log\LoggerInterface;

final class AppServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return [
            // Autowired service (singleton by default)
            App\Services\UserService::class => [
                'autowire' => true,
                'alias' => 'user_service',
                'tags' => [['name' => 'domain.user', 'priority' => 50]],
            ],

            // Interface binding via alias
            App\Services\RedisCache::class => [
                'autowire' => true,
                'alias' => App\Contracts\CacheInterface::class,
            ],

            // Factory service
            LoggerInterface::class => [
                'factory' => [App\Factories\LoggerFactory::class, 'create'],
                'shared' => true,
            ],

            // String ID alias convenience
            'payment' => [
                'class' => App\Services\PaymentService::class,
                'autowire' => true,
            ],
        ];
    }
}
```

### DSL Cheatsheet and Shorthands

Service spec keys:
- `class` string — FQCN; defaults to the ID if the ID is a FQCN.
- `autowire` bool — emit autowired class definition; ignores `arguments`.
- `factory` — any of: `'Class::method'`, `[ClassName::class, 'method']`, `['@service.id','method']`, or a Closure (dev only).
- `arguments` array — constructor args; values starting with `@` are service refs.
- `shared` bool — singleton when true (default: true).
- `alias` string|array — additional IDs that resolve to the same service.
- `tags` array — either `['tag.name', 'other.tag']` or `[['name' => 'tag.name', 'priority' => 10]]`.

Shorthands:
- `singleton: true|false` → maps to `shared`.
- `bind: true|false` → maps to `shared` (bind=true → shared; bind=false → not shared).

Service references:
- Use `'@id'` inside `arguments` and in factory target arrays. `'@'` alone is invalid.

Production rules (enforced by loader/compiler):
- No Closure factories in production.
- No arbitrary object instances in `arguments` in production (scalars/arrays/enums only).

Examples:

```php
return [
    // Class + arguments (singleton)
    'mail.transport' => [
        'class' => App\Mail\Transport::class,
        'arguments' => ['smtp', 587, '@'.Psr\Log\LoggerInterface::class],
        'singleton' => true, // shorthand
        'tags' => ['lazy.request_time'],
    ],

    // Autowire
    App\Search\Indexer::class => [
        'autowire' => true,
        'bind' => true, // shorthand for shared
        'alias' => 'search.indexer',
    ],

    // Factory using service method
    'blog.client' => [
        'class' => Vendor\Blog\Client::class,
        'factory' => ['@http.client', 'forBlog'],
        'shared' => true,
        'alias' => [Vendor\Blog\Client::class, 'blog.http'],
        'tags' => [['name' => 'lazy.background', 'priority' => 5]],
    ],

    // Static factory method string
    Psr\Log\LoggerInterface::class => [
        'factory' => App\Factories\LoggerFactory::class.'::create',
        'shared' => true,
    ],
];
```

### Typed defs() examples (advanced)

For maximum performance and explicit control, providers can return typed definitions. Framework providers commonly extend `Glueful\Container\Providers\BaseServiceProvider` which offers helpers for autowire, alias, and tag.

```php
use Glueful\Container\Providers\BaseServiceProvider;
use Glueful\Container\Definition\{FactoryDefinition, ValueDefinition, DefinitionInterface};

final class CoreProvider extends BaseServiceProvider
{
    /** @return array<string, DefinitionInterface|callable|mixed> */
    public function defs(): array
    {
        $defs = [];

        // Autowire singleton
        $defs[App\Services\HealthService::class] = $this->autowire(App\Services\HealthService::class);

        // Factory definition (shared)
        $defs['db.pool'] = new FactoryDefinition(
            'db.pool',
            fn(\Psr\Container\ContainerInterface $c) => \Vendor\Db\Pool::fromConfig((array) config('database.pool', []))
        );

        // String alias for convenience
        $defs['health'] = $this->alias('health', App\Services\HealthService::class);

        // Parameter/value style service
        $defs['feature.flags'] = new ValueDefinition('feature.flags', [
            'beta' => (bool) config('app.beta', false),
        ]);

        // Tag for lazy warmup (higher priority warms earlier)
        $this->tag('db.pool', 'lazy.background', 10);

        return $defs;
    }
}
```

Extension providers can also publish tags via a static `tags()` method which ContainerFactory reads when assembling the container:

```php
final class MyExtensionProvider extends \Glueful\Extensions\ServiceProvider
{
    public static function services(): array { /* ... */ }

    public static function tags(): array
    {
        return [
            'lazy.request_time' => [
                'payment', // string ID
                ['service' => App\Search\Indexer::class, 'priority' => 5],
            ],
        ];
    }
}
```

## Dependency Injection Patterns

### Constructor injection (recommended)

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
}

// Registration
public static function services(): array
{
    return [
        App\Services\UserService::class => [
            'autowire' => true,
            // Explicit args (optional) — 'arguments' => ['@'.UserRepository::class, '@'.LoggerInterface::class, 'member']
        ],
    ];
}
```

### Interface dependencies

```php
interface CacheInterface { /* ... */ }
class RedisCache implements CacheInterface
{
    public function __construct(private \Redis $redis) {}
}

class ProductService
{
    public function __construct(private CacheInterface $cache) {}
}

// Bind interface by aliasing to the implementation entry
public static function services(): array
{
    return [
        RedisCache::class => [
            'autowire' => true,
            'arguments' => ['@redis'],
            'alias' => CacheInterface::class,
        ],
    ];
}
```

### Optional dependencies and config

Use `#[Inject]` for configuration values and constructor defaults for optional services.

```php
use Glueful\Container\Autowire\Inject;
use Psr\Log\LoggerInterface;

class ApiClient
{
    public function __construct(
        #[Inject(param: 'api.base_url')] private string $baseUrl,
        #[Inject(param: 'api.key')] private string $apiKey,
        ?LoggerInterface $logger = null,
    ) {}
}

public static function services(): array
{
    return [ ApiClient::class => ['autowire' => true] ];
}
```

## Service Providers

Service providers organize service registration and lifecycle.

Enable providers via config:
- App providers: `config/serviceproviders.php` (`enabled`, `dev_only`, or `only` for allow‑list)
- Vendor extensions: `config/extensions.php` (`enabled`, `dev_only`, `disabled`, optional Composer scan)

Example provider:

```php
use Glueful\Extensions\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public static function services(): array { return [/* ... */]; }

    public function register(): void
    {
        // merge config, register routes, migrations, etc.
        $this->mergeConfig('payment', require base_path('config/payment.php'));
    }

    public function boot(): void
    {
        // optional: runs after all providers are registered
    }
}
```

Framework‑provided services (selection):
- `Psr\Log\LoggerInterface` — PSR‑3 logger
- `Glueful\Cache\CacheStore` — cache (also `'cache.store'`)
- `Glueful\Routing\Router` — HTTP router
- `'database'` — database connection factory
- `Glueful\Database\QueryBuilder` — query builder
- Middleware aliases: `'auth'`, `'rate_limit'`, `'csrf'`, `'metrics'`, `'tracing'`, etc.

## Service Factories

Factories provide dynamic service creation. Prefer class/method factories for production.

```php
use Glueful\Bootstrap\ConfigurationCache;
use Psr\Log\LoggerInterface;

class EmailServiceFactory
{
    public static function create(\Psr\Container\ContainerInterface $c): EmailServiceInterface
    {
        $config = ConfigurationCache::get('mail', []);
        return match ($config['driver'] ?? 'smtp') {
            'smtp' => new SmtpEmailService(/* ... */),
            'sendmail' => new SendmailEmailService(/* ... */),
            'log' => new LogEmailService($c->get(LoggerInterface::class)),
            default => throw new \InvalidArgumentException('Unsupported mail driver'),
        };
    }
}

public static function services(): array
{
    return [
        EmailServiceInterface::class => [
            'factory' => [EmailServiceFactory::class, 'create'],
            'shared' => true,
        ],
    ];
}
```

## Container Compilation

Glueful compiles service definitions to a compact PHP class in production. The framework automatically prefers a precompiled container at `storage/cache/container/CompiledContainer.php`; otherwise it attempts best‑effort compilation at runtime and falls back to the dynamic container if unsupported definitions are present.

CLI support:

```bash
php glueful di:container:debug --services                # List services
php glueful di:container:debug My\\Service                # Inspect a service
php glueful di:container:debug --aliases                 # Show aliases
php glueful di:container:debug --tags                    # Show tags
php glueful di:container:debug --parameters              # Show parameters
php glueful di:container:validate --check-circular       # Check circular deps
php glueful di:container:compile --optimize              # Compile for prod
php glueful di:lazy:status --warm-background             # Warm background set
```

Compiler support matrix:
- Supported: AutowireDefinition, ValueDefinition, TaggedIteratorDefinition, AliasDefinition
- Not compiled (fallback to runtime): FactoryDefinition and any definition involving runtime closures or non‑serializable objects

## Testing with DI

Unit tests:
- Construct services directly and pass mock dependencies.

Integration tests:
- Boot the framework to build the real container, then resolve services via `app()`.
- For overrides, layer a child container with `container()->with([ Service::class => fn($c) => new FakeService(), ])` and inject it where appropriate (e.g., into console commands or your own entrypoints).

## Best Practices

- Prefer constructor injection; avoid pulling from the container inside services.
- Bind interfaces via aliases and target the interface in your constructors.
- Keep services single‑purpose; split responsibilities rather than adding flags.
- Inject configuration with `#[Inject(param: 'key')]` instead of reading config in method bodies.
- Use tags for batch operations and to defer heavy warmups to lazy groups.

## Troubleshooting

Common issues:

Missing service:
```php
if (!has_service(App\Services\UserService::class)) {
    throw new \RuntimeException('UserService not registered');
}
```

Circular dependency:
```text
Glueful\Container\Exception\ContainerException: Circular dependency detected: A -> B -> A
```
Refactor to break the cycle (extract an interface, use a factory, or invert one dependency).

Debugging tools:
- `php glueful di:container:debug` to inspect services, aliases, tags, and parameters
- `php glueful di:container:validate` to validate graphs, circular refs, and providers
- `php glueful di:container:compile` to precompile for production
