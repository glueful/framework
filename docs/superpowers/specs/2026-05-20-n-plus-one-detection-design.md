# N+1 Query Detection (ORM-Aware) — Design Spec

**Status:** Draft, awaiting review
**Date:** 2026-05-20
**Tier:** Tier 1 (core, near-term) — per `docs/FRAMEWORK_IMPROVEMENTS.md`

## Goal

Add an ORM-aware N+1 detector that fires at the model layer with the context needed to produce actionable warnings ("`User::profile` lazy-loaded from a collection — add `->with('profile')`"). The detector is opt-in via configuration, dev-only by default, and supports a strict mode that throws on violation for CI enforcement.

## Non-goals

- Replacing or consolidating the existing SQL-pattern detectors (`DevelopmentQueryMonitor`, `QueryLogger::detectN1Patterns()`). They continue to work; this layer is additive.
- Production-time enforcement. Default mode is `auto`, which resolves to `off` outside development.
- Cross-request tracking. State is per-process / per-request, matching the rest of the framework.
- Performance benchmarking. Cost is one array key lookup per relation access; benchmark only if a user reports overhead.

## Why this approach

Two N+1 detectors already exist in `src/Database/`. Both detect *patterns* in raw SQL after the fact, which produces warnings like "10 similar queries ran" — the developer still has to trace back to which loop and which relation. An ORM-layer hook can say "`$post->comments` was lazy-loaded from a collection of 50 posts," which closes the loop.

The hook lives at `HasRelationships::getRelationshipFromMethod()` — the single chokepoint for unintentional lazy loads. Explicit user calls like `Model::load('posts')` bypass this path naturally because they call the eager loader directly.

## Architecture

### New files

| File | Responsibility |
|---|---|
| `src/Database/ORM/Concerns/PreventsLazyLoading.php` | Trait. Holds global mode (static), per-model opt-out, the violation handler, and the per-request dedupe set. |
| `src/Database/ORM/Exceptions/LazyLoadingViolationException.php` | Extends `\LogicException`. Carries `modelClass` and `relation` as public readonly properties. |
| `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php` | Unit tests for trait state, mode resolution, dedupe, reset. |
| `tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php` | Exception shape tests. |
| `tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php` | Verifies `Builder::hydrate()` tags collections correctly. |
| `tests/Unit/Database/ORM/WarnModeDedupeTest.php` | Dedupe semantics in warn mode. |
| `tests/Integration/Database/ORM/LazyLoadingDetectionTest.php` | End-to-end scenarios with SQLite in-memory. |
| `tests/Feature/Database/EnforceNoNPlusOneInCITest.php` | Meta-test demonstrating CI-enforcement pattern. |
| `tests/Support/ResetsLazyLoading.php` | Opt-in PHPUnit trait that calls `Model::resetLazyLoadingState()` in `tearDown()`. |
| `docs/ORM/N_PLUS_ONE_DETECTION.md` | Public documentation. |

### Edits to existing files

| File | Change |
|---|---|
| `src/Database/ORM/Model.php` | Add `use PreventsLazyLoading;` |
| `src/Database/ORM/Builder.php` | In `hydrate(array $results): array`: when result count > 1 and `Model::lazyLoadingEnabled()`, call `$model->setLoadedFromCollection(true)` on each hydrated model before returning. |
| `src/Database/ORM/Concerns/HasRelationships.php` | In `getRelationshipFromMethod()`: before `$relation->getResults()`, check `$this->loadedFromCollection && $this->preventsLazyLoadingNow()` and route to violation handler. |
| `config/database.php` | Add `orm.lazy_loading_mode` key with default `'auto'`. |
| `src/Framework.php::boot()` | Read config; call `Model::preventLazyLoading($resolvedMode)`. **Not** inside `initializeDevelopmentTools()` — that method is gated on `environment === 'development'`, which would break strict mode in CI (`APP_ENV=testing`) and any explicit non-dev configuration. Recommend a new private method `initializeOrmFeatures()` called unconditionally from `boot()`. |
| `CLAUDE.md` | Add one bullet under ORM section pointing to the new doc. |
| `docs/FRAMEWORK_IMPROVEMENTS.md` | Flip Tier 1 N+1 row to ✅ after implementation lands. |

## Trait surface

```php
namespace Glueful\Database\ORM\Concerns;

trait PreventsLazyLoading
{
    protected static string $lazyLoadingMode = 'off';
    protected static ?\Closure $violationCallback = null;
    /** @var array<string, true> */
    protected static array $warnedPairs = [];

    protected ?string $instanceLazyLoadingMode = null;
    protected bool $loadedFromCollection = false;

    // ── Global configuration ──
    public static function preventLazyLoading(string $mode = 'strict'): void;
    public static function lazyLoadingEnabled(): bool;         // true when mode !== 'off'
    public static function handleLazyLoadingViolationUsing(?\Closure $callback): void;  // null clears
    public static function clearLazyLoadingWarnings(): void;   // clears $warnedPairs only
    public static function resetLazyLoadingState(): void;      // clears all static state (for tests)

    // ── Builder hook ──
    public function setLoadedFromCollection(bool $value): void;

    // ── Internal check (called by HasRelationships) ──
    protected function preventsLazyLoadingNow(): bool;
    protected function handleLazyLoadingViolation(string $relation): void;
    protected function resolvedLazyLoadingMode(): string;
}
```

**Per-model opt-out** is via a subclass property:

```php
class LegacyUser extends Model
{
    protected ?string $instanceLazyLoadingMode = 'off';
}
```

## Configuration

```php
// config/database.php
'orm' => [
    'lazy_loading_mode' => env('DB_LAZY_LOADING_MODE', 'auto'),
    // 'off'    — skips hydration tagging and violation checks; minimal overhead (one static read per query)
    // 'warn'   — log warning via error_log() with [GLUEFUL-N+1] prefix
    // 'strict' — throw LazyLoadingViolationException
    // 'auto'   — 'warn' in development, 'off' otherwise
],
```

Resolution happens once at boot. `auto` resolves to `warn` when `env('APP_ENV') === 'development'`, otherwise `off`.

## Data flow

### Boot

```
Framework::boot()
  → reads config('database.orm.lazy_loading_mode')
  → resolves 'auto' → 'warn' | 'off' based on APP_ENV
  → Model::preventLazyLoading($resolvedMode)
```

### Hydration

```
Builder::get() / first() / find() / paginate()
  → Builder::hydrate(array $results): array  // returns array<Model>
       → foreach ($results as $row) { $models[] = $this->newModelInstance($row); }
       → if (Model::lazyLoadingEnabled() && count($models) > 1):
              foreach ($models as $m) { $m->setLoadedFromCollection(true); }
       → return $models
```

`Builder::hydrate()` currently returns `array<Model>` (it's the collection-wrapping caller — e.g., `get()` — that constructs a `Collection`). The tagging loop runs only when `Model::lazyLoadingEnabled()` is true, so `off` mode skips the entire tagging block.

### Relation access

```
Model::__get('posts')
  → HasRelationships::getRelationValue('posts')
       → if relationLoaded → return cached     (no-op)
       → getRelationshipFromMethod('posts')
            → if loadedFromCollection && preventsLazyLoadingNow():
                  → handleLazyLoadingViolation('posts')
            → $relation->getResults()           (SQL fires here)
            → setRelation('posts', $results)
```

### Violation handler routing

```
handleLazyLoadingViolation('posts')
  → if custom callback registered → invoke it, return
  → if mode === 'strict' → throw LazyLoadingViolationException
  → if mode === 'warn':
       → dedupe key = static::class . '::posts'
       → if already warned → return
       → mark warned
       → error_log('[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: ...')
```

## State lifecycle

| Scope | Lives in | Set when | Cleared when |
|---|---|---|---|
| Global mode | `PreventsLazyLoading::$lazyLoadingMode` (static) | Framework boot | Process exit / `Model::resetLazyLoadingState()` |
| Per-model `loadedFromCollection` flag | Instance property | `Builder::hydrate()` with >1 row | Model is GC'd |
| Custom violation handler | `PreventsLazyLoading::$violationCallback` (static) | `Model::handleLazyLoadingViolationUsing()` | Process exit / `Model::resetLazyLoadingState()` |
| Per-model `$instanceLazyLoadingMode` opt-out | Subclass property | Class definition | Never (compile-time) |
| Dedupe set | `PreventsLazyLoading::$warnedPairs` (static) | First warn fires | Automatically per-request under PHP-FPM/CLI (PHP request shutdown). Explicit hook required under long-running runtimes. Per-test via `Model::resetLazyLoadingState()`. See "Request-boundary state clearing" below. |

## Request-boundary state clearing

**PHP-FPM and CLI: nothing to do.** Userland static class properties are request-scoped in standard PHP SAPIs — PHP's request shutdown clears the runtime symbol table between requests even though the FPM worker process is reused. The existing `DevelopmentQueryMonitor` only uses `register_shutdown_function` to *display* its summary at request end, not to reset state, because state resets are automatic. The same applies here.

**Long-running runtimes (Swoole, RoadRunner, FrankenPHP via `glueful/runiva`) need explicit clearing.** These runtimes keep PHP running across many requests; the request shutdown cycle is bypassed in favor of the runtime's own loop. Without explicit clearing, `$warnedPairs` accumulates across requests within a single worker — a warning fires on request 1, then stays silent for the rest of that worker's lifetime.

The practical impact is narrow: default mode is `auto`, which resolves to `off` outside development. The only affected scenario is a developer running Runiva locally with warn mode enabled.

**Resolution belongs in the implementation plan, not this spec.** Candidate approaches:

1. Register a Runiva middleware that calls `Model::clearLazyLoadingWarnings()` at request boundaries.
2. Add a request lifecycle hook on `Framework` (the class currently lacks a `terminate()` equivalent; this may need Application/Router cooperation).
3. Document the limitation and require Runiva users to wire up the reset themselves.

The trait MUST expose a narrow `Model::clearLazyLoadingWarnings()` method (clears `$warnedPairs` only, preserves global mode and callback) so any of the three approaches can be wired in cleanly.

## Exception shape

```php
namespace Glueful\Database\ORM\Exceptions;

final class LazyLoadingViolationException extends \LogicException
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $relation,
    ) {
        parent::__construct(sprintf(
            'Attempted to lazy-load [%s] on model [%s], but lazy loading is disabled. '
            . 'Add ->with(\'%s\') to the query, or set $instanceLazyLoadingMode = \'off\' on the model.',
            $relation,
            $modelClass,
            $relation,
        ));
    }
}
```

Extends `\LogicException` deliberately — N+1 is a programmer error, not an HTTP or domain concern. It must not auto-map to a polite 422.

## Default warning format

```
[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: App\Models\User::profile. Add ->with('profile') to the query.
```

Sent via `error_log()`. The `[GLUEFUL-N+1]` prefix makes it greppable and distinguishes from `DevelopmentQueryMonitor`'s existing `POTENTIAL N+1 QUERY` lines. No HTML/console.warn injection — the trait fires before output and we must not corrupt JSON responses.

## Custom handler contract

```php
Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
    // Free to throw, log via PSR, dispatch an event, or do nothing.
});
```

**Contract rules:**

1. Signature: `function(Model $model, string $relation): void`. Documented; not enforced via interface.
2. The callback **replaces** default behavior. Neither `error_log()` nor the strict-mode throw fires automatically when a callback is registered.
3. Callback exceptions bubble up. The trait does not catch.
4. Per-request dedupe only applies to the default warn path. Custom callbacks own their own deduping.

## Behavior matrix — what does and does not trigger

| Scenario | Detection? |
|---|---|
| `User::all()` collection → `$user->posts` | **Yes** |
| `User::find(1)->posts` | No (single-row hydration; flag stays false) |
| `User::with('posts')->get()` → `$user->posts` | No (relation already loaded; `relationLoaded()` short-circuits) |
| `User::all()` → `$user->load('posts')` → `$user->posts` | No (explicit load goes through `setRelation()`, bypasses the hook) |
| `User::with('posts')->get()` → `$user->posts[0]->comments` | **Yes** (`$post` is collection-loaded from inside the eager load) |
| `new User([...])` (unsaved) → `$user->posts` | No (flag never set) |
| Model with `protected ?string $instanceLazyLoadingMode = 'off'` | No (per-model override beats global) |

## Error handling matrix

| Context | Behavior |
|---|---|
| HTTP request (dev) | Bubbles to modern `Handler`. `renderGenericException()` shows full message + stack trace because `APP_ENV=development`. |
| HTTP request (prod) | Should not fire — `auto` resolves to `off`. If misconfigured, `renderGenericException()` strips message (existing framework behavior), returns generic 500. |
| CLI (`glueful` commands, tinker) | Standard PHP unhandled exception. Stack trace to stderr. |
| PHPUnit | Bubbles up; PHPUnit fails with violation message. CI-enforcement use case. |
| Sentry / custom error handlers | Treated as uncaught `LogicException`. |

## Testing strategy

### Unit tests (`tests/Unit/Database/ORM/`)

| File | Covers |
|---|---|
| `PreventsLazyLoadingTraitTest` | Mode resolution (`auto` env-aware), per-model override beats global, `handleLazyLoadingViolationUsing()` replaces default, `handleLazyLoadingViolationUsing(null)` clears the callback, `resetLazyLoadingState()` clears all static state |
| `LazyLoadingViolationExceptionTest` | Public readonly properties populated, message format stable, extends `\LogicException` |
| `BuilderHydrationTaggingTest` | `count(rows) > 1` tags; `count(rows) === 1` doesn't; `new User(...)` doesn't |
| `WarnModeDedupeTest` | Same pair warns once per request; different pairs each warn; `clearLazyLoadingWarnings()` clears dedupe between requests |

### Integration tests (`tests/Integration/Database/ORM/LazyLoadingDetectionTest`)

In-memory SQLite. Fixture: `users`, `posts`, `comments` tables; three matching model classes. Each row in the behavior matrix above gets at least one test in both `warn` and `strict` modes.

### Feature test (`tests/Feature/Database/EnforceNoNPlusOneInCITest`)

Demonstrates the CI-enforcement pattern: strict mode in `setUp()`, real controller action via the router, asserts no `LazyLoadingViolationException`. Doubles as a copy-paste example.

### Test isolation

Opt-in trait `ResetsLazyLoading` (in `tests/Support/`) that calls `Model::resetLazyLoadingState()` in `tearDown()`. Test classes that use strict mode include the trait. Matches the existing `DevelopmentQueryMonitor::reset()` pattern (that method keeps its current name; the rename to `resetLazyLoadingState()` is scoped to the new trait to avoid the generic name).

### Coverage targets

- 100% statement coverage on `PreventsLazyLoading` and `LazyLoadingViolationException`
- Every row in the **behavior matrix** has a matching test (these are the user-visible contracts)
- The **testable** rows in the error-handling matrix have tests: HTTP-dev rendering, CLI bubble-up, PHPUnit failure-on-throw
- One test verifying coexistence with `DevelopmentQueryMonitor` (both fire on the same query without conflict)
- **Not targeted for tests:** HTTP-prod rendering (artificial — default mode is `off` in prod), Sentry/custom error handler integration (we can't meaningfully test third-party integrations)

### Intentionally not tested

- Performance benchmarks (premature)
- Cross-database compatibility (detector is above the driver layer)
- Concurrent request isolation (per-process, matches framework convention)

## Documentation

**New: `docs/ORM/N_PLUS_ONE_DETECTION.md`**

1. Overview — what fires, what doesn't
2. Modes — `off | warn | strict | auto`
3. Configuration — config key, env var, per-model override
4. Custom violation handler — closure contract, examples
5. Testing & CI enforcement — `ResetsLazyLoading` trait, strict mode in CI
6. Coexistence with existing detectors — one paragraph explaining the SQL-pattern detectors continue to work
7. Performance — `off` mode skips hydration tagging and violation handling; only cost is a single static property read per query. Not zero, but indistinguishable from noise.

**Updates:**
- `CLAUDE.md` — one bullet under ORM section pointing to the new doc
- `docs/FRAMEWORK_IMPROVEMENTS.md` — flip Tier 1 N+1 row to ✅ after implementation

**No changes to:**
- `README.md`
- Top-level `docs/` index

## Open questions

**For implementation planning to resolve:**

1. How to wire `Model::clearLazyLoadingWarnings()` into the request boundary under long-running runtimes. See "Request-boundary state clearing" section above for the three candidate approaches.

## Out of scope (future work)

- Consolidating `DevelopmentQueryMonitor` and `QueryLogger::detectN1Patterns()` into a single SQL-pattern detector. Tracked separately; not blocked by this work.
- A Telescope-style UI for N+1 violations. Would belong in an extension (`glueful/telescope` or similar), not core.
- Per-relation collection size tracking ("loaded from a collection of 50") — useful for severity, but adds state without changing detection accuracy. Revisit if users ask.
