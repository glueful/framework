# Platform Schema Ownership — Design Note (follow-up to the Users extraction)

> **Superseded (archive): see docs/superpowers/specs/2026-06-06-extract-archive-design.md** — archive is now the glueful/archive extension, not a core/skeleton system table.

**Status:** Proposed / deferred. Spun out of the Users-extension extraction (`2026-06-04-users-extension-extraction-design.md`, §8 deferral) and a review of the api-skeleton's remaining migrations.

**Date:** 2026-06-04

## Problem

After the Users extraction, the api-skeleton still ships migrations for six tables that back **framework-core subsystems** — the same code-owns-schema mismatch we removed for auth:

| Skeleton migration | Tables | Owning code (framework core) | Optional? |
|---|---|---|---|
| `001_CreateInitialSchema` | `blobs` | `src/Uploader` + `src/Storage` | common; storage is driver-abstracted |
| `007_CreateLocksTable` | `locks` | `src/Lock` (driver: file\|redis\|**database**) | DB table only needed for the database lock driver; default is `file` |
| `006_CreateQueueSystemTables` | `queue_jobs`, `queue_failed_jobs`, `queue_batches` | `src/Queue` (driver: **database**\|redis\|sync) | **DB tables only needed for the database driver** |
| `003_CreateScheduledJobsTables` | `scheduled_jobs`, `job_executions` | `src/Scheduler` | optional feature |
| `004_CreateNotificationSystemTables` | `notifications`, `notification_deliveries`, `notification_preferences`, `notification_templates` | `src/Notifications` | optional feature |
| `005_CreateArchiveSystemTables` | `archive_registry`, `archive_search_index`, `archive_table_stats` | `src/Services/Archive` | advanced/optional |

By the ownership principle (*a table's migration belongs to the package whose code reads/writes it*), none of these belong to the skeleton. Unlike auth, several are **optional, driver-gated features** (e.g. `queue_*` is dead schema under the redis/sync queue driver), so a blanket "move to core foundation" would create unused tables in many apps.

## Options considered

- **A — All → framework core foundation migrations** (move files like auth). Consistent ownership, trivial effort; but every app gets queue/archive/notification tables even when unused → schema bloat.
- **B — Capability packages** (`glueful/storage`, `glueful/queue`, `glueful/scheduler`, `glueful/notifications`, `glueful/archive`), each owning code **and** schema, enabled per app. Cleanest, opt-in, leanest core; but ~5 more extractions like the Users one, and these subsystems are arguably "core framework," so extracting their *code* is more debatable than the user store. Large multi-phase effort — would get its own decomposition spec.
- **C — Keep in skeleton** (status quo, §8 deferral). Zero work; ownership smell remains and any non-skeleton consumer re-ships them.
- **D — Core-owned *capability* migrations, registered conditionally, with distinct sources (recommended).** Move the migrations into framework core (the code is already there), organize `framework/migrations/` into per-capability subdirs, and have the `MigrationManager` container factory register each subdir **only when that capability is DB-backed and enabled**, each under its **own source name**:

  | Capability | Subdir source | Gate (register only when…) |
  |---|---|---|
  | locks | `glueful/framework:locks` | `config('lock.default') === 'database'` *(default is `file` — do NOT register by default)* |
  | uploads/blobs | `glueful/framework:uploads` | `config('uploads.enabled') === true` (or an explicit `uploads.database_metadata`) — blobs is DB metadata for uploads, not the storage *disk*, so don't tie it to the disk driver |
  | queue | `glueful/framework:queue` | `config('queue.default') === 'database'` |
  | scheduler | `glueful/framework:scheduler` | scheduler enabled |
  | notifications | `glueful/framework:notifications` | a DB notification channel/persistence is configured |
  | archive | `glueful/framework:archive` | archive enabled |

  Distinct source names (not one blanket `glueful/framework`) keep source-tracking meaningful and avoid `(source, migration)` collisions when each subdir ships its own `001_…`. Applies ownership correctly for all six, avoids bloat (tables exist only for enabled DB-backed capabilities), and needs **no code extraction** — it extends the `CoreProvider` `FactoryDefinition` → `addMigrationPath` pattern already built for auth (auth itself stays under the plain `glueful/framework` source, always-on). The skeleton then drops these migrations like it dropped the auth ones.

  > **Driver-awareness matters:** `lock.default` defaults to `file` and the storage disk defaults to `local`, so "locks/blobs are always-on" is wrong — both must be gated. Auth (`auth_sessions`/`auth_refresh_tokens`/`api_keys`) remains the only unconditional, always-registered core schema.

## Recommendation

**D** is the principled-yet-pragmatic fix; **B** is the long-term north star but a separate, larger project; **C** only as a deliberate "later." The strongest version of D is:

1. **Core-owned capability migrations** (code stays in core; schema moves out of the skeleton),
2. **registered conditionally** on each capability's DB-backed/enabled config signal,
3. with **distinct source names** per capability (`glueful/framework:<capability>`),
4. and **no runtime table creation** — except possibly an explicit dev/test fallback.

Before wiring it, confirm each subsystem exposes a clean "is this DB-backed / enabled?" config signal (some may need a new flag, e.g. `uploads.database_metadata`).

## Implementation notes (for the plan)

- **Remove/demote runtime DDL.** Several subsystems create their tables lazily today — first-class migrations must not compete with hidden runtime DDL. The plan must remove or demote these to a dev/test-only fallback (guarded, never in production):
  - `Scheduler\JobScheduler::ensureTablesExist()` (called from the constructor),
  - `Queue\Drivers\DatabaseQueue` (lazy `hasTable(...)` → `createTable(...)` for the jobs + failed-jobs tables),
  - `Notifications\Services\NotificationRetryService` (table-ensure path),
  - and any similar `ensure*Table*()` in the migrated capabilities (grep before implementing).
- **Deterministic order needs a `source` tiebreaker.** The current pending-migration sort is `(priority ASC, basename ASC)` (`MigrationManager::getPendingMigrations()`), with no `source` in the key. With multiple enabled capability sources each shipping `001_…`, two entries can tie on `(priority, basename)` and sort non-deterministically. Either change the sort to **`(priority ASC, basename ASC, source ASC)`** or **enforce globally unique migration filenames** across core capability dirs. (Source-tracking already prevents *duplicate application*; this is purely about *deterministic ordering*.)

## Notes / non-goals here

- Not started inline — captured as a follow-up so it doesn't expand the Users-extraction scope mid-flight.
- Config-gated migrations have a known wrinkle: enabling a feature later leaves its migration *pending* until the next `migrate:run` (this is desirable, but should be documented). `migrate:status` will only show tables for enabled features.
- The §2 referential-integrity policy still applies: any actor/principal reference in these tables stays an indexed UUID with no cross-package FK.
