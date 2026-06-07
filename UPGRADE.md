# Upgrade Notes

## Command cache (production) — required whenever a command is removed

Several upgrades below **remove** console commands from core — `archive:manage`
(Archive), `cache:purge` (CDN), and `queue:autoscale` (Queue ops). In production
Glueful caches its discovered command list to
`storage/cache/glueful_commands_manifest.php`; a manifest generated *before* the
upgrade still references the removed class and **breaks CLI boot**, e.g.:

```
Class "Glueful\Console\Commands\Queue\AutoScaleCommand" does not exist
```

Once that happens every `php glueful …` call fails — including the cache commands
themselves. Refresh the **command** manifest as part of any deploy that removes (or
adds) a command:

```bash
# Before deploying: clear the cached manifest so the next (post-deploy) boot
# regenerates it without the removed command.
php glueful commands:cache --clear
```

If the upgrade is **already deployed** and the CLI is erroring on a removed command
class, the broken CLI can't clear its own cache — delete the manifest by hand, then
regenerate:

```bash
rm -f storage/cache/glueful_commands_manifest.php
php glueful commands:cache
```

> `php glueful cache:clear` (the general application cache) does **not** clear the
> command manifest — use `commands:cache` for that. `cache:clear` remains useful for
> *other* caches (e.g. dropping a compiled-container binding for a removed service,
> as noted in the CDN section).

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

6. **Refresh the command manifest** so a cached manifest doesn't reference the
   removed core `archive:manage` command (see [Command cache (production)](#command-cache-production--required-whenever-a-command-is-removed)
   — `cache:clear` does **not** clear it):

   ```bash
   php glueful commands:cache --clear
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

4. **Refresh the command manifest and clear the application cache.** The command
   manifest must be regenerated so it doesn't reference the removed core
   `cache:purge` command (see [Command cache (production)](#command-cache-production--required-whenever-a-command-is-removed)),
   and the general cache cleared so the compiled container doesn't carry a stale
   `EdgeCacheService` binding:

   ```bash
   php glueful commands:cache --clear   # command manifest (cache:clear does NOT cover this)
   php glueful cache:clear              # application cache / compiled-container binding
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

> **Deploying this removal breaks CLI boot if the production command manifest is
> stale** (it still references the deleted `AutoScaleCommand`). Refresh it — see
> [Command cache (production)](#command-cache-production--required-whenever-a-command-is-removed)
> at the top of this file.

### Restoring supervised fleets / autoscaling / metrics

1. Install the extension:

   ```bash
   composer require glueful/queue-ops
   ```

   It is auto-discovered via `extra.glueful` and restores `queue:supervise`
   (supervisor + leaf workers) and `queue:autoscale`.

2. **Refresh the command manifest** so it picks up the extension's `queue:supervise`
   / `queue:autoscale` commands (see [Command cache (production)](#command-cache-production--required-whenever-a-command-is-removed)):

   ```bash
   php glueful commands:cache --clear
   ```

### Additive (already shipped this cycle — no action needed)

- New `php glueful queue:work --once` (drain and exit) and `--connection=` (target
  a named queue connection, defaults to `config('queue.default')`) flags.
- `WorkerOptions` `max-jobs` / `max-runtime` now treat `0` as **unlimited**.
- `ServeCommand` still shells `queue:work --sleep=3`; that is now one lean worker
  (previously it was implicitly supervised) — no action required.

### Config relocation (this completes the staged series)

The queue-ops **config keys** have now moved out of core `config/queue.php` into
the extension's `queue_ops.*` config (provided via `config/queue_ops.php` +
`mergeConfig` when `glueful/queue-ops` is installed). This is **part 2 (final)** of
the staged breaking series — commands were removed in part 1, config relocates here.

**Moved to the extension (`queue_ops.*`):**

- `queue.workers.process`            → `queue_ops.process`
- `queue.workers.auto_scaling`       → `queue_ops.auto_scaling`
- `queue.workers.resource_limits`    → `queue_ops.resource_limits`
- `queue.workers.resource_thresholds`→ `queue_ops.resource_thresholds`
- `queue.workers.supervisor`         → `queue_ops.supervisor`
- per-queue `queue.workers.queues.<name>.{workers, max_workers, auto_scale}`
  → `queue_ops.queues.<name>.{workers, max_workers, auto_scale}`

**Stays in core `config/queue.php`:**

- per-queue `priority` / `memory_limit` / `timeout` / `max_jobs`
  (`queue.workers.queues.<name>.*`)
- `queue.workers.performance.*` (read by the lean `QueueWorker`)
- `queue.monitoring.*`

**Env vars are unchanged.** The same variables (`QUEUE_PROCESS_ENABLED`,
`QUEUE_AUTO_SCALING`, `QUEUE_SCALE_*`, `QUEUE_WORKER_*`, `QUEUE_MEMORY_*` / `_CPU_` /
`_DISK_` / `_LOAD_*`, `QUEUE_SUPERVISOR_*`, and the per-queue `*_QUEUE_WORKERS` /
`*_QUEUE_MAX_WORKERS` / `*_QUEUE_AUTO_SCALE`) now feed `queue_ops.*`, read by
`glueful/queue-ops`. **No `.env` changes are required.**

**If you published/overrode `config/queue.php`:** move the moved blocks above into
a `config/queue_ops.php` override (the extension merges its defaults under
`queue_ops`, and your app config file wins). Leave the kept keys (per-queue
`priority`/`memory_limit`/`timeout`/`max_jobs`, `workers.performance`, `monitoring`)
in `config/queue.php`. Apps that never overrode these keys need to do nothing
beyond installing the extension — the defaults ship with it.

## Rich media → `glueful/media`

Image processing, thumbnail generation, and media metadata extraction were
removed from core and now live in the **`glueful/media`** extension, along with
the two heavy dependencies they pulled in (`intervention/image` and
`james-heinrich/getid3`). The upload pipeline itself stays in core: `FileUploader`,
the `Glueful\Uploader\Contracts\MediaProcessorInterface` seam, and the core
`Glueful\Uploader\MediaMetadata` value object are all retained. Without the
extension, uploads still work — they simply produce no thumbnails and only
type-level metadata.

**What was removed from core:**

```
Glueful\Services\ImageProcessor            →  Glueful\Extensions\Media\ImageProcessor
Glueful\Services\ImageProcessorInterface   →  Glueful\Extensions\Media\Contracts\ImageProcessorInterface
Glueful\Uploader\ThumbnailGenerator        →  Glueful\Extensions\Media\ThumbnailGenerator
Glueful\Uploader\MediaMetadataExtractor    →  Glueful\Extensions\Media\MediaMetadataExtractor
```

`Glueful\Uploader\MediaMetadata` is **unchanged and stays in core**. The
`FileUploader::getThumbnailGenerator()` / `getMetadataExtractor()` accessors were
removed (they leaked the moved types). The `image()` global helper and
`config/image.php` were also removed from core.

### Behavior without the extension installed

- **`image()` helper:** **undefined**. The helper is now registered by the
  extension; on a plain core install, calling `image(...)` is a *function-not-found*
  error (not a stub that returns null).
- **`uploadMedia()`:** still succeeds, but returns `thumb_url: null` and a
  **type-only** `MediaMetadata` (MIME/type classification only — no dimensions,
  duration, or codec details, which require getID3).
- **Blob-resize endpoint:** serves the **original** image unmodified for resize
  requests. It returns **`415`** only when an explicit **format conversion** is
  requested (there is no processor to convert with).

### Config — what moves, what stays

- **Moves to the extension:** the `IMAGE_*` environment variables and
  `config/image.php` (driver, processing limits, quality, security, caching) are
  now **extension-owned** and published by `glueful/media`.
- **Stays in core (but inert without the extension):** the
  `UPLOADS_IMAGE_PROCESSING` / `UPLOADS_THUMBNAILS` keys in `config/uploads.php`
  and the `uploader.thumbnail_*` keys in `config/filesystem.php` remain in core.
  They are **media-gated no-ops** — read by the upload pipeline but with no effect
  until `glueful/media` binds the `MediaProcessorInterface` seam.

### Restoring image processing / thumbnails / metadata

1. Install the extension:

   ```bash
   composer require glueful/media
   ```

   It is auto-discovered via `extra.glueful`; its service provider binds the
   `MediaProcessorInterface` seam, re-registers the `ImageProcessorInterface`
   graph, and re-defines the `image()` helper.

2. Move any local `config/image.php` overrides and `IMAGE_*` env vars — they are
   now read by the extension. The core `UPLOADS_*` / `THUMBNAIL_*` keys can stay
   where they are; they take effect again once the extension is present.

No command cache refresh is needed: this extraction removes **no** console
command.
