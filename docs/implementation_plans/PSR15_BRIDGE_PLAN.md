# PSR‑15 Middleware Bridge Plan

Objective: support native PSR‑15 (Psr\Http\Server\MiddlewareInterface/RequestHandlerInterface) middleware alongside Glueful’s middleware, without forcing PSR‑7.

Clarification: existing Glueful middleware is not PSR‑7
- `src/Routing/RouteMiddleware` is Glueful’s native middleware contract built on Symfony HttpFoundation (`Request`/`Response`), not PSR‑7.
- `src/Http/Middleware/Psr15MiddlewareInterface` and `RequestHandlerInterface` are “PSR‑15 compatible” look‑alikes using HttpFoundation types. They ease local interop but they are not the PSR interfaces.
- This plan introduces true PSR‑15 support (using the actual `Psr\Http\Server\*` interfaces) via adapters and optional PSR‑7 bridges.

## Current State

- Internal interfaces exist under `src/Http/Middleware` (RequestHandlerInterface, Psr15MiddlewareInterface) using Symfony HttpFoundation (Request/Response).
- Router resolves middleware strings to container services and expects a `handle(Request $req, callable $next, ...$params)` signature.

## Strategy

1) Accept true PSR‑15 middleware in the pipeline
- Add a resolver branch that, when a service implements `Psr\Http\Server\MiddlewareInterface`, wraps it in an adapter callable.
- Use `symfony/psr-http-message-bridge` and a PSR‑7 implementation (nyholm/psr7) as optional deps.
- If bridge classes are unavailable, throw a clear configuration error when such middleware is encountered.

2) Provide adapters (both directions)
- `Glueful\Bridge\Psr15\MiddlewareAdapter`:
  - Input: PSR‑15 middleware + PSR‑7 factory/bridge.
  - Output: callable(Request $req, callable $next): Response (compatible with Router pipeline).
- `Glueful\Bridge\Psr15\RequestHandlerAdapter`:
  - Wraps a `callable(Request): Response` (next in pipeline) as PSR‑15 `RequestHandlerInterface`.
- `Glueful\Bridge\Psr15\GluefulMiddlewareAsPsr15`:
  - Wraps a Glueful middleware service (with `handle`) as a PSR‑15 middleware for use by external stacks.

3) Configuration
- New config `http.psr15`:
  - `enabled` (bool), `auto_detect` (bool), `psr7_factory` service id (optional), `throw_on_missing_bridge` (bool).
- Update docs with usage examples and dependency notes.

## Minimal Implementation Sketch

```php
// Router::resolveMiddleware addition (conceptual)
if (is_string($middleware)) {
    $instance = $this->container->get($name);
    if (interface_exists(\Psr\Http\Server\MiddlewareInterface::class)
        && $instance instanceof \Psr\Http\Server\MiddlewareInterface) {
        return [Psr15Adapter::class, 'wrap']($instance, $this->container);
    }
}
```

```php
// Psr15Adapter::wrap (conceptual)
public static function wrap(MiddlewareInterface $mw, Container $c): callable {
    $psrFactory = $c->getOptional('psr7.factory'); // optional
    $bridge = new HttpFoundationFactory();
    $psrBridge = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);
    return function (Request $req, callable $next) use ($mw, $bridge, $psrBridge) {
        $handler = new RequestHandlerAdapter($next, $psrBridge, $bridge);
        $psrRequest = $psrBridge->createRequest($req);
        $psrResponse = $mw->process($psrRequest, $handler);
        return $bridge->createResponse($psrResponse);
    };
}
```

## Implementation Enhancements

### 1. Enhanced Adapter Pattern with Caching
```php
// Cache expensive bridge instances for better performance
class Psr15AdapterFactory 
{
    private static ?HttpFoundationFactory $httpFoundationBridge = null;
    private static ?PsrHttpFactory $psrBridge = null;
    
    public static function wrap(MiddlewareInterface $middleware, Container $container): callable
    {
        // Cache expensive bridge instantiation
        if (self::$httpFoundationBridge === null) {
            $psrFactory = $container->getOptional('psr7.factory');
            self::$httpFoundationBridge = new HttpFoundationFactory();
            self::$psrBridge = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);
        }
        
        return function (Request $req, callable $next) use ($middleware) {
            $handler = new RequestHandlerAdapter($next, self::$psrBridge, self::$httpFoundationBridge);
            $psrRequest = self::$psrBridge->createRequest($req);
            $psrResponse = $middleware->process($psrRequest, $handler);
            return self::$httpFoundationBridge->createResponse($psrResponse);
        };
    }
}
```

### 2. Enhanced Configuration with Validation
```php
// config/http.php
return [
    'psr15' => [
        'enabled' => env('PSR15_ENABLED', true),
        'auto_detect' => env('PSR15_AUTO_DETECT', true),
        'psr7_factory' => env('PSR7_FACTORY', 'nyholm'), // nyholm|guzzle|laminas
        'throw_on_missing_bridge' => env('PSR15_STRICT', true),
        'cache_adapters' => env('PSR15_CACHE_ADAPTERS', true), // Performance optimization
        'popular_packages' => [
            'cors' => \Middlewares\Cors::class,
            'security_headers' => \Middlewares\SecurityHeaders::class,
            'uuid' => \Middlewares\Uuid::class,
        ]
    ]
];
```

### 3. Developer Experience Helpers
```php
// Enhanced service provider methods
abstract class ServiceProvider 
{
    protected function registerPsr15Middleware(string $alias, string $middlewareClass): void
    {
        $this->container->set($alias, function() use ($middlewareClass) {
            if (!class_exists($middlewareClass)) {
                throw new \RuntimeException(
                    "PSR-15 middleware {$middlewareClass} not found. " .
                    "Install with: composer require " . $this->getPackageForMiddleware($middlewareClass)
                );
            }
            return new $middlewareClass();
        });
    }
    
    protected function getPackageForMiddleware(string $class): string
    {
        // Map common middleware to their packages
        return match($class) {
            \Middlewares\Cors::class => 'middlewares/cors',
            \Middlewares\SecurityHeaders::class => 'middlewares/security-headers',
            \Middlewares\Uuid::class => 'middlewares/uuid',
            default => 'vendor/package'
        };
    }
}
```

### 4. Enhanced Error Messages
```php
// In Router::resolveMiddleware
if ($instance instanceof \Psr\Http\Server\MiddlewareInterface) {
    if (!class_exists(PsrHttpFactory::class)) {
        throw new \RuntimeException(
            "PSR-15 middleware '" . get_class($instance) . "' requires PSR-7 bridge. " .
            "Install dependencies with: composer require symfony/psr-http-message-bridge nyholm/psr7"
        );
    }
    
    if (!$this->config('http.psr15.enabled', true)) {
        throw new \RuntimeException(
            "PSR-15 middleware support is disabled. Enable in config/http.php or set PSR15_ENABLED=true"
        );
    }
    
    return Psr15AdapterFactory::wrap($instance, $this->container);
}
```

## Phased Delivery

- **Phase A**: Detect PSR‑15 middleware and wrap if PSR‑7 bridge present; docs + examples.
- **Phase B**: Ship adapters (Glueful→PSR‑15) for outward interop; add sample integration tests.
- **Phase C**: Add error messages and config toggles; finalize documentation.
- **Phase D**: Popular package integration with pre-configured middleware aliases.
- **Phase E**: Performance optimization with adapter caching and benchmarks.

## Enhanced Acceptance Criteria

### Phase A - Core Functionality
- Can register and execute a PSR‑15 middleware in a Glueful route group.
- Example app shows both Glueful and PSR‑15 middleware in one pipeline.
- CI exercises the adapter paths (with and without bridge packages installed).

### Phase B - Developer Experience  
- Clear error messages provide actionable installation commands when dependencies are missing.
- Configuration validation prevents common setup issues.
- Service provider helpers simplify PSR-15 middleware registration.

### Phase C - Performance & Reliability
- Performance benchmarks show <5% overhead for PSR-15 bridge usage.
- Memory usage remains bounded under high middleware load scenarios.
- Adapter caching reduces instantiation overhead in production.

### Phase D - Ecosystem Integration
- Pre-configured popular PSR-15 middleware packages work out-of-the-box.
- Documentation includes integration examples for common use cases.
- Automatic package detection provides helpful installation suggestions.

### Phase E - Production Readiness
- Comprehensive test coverage includes error conditions and edge cases.
- Performance monitoring and metrics collection for bridge usage.
- Production deployment guide with optimization recommendations.

## Quickstart

- Install bridges and PSR‑17 factories
  ```bash
  composer require symfony/psr-http-message-bridge nyholm/psr7
  composer dump-autoload
  ```

- Enable detection via config
  ```php
  // config/http.php
  return [
    'psr15' => [
      'enabled' => true,
      'auto_detect' => true,
      // Optional: provide a PSR‑17 factories provider via DI service id
      // 'factory_provider' => 'psr17.factory_provider',
      'throw_on_missing_bridge' => true,
      'popular_packages' => [
        // 'cors' => \Middlewares\Cors::class,
      ],
    ],
  ];
  ```

- Use a PSR‑15 middleware by alias in routes (optional)
  ```php
  // After binding a PSR-15 class to 'psr15.cors' via the service provider
  $router->group(['middleware' => ['psr15.cors']], function($r) {
      $r->get('/ping', fn() => new \Glueful\Http\Response(['ok' => true]));
  });
  ```

- Non‑strict mode
  - If `throw_on_missing_bridge` is false and the PSR‑7 bridge isn’t installed,
    Glueful inserts a no‑op passthrough for PSR‑15 middleware so requests still flow.
  - In strict mode (true), missing bridges cause a clear error prompting installation.
