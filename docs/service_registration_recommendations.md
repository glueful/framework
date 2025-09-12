# Service Registration: Recommended Additions To Fully Close Gaps

This document proposes targeted enhancements to Glueful’s DI ergonomics while preserving the current compile‑first, deterministic container.

## Context

- Current model: Extensions providers declare services via static `services()` and are compiled into the container (zero runtime cost). Runtime setup happens in `register()/boot()`.
- Gaps (developer perspective):
  - Ergonomics: authoring service arrays is verbose; missing sugar like bind/singleton/tag/alias.
  - DX features: no attributes/autowire/autoconfigure; no request‑scoped services; no deferred providers.
  - Cohesion: apps/extensions use `Extensions\\ServiceProvider` while framework internals use DI `ServiceProviderInterface` with `ContainerBuilder`, which is confusing in app docs.

## Goals

- Improve daily ergonomics for app teams without sacrificing compile‑time validation and performance.
- Keep production immutability: compiled containers remain read‑only at runtime.
- Avoid breaking changes to existing extensions; make all new capabilities opt‑in.

## Recommendations

### 1) Cohesion: One App‑Facing Pattern

- Promote a single pattern for apps and extensions: `Glueful\\Extensions\\ServiceProvider` with static `services()` (compiled) + `register()/boot()` (runtime setup).
- Position `Glueful\\DI\\ServiceProviderInterface` as framework‑internal in docs; avoid exposing `ContainerBuilder`/`Reference` to app authors.
- Optional convenience: add a tiny `AppServiceProvider` base (in extensions layer) that just provides helper methods but still compiles to `services()` under the hood.

### 2) Definition Builder DSL (Near‑term, low risk)

- Provide a thin PHP DSL that emits the exact arrays accepted by `services()`.
- Purpose: remove verbosity while keeping compile‑first behavior and validation.

Example DSL usage (conceptual):

```php
use Glueful\\DI\\DSL\\Def as def;

return def()
  ->service(App\\Services\\ReportService::class)
    ->args('@Psr\\\\Log\\\\LoggerInterface', '@database', '%report.default_window%')
    ->shared(true)->public()
    ->alias('report.service')
  ->service(App\\Services\\PaymentService::class)
    ->factory(['@payment.gateway.factory', 'create'])
    ->tag('payment.service', ['priority' => 100])
  ->toArray();
```

Equivalent `services()` array (what compiler consumes):

```php
return [
  App\\Services\\ReportService::class => [
    'class' => App\\Services\\ReportService::class,
    'arguments' => ['@Psr\\\\Log\\\\LoggerInterface', '@database', '%report.default_window%'],
    'shared' => true,
    'public' => true,
    'alias' => ['report.service'],
  ],
  App\\Services\\PaymentService::class => [
    'class' => App\\Services\\PaymentService::class,
    'factory' => ['@payment.gateway.factory', 'create'],
    'tags' => [ ['name' => 'payment.service', 'priority' => 100] ],
  ],
];
```

Notes:
- No runtime reflection; all resolved at compile time.
- Tags, aliases, factories map 1:1 to the existing compiler (no new format required).

#### Proposed compile‑time helpers (API sketch)

- service(class): starts a definition for a concrete class
- singleton(class): sugar for `service(class)->shared(true)`
- bind(interface, impl): emits a concrete service for impl and an alias from interface → impl
- alias(idOrClass, targetIdOrClass): emits an alias (array or single)
- args(...): adds constructor arguments; strings starting with `@` become references; `%...%` become parameters
- shared(bool): toggles singleton vs new instance per request
- public(): marks service public (default remains private unless explicitly needed)
- tag(name, attrs = []): adds a compiler‑visible tag
- factory([ '@serviceId', 'method' ]) or `Class::method`: sets a factory
- decorate(Target::class, Decorator::class, priority = 0): emits a decorated service

Constraints:
- Helpers return arrays for `services()`; no runtime container mutation.
- Any “getTagged” usage happens at compile time via TaggedServicePass (e.g., to build registries).

### 3) Tagging & Decoration Shortcuts (Medium‑term, opt‑in)

- Add simple helpers (or DSL shorthands) for:
  - Getting tagged services: `getTagged('tag.name')` where appropriate via existing `TaggedServicePass`.
  - Decorating services: `decorate(Service::class, Decorator::class)` compiled into `setDecoratedService()`.
- Keep support entirely in the compile pipeline; no runtime scanning.

### 4) DX Features Roadmap (Longer‑term, opt‑in and compile‑only)

- Attributes / Autowire / Autoconfigure:
  - Compile‑time scanner gated by a config flag (off by default).
  - Generates the same service definitions (class/args/tags) into the compiler stage.
  - No runtime reflection cost; clear, explicit enablement.
- Request‑scoped services:
  - Introduce a ‘scope’ concept at compile time; provide an HTTP request scope provider.
  - Default remains shared (singleton) or non‑shared; scope is opt‑in.
- Deferred providers:
  - Explore lazy proxies where valuable; remain compile‑time and explicit.

## Documentation Changes

- App docs show only the Extensions provider pattern + DSL; avoid Symfony DI types in app code snippets.
- Provide migration notes: raw arrays (today) ⇄ DSL (new) generate identical compiler input.
- Keep `config/services.yaml` documented as a lightweight alternative for simple apps.

## Phased Plan

- Near‑term
  - Implement the DSL builder that outputs the `services()` arrays.
  - Update docs with the DSL pattern and keep raw array examples as reference.
- Medium‑term
  - Add tag retrieval and decoration shorthands through the existing compiler pass pipeline.
- Long‑term
  - Pilot attributes/autowire/autoconfigure behind a feature flag (compile‑only).
  - Evaluate request scope and deferred providers with real use cases.

## Non‑Goals

- No change to compiled container immutability.
- No runtime mutation APIs in production code paths.
- No mandatory migration for existing extensions; raw arrays continue to work.

## Open Questions

- Should the DSL live under `Glueful\\DI\\DSL` or `Glueful\\Extensions\\DSL`?
- Do we want a minimal `AppServiceProvider` base class exposing helper methods (docs‑only), or keep everything purely via `static services()` + DSL?
- Which tag/decoration helper APIs add the most value without adding magic?

This proposal keeps Glueful’s production‑first guarantees while significantly improving authoring experience for app teams.

## Implementation Plan

- See `docs/implementation_plans/SERVICE_REGISTRATION_ENHANCEMENTS.md` for milestones, APIs, and rollout steps.
