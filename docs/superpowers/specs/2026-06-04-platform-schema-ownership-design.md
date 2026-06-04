# Platform Schema Ownership — Design Note (follow-up to the Users extraction)

**Status:** Proposed / deferred. Spun out of the Users-extension extraction (`2026-06-04-users-extension-extraction-design.md`, §8 deferral) and a review of the api-skeleton's remaining migrations.

**Date:** 2026-06-04

## Problem

After the Users extraction, the api-skeleton still ships migrations for six tables that back **framework-core subsystems** — the same code-owns-schema mismatch we removed for auth:

| Skeleton migration | Tables | Owning code (framework core) | Optional? |
|---|---|---|---|
| `001_CreateInitialSchema` | `blobs` | `src/Uploader` + `src/Storage` | common; storage is driver-abstracted |
| `007_CreateLocksTable` | `locks` | `src/Lock` | core infra — effectively always-on |
| `006_CreateQueueSystemTables` | `queue_jobs`, `queue_failed_jobs`, `queue_batches` | `src/Queue` (driver: **database**\|redis\|sync) | **DB tables only needed for the database driver** |
| `003_CreateScheduledJobsTables` | `scheduled_jobs`, `job_executions` | `src/Scheduler` | optional feature |
| `004_CreateNotificationSystemTables` | `notifications`, `notification_deliveries`, `notification_preferences`, `notification_templates` | `src/Notifications` | optional feature |
| `005_CreateArchiveSystemTables` | `archive_registry`, `archive_search_index`, `archive_table_stats` | `src/Services/Archive` | advanced/optional |

By the ownership principle (*a table's migration belongs to the package whose code reads/writes it*), none of these belong to the skeleton. Unlike auth, several are **optional, driver-gated features** (e.g. `queue_*` is dead schema under the redis/sync queue driver), so a blanket "move to core foundation" would create unused tables in many apps.

## Options considered

- **A — All → framework core foundation migrations** (move files like auth). Consistent ownership, trivial effort; but every app gets queue/archive/notification tables even when unused → schema bloat.
- **B — Capability packages** (`glueful/storage`, `glueful/queue`, `glueful/scheduler`, `glueful/notifications`, `glueful/archive`), each owning code **and** schema, enabled per app. Cleanest, opt-in, leanest core; but ~5 more extractions like the Users one, and these subsystems are arguably "core framework," so extracting their *code* is more debatable than the user store. Large multi-phase effort — would get its own decomposition spec.
- **C — Keep in skeleton** (status quo, §8 deferral). Zero work; ownership smell remains and any non-skeleton consumer re-ships them.
- **D — Core-owned, config-gated migrations (recommended).** Move the migrations into framework core (the code is already there), and have the `MigrationManager` container factory register each subsystem's migration path **conditionally on config**:
  - `locks`, `blobs` → always (core infra). *(Open question: gate `blobs` on a storage/DB flag?)*
  - `queue_*` → only when `config('queue.default') === 'database'`.
  - `scheduled_jobs`/`job_executions` → only when the scheduler is enabled.
  - `notifications*` → only when a DB notification channel is configured.
  - `archive_*` → only when archive is enabled.

  Applies ownership correctly for all six, avoids bloat (tables exist only for enabled DB-backed features), and needs **no code extraction** — it extends the `CoreProvider` `FactoryDefinition` → `addMigrationPath` pattern already built for auth (organize `framework/migrations/` into per-subsystem subdirs, add each conditionally; all at `FOUNDATION`, source `glueful/framework`). The skeleton then drops these migrations like it dropped the auth ones.

## Recommendation

**D** is the principled-yet-pragmatic fix; **B** is the long-term north star but a separate, larger project; **C** only as a deliberate "later." If pursued, decide the `blobs` gating (always vs storage-flag) and confirm each subsystem has a clean "is this DB-backed / enabled?" config signal before wiring the conditional registration.

## Notes / non-goals here

- Not started inline — captured as a follow-up so it doesn't expand the Users-extraction scope mid-flight.
- Config-gated migrations have a known wrinkle: enabling a feature later leaves its migration *pending* until the next `migrate:run` (this is desirable, but should be documented). `migrate:status` will only show tables for enabled features.
- The §2 referential-integrity policy still applies: any actor/principal reference in these tables stays an indexed UUID with no cross-package FK.
