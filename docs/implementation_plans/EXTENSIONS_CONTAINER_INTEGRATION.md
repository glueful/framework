# Extensions + New Container Integration

Status: Draft (proposal only; not implemented)

Note: Current runtime supports only `Glueful\Extensions\ServiceProvider::services()` for extension DI.
`defs()` and DSL-based registration are proposed in this document and are not available yet.

This plan describes how extensions define services and runtime hooks with the new PSR‑11 container. It keeps DX high while avoiding Symfony‑specific features (parameters, compiler passes, runtime mutation).

## Goals

- PSR‑11 container only — no Symfony DI parameters or mutation
- Clean DX for extension authors (simple patterns, minimal boilerplate)
- Works in both development and compiled (production) modes
- Clear separation between DI registration and runtime boot steps

## Overview

Extensions consist of two complementary parts (proposed):

1) DI registration (services) — compile‑time
- Provide service definitions to the container before it is created/compiled.
- Proposed ways (not implemented yet):
  - Strongly typed: `defs(): array` returning DefinitionInterface objects
  - DSL: `services(): array` — a small declarative map we translate into definitions

2) Runtime provider — boot‑time
- `register()` and `boot()` for routes, migrations, config merging, static asset mounts, console commands.

Both live in a single `Glueful\Extensions\ServiceProvider` subclass for a convenience, static (services/defs) + instance (register/boot) model.
In current runtime, only `services()` exists.

## Option A: Strongly‑Typed Definitions (Recommended)

Expose `public static function defs(): array` returning DefinitionInterface objects from the extension provider.

```php
namespace Vendor\DemoExt;

use Glueful\Container\Providers\BaseServiceProvider; // for autowire() helper
use Glueful\Container\Definition\{FactoryDefinition, AliasDefinition, DefinitionInterface};

final class Provider extends BaseServiceProvider
{
    /** @return array<string, DefinitionInterface|callable|mixed> */
    public static function defs(): array
    {
        $defs = [];

        // Autowire
        $defs[\Vendor\DemoExt\MyService::class] =
            new \Glueful\Container\Autowire\AutowireDefinition(\Vendor\DemoExt\MyService::class);

        // Factory with config
        $defs['demo.client'] = new FactoryDefinition(
            'demo.client',
            fn(\Psr\Container\ContainerInterface $c) =>
                new \Vendor\DemoExt\Http\Client((array) config('demo.client', []))
        );

        // Alias for convenience
        $defs[\Vendor\DemoExt\Http\Client::class] =
            new AliasDefinition(\Vendor\DemoExt\Http\Client::class, 'demo.client');

        // Lazy warmup hint (processed by LazyProvider)
        // If you extend BaseServiceProvider, you can tag services via $this->tag()
        return $defs;
    }
}
```

Pros:
- Compile‑safe, no DSL parsing
- Full access to definitions (AliasDefinition, FactoryDefinition, AutowireDefinition, ValueDefinition)
- Works in compiled mode without special cases

## Option B: Lightweight DSL (DX‑friendly)

Expose `public static function services(): array` returning a small declarative map we translate to definitions at build time.

Supported keys:
- `class`: FQCN (defaults to the map key when omitted)
- `shared`: bool (default true)
- `arguments`: array; use `'@serviceId'` to reference another service
- `factory`: `['@serviceId', 'method']` or `'Class::method'` (no closures in compiled mode)
- `alias`: string or array of alias IDs
- `tags`: `['tag.name', ...]` or `[['name' => 'tag.name', 'priority' => 10], ...]`

Constraints:
- No `%param%` placeholders — use `config()` in factories (Option A) or precomputed values
- In compiled (prod) mode, reject closures in `factory`

Example DSL:

```php
public static function services(): array
{
    return [
        \Vendor\DemoExt\MyService::class => [
            'class' => \Vendor\DemoExt\MyService::class,
            'shared' => true,
            'arguments' => ['@db'],
            'alias' => ['demo.my_service'],
            'tags' => [['name' => 'lazy.background', 'priority' => 5]],
        ],
        'demo.client' => [
            'class' => \Vendor\DemoExt\Http\Client::class,
            'factory' => ['@http.client', 'forVendor'],
            'shared' => true,
        ],
    ];
}
```

Our container factory will translate the above into real definitions during build.

## Runtime Provider (register/boot)

Use the instance lifecycle for runtime setup — routes, migrations, config defaults, assets, commands:

```php
use Glueful\Extensions\ServiceProvider;

final class Provider extends ServiceProvider
{
    // Optionally add: public static function defs() or services() as shown above

    public function register(): void
    {
        // Merge default configs (app overrides win)
        $this->mergeConfig('demo', [
            'client' => ['timeout' => 5],
        ]);

        // Routes & migrations
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function boot(): void
    {
        // Static assets (served without templating)
        $this->mountStatic('demo-ui', __DIR__ . '/../public');

        // Console commands (optional)
        // $this->commands([
        //     \Vendor\DemoExt\Console\SyncCommand::class,
        // ]);
    }
}
```

## How the container sees extension services

At build time, during `Glueful\Container\Bootstrap\ContainerFactory::create()`:
1) Discover extension providers (e.g., via `Glueful\Extensions\ProviderLocator`)
2) For each provider class:
   - If it has `defs()`: merge its definitions directly
   - Else if it has `services()`: translate DSL → definitions and merge
3) Build the container (dev) or compile (prod) as usual

This ensures all extension services are available from the very start, and compiled in production.

## Event subscribers & listeners

- Use PSR‑14. Options:
  - Subscriber classes implement `public static function getSubscribedEvents(): array`, then
    register with `Glueful\Events\Event::subscribe(Subscriber::class)` during `boot()`.
  - Or add listeners via `Event::listen(EventClass::class, '@serviceId:method')` for lazy, container‑backed listeners.

## Lazy warmup hints

- Tag heavy services so the framework can warm them without container mutation:
  - `lazy.background` — warm after the response is sent
  - `lazy.request_time` — warm early at request start (when wired)

In strongly‑typed defs (Option A), call `$this->tag('service.id', 'lazy.background', 10)` if your provider extends BaseServiceProvider. In DSL, add `tags` as shown.

## Why not Symfony parameters

- The new container eliminates parameter bag semantics; use helpers (`base_path()`, `config_path()`) and `config()` for values.
- Inject settings via factories or small immutable “settings” objects instead of `%param%` placeholders.

## Migration notes

- Existing extensions that returned Symfony DI structures should be refactored to either `defs()` or `services()`.
- Replace `%param%` with `config()`‑backed values, and `@service` references remain valid in DSL.
- Avoid closures in factories for compiled (production) builds; use `Class::method` or `['@id', 'method']`.
