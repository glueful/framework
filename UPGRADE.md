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

## Queue ops (supervised fleets / autoscaling / metrics) extracted to `glueful/queue-ops`

Supervised worker fleets, autoscaling, and worker/job metrics were removed from
core and now live in the **`glueful/queue-ops`** extension. Core keeps the lean
worker and the monitoring seam: `Glueful\Queue\Contracts\WorkerMonitorInterface`
and its no-op default `Glueful\Queue\Monitoring\NullWorkerMonitor` (bound by
default), so `queue:work` and `QueueMaintenance` keep working with or without the
extension.

> **Part 1 of a staged breaking series.** This change removes the **commands and
> classes**. The queue-ops **config keys** relocate in a **follow-up commit** (see
> the note at the end) — reviewers reading commits individually see the config
> move as a deliberate second step.

### What changed

- **`php glueful queue:work` is now a single lean worker.** The old `queue:work`
  sub-actions — `work` (multi/manager mode), `spawn`, `scale`, `status`, `stop`,
  `restart`, and `health` — are **removed**. The lean command declares no
  positional `action` argument, so plain `queue:work` runs one worker and passing
  an action (e.g. `queue:work spawn`) is a console "too many arguments" error.
- **`queue:autoscale` is removed.** It is no longer registered or discoverable.
- **No stub.** Invoking a removed sub-action or `queue:autoscale` is a generic
  command-not-found / unknown-argument error — there is **no** placeholder command
  printing an actionable "install the extension" message.
- **Deleted core classes** (moved to the extension):

  ```
  Glueful\Queue\Monitoring\WorkerMonitor        →  Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor
  Glueful\Queue\Process\ProcessManager          →  Glueful\Extensions\QueueOps\Process\ProcessManager
  Glueful\Queue\Process\ProcessFactory          →  Glueful\Extensions\QueueOps\Process\ProcessFactory
  Glueful\Queue\Process\WorkerProcess           →  Glueful\Extensions\QueueOps\Process\WorkerProcess
  Glueful\Queue\Process\AutoScaler              →  Glueful\Extensions\QueueOps\Process\AutoScaler
  Glueful\Queue\Process\ScheduledScaler         →  Glueful\Extensions\QueueOps\Process\ScheduledScaler
  Glueful\Queue\Process\ResourceMonitor         →  Glueful\Extensions\QueueOps\Process\ResourceMonitor
  Glueful\Queue\Process\StreamingMonitor        →  Glueful\Extensions\QueueOps\Process\StreamingMonitor
  Glueful\Console\Commands\Queue\AutoScaleCommand  →  (the queue:autoscale command, now shipped by the extension)
  ```

  Core retains `Glueful\Queue\Monitoring\NullWorkerMonitor` and
  `Glueful\Queue\Contracts\WorkerMonitorInterface` (bound to the null monitor).

### Restoring supervised fleets / autoscaling / metrics

1. Install the extension:

   ```bash
   composer require glueful/queue-ops
   ```

   It is auto-discovered via `extra.glueful` and restores `queue:supervise`
   (supervisor + leaf workers) and `queue:autoscale`.

2. **Clear the command cache** so a cached command manifest doesn't reference the
   removed core `queue:autoscale` command:

   ```bash
   php glueful cache:clear
   ```

### Additive (already shipped this cycle — no action needed)

- New `php glueful queue:work --once` (drain and exit) and `--connection=` (target
  a named queue connection, defaults to `config('queue.default')`) flags.
- `WorkerOptions` `max-jobs` / `max-runtime` now treat `0` as **unlimited**.
- `ServeCommand` still shells `queue:work --sleep=3`; that is now one lean worker
  (previously it was implicitly supervised) — no action required.

### Staged config relocation (follow-up commit)

The queue-ops **config keys** are **not** moved in this commit. In a follow-up,
`queue.workers.{process,auto_scaling,resource_limits,resource_thresholds,supervisor}`
and the per-queue `workers` / `max_workers` / `auto_scale` settings relocate to
the extension's `queue_ops.*` config. Until then, those keys remain in
`config/queue.php` but are inert on core (nothing reads them once the ops classes
are gone).
