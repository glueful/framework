# Glueful Service Registration Design

## Philosophy: Code-first by default, config when you need it
- **Default**: register services in **Service Providers** (developer-friendly, discoverable).
- **Optional**: override/extend via **PHP config** (and/or YAML) that’s compiled into the container for **zero-cost at runtime**.
- **Autowire on, autoconfigure on** by default (type-hint → wiring; common interfaces → tags).

---

## Container lifecycle
Glueful runs three deterministic phases during bootstrap:

1. **register()** – Providers add bindings/definitions (no I/O, no side-effects).
2. **configure()** *(optional)* – Providers read env/config and adjust definitions (parameters, aliases, conditional bindings).
3. **boot()** – Only after the container is “frozen.” Subscribe listeners, warm caches, start telemetry, etc.

---

## Service Providers (code-first)
```php
namespace App\Providers;

use Glueful\DI\Container;
use Glueful\DI\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        // Simple bindings
        $c->bind(\App\Contracts\Clock::class, \App\Infra\SystemClock::class);

        // Singleton
        $c->singleton(\App\Security\TokenManager::class);

        // Factory / closure
        $c->bind(\App\Auth\AuthManager::class, fn (Container $c) => 
            new \App\Auth\AuthManager(
                $c->get(\App\Security\TokenManager::class),
                env('AUTH_DRIVER', 'jwt')
            )
        );

        // Tagged services
        $c->tag(\App\Payments\StripeGateway::class, ['payment.gateway']);
    }

    public function configure(Container $c): void
    {
        if (env('APP_DEBUG')) {
            $c->alias(\Psr\Log\LoggerInterface::class, \App\Log\VerboseLogger::class);
        }
    }

    public function boot(Container $c): void
    {
        $c->get(\App\Telemetry\Tracer::class)->start();
    }
}
```

---

## Attribute/annotation shortcuts
```php
use Glueful\DI\Attributes\Service;
use Glueful\DI\Attributes\Tag;

#[Service(singleton: true)]
#[Tag('payment.gateway')]
final class StripeGateway implements PaymentGateway
{
    public function __construct(
        #[ServiceRef('%env(STRIPE_KEY)%')] string $apiKey
    ) {}
}
```

---

## Config overlay
**config/services.php**
```php
use Glueful\DI\Definition as def;

return [
    'auth.default_driver' => env('AUTH_DRIVER', 'jwt'),

    def::service(\App\Auth\AuthManager::class)
        ->factory(function ($c) {
            return new \App\Auth\AuthManager(
                $c->get(\App\Security\TokenManager::class),
                $c->param('auth.default_driver')
            );
        })
        ->public(),
];
```

---

## Compile step & cache
- During `glueful:container:compile`, Glueful:
  1. Loads providers + attributes + config.
  2. Resolves autowiring, tags, aliases, parameters.
  3. Dumps a **final PHP class** (e.g., `cache/container/CompiledContainer.php`).

---

## Lazy services & deferred providers
- Make **lazy by default** using lightweight proxies.
- **Deferred providers**: only load provider `register()` when a bound id is requested.

---

## Extensions/Modules integration
```php
final class PaymentsExtensionProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->tag(\Vendor\Mod\PayPalGateway::class, ['payment.gateway']);
        $c->tag(\Vendor\Mod\StripeGateway::class, ['payment.gateway']);
    }
}
```

---

## Request scope (HTTP) & middleware interop
- Add **scoped lifetimes**: `scoped()` creates one instance per request.
- Integrate with PSR-15 middleware.

---

## Testing & overrides
```php
$c = TestContainerBuilder::fromCompiled()
      ->override(\Psr\Log\LoggerInterface::class, new NullLogger())
      ->build();
```

---

## Common tasks
- **Alias**: `$c->alias(LoggerInterface::class, MonologLogger::class);`
- **Decorate**: `$c->decorate(PaymentGateway::class, LoggingPaymentGateway::class);`
- **Collect tagged**: `$c->getTagged('payment.gateway')`
- **Env params**: `%env(STRIPE_KEY)%`

---

## Minimal starter template
```
app/Providers/AppServiceProvider.php
app/Providers/HttpServiceProvider.php
app/Providers/AuthServiceProvider.php

config/services.php
bootstrap/app.php
cache/container/CompiledContainer.php
```

**bootstrap/app.php**
```php
use Glueful\Kernel\Kernel;

return Kernel::make()
    ->providers([
        App\Providers\AppServiceProvider::class,
        App\Providers\HttpServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
    ])
    ->compileIfNeeded()
    ->boot();
```
