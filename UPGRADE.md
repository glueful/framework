# Upgrade Notes

## Archive extracted to `glueful/archive`

The archive subsystem is no longer part of the framework core. Its code, schema,
console command, config, and capability gate were all removed and now live in the
standalone **`glueful/archive`** extension. If your application uses archiving,
follow these steps.

1. **Require the extension:**

   ```bash
   composer require glueful/archive
   ```

2. **No manual registration needed.** The extension auto-discovers via its
   `extra.glueful` composer manifest entry — its `ServiceProvider` (services,
   routes, migrations, the `archive:manage` command) is wired up automatically.

3. **Run migrations:**

   ```bash
   php glueful migrate:run
   ```

   The archive migration re-runs **once** under its new source `glueful/archive`.
   The migration ledger is keyed on the composite `(source, migration)` pair, so
   the previously-applied core entry (source `glueful/framework:archive`) does not
   match, and the extension's copy is treated as pending. This writes one extra,
   harmless ledger row. The DDL is idempotent, so on a database that already has
   the archive tables this is a no-op (Decision §9 — intended; there is no repair
   tooling to "rename" the old ledger source).

4. **Move local config overrides.** If you published or overrode `config/archive.php`,
   move those overrides to the extension's config / the `ARCHIVE_*` environment
   variables. The core `config/archive.php` no longer exists.

5. **Capability gate moved to the extension.** `ARCHIVE_DATABASE_SCHEMA=true` now
   backs the extension's own `archive.enabled` toggle (instead of the removed core
   `capabilities.archive` gate). If you publish a `config/capabilities.php`, drop
   any `archive` key from it — the core switchboard no longer reads it.

6. **Clear the command cache.** So a cached command manifest doesn't reference the
   removed core `archive:manage` command:

   ```bash
   php glueful cache:clear
   ```

7. **Update imports.** Any application or extension code referencing the archive
   classes must update its namespace:

   ```
   Glueful\Services\Archive\*  →  Glueful\Extensions\Archive\*
   ```

## CDN / edge cache extracted to `glueful/cdn`

The edge-cache / CDN integration was removed from core and now lives in the
`glueful/cdn` extension. Core retains only the seam: the
`Glueful\Cache\Contracts\EdgeCacheInterface` contract and its no-op default
`Glueful\Cache\NullEdgeCache`. Response caching (`ResponseCachingTrait`) runs
against that interface, so it keeps working with or without the extension.

**What was removed from core:**

```
Glueful\Cache\EdgeCacheService                 →  Glueful\Extensions\Cdn\EdgeCachePurger
Glueful\Cache\CDN\CDNAdapterInterface          →  Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface
Glueful\Cache\CDN\AbstractCDNAdapter           →  Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter
Glueful\Console\Commands\Cache\PurgeCommand    →  (the cache:purge command, now shipped by the extension)
Glueful\Helpers\CDNAdapterManager              →  deleted (dead trait, no replacement)
```

The `EdgeCacheService` container binding and the `cache.edge` config block were
also removed.

### Behavior without the extension installed

These two are distinct — do not read them as "the CLI returns false":

- **CLI:** `php glueful cache:purge` is **absent** — it is not registered at all,
  so invoking it is a *command-not-found* error (not a command that runs and
  returns `false`).
- **Programmatic:** resolving `Glueful\Cache\Contracts\EdgeCacheInterface` from
  the container yields `Glueful\Cache\NullEdgeCache`. `isEnabled()` returns
  `false`, `getProvider()` returns `null`, `generateCacheHeaders()` returns `[]`
  (response caching still emits its surrogate keys), and all purge calls return
  `false`. Nothing throws.

### Enabling edge caching again

1. Install the extension:

   ```bash
   composer require glueful/cdn
   ```

   It is auto-discovered via `extra.glueful`; its `CdnServiceProvider` rebinds
   `EdgeCacheInterface` to `EdgeCachePurger` and registers the `cache:purge`
   command.

2. Configure a provider in the extension's `cdn` config:

   ```php
   // config/cdn.php (published by the extension)
   'provider' => env('EDGE_CACHE_PROVIDER', 'cloudflare'),
   ```

   and ensure the chosen provider name is mapped to its adapter class in
   `cdn.adapters` (name → class). Third-party adapters register the same way —
   by merging their `name => AdapterClass::class` entry into `cdn.adapters`.

3. Move any local config overrides. If you overrode the old core `cache.edge`
   block, move those settings to the extension's `cdn` config key. The
   `EDGE_CACHE_*` environment variables are now read only by the extension.

4. **Clear the command cache** so a cached command manifest doesn't reference the
   removed core `cache:purge` command (and the compiled container doesn't carry a
   stale `EdgeCacheService` binding):

   ```bash
   php glueful cache:clear
   ```
