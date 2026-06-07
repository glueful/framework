# Extract CDN / Edge Cache → `glueful/cdn` — Implementation Plan

> **For agentic workers:** implement task-by-task with TDD (write the failing test first, run it red, implement, run it green, commit). Each task is independently green and reviewable; don't start a task before the previous is green. Design (authoritative — do not re-litigate decisions): `docs/superpowers/specs/2026-06-06-extract-cdn-design.md` (v3).

**Goal:** Move the CDN / edge-cache integration out of framework core into a new `glueful/cdn` extension, leaving a single capability seam (`Glueful\Cache\Contracts\EdgeCacheInterface`) plus a `NullEdgeCache` no-op default in core so response caching keeps working with no CDN installed.

**Compatibility:** **Intentional clean break, shipped in a coordinated breaking release** (spec G5) — the framework is past 1.0 and we've chosen no backward compatibility for these extractions. No back-compat shim/alias for `Glueful\Cache\EdgeCacheService`; classes move and the namespace changes. `EdgeCachePurger`'s constructor signature is **deliberately changed** (`__construct(ApplicationContext, array $config)`). Tech: PHP 8.3, PHPUnit 10.5; framework/extension library tests extend `PHPUnit\Framework\TestCase`. Container definitions use `Glueful\Container\Definition\{FactoryDefinition,AliasDefinition}` (constructors verified: `FactoryDefinition(string $id, callable $factory, bool $shared = true)`, `AliasDefinition(string $id, string $target)`).

**Sequencing (from spec §"Sequencing & non-goals"):** Step 1 (Tasks 1–2) is an **in-place core refactor** — it adds the `EdgeCacheInterface` seam + `NullEdgeCache` and rewires `ResponseCachingTrait`/`CoreProvider`, shipping as incremental green core commits (this is **not** a copy-first move). Step 2 (Tasks 3–6) stands up the extension by **copying** the CDN sources into the package (core originals left in place and still bound/working). Step 3 (Task 7) is the **single atomic core-removal** that deletes the now-duplicated core sources, the dead trait, and the core `cache.edge` block together. Tasks 8–9 are verification.

> **Core stays green every task — copy-first, then one atomic removal.** This plan has two kinds of work. **Step 1 (Tasks 1–2)** is an in-place core refactor — add the seam, point core at it, default to `NullEdgeCache` — committed as ordinary green core commits; leave those tasks exactly as written. **Step 2 (Tasks 3–6)** populates the `glueful/cdn` package by **copying** code out of core (`EdgeCacheService`→`EdgeCachePurger`, the `Cache/CDN/*` adapters, `PurgeCommand`, the `cache.edge`→`cdn` config) — the core originals stay **untouched and still functional** so core compiles and its full suite passes after every one of those tasks (the extension is built/tested standalone against the framework as a dependency). **Task 7 is the single, atomic core-removal commit** that deletes all the now-duplicated core sources, the dead `CDNAdapterManager` trait, and the `cache.edge` config block together. So that core is never left referencing a class it no longer binds, the `CoreProvider`/`ResponseCachingTrait` rebind that switches edge off in core lands in **Task 2** and core keeps `EdgeCacheService` **bound and resolvable** the whole way through — see the reconciliation note in Task 2 — until Task 7 removes it. Do **not** `git mv` out of core in Tasks 4–6 (that would break core until Task 7); copy instead.

---

## Task 1 — Add the core seam: `EdgeCacheInterface` + `NullEdgeCache`

**Create**
- `src/Cache/Contracts/EdgeCacheInterface.php` — namespace `Glueful\Cache\Contracts`. Methods (signatures copied verbatim from current `EdgeCacheService` usage so the later move is mechanical):
  - `isEnabled(): bool`
  - `getProvider(): ?string`
  - `generateCacheHeaders(string $route, ?string $contentType = null): array` (`@return array<string, string>`)
  - `purgeUrl(string $url): bool`
  - `purgeByTag(string $tag): bool`
  - `purgeAll(): bool`
  - **Not** in the seam: `getStats()`, `isCacheable()`, `getCDNAdapter()`, `setCDNAdapter()` (spec Decision §5 — only the moving `PurgeCommand` uses `getStats()`; they stay on the concrete impl).
- `src/Cache/NullEdgeCache.php` — namespace `Glueful\Cache`; `final class NullEdgeCache implements Contracts\EdgeCacheInterface`. No constructor deps. Returns: `isEnabled()=false`, `getProvider()=null`, `generateCacheHeaders()=[]`, all three purges `=false`. (Mirrors `EdgeCacheService`'s disabled-state returns: `:94`, `:109`, `:124`, `:138`.)
- `tests/Unit/Cache/NullEdgeCacheTest.php`

**Steps**
- [ ] Write `tests/Unit/Cache/NullEdgeCacheTest.php` asserting: `NullEdgeCache instanceof EdgeCacheInterface`; `isEnabled()===false`; `getProvider()===null`; `generateCacheHeaders('any','application/json')===[]`; `purgeUrl/purgeByTag/purgeAll` all return `false`.
- [ ] Run `vendor/bin/phpunit tests/Unit/Cache/NullEdgeCacheTest.php` → red (classes don't exist yet).
- [ ] Create `src/Cache/Contracts/EdgeCacheInterface.php` then `src/Cache/NullEdgeCache.php`.
- [ ] Run the test → green.
- [ ] `composer run analyse` + `composer run phpcs` on the two new files → clean.
- [ ] Commit: `feat(cache): add EdgeCacheInterface seam + NullEdgeCache no-op default`.

**Rollback risk:** Low — purely additive; nothing depends on the new types yet.

---

## Task 2 — Rewire `ResponseCachingTrait` + `CoreProvider` to the seam (core green, edge no-op)

**Modify**
- `src/Controllers/Traits/ResponseCachingTrait.php`:
  - `:10` — replace `use Glueful\Cache\EdgeCacheService;` with `use Glueful\Cache\Contracts\EdgeCacheInterface;`.
  - `:390` — change `container($this->getContext())->get(EdgeCacheService::class)` to `container($this->getContext())->get(EdgeCacheInterface::class)`. Everything else in `edgeCacheResponse()` (`:384-409`) is unchanged: it calls `generateCacheHeaders($pattern, $contentType)` (`:392`), emits the returned headers, then always emits the `Surrogate-Key` header and returns the response.
- `src/Container/Providers/CoreProvider.php:460`:
  - Add `$defs[\Glueful\Cache\Contracts\EdgeCacheInterface::class] = $this->autowire(\Glueful\Cache\NullEdgeCache::class);` (`NullEdgeCache` has no ctor deps, so autowire is trivial) — so the new seam resolves and `ResponseCachingTrait` gets the no-op edge.
  - **Keep** the existing `$defs[\Glueful\Cache\EdgeCacheService::class] = $this->autowire(\Glueful\Cache\EdgeCacheService::class);` line in place (do **not** delete it in this task). Core still ships `src/Cache/EdgeCacheService.php` until Task 7's atomic removal, and the still-in-core `PurgeCommand` (auto-discovered by `ConsoleProvider`) resolves `EdgeCacheService::class` from the container — so the binding must remain resolvable. It is simply no longer what `ResponseCachingTrait` / the `EdgeCacheInterface` seam point at.

**Reconciliation — PurgeCommand / rebind sequencing (copy-first):** Because Tasks 4–6 **copy** (not move) the CDN sources, core retains both `EdgeCacheService.php` and `src/Console/Commands/Cache/PurgeCommand.php`, and `PurgeCommand` stays auto-discovered by `ConsoleProvider`'s `#[AsCommand]` scan of `src/Console/Commands`. That command resolves `EdgeCacheService::class` from the container — so core **must keep `EdgeCacheService` bound and resolvable** through the whole series. This task therefore only **adds** the `EdgeCacheInterface => NullEdgeCache` binding for the seam; it does **not** unbind or rebind `EdgeCacheService` away from its working implementation. Core stays green (response caching uses the no-op seam, `cache:purge` still resolves its concrete service) until **Task 7** atomically removes `EdgeCacheService`, `PurgeCommand`, and the `EdgeCacheService::class` binding together. (The extension's own `EdgeCachePurger` + `cache:purge` are exercised standalone in the package suite; they never coexist with core's copies in the same install.)

**Create**
- `tests/Unit/Controllers/ResponseCachingTraitEdgeTest.php` (or extend an existing ResponseCachingTrait test) — a controller stub using the trait, given a context whose container binds `EdgeCacheInterface => NullEdgeCache`, asserts `edgeCacheResponse()` returns the same `Response`, adds **no** edge cache-control header, and (since headers are emitted via the global `header()` function, assert via the path that matters) that `generateCacheHeaders()` resolved to `[]`. Use a fake `EdgeCacheInterface` to assert the trait calls `generateCacheHeaders($pattern, $contentType)` exactly and only that method.

**Steps**
- [ ] Write the trait test asserting resolution via `EdgeCacheInterface` and the no-op header behavior → run red (trait still imports `EdgeCacheService`).
- [ ] Edit `ResponseCachingTrait.php` (`:10`, `:390`).
- [ ] Edit `CoreProvider.php:460`.
- [ ] Run the trait test → green.
- [ ] Run `composer test` (full suite) → green. Run `composer run analyse` + `composer run phpcs` → clean.
- [ ] Commit: `refactor(cache): depend on EdgeCacheInterface; bind NullEdgeCache by default`.

**Rollback risk:** Medium — touches a core controller trait + the core provider binding. Revert = restore the two `EdgeCacheService` references and the autowire line. The seam (Task 1) can stay.

---

## Task 3 — Scaffold the `glueful/cdn` extension package

> Tasks 3–7 operate in the new `glueful/cdn` package. Develop it alongside the framework via path/symlink repo (per the Aegis cross-repo pattern) so the framework test suite can install it for the provider-override / adapter-failure tests. All paths below are **relative to the `glueful/cdn` package root** unless prefixed with `framework:`.

**Create**
- `composer.json` — package `glueful/cdn`; `"type": "glueful-extension"`. One complete, valid strict-JSON object matching the canonical Glueful extension manifest shape. No `classmap`: CDN ships **no migrations**.
  ```json
  {
      "name": "glueful/cdn",
      "description": "CDN / edge-cache integration for Glueful (edge headers, purge, provider adapters).",
      "type": "glueful-extension",
      "license": "MIT",
      "authors": [{ "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }],
      "keywords": ["cdn", "edge-cache", "cache", "glueful"],
      "require": {
          "php": "^8.3"
      },
      "require-dev": {
          "glueful/framework": "^1.52.0",
          "phpunit/phpunit": "^10.5",
          "squizlabs/php_codesniffer": "^3.6",
          "phpstan/phpstan": "^1.0"
      },
      "homepage": "https://github.com/glueful/cdn",
      "autoload": {
          "psr-4": { "Glueful\\Extensions\\Cdn\\": "src/" }
      },
      "autoload-dev": {
          "psr-4": { "Glueful\\Extensions\\Cdn\\Tests\\": "tests/" }
      },
      "scripts": {
          "test": "vendor/bin/phpunit",
          "phpcs": "vendor/bin/phpcs --standard=Squiz src",
          "phpcbf": "vendor/bin/phpcbf --standard=Squiz src",
          "analyze": "vendor/bin/phpstan analyze src --level=8"
      },
      "extra": {
          "glueful": {
              "name": "Cdn",
              "displayName": "CDN / Edge Cache",
              "description": "CDN / edge-cache integration for Glueful.",
              "version": "1.0.0",
              "categories": ["cache", "cdn"],
              "publisher": "glueful-team",
              "provider": "Glueful\\Extensions\\Cdn\\CdnServiceProvider",
              "requires": { "glueful": ">=1.52.0", "extensions": [] }
          }
      },
      "config": { "sort-packages": true }
  }
  ```
  `glueful/framework` sits in **require-dev** (the extension is tested against the framework, not coupled to it at runtime). `^1.52.0` is the coordinated breaking release that performs the core removal (Task 7) — fill in the real version once it's set. For local dev **before** that release is published, register a path repository in the *project* composer (the way `create:extension` does), **not** committed to the extension's own `composer.json`.

  (Namespace root is `Glueful\Extensions\Cdn\`. The package ships its own `test`/`phpcs`/`analyze` scripts + dev tooling so the per-task gates run **from the package root**; they do not depend on the framework's composer scripts.)
- `src/CdnServiceProvider.php` — `final class CdnServiceProvider extends \Glueful\Extensions\ServiceProvider`. Implement skeleton only here (filled by later tasks): `services()` returning `[]`, `register()` merging config, `boot()` discovering commands. (Concrete bindings/config/commands wired in Tasks 4–6.)
- `phpunit.xml` (extension's own), `tests/` dir with the `Glueful\Extensions\Cdn\Tests` namespace.

**Steps**
- [ ] Create `composer.json`, `phpunit.xml`, `src/CdnServiceProvider.php` (skeleton), `tests/.gitkeep`.
- [ ] `composer install` / `composer dump-autoload` in the package → autoload resolves.
- [ ] Commit (in `glueful/cdn`): `chore: scaffold glueful/cdn extension package`.

**Rollback risk:** Low — new package, no framework changes.

---

## Task 4 — Move adapters + `EdgeCachePurger` into the extension

**Copy (framework → extension; core originals stay until Task 7's atomic removal — do not `git mv`), with namespace + behavior changes**
- `framework:src/Cache/CDN/CDNAdapterInterface.php` → `src/Adapters/CDNAdapterInterface.php`, namespace `Glueful\Extensions\Cdn\Adapters`. (Content unchanged besides namespace.)
- `framework:src/Cache/CDN/AbstractCDNAdapter.php` → `src/Adapters/AbstractCDNAdapter.php`, namespace `Glueful\Extensions\Cdn\Adapters`; `implements CDNAdapterInterface` resolves within the new namespace. (Content unchanged besides namespace.)
- `framework:src/Cache/EdgeCacheService.php` → `src/EdgeCachePurger.php`, namespace `Glueful\Extensions\Cdn`; `final class EdgeCachePurger implements \Glueful\Cache\Contracts\EdgeCacheInterface`. Changes from the original:
  - **Constructor** becomes `__construct(\Glueful\Bootstrap\ApplicationContext $context, array $config)` (the `cdn` config block injected — spec §"EdgeCachePurger constructor contract"). Drop the `?CacheStore`/`?CDNAdapterInterface` params. Resolve `CacheStore` internally via `CacheHelper::createCacheInstance()` fallback (same as today's `:66-73`). Store `$config` directly (no `config(..., 'cache.edge', [])` read).
  - **Adapter resolution** is config-driven (Task 5 implements `resolveAdapter()`); **delete** the `resolveCDNAdapter()` extension-scan (`EdgeCacheService.php:228-274`) and the `use Glueful\Extensions\ExtensionManager;` import. Wire the constructor to call the new `resolveAdapter()`.
  - Keep `getStats()`, `isCacheable()`, `getCDNAdapter()`, `setCDNAdapter()` on the concrete class (off the core contract — Decision §5). Update type hints to `Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface`.
  - `isEnabled()` stays `($this->config['enabled'] ?? false) && $this->cdnAdapter !== null`.

**Create**
- `tests/Unit/EdgeCachePurgerTest.php` — covers: an enabled purger with a working stub adapter (config `['enabled'=>true,'provider'=>'stub','adapters'=>['stub'=>StubAdapter::class]]`) reports `isEnabled()===true`, `getProvider()==='stub'`, `generateCacheHeaders()` delegates to the adapter, `purgeUrl/ByTag/All` delegate; a disabled config (`enabled=>false`) no-ops everything. Define an in-test `StubAdapter extends AbstractCDNAdapter`.

**Steps**
- [ ] Write `EdgeCachePurgerTest.php` against the target API → red.
- [ ] **Copy** the three files into the package (leave the core originals in place — Task 7 removes them atomically), apply namespace + constructor + signature changes (adapter resolution stubbed to "instantiate the mapped class if present", refined in Task 5).
- [ ] Run the test → green.
- [ ] `composer run analyse` + `composer run phpcs` (extension) → clean.
- [ ] Commit (in `glueful/cdn`): `feat: copy EdgeCacheService→EdgeCachePurger + CDN adapters into extension`.

**Rollback risk:** Low — **core is untouched** (its `Glueful\Cache\EdgeCacheService` + `Cache/CDN/*` originals remain bound and working), so core stays green; the renamed class + changed constructor live only in the package and are exercised by the package suite. Revert = delete the copied files from the package.

---

## Task 5 — Config-driven adapter resolution (no scan; degrade-to-disabled)

**Create**
- `config/cdn.php` — the moved `edge` block, re-keyed `cdn`. Keys `enabled`/`provider`/`default_ttl`/`rules` and env vars `EDGE_CACHE_ENABLED`/`EDGE_CACHE_PROVIDER`/`EDGE_CACHE_TTL` move verbatim, **plus** a new `'adapters' => []` name→class map. The `'provider'` default must **not** be `'cloudflare'` (that vendor bias leaves core/extension defaults per G4/R5) — default it to `env('EDGE_CACHE_PROVIDER', '')` (empty ⇒ disabled). Whether a concrete bundled adapter is added to the map is a packaging follow-up (spec §"Bundled adapter"); ship the map empty.
- `tests/Unit/AdapterResolutionTest.php`

**Modify**
- `src/EdgeCachePurger.php` — implement `private function resolveAdapter(): ?CDNAdapterInterface` driven by `$this->config['provider']` selecting a key from `$this->config['adapters']` (name→class map). **Degrade to disabled (return `null`, never throw) on:** (a) `provider` unset/empty; (b) `provider` names a key absent from `adapters`; (c) mapped class missing or not `instanceof CDNAdapterInterface`; (d) adapter constructor throws. Wrap instantiation in try/catch; on failure log a warning (via the context's logger / `error_log` fallback, matching the old `:268-272` style) and return `null`. With `null` adapter, `isEnabled()` is already `false` and every method no-ops exactly like `NullEdgeCache`.

**Steps**
- [ ] Write `AdapterResolutionTest.php` parametrizing the four failure modes (a–d) → each yields a purger with `isEnabled()===false`, `generateCacheHeaders()===[]`, purges `=== false`, **no exception**; plus the happy path (valid map) yields the adapter. Include a `ThrowingAdapter` (ctor throws) and a `NotAnAdapter` (doesn't implement the interface) as in-test fixtures.
- [ ] Run → red.
- [ ] Create `config/cdn.php`; implement `resolveAdapter()`.
- [ ] Run → green.
- [ ] `composer run analyse` + `composer run phpcs` → clean.
- [ ] Commit (in `glueful/cdn`): `feat: config-driven CDN adapter resolution with degrade-to-disabled`.

**Rollback risk:** Medium — this is the spec's safety-critical "never crash boot" contract. The failure-mode test is the guard; keep it.

---

## Task 6 — Move `cache:purge` into the extension + provider wiring

**Copy (framework → extension; core original stays until Task 7 — do not `git mv`)**
- `framework:src/Console/Commands/Cache/PurgeCommand.php` → `src/Console/PurgeCommand.php`, namespace `Glueful\Extensions\Cdn\Console`. Keeps `#[AsCommand(name: 'cache:purge', …)]`. Retype the `private EdgeCacheService $edgeCacheService` field (`:31`) and `getService(EdgeCacheService::class)` (`:151`) to `\Glueful\Extensions\Cdn\EdgeCachePurger` **in the package copy**. (The pre-existing `getStats()` key mismatch at `:404-418` — R4 — travels with the command; **do not** fix it here, it is a non-goal of this extraction. Optionally leave a `// TODO` referencing R4.)
- **Core's `cache:purge` stays until Task 7:** core retains `framework:src/Console/Commands/Cache/PurgeCommand.php`, which `ConsoleProvider` keeps auto-discovering by scanning `framework:src/Console/Commands` for `#[AsCommand]` (verified `src/Container/Providers/ConsoleProvider.php:79-115`). It still resolves the core `EdgeCacheService::class` binding (kept bound in Task 2), so core stays green. **Task 7** deletes the core file, dropping it from core's `console.commands` tag with **no manifest edit** (the production command-cache manifest is regenerated; note for ops). No duplicate-command risk meanwhile: the extension copy and the core copy never coexist in the same install — the extension isn't registered into the framework checkout.

**Modify**
- `src/CdnServiceProvider.php`:
  - `services()` returns (per spec, `FactoryDefinition`/`AliasDefinition`, not array shorthand):
    ```php
    \Glueful\Cache\Contracts\EdgeCacheInterface::class => new \Glueful\Container\Definition\FactoryDefinition(
        \Glueful\Cache\Contracts\EdgeCacheInterface::class,
        static function (\Psr\Container\ContainerInterface $c) {
            $context = $c->get(\Glueful\Bootstrap\ApplicationContext::class);
            return new \Glueful\Extensions\Cdn\EdgeCachePurger($context, (array) config($context, 'cdn', []));
        },
        true,
    ),
    \Glueful\Extensions\Cdn\EdgeCachePurger::class => new \Glueful\Container\Definition\AliasDefinition(
        \Glueful\Extensions\Cdn\EdgeCachePurger::class,
        \Glueful\Cache\Contracts\EdgeCacheInterface::class,
    ),
    ```
    This overrides the core `EdgeCacheInterface => NullEdgeCache` binding (last-provider-wins) and aliases the concrete class to the same shared instance.
  - `register()`: `$this->mergeConfig('cdn', require __DIR__ . '/../config/cdn.php');`
  - `boot()`: `$this->discoverCommands('Glueful\\Extensions\\Cdn\\Console', __DIR__ . '/Console');` (ships `cache:purge` with the extension — Decision §3).

**Steps**
- [ ] **Copy** `PurgeCommand.php` into the extension (leave the core original until Task 7); retype its purger field/getService call in the package copy.
- [ ] Fill `CdnServiceProvider::services()/register()/boot()`.
- [ ] Write `tests/Unit/CdnServiceProviderTest.php` asserting `services()` returns a `FactoryDefinition` for `EdgeCacheInterface::class` and an `AliasDefinition` for `EdgeCachePurger::class` pointing at the interface. Run → green.
- [ ] In **framework**, run `composer test` → still green (core is untouched — its `PurgeCommand`/`EdgeCacheService` remain bound and working; Task 7 removes them).
- [ ] `composer run analyse` + `composer run phpcs` (both repos) → clean.
- [ ] Commit (in `glueful/cdn`): `feat: ship cache:purge command + wire EdgeCachePurger via FactoryDefinition`.

**Rollback risk:** Low while copying — **core is untouched** (its `cache:purge` + `EdgeCacheService` binding remain), so core stays green; the provider binding + command copy live only in the package. Revert = delete the package copy and drop the provider bindings.

---

## Task 7 — Single atomic core-removal: delete CDN/edge surface + dead trait + `cache.edge` block (Step 3)

**This is the single, atomic core-removal commit.** Every core deletion + edit below lands together so core goes from green (with the still-bound `EdgeCacheService` + core `cache:purge`) to green (CDN/edge surface gone, response caching on the `NullEdgeCache` seam) in one step — there is no intermediate broken state (the copies made in Tasks 4–6 are already proven green in the package suite). This is the breaking change.

**Delete (framework)**
- `framework:src/Helpers/CDNAdapterManager.php` — dead trait, zero references in `src/` (verified by grep; Decision §4 / G6). Delete outright, not move.
- `framework:src/Cache/EdgeCacheService.php`, `framework:src/Cache/CDN/CDNAdapterInterface.php`, `framework:src/Cache/CDN/AbstractCDNAdapter.php`, `framework:src/Console/Commands/Cache/PurgeCommand.php` — their content was **copied** to the extension (Tasks 4, 6). Remove the now-duplicated originals and the empty `src/Cache/CDN/` directory. Removing `PurgeCommand.php` drops `cache:purge` from core's `ConsoleProvider` `#[AsCommand]` scan with no manifest edit.

**Modify (framework)**
- `framework:src/Container/Providers/CoreProvider.php:460` — **remove** the `$defs[\Glueful\Cache\EdgeCacheService::class] = $this->autowire(\Glueful\Cache\EdgeCacheService::class);` line that Task 2 deliberately kept (the class it autowires is deleted above). Leave the `EdgeCacheInterface => NullEdgeCache` binding (added in Task 2) in place — that is core's only remaining edge binding.
- `framework:config/cache.php` — delete the `'edge' => [...]` block (`:79-87`). Leave `distributed`, `enable_tags`, `tags_store`, stores, stampede settings untouched (they stay in core — G1). The `EDGE_CACHE_*` env vars are now read only by the extension.

**Docs (framework CHANGELOG + upgrade notes) — concrete, do not skip**
- `framework:CHANGELOG.md` `[Unreleased]` — add a **breaking-change** entry recording the five breaks: (1) edge purging + edge cache headers now require `composer require glueful/cdn`; (2) `Glueful\Cache\EdgeCacheService` → `Glueful\Extensions\Cdn\EdgeCachePurger` (now `implements Glueful\Cache\Contracts\EdgeCacheInterface`); (3) `Glueful\Cache\CDN\{CDNAdapterInterface,AbstractCDNAdapter}` → `Glueful\Extensions\Cdn\Adapters\*`; (4) `cache.edge` config moves to the extension's `cdn` key; (5) `Glueful\Helpers\CDNAdapterManager` deleted.
- Framework **upgrade notes** (`UPGRADE.md` or the repo's upgrade-notes location — append a section) stating the **two distinct without-`glueful/cdn` behaviors** (keep these separate so it doesn't read as "the CLI returns false"):
  - **CLI:** `php glueful cache:purge` is **absent** (not registered) — invoking it is a command-not-found, *not* a command that returns false.
  - **Programmatic:** code resolving `EdgeCacheInterface` from the container gets `NullEdgeCache` — `isEnabled()===false`, `getProvider()===null`, `generateCacheHeaders()===[]` (response caching still emits its own surrogate keys), and `purgeUrl/purgeByTag/purgeAll` return `false` (disabled no-op).
  - Enablement: `composer require glueful/cdn` + set `cdn.provider` (and register the adapter class in `cdn.adapters`) to restore edge purging/headers.
- Per repo convention, **do not stage `CLAUDE.md`** in this commit; if CLAUDE.md's caching section needs a note, edit it locally and exclude it via explicit `git add`.

**Docs (extension `glueful/cdn` README)**
- Author `glueful/cdn/README.md` documenting: the **intentional constructor change** (`EdgeCachePurger(ApplicationContext $context, array $config)` — config injected, not read from `cache.edge`); the `cdn.provider` → `cdn.adapters` (name→class) registration contract incl. how third-party adapters register by merging into `cdn.adapters`; the degrade-to-disabled behavior; and install/enable steps.

**Steps**
- [ ] Delete the five framework files (`CDNAdapterManager`, `EdgeCacheService`, the two `Cache/CDN/*`, `PurgeCommand`) + the empty `src/Cache/CDN/` dir.
- [ ] Remove the `EdgeCacheService::class` binding line (`CoreProvider.php:460`) and the `cache.edge` block (`config/cache.php:79-87`).
- [ ] Write the framework `CHANGELOG.md` `[Unreleased]` breaking entry + the `UPGRADE.md` section (CLI-absent vs programmatic-no-op, kept distinct as above).
- [ ] Author `glueful/cdn/README.md` (constructor change + adapter-registration contract + enable steps).
- [ ] `composer dump-autoload` (framework).
- [ ] Run `composer test` (framework) → green (Task 8/9 acceptance tests prove the absence; no dangling `EdgeCacheService` binding remains).
- [ ] `composer run analyse` + `composer run phpcs` (framework) → clean (no dangling imports/refs).
- [ ] Commit (framework): `chore(cache)!: remove CDN/edge surface from core (extracted to glueful/cdn)` — staging the touched files explicitly (not `CLAUDE.md`).

**Rollback risk:** High — this is the breaking, destructive commit (deletes core code, removes the `EdgeCacheService` binding, drops `cache:purge`). Mitigated: all deleted content lives in the extension and core now runs behind the `NullEdgeCache` seam; revert = `git revert` (restores the files + the `EdgeCacheService` binding).

---

## Task 8 — Core-only acceptance test (framework, extension absent)

**Create (framework)**
- `tests/Integration/Cache/EdgeCacheCoreOnlyTest.php`

**Assertions (spec Verification §2):**
- [ ] A booted framework container (no `glueful/cdn`) resolves `EdgeCacheInterface::class` to an instance of `NullEdgeCache`.
- [ ] `ResponseCachingTrait::edgeCacheResponse()` (via a controller stub) returns a valid `Response` and adds **no** edge cache-control header (`generateCacheHeaders()` → `[]`); the `Surrogate-Key` header is still emitted (surrogate keys unaffected).
- [ ] `cache:purge` is **absent** from the console application's command list (build the `Console\Application` from the test container and assert `has('cache:purge') === false`); other `cache:*` commands (`cache:clear`, `cache:status`, etc.) are still present.
- [ ] A grep guard over **runtime/core source only** (`src/`, `config/`, `routes/` — excluding `docs/`, `tests/`, specs, fixtures) finds **no** occurrences of `EdgeCacheService`, `CDNAdapterManager`, or `Glueful\Cache\CDN\`. Implement as a test that shells the grep (or scans via `RecursiveDirectoryIterator`) and asserts zero matches.
- [ ] `composer.json` (framework) gains **no** CDN dependency (assert the spec's "no new core dep": parse `composer.json`, assert no `glueful/cdn` and no CDN SDK in `require`).

**Steps**
- [ ] Write the test → run; the grep-guard + command-absence parts should already pass after Task 7, the binding/trait parts after Task 2.
- [ ] Run `composer test` → green; `analyse` + `phpcs` clean.
- [ ] Commit (framework): `test(cache): core-only acceptance — NullEdgeCache, no edge surface`.

**Rollback risk:** Low — test-only.

---

## Task 9 — Provider-override + adapter-failure tests (extension installed)

> These run with `glueful/cdn` installed (path repo). Place them in the **extension** so the framework suite stays extension-free; they boot a framework container with `CdnServiceProvider` registered.

**Create (extension)**
- `tests/Integration/ProviderOverrideTest.php`
- `tests/Integration/AdapterFailureBootTest.php`

**Provider-override (spec Verification §1):**
- [ ] With `CdnServiceProvider` registered, a booted container resolves `EdgeCacheInterface::class` to an `EdgeCachePurger` (**not** `NullEdgeCache`) — proves last-provider-wins.
- [ ] `get(EdgeCachePurger::class)` returns the **same shared instance** as `get(EdgeCacheInterface::class)` (alias + shared factory).

**Adapter-failure boot (spec Verification §3):** for each of `cdn.provider` (a) unset/empty, (b) unknown key, (c) mapped to a missing class, (d) mapped to a non-`CDNAdapterInterface` / throwing-ctor class:
- [ ] The container **boots without throwing**.
- [ ] `EdgeCacheInterface` resolves to an `EdgeCachePurger` with `isEnabled() === false`.
- [ ] `generateCacheHeaders()` → `[]` and the three purges → `false` (no exception escapes boot or a request).
- [ ] A warning is logged (assert via a spy logger or captured `error_log`, if feasible in the harness).

**Steps**
- [ ] Write both tests → red where applicable.
- [ ] Run the extension suite → green. `analyse` + `phpcs` (extension) clean.
- [ ] Commit (in `glueful/cdn`): `test: provider-override + adapter-failure boot coverage`.

**Rollback risk:** Low — test-only.

---

## Cross-cutting

- **Verification gate per task:** `composer test` (targeted then full), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.
- **Upgrade notes / docs:** the concrete CHANGELOG / `UPGRADE.md` / extension-README edits are a **task step in Task 7** (not just cross-cutting prose) so they can't be skipped. They record the five breaks (class/namespace/config moves + `CDNAdapterManager` deletion) and, crucially, keep the two without-`glueful/cdn` behaviors **distinct**: the **`cache:purge` CLI is absent** (command-not-found — it is *not* a command that returns false), whereas **programmatic** `EdgeCacheInterface` calls resolve to `NullEdgeCache` and return disabled/no-op results (`generateCacheHeaders()===[]`; `purgeUrl/ByTag/All` return `false`; response caching still emits its own surrogate keys). The extension README documents the intentional constructor change and the `cdn.provider` → `cdn.adapters` registration contract.
- **Sequencing:** Step 1 (Tasks 1–2) is an in-place core refactor committed as ordinary green core commits; Tasks 3–6 **copy** the CDN sources into `glueful/cdn` while **core remains untouched** (core stays green throughout — `EdgeCacheService` stays bound and `cache:purge` keeps resolving it); **Task 7 is the single atomic core-removal** (the breaking commit). Keep the series on one branch and ship the core removal only at/after Task 7 — never mid-series. Core never references a class it no longer binds: the no-op seam lands in Task 2, `EdgeCacheService` stays bound until Task 7.
- **Step 1 shippable alone:** Tasks 1–2 form a self-contained, green, shippable increment per the spec sequencing — core runs on the `NullEdgeCache` seam while `EdgeCacheService` + core `cache:purge` remain bound and working (no copy/move required for Step 1 to be green).
- **Non-goals (do not do):** touch the cache primitive (`CacheStore`/tagging/`QueryCacheService`/`DistributedCacheService`/`CacheWarmupService` and the rest of `config/cache.php`); change response-caching semantics; fix the pre-existing `getStats()` key mismatch (R4 — belongs to the extension as a later cleanup); ship a concrete vendor adapter (Cloudflare/Fastly) — the `cdn.adapters` map is defined, populating it is a packaging follow-up.

## Self-review (completed during planning)

- **Spec coverage:** seam + null default (T1) ✓; `ResponseCachingTrait`/`CoreProvider` rewire to seam, `EdgeCacheService` kept bound (T2) ✓; extension scaffold w/ concrete `composer.json` (T3) ✓; class/adapter **copy** + ctor change (T4) ✓; config-driven resolution + degrade-to-disabled (T5) ✓; `cache:purge` **copy** + `FactoryDefinition`/`AliasDefinition` wiring (T6) ✓; single atomic core-removal — delete CDN/edge surface + `EdgeCacheService` binding + `CDNAdapterManager` + `cache.edge` (T7) ✓; all three required verification scenarios — provider-override, core-only acceptance, adapter-failure (T8–T9) ✓.
- **Sequencing matches spec:** Step 1 = T1–T2 (in-place core refactor, core green/no-op, shippable); Step 2 = T3–T6 (**copy-first** into the extension, core untouched/green); Step 3 = T7 (single atomic core-removal, breaking). Verification T8–T9. Core stays green after every task; exactly one atomic core-removal commit (T7).
- **Grounded paths verified:** `EdgeCacheService.php:57-83` ctor, `:228-274` scan; `CoreProvider.php:460` autowire; `ResponseCachingTrait.php:10,390-392`; `config/cache.php:79-87`; `PurgeCommand.php:31,151,404`; `ConsoleProvider.php:79-115` auto-discovery (confirms deleting the file at T7 removes the command); `FactoryDefinition`/`AliasDefinition` constructors. Grep confirms zero `CDNAdapterManager` references and zero `tests/` references to the moving classes.
