# Platform Schema Ownership Implementation Plan

> **Superseded (archive): see docs/superpowers/specs/2026-06-06-extract-archive-design.md** — archive is now the glueful/archive extension, not a core/skeleton system table.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the schema for six framework-core subsystems (locks, uploads/blobs, queue, scheduler, notifications, archive) out of the api-skeleton into **core-owned capability migrations**, each registered **conditionally on config** under its **own source name**, and remove the competing runtime DDL — so the package that owns the code owns the (versioned, source-tracked) schema.

**Architecture:** Extends the auth pattern from the Users extraction. `framework/migrations/` gains one subdir per capability. The `CoreProvider` `MigrationManager` factory registers each subdir only when that capability is DB-backed/enabled, under source `glueful/framework:<capability>`. Auth stays unconditional under `glueful/framework`. The pending-migration sort gains a `source` tiebreaker. Lazy `ensure*Table*()` paths are removed (or demoted to a guarded dev/test fallback).

**Tech Stack:** PHP 8.3, `Glueful\Database\Migrations\{MigrationManager,MigrationPriority}`, `Glueful\Container\Definition\FactoryDefinition`, `Glueful\Database\Schema\Interfaces\SchemaBuilderInterface`, PHPUnit over file-based SQLite.

**Spec:** [`../specs/2026-06-04-platform-schema-ownership-design.md`](../specs/2026-06-04-platform-schema-ownership-design.md) (option D).

**Depends on:** Users extraction (Phases 1–5) merged — core foundation migrations + the `CoreProvider` `MigrationManager` factory must already exist.

**Repos:** `framework` (`/Users/michaeltawiahsowah/Sites/glueful/framework`), `api-skeleton` (`/Users/michaeltawiahsowah/Sites/glueful/api-skeleton`).

---

## Gating decisions (settled — no placeholders)

| Capability | Subdir | Source | Gate (register iff…) | Default | Runtime DDL to remove |
|---|---|---|---|---|---|
| auth *(existing — moved into a subdir, see Task 2)* | `framework/migrations/auth/` | `glueful/framework` | always | on | — |
| locks | `framework/migrations/locks/` | `glueful/framework:locks` | `config('lock.default') === 'database'` | off (`file`) | — (none) |
| uploads/blobs | `framework/migrations/uploads/` | `glueful/framework:uploads` | `config('uploads.enabled', true) === true` | on | — (none) |
| queue | `framework/migrations/queue/` | `glueful/framework:queue` | `config('queue.default') === 'database'` | on | `DatabaseQueue::ensureQueueTables()` |
| scheduler | `framework/migrations/scheduler/` | `glueful/framework:scheduler` | `config('schedule.database_store', true) === true` *(new flag)* | on | `JobScheduler::ensureTablesExist()` |
| notifications | `framework/migrations/notifications/` | `glueful/framework:notifications` | `config('notifications.database_store', true) === true` *(new config file)* | on | `NotificationRetryService::ensureRetryQueueTableExists()` + `initDatabase()` |
| archive | `framework/migrations/archive/` | `glueful/framework:archive` | `config('archive.database_schema', false) === true` *(new flag)* | off (opt-in) | — (none) |

All capability migrations use `MigrationPriority::FOUNDATION` (same tier as auth) and namespace `Glueful\Migrations\<Capability>` (e.g. `Glueful\Migrations\Queue`). They are NOT autoloaded (the runner requires them); the namespace only needs to be unique per file.

> **Note — `notification_retry_queue` has no migration today.** It is created only by `NotificationRetryService` at runtime. This plan adds a first-class migration for it alongside the other notification tables.

> **⚠ Load-bearing: `FileFinder::findMigrations()` is RECURSIVE.** It does `Finder->in($dir)->files()` with no depth limit, so registering the parent `framework/migrations/` would discover **every capability subdir's** files and record them under the wrong source (`glueful/framework`), bypassing all gates. Therefore the factory must register **only explicit leaf subdirs, never the parent** — and auth moves into its own `framework/migrations/auth/` subdir (Task 2). (Recursion *within* a leaf capability dir is harmless — they have no nested dirs.)

> **Testing approach for gated registration.** Every gate is **env-backed** (`LOCK_DRIVER`, `QUEUE_CONNECTION`, `UPLOADS_ENABLED`, `SCHEDULE_DATABASE_STORE`, `NOTIFICATIONS_DATABASE_STORE`, `ARCHIVE_DATABASE_SCHEMA`) — the framework's default `config/*.php` read them via `env()` at **boot**. So a gated test must set the env var (`putenv()` + `$_ENV`/`$_SERVER`) **before the app boots**, then resolve the container `MigrationManager`. Because `MigrationTestCase` auto-boots in `setUp()`, gated tests must either (a) set env in their **own `setUp()` before `parent::setUp()`** (one gate-state per test class), or (b) set env in the method then `refreshApplication()` (re-boot). Setting env *after* boot has no effect — config is already resolved. Each capability task's test step names the env var; assert via the container manager's `allSources()` / `getPendingMigrations()` and the resulting tables.

---

## File structure

**Create (framework):**
- `framework/migrations/locks/001_CreateLocksTable.php`
- `framework/migrations/uploads/001_CreateBlobsTable.php`
- `framework/migrations/queue/001_CreateQueueSystemTables.php`
- `framework/migrations/scheduler/001_CreateScheduledJobsTables.php`
- `framework/migrations/notifications/001_CreateNotificationSystemTables.php` (4 existing tables) + `002_CreateNotificationRetryQueueTable.php`
- `framework/migrations/archive/001_CreateArchiveSystemTables.php`
- `framework/config/notifications.php` (new — holds `database_store`)
- `tests/Integration/Database/Migrations/CapabilityMigrationsTest.php`
- `tests/Unit/Database/Migrations/PendingSortTiebreakerTest.php`

**Move (framework):**
- `framework/migrations/00{1,2,3}_*.php` (auth) → `framework/migrations/auth/00{1,2,3}_*.php` (Task 2). Source + basename are unchanged, so the `migrations` applied-tracking still matches on already-migrated DBs — no re-application.

**Modify (framework):**
- `src/Database/Migrations/MigrationManager.php` — add `source` tiebreaker to the pending sort.
- `src/Container/Providers/CoreProvider.php` — generalize the `MigrationManager` factory to register **explicit leaf subdirs only** (auth + gated capabilities), never the parent `migrations/`.
- `config/schedule.php` — add `'database_store' => env('SCHEDULE_DATABASE_STORE', true)`.
- `config/archive.php` — add `'database_schema' => env('ARCHIVE_DATABASE_SCHEMA', false)`.
- `src/Queue/Drivers/DatabaseQueue.php`, `src/Scheduler/JobScheduler.php`, `src/Notifications/Services/NotificationRetryService.php` — remove/demote runtime DDL.
- `CHANGELOG.md`.

**Modify (api-skeleton):**
- Delete `database/migrations/001_CreateInitialSchema.php`, `003_CreateScheduledJobsTables.php`, `004_CreateNotificationSystemTables.php`, `005_CreateArchiveSystemTables.php`, `006_CreateQueueSystemTables.php`, `007_CreateLocksTable.php`; keep `database/migrations/` (with `.gitkeep`) for app migrations.
- `tests/Feature/` migrate-clean assertion updated.

---

## Task 1: Add a `source` tiebreaker to the pending-migration sort

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` (the `usort` in `getPendingMigrations()`, ~line 256)
- Test: `tests/Unit/Database/Migrations/PendingSortTiebreakerTest.php`

- [ ] **Step 1: Write the failing test.** Two candidates with the same priority and basename but different sources must order deterministically by source.

```php
<?php
declare(strict_types=1);
namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class PendingSortTiebreakerTest extends MigrationTestCase
{
    public function test_same_basename_different_sources_sort_by_source(): void
    {
        $a = $this->tempMigrationsDir() . '/a'; $b = $this->tempMigrationsDir() . '/b';
        $this->writeFixture($a, '001_create_tables.php', 'cap_b_table'); // dir 'a' but source 'zsrc'
        $this->writeFixture($b, '001_create_tables.php', 'cap_a_table'); // dir 'b' but source 'asrc'

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($a, \Glueful\Database\Migrations\MigrationPriority::FOUNDATION, 'zsrc');
        $mm->addMigrationPath($b, \Glueful\Database\Migrations\MigrationPriority::FOUNDATION, 'asrc');

        $pending = $mm->getPendingMigrations(); // full paths
        // 'asrc' must come before 'zsrc' on the (priority, basename, source) key.
        self::assertStringContainsString('/b/001_create_tables.php', $pending[0]);
        self::assertStringContainsString('/a/001_create_tables.php', $pending[1]);
    }
}
```

- [ ] **Step 2: Run → fail.** `vendor/bin/phpunit tests/Unit/Database/Migrations/PendingSortTiebreakerTest.php` — order is currently non-deterministic / dir-insertion-ordered.

- [ ] **Step 3: Implement.** In `getPendingMigrations()`, the candidates already carry `source`. Extend the comparator key:

```php
// before: return [$a['priority'], basename($a['file'])] <=> [$b['priority'], basename($b['file'])];
return [$a['priority'], basename($a['file']), $a['source']]
    <=> [$b['priority'], basename($b['file']), $b['source']];
```

Update the adjacent comment to `// (priority ASC, basename ASC, source ASC)`.

- [ ] **Step 4: Run → pass.** `vendor/bin/phpunit tests/Unit/Database/Migrations/PendingSortTiebreakerTest.php`

- [ ] **Step 5: Regression.** `vendor/bin/phpunit tests/Integration/Database/Migrations/` (ordering/sources/core tests still green).

- [ ] **Step 6: Commit.** `git commit -am "fix(db): deterministic pending-migration order via (priority, basename, source) tiebreaker"`

---

## Task 2: Move auth into a subdir + generalize the factory (explicit subdirs only)

**Why a subdir for auth:** `findMigrations()` recurses, so the factory must never register the parent `migrations/` (it would slurp the capability subdirs under the wrong source, bypassing gates). Every registered path must be an explicit leaf dir — so auth gets its own `migrations/auth/`.

**Files:**
- Move: `framework/migrations/00{1,2,3}_*.php` → `framework/migrations/auth/`
- Modify: `src/Container/Providers/CoreProvider.php` (the `MigrationManager` `FactoryDefinition`)
- Test: `tests/Integration/Database/Migrations/CapabilityMigrationsTest.php`

- [ ] **Step 1: Move auth files.** `git mv migrations/001_CreateAuthSessionsTable.php migrations/auth/001_CreateAuthSessionsTable.php` (and `002`, `003`). Basenames + the `glueful/framework` source are unchanged, so applied-tracking on existing DBs still matches (no re-run). No code edits to the files.

- [ ] **Step 2: Write the failing test.** Assert the **path** registered for `glueful/framework`, not just that the source exists — the old factory already exposes `glueful/framework` (pointing at the parent `migrations/`), so a source-only check would pass before implementation. The test must require that source's path to be the `auth/` subdir:

```php
public function test_factory_registers_auth_from_its_subdir_not_the_parent(): void
{
    $mm = $this->app()->getContainer()->get(\Glueful\Database\Migrations\MigrationManager::class);
    $ref = new \ReflectionMethod($mm, 'allSources'); $ref->setAccessible(true);

    $bySource = [];
    foreach ($ref->invoke($mm) as $entry) {
        $bySource[$entry['source']] = $entry['path'];
    }

    // FAILS before implementation: the old factory registers glueful/framework at the PARENT
    // migrations/ dir; it must register the auth/ leaf subdir (parent recurses into capabilities).
    self::assertArrayHasKey('glueful/framework', $bySource);
    self::assertStringEndsWith('/migrations/auth', rtrim($bySource['glueful/framework'], '/'));

    // No capability sources yet (their dirs don't exist).
    self::assertSame([], array_values(array_filter(
        array_keys($bySource), fn($s) => str_starts_with($s, 'glueful/framework:')
    )));
}
```
(Per-capability registration is asserted in each capability task, which creates the dir and sets config.)

- [ ] **Step 3: Run → fail.** The `assertStringEndsWith('/migrations/auth', …)` fails because the old factory points `glueful/framework` at the parent `migrations/`.

- [ ] **Step 4: Implement.** Register explicit leaf subdirs only — never `$base`:

```php
function (): \Glueful\Database\Migrations\MigrationManager {
    $base = \dirname(__DIR__, 3) . '/migrations';
    $mm = new \Glueful\Database\Migrations\MigrationManager(null, null, $this->context);

    $cfg = fn(string $key, $default) =>
        \function_exists('config') ? config($this->context, $key, $default) : $default;

    // [subdir, source, enabled] — auth is unconditional; capabilities are config-gated.
    // NEVER register $base itself: findMigrations() recurses and would slurp every subdir.
    $dirs = [
        ['auth',          'glueful/framework',               true],
        ['locks',         'glueful/framework:locks',         $cfg('lock.default', 'file') === 'database'],
        ['uploads',       'glueful/framework:uploads',       (bool) $cfg('uploads.enabled', true)],
        ['queue',         'glueful/framework:queue',         $cfg('queue.default', 'sync') === 'database'],
        ['scheduler',     'glueful/framework:scheduler',     (bool) $cfg('schedule.database_store', true)],
        ['notifications', 'glueful/framework:notifications', (bool) $cfg('notifications.database_store', true)],
        ['archive',       'glueful/framework:archive',       (bool) $cfg('archive.database_schema', false)],
    ];
    foreach ($dirs as [$dir, $source, $enabled]) {
        if ($enabled) {
            // addMigrationPath() no-ops when the dir is absent (safe before a subdir is created).
            $mm->addMigrationPath($base . '/' . $dir, \Glueful\Database\Migrations\MigrationPriority::FOUNDATION, $source);
        }
    }
    return $mm;
}
```

- [ ] **Step 5: Run → pass.** Also re-run `tests/Integration/Database/Migrations/CoreMigrationsTest.php` — it asserts the auth basenames under `glueful/framework`, still valid after the move.

- [ ] **Step 6: Commit.** `git commit -am "feat(db): register core migrations as explicit leaf subdirs (auth/ + gated capabilities); recursion-safe"`

> Each capability task below: (a) create `framework/migrations/<cap>/001_*.php` (port the skeleton table definitions verbatim, change namespace to `Glueful\Migrations\<Cap>`), (b) add the gate flag/config if new, (c) remove the skeleton migration, (d) remove the runtime DDL, (e) add a `CapabilityMigrationsTest` case that **writes the gating config before boot** (see Testing approach) and asserts the table(s) exist + the source is recorded when on, and the source is absent when off, (f) commit. Do NOT change column definitions — verbatim move (the §2 no-FK policy already holds for these tables).

---

## Task 3: Locks capability

**Files:** create `framework/migrations/locks/001_CreateLocksTable.php`; delete `api-skeleton/database/migrations/007_CreateLocksTable.php`.

- [ ] **Step 1:** Create `framework/migrations/locks/001_CreateLocksTable.php` — copy `up()`/`down()` from the skeleton's `007`, namespace `Glueful\Migrations\Locks`, class `CreateLocksTable`, guard with `if ($schema->hasTable('locks')) return;`.
- [ ] **Step 2: Test** (add to `CapabilityMigrationsTest`): with `LOCK_DRIVER=database`, container manager migrate → `locks` exists and a `migrations` row has `source='glueful/framework:locks'`; with default (`file`), the `locks` source is **absent** from `getPendingMigrations()`.
- [ ] **Step 3:** Delete the skeleton migration `007_CreateLocksTable.php`.
- [ ] **Step 4: Commit** — framework: `"feat(db): locks capability migration (gated on lock.default=database)"`; skeleton change committed in Task 9.

---

## Task 4: Uploads / blobs capability

**Files:** create `framework/migrations/uploads/001_CreateBlobsTable.php`; the skeleton's `001_CreateInitialSchema.php` (blobs-only after Phase 5) is removed in Task 9.

- [ ] **Step 1:** Create `framework/migrations/uploads/001_CreateBlobsTable.php` — port the blobs table from the skeleton's `001` (namespace `Glueful\Migrations\Uploads`, class `CreateBlobsTable`, `hasTable` guard). `created_by` stays an indexed UUID, no FK (§2).
- [ ] **Step 2: Test:** with `uploads.enabled=true` (default), `blobs` exists, source `glueful/framework:uploads`; with `UPLOADS_ENABLED=false`, the uploads source is absent.
- [ ] **Step 3:** (skeleton `001` deletion → Task 9.)
- [ ] **Step 4: Commit** — `"feat(db): uploads/blobs capability migration (gated on uploads.enabled)"`

---

## Task 5: Queue capability + remove DatabaseQueue runtime DDL

**Files:** create `framework/migrations/queue/001_CreateQueueSystemTables.php`; modify `src/Queue/Drivers/DatabaseQueue.php`; delete skeleton `006`.

- [ ] **Step 1:** Create `framework/migrations/queue/001_CreateQueueSystemTables.php` — port `queue_jobs`/`queue_failed_jobs`/`queue_batches` from skeleton `006` (namespace `Glueful\Migrations\Queue`).
- [ ] **Step 2: Remove runtime DDL.** In `DatabaseQueue`, delete `ensureQueueTables()` and its constructor call. If a dev/test convenience is desired, gate any fallback behind `config('app.env') !== 'production'` AND a `hasTable` check, and log a deprecation — but the default path must assume migrations ran.
- [ ] **Step 3: Test:** with `QUEUE_CONNECTION=database` (default), the three queue tables exist, source `glueful/framework:queue`; with `QUEUE_CONNECTION=sync`, the queue source is absent. Add a guard test that constructing `DatabaseQueue` against a migrated DB does not attempt DDL.
- [ ] **Step 4:** Delete skeleton `006_CreateQueueSystemTables.php` (Task 9 commit).
- [ ] **Step 5: Commit** — `"feat(db): queue capability migration; drop DatabaseQueue runtime DDL"`

---

## Task 6: Scheduler capability + remove JobScheduler runtime DDL

**Files:** create `framework/migrations/scheduler/001_CreateScheduledJobsTables.php`; modify `config/schedule.php`, `src/Scheduler/JobScheduler.php`; delete skeleton `003`.

- [ ] **Step 1:** Add to `config/schedule.php` (top-level): `'database_store' => env('SCHEDULE_DATABASE_STORE', true),`.
- [ ] **Step 2:** Create `framework/migrations/scheduler/001_CreateScheduledJobsTables.php` — port `scheduled_jobs`/`job_executions` from skeleton `003` (namespace `Glueful\Migrations\Scheduler`).
- [ ] **Step 3: Remove runtime DDL.** In `JobScheduler`, delete `ensureTablesExist()` and the constructor call (or demote to a guarded non-prod fallback as in Task 5 Step 2).
- [ ] **Step 4: Test:** default → `scheduled_jobs`/`job_executions` exist, source `glueful/framework:scheduler`; with `SCHEDULE_DATABASE_STORE=false`, source absent. Guard test: constructing `JobScheduler` runs no DDL.
- [ ] **Step 5: Commit** — `"feat(db): scheduler capability migration; drop JobScheduler runtime DDL"`

---

## Task 7: Notifications capability (+ retry-queue migration) + remove runtime DDL

**Files:** create `framework/config/notifications.php`, `framework/migrations/notifications/001_CreateNotificationSystemTables.php`, `framework/migrations/notifications/002_CreateNotificationRetryQueueTable.php`; modify `src/Notifications/Services/NotificationRetryService.php`; delete skeleton `004`.

- [ ] **Step 1:** Create `framework/config/notifications.php` returning at least `['database_store' => env('NOTIFICATIONS_DATABASE_STORE', true)]`.
- [ ] **Step 2:** Create `001_CreateNotificationSystemTables.php` — port `notifications`/`notification_deliveries`/`notification_preferences`/`notification_templates` from skeleton `004` (namespace `Glueful\Migrations\Notifications`).
- [ ] **Step 3:** Create `002_CreateNotificationRetryQueueTable.php` for `notification_retry_queue` — lift the column definitions from `NotificationRetryService::ensureRetryQueueTableExists()` / `initDatabase()` into a real migration (this table had no migration before).
- [ ] **Step 4: Remove runtime DDL.** Delete `NotificationRetryService::ensureRetryQueueTableExists()` + the `initDatabase()` DDL path, and update **both** call sites that invoke it: `src/Console/Commands/Notifications/ProcessRetriesCommand.php:88` and `src/Tasks/NotificationRetryTask.php:82` (drop the calls — the table now comes from migration `002`). If a non-prod fallback is kept, guard it (`config('app.env') !== 'production'` + `hasTable`) and centralize it so both call sites stay clean.
- [ ] **Step 5: Test:** default → all 5 notification tables exist, source `glueful/framework:notifications`; with `NOTIFICATIONS_DATABASE_STORE=false`, source absent. Guard test: `NotificationRetryService` runs no DDL on construction.
- [ ] **Step 6: Commit** — `"feat(db): notifications capability migrations (incl. retry_queue); drop runtime DDL"`

---

## Task 8: Archive capability

**Files:** create `framework/migrations/archive/001_CreateArchiveSystemTables.php`; modify `config/archive.php`; delete skeleton `005`.

- [ ] **Step 1:** Add to `config/archive.php` (top-level): `'database_schema' => env('ARCHIVE_DATABASE_SCHEMA', false),` (archive is opt-in → default off, so unused apps get no archive tables).
- [ ] **Step 2:** Create `framework/migrations/archive/001_CreateArchiveSystemTables.php` — port `archive_registry`/`archive_search_index`/`archive_table_stats` from skeleton `005` (namespace `Glueful\Migrations\Archive`).
- [ ] **Step 3: Test:** with `ARCHIVE_DATABASE_SCHEMA=true`, the three archive tables exist, source `glueful/framework:archive`; with default (false), the archive source is absent and `getPendingMigrations()` lists none of them.
- [ ] **Step 4: Commit** — `"feat(db): archive capability migration (gated on archive.database_schema, default off)"`

---

## Task 9: Skeleton cleanup

**Files (api-skeleton):** delete the six framework-subsystem migrations; keep `database/migrations/`.

- [ ] **Step 1:** `git rm database/migrations/{001_CreateInitialSchema,003_CreateScheduledJobsTables,004_CreateNotificationSystemTables,005_CreateArchiveSystemTables,006_CreateQueueSystemTables,007_CreateLocksTable}.php` and `touch database/migrations/.gitkeep`.
- [ ] **Step 2: Test (skeleton).** Extend the skeleton suite: with default config (`glueful/users` enabled, queue=database, uploads enabled, scheduler on; archive/locks off), a fresh `migrate:run` over file SQLite applies cleanly and creates `users`, `blobs`, `queue_jobs`, `scheduled_jobs`, `notifications` but NOT `archive_registry` or `locks`. Assert `migrations.source` contains `glueful/framework`, `glueful/framework:queue`, `glueful/framework:uploads`, `glueful/users`. (Run via `php glueful migrate:run --force` against a temp DB, then inspect, mirroring the Phase-5 CLI check.)
- [ ] **Step 3: Commit (api-skeleton).** `git commit -am "refactor(db): drop framework-subsystem migrations (now core capability migrations)"`

---

## Task 10: Cross-repo verification + docs

- [ ] **Step 1:** Framework suite green (`composer test`), PHPStan + phpcs clean (`composer run analyse`, `composer run phpcs`).
- [ ] **Step 2:** Fresh-skeleton matrix smoke: toggle each gate (e.g. `LOCK_DRIVER=database`, `ARCHIVE_DATABASE_SCHEMA=true`, `QUEUE_CONNECTION=sync`) and confirm `migrate:status` lists exactly the expected capability tables/sources.
- [ ] **Step 3:** Update `CHANGELOG.md [Unreleased]`: core now owns platform schema via config-gated capability migrations (`glueful/framework:<capability>`), runtime DDL removed from DatabaseQueue/JobScheduler/NotificationRetryService, `notification_retry_queue` now migrated, new flags (`schedule.database_store`, `notifications.database_store`, `archive.database_schema`); note the breaking change for apps that ran on the old skeleton-owned schema (no data change; ownership/registration only).
- [ ] **Step 4: Commit** — `"docs: record platform schema move to core capability migrations"`

## Done when

- The six subsystems' tables are created by **core** capability migrations, gated by config, each under `glueful/framework:<capability>`; the skeleton ships no framework-subsystem migrations.
- No production runtime DDL remains in `DatabaseQueue`, `JobScheduler`, `NotificationRetryService` (verified by guard tests); `notification_retry_queue` has a real migration.
- Pending migrations sort deterministically by `(priority, basename, source)`.
- A fresh skeleton migrates cleanly with only the enabled capabilities' tables present; toggling a gate adds/removes exactly that capability's tables.
- All suites green; analysis/style clean; CHANGELOG updated.

## Self-review checklist (run before executing)

- Confirm each capability's table list matches the current skeleton migration verbatim (no column drift): `locks`(1), `uploads`/blobs(1), `queue`(3), `scheduler`(2), `notifications`(4 + retry_queue), `archive`(3).
- Confirm the gate config keys exist after Tasks 6–8 add them; `lock.default`/`queue.default`/`uploads.enabled` already exist.
- Grep for any other `ensure*Table*`/lazy `hasTable(...)→createTable(...)` in the six subsystems before implementing (the audit named three; verify there are no others).

---

## Execution notes (as built)

All 10 tasks implemented inline on `dev`. Deviations from the plan as written:

- **Config consolidated into `config/capabilities.php`.** Instead of per-subsystem flags
  (`config/notifications.php`, `config/metrics.php`, `schedule.database_store`,
  `archive.database_schema`), the explicit capability gates live in one switchboard
  `config/capabilities.php` (`scheduler`, `notifications`, `metrics`, `archive`). The
  driver-derived gates (`locks`→`lock.default`, `queue`→`queue.default`, `uploads`→`uploads.enabled`)
  stay in their own domain config — no second source of truth. Env var names are unchanged
  (`SCHEDULE_DATABASE_STORE`, `NOTIFICATIONS_DATABASE_STORE`, `METRICS_DATABASE_STORE`,
  `ARCHIVE_DATABASE_SCHEMA`), so the gated tests were unaffected.
- **A 7th capability — `metrics`.** An additional lazy-DDL subsystem (`ApiMetricsService`, tables
  `api_metrics`/`api_metrics_daily`/`api_rate_limits`) was found and folded in:
  `framework/migrations/metrics/`, gated on `capabilities.metrics` (default on), with its runtime
  DDL removed and its now-unused `SchemaBuilderInterface` constructor dependency dropped.
- **Runtime DDL removed from four subsystems** (not three): `DatabaseQueue`, `JobScheduler`,
  `NotificationRetryService` (both `ensureRetryQueueTableExists()` call sites — `ProcessRetriesCommand`
  *and* `NotificationRetryTask`, plus the internal `queueForRetry` call), and `ApiMetricsService`.
- **`notification_retry_queue`** got a first-class migration (it previously had none).
- **Tests:** `CapabilityMigrationsTest` (per-capability on/off + 4 no-runtime-DDL guards) and
  `PendingSortTiebreakerTest`. Framework suite green (1056); PHPStan/phpcs clean. Verified live via
  a fresh skeleton `migrate:run` (correct sources; `locks`/`archive` gated off by default).
- **Docs:** added user-facing `docs/MIGRATIONS_AND_CAPABILITIES.md` and `docs/IDENTITY.md`.
- **Out of scope (noted):** `ApiMetricsService` was the last lazy-DDL subsystem found; no others
  remain among the migrated capabilities.
