# Extract CDN / Edge Cache → `glueful/cdn` — Design Note

**Status:** Draft v3 — review folded in (seam renamed `EdgeCacheInterface`/`NullEdgeCache`, `FactoryDefinition` provider style, config-driven adapter registry, explicit `EdgeCachePurger` constructor contract, required-coverage section: provider-override + core-only acceptance + adapter-failure); no code yet · **Scope:** `src/Cache/EdgeCacheService.php`, `src/Cache/CDN/**`, `src/Helpers/CDNAdapterManager.php`, the `cache:purge` command, the `cache.edge` config block, and the `CoreProvider`/`ResponseCachingTrait` seams. The cache *primitive* (cache abstraction + tagging) stays in core and is out of scope except as the thing CDN sits on top of.

## Problem

The CDN / edge-cache integration is a **provider/ops surface** sitting on top of the core cache primitive — the same category as metrics exporters, which we keep out of core. Today it lives in framework core:

- `EdgeCacheService` (`src/Cache/EdgeCacheService.php:25`) is a thin façade over a pluggable `CDNAdapterInterface`. It is **bound in `CoreProvider`** via plain autowire (`src/Container/Providers/CoreProvider.php:460`), so every app — including ones that never touch a CDN — carries it.
- The adapter contract + base class live under `src/Cache/CDN/` (`CDNAdapterInterface.php:12`, `AbstractCDNAdapter.php:11`). There is no concrete adapter in core and **no vendor CDN SDK in `composer.json`** — the integration is light, but it is pure provider plumbing. Adapters are expected to arrive from extensions via a `registerCDNAdapters()` static method (`EdgeCacheService.php:245-263`).
- A dead-weight trait `src/Helpers/CDNAdapterManager.php` duplicates the adapter-resolution loop and is **referenced nowhere** (grep for `CDNAdapterManager` across `src/` returns only its own definition).
- `EdgeCacheService` exposes `purgeUrl()` (`:106`), `purgeByTag()` (`:121`), `purgeAll()` (`:135`), plus `generateCacheHeaders()` (`:91`), `getStats()` (`:149`), `isCacheable()` (`:168`), `getProvider()` (`:182`), `isEnabled()` (`:196`).

**The seam rule** ("leave a contract in core only if core, or multiple packages, calls through it") means we must look at exactly which methods core calls. There are **two core call sites**, and they use *different* methods:

1. `ResponseCachingTrait::edgeCacheResponse()` (`src/Controllers/Traits/ResponseCachingTrait.php:390`) resolves `EdgeCacheService` from the container and calls **only `generateCacheHeaders($pattern, $contentType)`** (`:392`). It never purges. This is a core controller feature (response caching) that wants edge cache *headers* when an edge integration is present.
2. `cache:purge` (`src/Console/Commands/Cache/PurgeCommand.php`) is the **only** caller of `purgeUrl`/`purgeByTag`/`purgeAll` (`:225,:264,:292`) and also calls `isEnabled()` (`:114`), `getProvider()` (`:120`), `getStats()` (`:404`). This command is entirely CDN-specific.

So the *purge* methods named in the agreed seam are **not** called from `ResponseCachingTrait` at all — they are only called from a command that is itself CDN-specific and should move. Header generation is the only edge concern core actually reaches for.

## Guardrails

- **G1 — Keep the cache primitive in core.** `CacheStore`, tagging (`invalidateTags`), `QueryCacheService`, `DistributedCacheService`, `CacheWarmupService` and the rest of `src/Cache/` stay. Only the CDN/edge façade + adapters move.
- **G2 — Core keeps working with no CDN installed.** `ResponseCachingTrait::edgeCacheResponse()` must still produce a response (today it would fatal if `EdgeCacheService` were simply deleted from the container). With no purger installed, response caching works and edge headers are simply empty.
- **G3 — Seam = real consumers only.** Put a contract in core only for the method core actually calls. Core calls `generateCacheHeaders()` (headers) — not purge. The interface core depends on must cover *that* call; purge lives behind the same interface so a future core/multi-package caller and the moved command share one contract, but the no-op default makes purge a silent false in core.
- **G4 — No vendor coupling enters core.** No CDN SDK, no `registerCDNAdapters()` discovery, no `cloudflare` default leaks into the framework after extraction.
- **G5 — Breaking changes are acceptable now** (framework is public but pre-production), with clear upgrade notes. We do a clean break: move the classes, change the namespace, drop the autowire binding. No back-compat shim class in core.
- **G6 — One resolution path.** Kill the duplicate `CDNAdapterManager` trait; the extension's purger owns adapter resolution.

## The core seam — `EdgeCacheInterface`

Add one small interface to core. **Name it for the capability, not one verb:** the only *core* caller is `generateCacheHeaders()` (`ResponseCachingTrait`) — purge is used solely by the command that moves out — so naming it `CachePurgerInterface` would be backwards (it would advertise the method core doesn't call and hide the one it does). `EdgeCacheInterface` describes the whole edge-cache capability (headers + provider state + purge), which is what the contract actually models. It covers **both** consumers — the header consumer (`ResponseCachingTrait`) and the purge consumer (the moved command) — under one contract.

```php
namespace Glueful\Cache\Contracts;   // new file: src/Cache/Contracts/EdgeCacheInterface.php

interface EdgeCacheInterface
{
    /** True when a real edge/CDN integration is installed and enabled. */
    public function isEnabled(): bool;

    /** Provider slug (e.g. "cloudflare"), or null when no purger is installed. */
    public function getProvider(): ?string;

    /**
     * Edge cache-control headers for a route/content-type.
     * @return array<string, string>  Empty when disabled — callers add nothing.
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array;

    /** Purge a single URL. Returns false when no purger is installed. */
    public function purgeUrl(string $url): bool;

    /** Purge by cache tag. Returns false when no purger is installed. */
    public function purgeByTag(string $tag): bool;

    /** Purge everything. Returns false when no purger is installed. */
    public function purgeAll(): bool;
}
```

Signatures are copied verbatim from current `EdgeCacheService` usage so the move is mechanical:
- `generateCacheHeaders(string, ?string): array` — matches `ResponseCachingTrait.php:392` and `EdgeCacheService.php:91`.
- `isEnabled(): bool` / `getProvider(): ?string` — matches `PurgeCommand.php:114,120` and `EdgeCacheService.php:196,182`.
- `purgeUrl/purgeByTag/purgeAll(): bool` — matches `PurgeCommand.php:225,264,292` and `EdgeCacheService.php:106,121,135`.

`getStats()` / `isCacheable()` / `getCDNAdapter()` / `setCDNAdapter()` are **not** in the seam — only `cache:purge`'s stats display (`PurgeCommand.php:404`) uses `getStats()`, and that command moves to the extension, so stats can stay on the concrete impl without polluting the core contract.

### No-op core default — `NullEdgeCache`

```php
namespace Glueful\Cache;   // new file: src/Cache/NullEdgeCache.php

final class NullEdgeCache implements Contracts\EdgeCacheInterface
{
    public function isEnabled(): bool { return false; }
    public function getProvider(): ?string { return null; }
    public function generateCacheHeaders(string $route, ?string $contentType = null): array { return []; }
    public function purgeUrl(string $url): bool { return false; }
    public function purgeByTag(string $tag): bool { return false; }
    public function purgeAll(): bool { return false; }
}
```

This mirrors `EdgeCacheService`'s own disabled-state returns (`generateCacheHeaders` → `[]` at `:94`; purges → `false` at `:109,:124,:138`), so behaviour with no integration installed is identical to today's behaviour with edge caching disabled.

### Rewiring `ResponseCachingTrait`

`edgeCacheResponse()` (`ResponseCachingTrait.php:384-409`) changes its one dependency line:

- Replace `use Glueful\Cache\EdgeCacheService;` (`:10`) with `use Glueful\Cache\Contracts\EdgeCacheInterface;`.
- Change `container(...)->get(EdgeCacheService::class)` (`:390`) to `container(...)->get(EdgeCacheInterface::class)`.
- The rest is unchanged: it calls `generateCacheHeaders($pattern, $contentType)` (`:392`) and emits headers. With `NullEdgeCache` bound by default this returns `[]`, the `foreach` adds nothing, surrogate keys are still emitted, and the response is returned — exactly G2.

## Files to move / change / delete

| Path | Action | Destination / Notes |
|---|---|---|
| `src/Cache/EdgeCacheService.php` | **Move + rename impl** | → `glueful/cdn`: `Glueful\Extensions\Cdn\EdgeCachePurger implements Glueful\Cache\Contracts\EdgeCacheInterface`. Drop the `resolveCDNAdapter()` extension-scan (`:228-274`); the extension wires its adapter directly. Keep `getStats()`/`isCacheable()` on the concrete class (not in the core contract). |
| `src/Cache/CDN/CDNAdapterInterface.php` | **Move** | → `glueful/cdn`: `Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface`. |
| `src/Cache/CDN/AbstractCDNAdapter.php` | **Move** | → `glueful/cdn`: `Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter`. |
| `src/Helpers/CDNAdapterManager.php` | **Delete** | Dead trait — zero references in `src/`. Its resolution logic is duplicated in `EdgeCacheService::resolveCDNAdapter()`; neither survives the move (G6). |
| `src/Console/Commands/Cache/PurgeCommand.php` | **Move** | → `glueful/cdn`: `Glueful\Extensions\Cdn\Console\PurgeCommand`, discovered via the extension's `discoverCommands()` (recommended — it is CDN-specific and unusable without a purger; see Decisions §3). Retype its `private EdgeCacheService` field (`:31`) and `getService(EdgeCacheService::class)` (`:151`) to the extension's `EdgeCachePurger`. |
| `config/cache.php` `'edge' => [...]` block (`:79-87`) | **Move** | → `glueful/cdn` `config/cdn.php`, merged via `mergeConfig('cdn', …)`. Keys (`enabled`/`provider`/`default_ttl`/`rules`) and env vars (`EDGE_CACHE_ENABLED`, `EDGE_CACHE_PROVIDER`, `EDGE_CACHE_TTL`) move with it. The default `'provider' => 'cloudflare'` (`:82`) is vendor bias — it leaves core (G4). `EdgeCacheService.php:74` reads `config(..., 'cache.edge', [])`; the extension reads its own `cdn` key. |
| `src/Container/Providers/CoreProvider.php:460` | **Replace binding** | Remove `EdgeCacheService` autowire; bind `EdgeCacheInterface::class => NullEdgeCache` instead (see below). |
| `src/Cache/Contracts/EdgeCacheInterface.php` | **Add (new, core)** | The seam. |
| `src/Cache/NullEdgeCache.php` | **Add (new, core)** | No-op default. |
| `src/Controllers/Traits/ResponseCachingTrait.php:10,390` | **Edit (core)** | Depend on `EdgeCacheInterface` instead of `EdgeCacheService`. |

### What stays in core (the cache primitive)

`src/Cache/CacheStore.php` and the store implementations, tagging/`invalidateTags`, `QueryCacheService` (`CoreProvider.php:461`), `DistributedCacheService` (`:453`), `CacheWarmupService` (`:452`), and all `src/Console/Commands/Cache/*` **except `PurgeCommand`** (`ClearCommand`, `GetCommand`, `SetCommand`, `DeleteCommand`, `StatusCommand`, `InspectCommand`, `ExpireCommand`, `TtlCommand`, `MaintenanceCommand` — none reference edge/CDN). The `cache.edge` block is the only part of `config/cache.php` that leaves; `distributed`, tags, etc. stay.

## `CoreProvider` changes

Replace the single autowire line (`CoreProvider.php:460`):

```php
// was: $defs[\Glueful\Cache\EdgeCacheService::class] = $this->autowire(...);
$defs[\Glueful\Cache\Contracts\EdgeCacheInterface::class] =
    $this->autowire(\Glueful\Cache\NullEdgeCache::class);
```

`NullEdgeCache` has no constructor dependencies, so autowire is trivial. The extension overrides this binding (last provider wins) to point `EdgeCacheInterface` at its real `EdgeCachePurger`.

## New extension layout — `glueful/cdn`

```
glueful/cdn/
  composer.json            # type: glueful-extension; extra.glueful.provider = Glueful\Extensions\Cdn\CdnServiceProvider
  config/cdn.php           # the moved 'edge' block, keyed 'cdn'
  src/
    CdnServiceProvider.php
    EdgeCachePurger.php     # ex-EdgeCacheService, implements EdgeCacheInterface
    Adapters/
      CDNAdapterInterface.php
      AbstractCDNAdapter.php
      # concrete adapters register via the cdn.adapters config map (see Adapter resolution);
      # a bundled adapter here is optional, external provider packages add to the map
    Console/
      PurgeCommand.php      # ex-cache:purge
```

`CdnServiceProvider` (extends `Glueful\Extensions\ServiceProvider`):

```php
public static function services(): array
{
    return [
        // Override the core no-op binding with the real purger. EdgeCachePurger needs the
        // ApplicationContext + the 'cdn' config (it resolves its adapter from cdn.provider
        // against the cdn.adapters map — see Adapter resolution), so use a FactoryDefinition
        // — the repo's standard provider style — not the array/autowire shorthand.
        \Glueful\Cache\Contracts\EdgeCacheInterface::class => new \Glueful\Container\Definition\FactoryDefinition(
            \Glueful\Cache\Contracts\EdgeCacheInterface::class,
            static function (\Psr\Container\ContainerInterface $c) {
                $context = $c->get(\Glueful\Bootstrap\ApplicationContext::class);
                return new \Glueful\Extensions\Cdn\EdgeCachePurger($context, (array) config($context, 'cdn', []));
            },
            true,
        ),
        // Resolve the concrete class to the same shared instance.
        \Glueful\Extensions\Cdn\EdgeCachePurger::class => new \Glueful\Container\Definition\AliasDefinition(
            \Glueful\Extensions\Cdn\EdgeCachePurger::class,
            \Glueful\Cache\Contracts\EdgeCacheInterface::class,
        ),
    ];
}

public function register(\Glueful\Bootstrap\ApplicationContext $context): void
{
    $this->mergeConfig('cdn', require __DIR__ . '/../config/cdn.php');
}

public function boot(\Glueful\Bootstrap\ApplicationContext $context): void
{
    // cache:purge ships with the extension, not core.
    $this->discoverCommands('Glueful\\Extensions\\Cdn\\Console', __DIR__ . '/Console');
}
```

### `EdgeCachePurger` constructor contract (intended change)

The move deliberately changes the constructor. Today:

```php
// core EdgeCacheService — reads 'cache.edge' itself, scans for an adapter
public function __construct(
    ApplicationContext $context,
    ?CacheStore $cacheStore = null,
    ?CDNAdapterInterface $cdnAdapter = null,
) // EdgeCacheService.php:57-61
```

The extension's `EdgeCachePurger` takes the resolved `cdn` config explicitly and owns adapter resolution from it:

```php
namespace Glueful\Extensions\Cdn;

public function __construct(
    \Glueful\Bootstrap\ApplicationContext $context,
    array $config,                 // the 'cdn' config block (provider, adapters, edge settings)
)
```

- The config is **injected** (`config($context, 'cdn', [])` via the factory), not read from `cache.edge` inside the class — so the class no longer hard-codes a core config key.
- The **adapter is resolved internally from `$config`** (`provider` + `adapters` map — see below), replacing the `?CDNAdapterInterface` ctor arg and the `resolveCDNAdapter()` scan.
- The `CacheStore` is resolved internally via the existing `CacheHelper::createCacheInstance()` fallback (today's behaviour when none is passed, `EdgeCacheService.php:66-73`), so it drops off the constructor signature; if a future need arises it can be added as a third factory-supplied arg.
- This is an **intentional API change**, not a like-for-like move — call it out in the extension's docs. Apps that constructed `EdgeCacheService` by hand (none in-tree) must switch to resolving `EdgeCacheInterface` from the container.

### Adapter resolution (decided — config-driven map, no scan)

Core ships **no** concrete CDN adapter today — only `CDNAdapterInterface` + `AbstractCDNAdapter` — and resolves adapters through a legacy static `registerCDNAdapters()` extension-scan (`EdgeCacheService.php:228-274`). That scan **does not survive** (G4). Replacement, decided now (Decision §6):

- `glueful/cdn` owns `CDNAdapterInterface` + `AbstractCDNAdapter` (moved to `Glueful\Extensions\Cdn\Adapters\*`).
- The active adapter is resolved from config by a **name → class map**: `config('cdn.provider')` selects a key from `config('cdn.adapters')`, e.g. `['cloudflare' => CloudflareAdapter::class, 'fastly' => FastlyAdapter::class]`, shipped in the extension's `config/cdn.php`. `EdgeCachePurger` instantiates that class in its constructor — **no runtime class scan, no `ExtensionManager` reach-in**.
- **Third-party CDN adapters register by extending the map**, not by a discovery hook: a provider package merges `['adapters' => ['myprovider' => MyAdapter::class]]` into the `cdn` config from its own `ServiceProvider::register()` (`mergeConfig('cdn', ...)`), then apps set `cdn.provider=myprovider`. Pure config; deterministic.
- **Degrade to disabled on any resolution failure — never crash boot.** All of these resolve to a disabled `EdgeCachePurger` (`isEnabled() === false`, every method no-ops exactly like `NullEdgeCache`): (a) `cdn.provider` unset/empty; (b) `cdn.provider` names a key absent from `cdn.adapters`; (c) the mapped class doesn't exist or doesn't implement `CDNAdapterInterface`; (d) the adapter constructor throws. Resolution is wrapped so a misconfiguration logs a warning and disables the purger — it must **not** raise during container build / app boot. Installing `glueful/cdn` without (or with a broken) provider config is therefore safe. (Covered by the adapter-failure test below.)
- **Bundled adapter:** whether `glueful/cdn` ships one concrete adapter out of the box (a generic HTTP-purge adapter, or Cloudflare) is a packaging follow-up — but the registration *contract* (the `cdn.adapters` map) is defined here, so the extension is coherent with zero, one, or many external adapters.

## Upgrade notes

- **Edge purging / edge cache headers now require an extension.** Run `composer require glueful/cdn` to restore them. Without it:
  - Response caching **still works** — `ResponseCachingTrait` and all `controller:*` caching are unaffected (they use the core cache primitive, not the CDN seam).
  - `edgeCacheResponse()` still returns a valid response; `generateCacheHeaders()` resolves to `NullEdgeCache` and returns `[]`, so no edge headers are added (surrogate keys are still emitted).
  - `purgeUrl/purgeByTag/purgeAll` resolve to the no-op and return `false` (same as "edge disabled" today).
  - `php glueful cache:purge` is no longer registered by core; it ships with `glueful/cdn`. Other `cache:*` commands are unchanged.
- **Namespace / class moves:** `Glueful\Cache\EdgeCacheService` → `Glueful\Extensions\Cdn\EdgeCachePurger` (now implements `Glueful\Cache\Contracts\EdgeCacheInterface`); `Glueful\Cache\CDN\{CDNAdapterInterface,AbstractCDNAdapter}` → `Glueful\Extensions\Cdn\Adapters\*`. Code that injected `EdgeCacheService` should depend on `EdgeCacheInterface` instead.
- **Config:** the `cache.edge` block moves to the extension's `cdn` config key. Env vars (`EDGE_CACHE_*`) are read by the extension; they are inert in core-only installs.
- **Removed:** `Glueful\Helpers\CDNAdapterManager` (dead trait) is deleted outright.

## Risks

- **R1 — Apps injecting `EdgeCacheService` directly** break at container-resolve time. Mitigation: it was bound by autowire in `CoreProvider` only; in-tree the sole consumers are `ResponseCachingTrait` and `PurgeCommand`, both handled here. App-side usages get a clear upgrade note (depend on `EdgeCacheInterface`).
- **R2 — Silent no-op purges.** With the extension absent, `cache:purge`-style purge calls return `false` rather than erroring. This matches today's "edge disabled" behaviour, but operators must know edge purging is now opt-in. Covered in upgrade notes; the no-op is intentional per G2/G3.
- **R3 — Contract slightly broader than core's single call.** `generateCacheHeaders` is the *only* real core consumer; the purge methods exist for the (moving) command. Keeping all of them in one `EdgeCacheInterface` is intentional — the interface models the whole edge-cache capability (hence the name) so the command and any future caller share one contract, and the no-op default stays honest. (See Decisions §1.)
- **R4 — `getStats()` shape mismatch.** `PurgeCommand::displayCacheStats()` (`:404-418`) reads keys (`entries`, `size`, `hit_rate`) that `AbstractCDNAdapter::getStats()` does not return (`provider`, `status`, `cache_hit_ratio`, …). This is a pre-existing bug; it travels with the command to the extension and is **not** introduced by this extraction. Flag for cleanup there.
- **R5 — Provider default leak.** Leaving `'provider' => 'cloudflare'` in core config would violate G4; the whole `edge` block must move, not be copied.

## Decisions (resolved)

1. **Seam named `EdgeCacheInterface` (capability), not `CachePurgerInterface` (one verb).** Core's only call is `generateCacheHeaders()` (`ResponseCachingTrait`); purge is the moving command's. Naming the contract after purge would advertise the method core doesn't use and hide the one it does. `EdgeCacheInterface` (in `Glueful\Cache\Contracts\`) models the whole edge-cache capability — headers + `isEnabled`/`getProvider` + purge — and one contract serves both the header consumer and the (moving) purge consumer plus the no-op default. (G3, R3)
2. **No back-compat shim in core.** Pre-production framework + clear upgrade notes (G5): delete the binding, move the classes, change the namespace. No deprecated `Glueful\Cache\EdgeCacheService` alias.
3. **`cache:purge` moves to the extension** (not a thin core no-op command). It is unusable without a real purger, calls purge/stats directly, and is conceptually CDN ops. Discovered via the extension's `discoverCommands()`. A core no-op command would only print "no edge integration installed" — not worth the surface.
4. **`CDNAdapterManager` trait is deleted, not moved.** It is dead code (zero refs) and duplicates the adapter-resolution loop; the extension's purger owns resolution. (G6)
5. **`getStats()`/`isCacheable()` stay off the core contract.** Their only in-tree caller (`PurgeCommand`) moves to the extension, so they live on the concrete `EdgeCachePurger` without entering `EdgeCacheInterface`.
6. **Adapter resolution is a config-driven map, not a scan.** `cdn.provider` selects from a `cdn.adapters` (name → class) map in the extension's `config/cdn.php`; `EdgeCachePurger` instantiates that class. The legacy static `registerCDNAdapters()` scan is removed (not moved). Third-party CDN packages register by merging into `cdn.adapters` from their own provider; an unconfigured/missing provider leaves the purger in a no-op disabled state. Whether the extension bundles a concrete adapter is a packaging follow-up; the registration contract is fixed here. (G4, G6 — see Adapter resolution)
7. **Provider uses `FactoryDefinition`/`AliasDefinition`, not the array shorthand.** `EdgeCachePurger` needs the `ApplicationContext` + `cdn` config (and resolves its adapter), so it's built via a `FactoryDefinition` (same style as the Archive spec and `ConversaServiceProvider`); the concrete class aliases to the shared interface binding.

## Verification (required coverage)

The implementation plan must include these tests/checks.

**1. Provider-override (booted container).** With `glueful/cdn` installed and registered, a booted container resolves `EdgeCacheInterface` to the extension's `EdgeCachePurger` — **not** `NullEdgeCache`. Proves last-provider-wins over the core no-op binding. (Assert `get(EdgeCacheInterface::class) instanceof EdgeCachePurger`, and that `EdgeCachePurger::class` aliases to the same shared instance.)

**2. Core-only acceptance (extension absent).** On a plain framework checkout with no `glueful/cdn`:
- the container boots cleanly;
- `EdgeCacheInterface` resolves to `NullEdgeCache`;
- `ResponseCachingTrait::edgeCacheResponse()` still returns a valid response and adds **no** edge headers (`generateCacheHeaders()` → `[]`), surrogate keys still emitted;
- `cache:purge` is **absent** from `commands:list` (it ships with the extension);
- a grep of **runtime/core source** (`src/`, `config/`, `routes/` — excluding `docs/`, specs, and test fixtures, which intentionally reference the old names) finds **no** remaining references to `EdgeCacheService`, `CDNAdapterManager`, or `Glueful\Cache\CDN\*`, and core `composer.json` gains no CDN dependency.

**3. Adapter-failure behavior (extension installed, bad config).** With `glueful/cdn` installed but `cdn.provider` (a) unset, (b) set to an unknown key, (c) mapped to a missing class, or (d) mapped to a class that doesn't implement `CDNAdapterInterface` / throws in its constructor — the container still boots, `EdgeCacheInterface` resolves to an `EdgeCachePurger` with `isEnabled() === false`, and `generateCacheHeaders()`/purges no-op (no exception escapes boot or a request). A warning is logged. Mirrors the Adapter-resolution contract above.

## Sequencing & non-goals

- Order: **(1)** add `EdgeCacheInterface` + `NullEdgeCache` and rewire `ResponseCachingTrait` + `CoreProvider` (core stays green, edge becomes no-op) → **(2)** stand up `glueful/cdn`, move `EdgeCacheService`→`EdgeCachePurger`, adapters, `PurgeCommand`, and `cdn` config; bind the real purger → **(3)** delete `CDNAdapterManager` and the `cache.edge` block from core. Step 1 is self-contained and shippable on its own.
- **Non-goals:** touching the cache primitive (store/tagging/query cache/distributed cache), changing response-caching semantics, fixing the pre-existing `getStats()` key mismatch (R4 — belongs to the extension), and shipping a concrete vendor adapter (Cloudflare/Fastly) in this spec — adapters are the extension's concern.
