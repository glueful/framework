# Service Registration Enhancements — Implementation Plan

Goal: implement the recommendations in `docs/service_registration_recommendations.md` to improve DI ergonomics while preserving compile‑first guarantees and production immutability.

## Scope & Non‑Goals

- In‑scope: compile‑time DSL, tagging/decoration shortcuts, docs alignment, optional feature flags.
- Out‑of‑scope: runtime container mutation in production, breaking changes to existing providers, mandatory migrations.

## Guiding Principles

- Compile‑first: All helpers emit the same arrays consumed by the existing compiler (`ExtensionServiceCompiler`).
- Backward compatible: Raw `services()` arrays keep working as‑is.
- Opt‑in: New DX features gated by config flags; defaults keep current behavior.

---

## Milestone 1 (Near‑Term): DSL

Deliver a thin PHP DSL for authoring service definitions that compiles to the same structure our compiler consumes. No runtime container mutation.

### API Draft

- Namespace: `Glueful\DI\DSL`
- Entry: `Def::create()` or function `def()`
- Fluent builders:
  - `->service(class)`
  - `->singleton(class)` (sugar for `service()->shared(true)`)
  - `->bind(interface, impl)` (ensures concrete service, attaches alias on impl)
  - `->alias(idOrClass, targetIdOrClass)`
  - `->args(mixed ...$args)` (‘@id’ → Reference, ‘%param%’ → parameter)
  - `->shared(bool)`
  - `->public(bool = true)`
  - `->tag(name, array $attrs = [])`
  - `->factory(array|string $factory)`
  - `->call(string $method, array $args = [])` and `->calls(array $methodCalls)`
  - `->decorate(class $target, class $decorator, int $priority = 0)`
  - Finalize: `->toArray()` (returns `array<string, array<string,mixed>>`)

Schema decisions (compiler-aligned)
- Aliases: stored on the implementation entry as `['alias' => [InterfaceFqcn,...]]`.
- Tags: each tag item uses flat attributes: `['name' => 'tag.name'] + $attrs`.
- Calls: list of tuples `[['methodName', [arg1, arg2]]]`.
- Omit empty keys: do not emit `arguments`, `tags`, or `calls` until set.
- Defaults: `shared` defaults to false (prototype); `public` defaults to false unless explicitly set.

### Files

- DSL (authoring)
  - `src/DI/DSL/Def.php` (entry/builder)
  - `src/DI/DSL/ServiceDef.php` (per‑service builder)
  - `src/DI/DSL/Utils.php` (helpers: normalize ids, validate args, reference helpers)
  - Example skeletons:
  ```php
  // src/DI/DSL/Def.php
  namespace Glueful\DI\DSL;

  final class Def
  {
      /** @var array<string, array<string,mixed>> */
      private array $defs = [];
      public static function create(): self { return new self(); }
      public function service(string $class): ServiceDef { return new ServiceDef($this, $class); }
      public function singleton(string $class): ServiceDef { return $this->service($class)->shared(true); }
      public function bind(string $iface, string $impl): self {
          $this->defs[$impl] = ($this->defs[$impl] ?? []) + ['class' => $impl];
          $aliases = $this->defs[$impl]['alias'] ?? [];
          $this->defs[$impl]['alias'] = array_values(array_unique([...$aliases, $iface]));
          return $this;
      }
      /** @internal */
      public function put(string $id, array $def): void { $this->defs[$id] = ($this->defs[$id] ?? []) + $def; }
      /** @return array<string, array<string,mixed>> */
      public function toArray(): array { return $this->defs; }
  }
  ```

  ```php
  // src/DI/DSL/ServiceDef.php
  namespace Glueful\DI\DSL;

  final class ServiceDef
  {
      public function __construct(private Def $root, private string $class) {
          $this->root->put($class, ['class' => $class]);
      }
      public function args(mixed ...$args): self { $this->root->put($this->class, ['arguments' => $args]); return $this; }
      public function shared(bool $shared = true): self { $this->root->put($this->class, ['shared' => $shared]); return $this; }
      public function public(bool $public = true): self { $this->root->put($this->class, ['public' => $public]); return $this; }
      public function alias(string $alias): self { $this->root->put($this->class, ['alias' => [$alias]]); return $this; }
      public function tag(string $name, array $attrs = []): self {
          $tags = $this->root->toArray()[$this->class]['tags'] ?? [];
          $tags[] = ['name' => $name] + $attrs; $this->root->put($this->class, ['tags' => $tags]); return $this;
      }
      public function factory(array|string $factory): self { $this->root->put($this->class, ['factory' => $factory]); return $this; }
      public function call(string $method, array $args = []): self {
          $calls = $this->root->toArray()[$this->class]['calls'] ?? [];
          $calls[] = [$method, $args];
          $this->root->put($this->class, ['calls' => $calls]);
          return $this;
      }
      /** Replace all method calls at once */
      public function calls(array $methodCalls): self { $this->root->put($this->class, ['calls' => $methodCalls]); return $this; }
      public function decorate(string $target, int $priority = 0): self { $this->root->put($this->class, ['decorate' => ['id' => $target, 'priority' => $priority]]); return $this; }
      public function end(): Def { return $this->root; }
  }
  ```

  ```php
  // src/DI/DSL/Utils.php
  namespace Glueful\\DI\\DSL;

  final class Utils
  {
      public static function ref(string $id): string { return '@'.$id; }
      public static function param(string $key): string { return '%'.$key.'%'; }
  }
  ```

### Integration

- DSL examples:
  ```php
  use Glueful\DI\DSL\Def;
  public static function services(): array {
    return Def::create()
      ->service(App\Foo::class)->args('@cache.store')->shared(true)->public()
      ->bind(App\Contracts\Bar::class, App\Infra\BarImpl::class)
      ->toArray();
  }
  ```

- Preferred DSL example (with helpers):
  ```php
  use Glueful\\DI\\DSL\\Def;
  use Glueful\\DI\\DSL\\Utils as U;
  public static function services(): array {
    return Def::create()
      ->service(App\\Foo::class)
        ->args(U::ref('cache.store'))
        ->shared(true)
        ->public()
        ->call('setLogger', [U::ref('logger')])
      ->bind(App\\Contracts\\Bar::class, App\\Infra\\BarImpl::class)
      ->toArray();
  }
  ```

- No change to `ExtensionServiceCompiler` required; DSL emits the same shape.

### Compilation strategy

- During compilation, collect arrays returned by each provider’s static `services()` (which may be authored using the DSL) and feed them to `ExtensionServiceCompiler`.

### Acceptance

- DSL: unit tests verify References (‘@…’), params (‘%…%’), tags, factories, aliases (on impl), decorate output.
- DSL: method calls API (`call()`/`calls()`) emits the expected structure and appends deterministically.
- Helpers: `Utils::ref()` and `Utils::param()` normalize to the correct string forms.
- Example providers using the DSL compile and run with the current compiler.
- No runtime performance regression (compile path only).

---

## Milestone 2 (Medium‑Term): Tagging & Decoration Shortcuts

Enhance ergonomics around tagged collections and decorators using compile‑time constructs.

### Tasks

- Confirm/extend `Glueful\DI\Passes\TaggedServicePass` to support common patterns:
  - Inject array of services by tag into a consumer via constructor arg (documented pattern).
  - Optional priority order support via tag attribute (e.g., `['name' => 'x', 'priority' => 100]`).
- DSL shorthands:
  - `->tag('my.tag', ['priority' => 10])`
  - `->decorate(Target::class, Decorator::class, 0)` → emits `decorate` config compatible with compiler

### Acceptance

- Example: register N `payment.gateway` services and inject ordered list into a registry.
- Example: decorate `LoggerInterface` with an audit logger; verify order.

---

## Milestone 3 (Longer‑Term, Opt‑in): DX Feature Flags

Introduce optional compile‑only features gated by config.

### Request‑Scoped Services (design spike)

- Add `scope` concept in service definition (default `shared` / `prototype`), optional `request` scope.
- HTTP integration: initialize/dispose request scope around the request lifecycle.
- Start with RFC/design; defer implementation until we have clear use‑cases.

### Deferred/Lazy Services (investigate)

- Explore Symfony DI `lazy` proxies; add `lazy: true` key in definitions where appropriate.
- Keep off by default; only for services where benefit is clear.

### Acceptance

- Flags off: no behavior change.
- Flags on (pilot env): request‑scope and/or lazy service samples compile and run; no runtime penalty.

---

## Documentation Work

- Update `docs/EXTENSIONS.md` with a “Using the DSL with services()” section (arrays remain as canonical reference).
- Add a short “AppServiceProvider” example that extends `Glueful\Extensions\ServiceProvider` and uses DSL.
- Cross‑link from `docs/service_registration_recommendations.md` to this plan.

---

## Risks & Mitigations

- API creep: Keep DSL minimal; mirror only compiler‑supported keys.
- Misuse at runtime: Emphasize compile‑time only; no runtime mutation APIs.
- Performance: All logic executes in compile path; add a micro‑bench in CI only if needed.

---

## Rollout & Timeline (indicative)

- Weeks 1–2: Milestone 1 (DSL core + examples + tests)
- Weeks 3–4: Milestone 2 (tagging/decoration ergonomics + docs)
- Weeks 5–6: Milestone 3 pilots (attributes/autowire flags; design spike for scopes/lazy)

---

## Success Criteria

- Developers can author providers with terse, readable DSL while containers compile identically to today.
- Tagged collections and decorators are straightforward to define and consume.
- No production regressions; compile‑first, immutable container remains the default.
