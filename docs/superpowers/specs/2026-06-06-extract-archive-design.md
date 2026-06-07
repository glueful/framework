# Extract Archive → `glueful/archive` — Design Note

**Status:** Draft v2 — review folded in (config-key fix, explicit migration source, ledger handoff, docs supersession); no code yet · **Scope:** `src/Services/Archive/**`, its provider/CLI, `config/archive.php`, the `archive` capability gate (`config/capabilities.php:30`, `src/Container/Providers/CoreProvider.php:495`), and the capability migration `migrations/archive/001_CreateArchiveSystemTables.php`. Target: a new standalone `glueful/archive` extension. Out of scope: any change to retention/cleanup behavior in core tasks (they already hard-delete; see Problem).

## Problem

The Archive subsystem is a self-contained, generic *table-archive product* that nothing in core consumes — yet it lives in core and (since the 1.50 platform-schema-ownership work) ships a capability-gated migration. That is the one live violation of the agreed boundary rule: **no core capability should ship schema core itself doesn't read/write.**

Grounding (verified by reading the repo):

1. **It is a generic, infra-heavy product, not a primitive.** `ArchiveServiceInterface` (`src/Services/Archive/ArchiveServiceInterface.php:22`) exposes `archiveTable(string, \DateTime)`, `searchArchives`, `restoreFromArchive`, `verifyArchive`, `deleteArchive`, `getTableStats`, `trackTableGrowth`, `getArchiveSummary`, `getTablesNeedingArchival`, `getTableArchives`. The implementation pulls in compression, encryption, checksums, and blob storage (`ArchiveService` ctor + uses `StorageManager`, `PathGuard`, `RandomStringGenerator`, `SchemaBuilderInterface` — `src/Services/Archive/ArchiveService.php:5-22,48-68`). This is exactly a "zero-infra reference implementation" the rule says core should *not* carry.

2. **No core subsystem consumes Archive.** Every core retention/cleanup path hard-deletes directly and never archives first:
   - `src/Repository/NotificationRepository.php` `deleteOldNotifications()`
   - `src/Tasks/SessionCleanupTask.php`
   - `src/Tasks/LogCleanupTask.php`
   - `src/Tasks/DatabaseBackupTask.php`

   A full grep for `ArchiveService` / `ArchiveServiceInterface` / `Services\Archive` outside `src/Services/Archive/` returns **only** wiring, never a domain caller:
   - DI bootstrap: `ArchiveProvider` is registered in `src/Container/Bootstrap/ContainerFactory.php:136`.
   - The capability gate: `config/capabilities.php:30` + `src/Container/Providers/CoreProvider.php:495`.
   - The CLI command: `src/Console/Commands/Archive/ManageCommand.php` (`archive:manage`), which resolves the interface from the container at `:185` and `new ArchiveHealthChecker(...)` at `:397`.
   - One integration test: `tests/Integration/Services/Archive/ArchiveRestoreTest.php`.

3. **It already has a latent config-key bug.** `config/archive.php` nests storage under `archive.storage.path` / `archive.storage.temp_path` (`config/archive.php:23-25`), but `ManageCommand` reads the flat key `archive.storage_path` (`ManageCommand.php:465,631`) and `ArchiveProvider` reads `archive.config` (`ArchiveProvider.php:23`) — **neither key exists in the config file**, so both silently fall through to a hardcoded default / empty array. So "preserve behavior byte-for-byte" would mean preserving a bug. The extraction must decide (it does — Decision §8: normalize).

4. **It ships schema core never reads.** `migrations/archive/001_CreateArchiveSystemTables.php` creates `archive_registry`, `archive_search_index`, `archive_table_stats`. These are registered through the `CoreProvider` `MigrationManager` factory under source `glueful/framework:archive`, gated by `capabilities.archive` (default **off** — `config/capabilities.php:31`, `CoreProvider.php:495-502`). The default-off gate is cost mitigation, **not** a justification to keep it: per the boundary rule, gating does not earn a seat in core.

**Conclusion:** Archive moves out whole. Because no core code (and no second package) calls `ArchiveServiceInterface`, the seam rule says the **contract + DTOs move too** — there is nothing in core to call through them.

## Guardrails

- **G1 — No core seam.** Confirmed: nothing in core depends on `ArchiveServiceInterface`. Move the interface and every DTO to the extension. Do **not** leave a stub contract behind. (If a future core feature ever needs archive-on-delete, it defines its *own* narrow contract then; it does not resurrect this one.)
- **G2 — Schema follows code.** `archive_*` tables move to the extension and are owned by it (`MigrationPriority` + `source` per the migration-ownership rule). Core stops shipping/gating them.
- **G3 — Clean break, documented.** Project is pre-1.0/few users: no compatibility shims, no class aliases. App authors get clear upgrade notes (`composer require glueful/archive`, re-run migrations).
- **G4 — Extension is self-contained.** It owns its config (`archive.php`), its capability flag, its provider, its CLI command, and its migration. It depends only on stable core contracts it already uses (`Connection`, `SchemaBuilderInterface`, `StorageManager`, `PathGuard`, `RandomStringGenerator`, the `Http\Exceptions\Domain` classes, `Utils`).
- **G5 — Behavior-preserving relocation (not byte-for-byte).** This is a relocation, not a redesign: the `ArchiveService` logic, DTO shapes, CLI verbs, and table schema are preserved. But it is **not** literally byte-for-byte — namespaces change, the provider becomes an extension `ServiceProvider`, the migration source/priority change, and the **pre-existing config-key bug is fixed** (see Decision §8). "Behavior-preserving" with those explicit, intended deltas.
- **G6 — Don't touch core cleanup tasks.** They already hard-delete and never archived; extracting Archive changes nothing for them. No "archive-first" behavior is introduced here.

## Workstreams (priority order)

1. **Stand up `glueful/archive` package skeleton.** New composer package (`type: glueful-extension`, `extra.glueful.provider` → `Glueful\Extensions\Archive\ArchiveServiceProvider`), PSR-4 `Glueful\Extensions\Archive\` → `src/`. Depends on `glueful/framework` (via `require-dev`). *Low risk; prerequisite for everything else.* (G4)

2. **Move the service, contract, DTOs, and health checker.** Relocate all of `src/Services/Archive/**` into the extension under namespace `Glueful\Extensions\Archive\` (drop the `Services\` segment) — see files-to-move table. Because no core caller exists, the interface and DTOs go too. (G1, G5)

3. **Move + own the schema.** Relocate `migrations/archive/001_CreateArchiveSystemTables.php` into the extension's `migrations/` dir, registered via `loadMigrationsFrom()` in the provider. Re-namespace it `Glueful\Extensions\Archive\Migrations`. Owned by the extension at the right `MigrationPriority`/`source` (see Migration relocation). (G2, G5)

4. **Relocate the capability gate + config.** Move `config/archive.php` into the extension and merge it via `mergeConfig('archive', ...)`; ship `'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false)` in it. The extension registers its migrations only when `config($context, 'archive.enabled', false)` is true (canonical gate — see Capability-gate relocation). Remove `archive` from core `config/capabilities.php` and from the `CoreProvider` migration factory `$gates` map. (G2, G3)

5. **Move the CLI command.** Relocate `ManageCommand` (`archive:manage`) into the extension's `Console/` and register it via the extension's `boot()` using `discoverCommands(...)`. It no longer lives in `src/Console/Commands/` (so `ConsoleProvider`'s recursive scan no longer finds it). (G3, G5)

6. **Move the test + write upgrade notes.** Relocate `tests/Integration/Services/Archive/ArchiveRestoreTest.php` to the extension's test suite (re-namespaced). Add a CHANGELOG `[Unreleased]` entry + UPGRADE note in core (this is a breaking removal). (G3)

## Files to move

All paths relative to `/Users/michaeltawiahsowah/Sites/glueful/framework`. New namespace root: `Glueful\Extensions\Archive\` (the `Services\` segment is dropped).

| Current (core) | New (extension) | Notes |
|---|---|---|
| `src/Services/Archive/ArchiveService.php` | `src/ArchiveService.php` | `Glueful\Services\Archive\ArchiveService` → `Glueful\Extensions\Archive\ArchiveService`. External deps stay (`Connection`, `SchemaBuilderInterface`, `RandomStringGenerator`, `StorageManager`, `PathGuard`, `Utils`, `Http\Exceptions\Domain\{DatabaseException,BusinessLogicException}`, `ApplicationContext`). |
| `src/Services/Archive/ArchiveServiceInterface.php` | `src/ArchiveServiceInterface.php` | Moves — **no core caller** (verified). |
| `src/Services/Archive/ArchiveHealthChecker.php` | `src/ArchiveHealthChecker.php` | Constructed directly by `ManageCommand:397`; depends on `Connection` + `DTOs\HealthCheckResult`. |
| `src/Services/Archive/ServiceProvider/ArchiveProvider.php` | `src/ArchiveServiceProvider.php` | Replaced by an extension `ServiceProvider` subclass; keep the same `FactoryDefinition` + `AliasDefinition` wiring (`ArchiveProvider.php:19-37`) inside `services()`. |
| `src/Services/Archive/DTOs/ArchiveFile.php` | `src/DTOs/ArchiveFile.php` | |
| `src/Services/Archive/DTOs/ArchiveRestoreOptions.php` | `src/DTOs/ArchiveRestoreOptions.php` | |
| `src/Services/Archive/DTOs/ArchiveResult.php` | `src/DTOs/ArchiveResult.php` | |
| `src/Services/Archive/DTOs/ArchiveSearchQuery.php` | `src/DTOs/ArchiveSearchQuery.php` | |
| `src/Services/Archive/DTOs/ArchiveSearchResult.php` | `src/DTOs/ArchiveSearchResult.php` | |
| `src/Services/Archive/DTOs/ArchiveSummary.php` | `src/DTOs/ArchiveSummary.php` | |
| `src/Services/Archive/DTOs/ExportResult.php` | `src/DTOs/ExportResult.php` | |
| `src/Services/Archive/DTOs/HealthCheckResult.php` | `src/DTOs/HealthCheckResult.php` | |
| `src/Services/Archive/DTOs/RestoreResult.php` | `src/DTOs/RestoreResult.php` | |
| `src/Services/Archive/DTOs/TableArchiveStats.php` | `src/DTOs/TableArchiveStats.php` | |
| `src/Console/Commands/Archive/ManageCommand.php` | `src/Console/ManageCommand.php` | `Glueful\Console\Commands\Archive\ManageCommand` → `Glueful\Extensions\Archive\Console\ManageCommand`. Keeps `#[AsCommand(name: 'archive:manage')]` (`ManageCommand.php:32-33`). **Fix config reads** `archive.storage_path` (`:465,:631`) → `archive.storage.path` to match `config/archive.php` (Decision §8). |
| `config/archive.php` | `config/archive.php` (extension) | Merged via `mergeConfig('archive', ...)`. Must add `'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false)` (the canonical gate). Canonical storage keys are `archive.storage.path`/`archive.storage.temp_path` (`:23-25`); the extension's code reads these consistently (no flat `storage_path`, no `archive.config`). |
| `tests/Integration/Services/Archive/ArchiveRestoreTest.php` | `tests/Integration/ArchiveRestoreTest.php` (extension) | Re-namespace `Glueful\Tests\Integration\Services\Archive` → the extension's test namespace. |

There are **13 DTOs+service+interface+healthchecker** files plus the provider, command, config, migration, and test. (Note: the `ServiceProvider/` subdir under `src/Services/Archive/` collapses into a single extension `ArchiveServiceProvider`.)

## Migration relocation

- **From:** `migrations/archive/001_CreateArchiveSystemTables.php` (class `Glueful\Migrations\Archive\CreateArchiveSystemTables`), creating `archive_registry`, `archive_search_index`, `archive_table_stats` (`001_CreateArchiveSystemTables.php:19,47,72`). The single FK is intra-capability (`archive_search_index.archive_uuid` → `archive_registry.uuid`, `:64-67`) — no cross-package FK, so it's cleanly portable.
- **To:** the extension's `migrations/` directory, re-namespaced (e.g. `Glueful\Extensions\Archive\Migrations\CreateArchiveSystemTables`), registered via `loadMigrationsFrom()` in the provider's `register()`/`boot()`.
- **Ownership metadata — pass the source explicitly.** `loadMigrationsFrom()` does **not** auto-derive the package name: it forwards `$source` straight to `MigrationManager::addMigrationPath($dir, $priority, $source)` (`ServiceProvider.php`), and when `$source` is null it defaults to the directory's last path segment (`migrations`) — useless as ownership metadata. The provider **must** pass it explicitly:
  ```php
  $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEFAULT, 'glueful/archive');
  ```
  Archive is non-foundational with no dependents, so application/`DEFAULT` priority (not core `FOUNDATION`) is correct. The old `glueful/framework:archive` source disappears from `CoreProvider`.
- **Ledger source handoff (decided — accept the re-pend).** `MigrationManager` tracks applied state by a **composite `unique(['source','migration'])`** (`MigrationManager.php:206`, keyed via `sourceKey($source,$migration)` = `source\0migration`, `:296-299`). So moving the basename from source `glueful/framework:archive` → `glueful/archive` makes the migration appear **pending again** under the new source, and `migrate:run` will execute it and write a **second ledger row** under the new source. The up() is idempotent (`if (!$schema->hasTable(...))`, `001_CreateArchiveSystemTables.php:18,46,71`), so the re-apply is a harmless no-op on existing tables. **Decision §9: accept the duplicate ledger row as the clean source-ownership handoff** — do *not* build a ledger-repair/adopt command (over-engineering for a pre-1.0, default-off, rarely-installed subsystem). Document it in the upgrade notes so the extra row isn't mistaken for a fault. (Alternative, explicitly rejected: ship a one-off `migrate:adopt --from=glueful/framework:archive --to=glueful/archive` ledger-rewrite step.)
- **Core changes:**
  - Delete the `migrations/archive/` directory from the framework.
  - Remove the `'archive' => (bool) $cfg('capabilities.archive', false)` entry from the `$gates` map in `CoreProvider.php:495` (and its loop is otherwise unchanged — `addMigrationPath` already no-ops on absent dirs, so removing the entry is sufficient).
  - Remove the `archive` line from `config/capabilities.php:30-31`.
- **Self-gating in the extension:** call `loadMigrationsFrom()` only when `config($context, 'archive.enabled', false)` is true (the canonical gate — see Capability-gate relocation) — preserving today's "opt-in, default-off" behavior, now owned by the package that reads the tables.

## Capability-gate relocation

- Today: core's `config/capabilities.php` is described as the "Core Capability Schema Switchboard" and lists `archive` (`:30-31`). After extraction, archive is **not** a core capability — it is an installed extension. The switchboard's own doc comment (`capabilities.php:5-7`) says only capabilities whose owning subsystem lives in core belong there; archive no longer qualifies.
- **Enablement (canonical — define once, reference everywhere).** The extension owns its enablement through a single config key, with the legacy env as its default:
  - `config/archive.php` ships: `'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false),`
  - The provider gates **only** on the config key: `config($context, 'archive.enabled', false)`.

  So `ARCHIVE_DATABASE_SCHEMA` keeps working (it's the config default — continuity for existing deployments), but no code reads the env directly; the one gate expression is `config($context, 'archive.enabled', false)`. No entry remains in core `config/capabilities.php`.
- Update core docs that enumerate the switchboard: `docs/MIGRATIONS_AND_CAPABILITIES.md:55,79,89` (the `archive` table row, the `'archive' => env(...)` config example, and the `ARCHIVE_DATABASE_SCHEMA=true` env example) must drop archive or point to the extension.
- **Two historical specs assert archive-in-core and must be marked superseded** (add a one-line "**Superseded (archive): see `2026-06-06-extract-archive-design.md`**" banner so future readers aren't misled):
  - `docs/superpowers/specs/2026-06-04-users-extension-extraction-design.md:234` — lists archive among "system tables" the skeleton/core keeps.
  - `docs/superpowers/specs/2026-06-04-platform-schema-ownership-design.md` + `docs/superpowers/plans/2026-06-04-platform-schema-ownership.md` — the platform-schema work that put archive's migration in core. (These already flagged `glueful/archive` as the eventual home — Option B at `platform-schema-ownership-design.md:25,36` — so this note simply executes that.)

## New extension layout

```
glueful/archive/
  composer.json            # type: glueful-extension; extra.glueful.provider = Glueful\Extensions\Archive\ArchiveServiceProvider; PSR-4 Glueful\Extensions\Archive\ → src/
  config/archive.php       # moved from core config/archive.php
  migrations/
    001_CreateArchiveSystemTables.php   # re-namespaced Glueful\Extensions\Archive\Migrations
  src/
    ArchiveServiceProvider.php          # ServiceProvider subclass
    ArchiveService.php
    ArchiveServiceInterface.php
    ArchiveHealthChecker.php
    Console/
      ManageCommand.php                 # #[AsCommand('archive:manage')]
    DTOs/
      ArchiveFile.php  ArchiveRestoreOptions.php  ArchiveResult.php
      ArchiveSearchQuery.php  ArchiveSearchResult.php  ArchiveSummary.php
      ExportResult.php  HealthCheckResult.php  RestoreResult.php
      TableArchiveStats.php
  tests/
    Integration/ArchiveRestoreTest.php
```

**`ArchiveServiceProvider` shape:**
- `services()` — returns the `FactoryDefinition` for `ArchiveServiceInterface::class` plus the `ArchiveService::class` → interface `AliasDefinition`, mirroring today's `ArchiveProvider::defs()` (`ArchiveProvider.php:15-40`). **Fix the config key while moving (Decision §8):** today the factory passes `config('archive.config', [])` (`ArchiveProvider.php:23`), which doesn't exist → `ArchiveService` always gets `[]`. Pass the real `archive` config array instead (`config($ctx, 'archive', [])`), and have `ArchiveService` read `storage.path`/`storage.temp_path` consistently with `config/archive.php`. Optionally register `ArchiveHealthChecker::class` so `ManageCommand` resolves it from the container instead of `new`-ing it (`ManageCommand.php:397`) — small cleanup, not required.
- `register(ApplicationContext $context)` — `mergeConfig('archive', require __DIR__.'/../config/archive.php')`; then, **only if `config($context, 'archive.enabled', false)`**, `loadMigrationsFrom(__DIR__.'/../migrations', MigrationPriority::DEFAULT, 'glueful/archive')` (explicit source — see Migration relocation; canonical gate — see Capability-gate relocation).
- `boot(ApplicationContext $context)` — `discoverCommands('Glueful\\Extensions\\Archive\\Console', __DIR__.'/Console')` so `archive:manage` is registered. **Caveat:** `discoverCommands()` is a no-op outside console boot — it early-returns when `!runningInConsole()` and when the `console.application` service is absent (`ServiceProvider.php`). So the command is only registered during CLI runs; **tests must not expect `archive:manage` outside a console-booted kernel** (assert via a console application/`commands:list`, not a bare container).

**Routes:** none. Archive has no HTTP routes (no controllers/route files reference it); CLI-only. No route file is needed.

## Core removals (checklist)

- `src/Services/Archive/` directory — deleted.
- `src/Console/Commands/Archive/ManageCommand.php` — deleted (so `ConsoleProvider`'s `src/Console/Commands` recursive `#[AsCommand]` scan, `ConsoleProvider.php:79-115`, no longer auto-registers `archive:manage`).
- `src/Container/Bootstrap/ContainerFactory.php:136` — remove the `ArchiveProvider::class` registration line.
- `migrations/archive/` — deleted.
- `config/archive.php` — deleted from core.
- `config/capabilities.php:30-31` — remove the `archive` entry.
- `src/Container/Providers/CoreProvider.php:495` — remove the `'archive'` gate entry.
- `tests/Integration/Services/Archive/ArchiveRestoreTest.php` — moved out.
- Docs: prune archive from `docs/MIGRATIONS_AND_CAPABILITIES.md` (`:55,79,89`); add "Superseded (archive)" banners to `docs/superpowers/specs/2026-06-04-users-extension-extraction-design.md` and the two `platform-schema-ownership` docs.

**Post-extraction verification (acceptance gate):**
- Core container boots with **no** `ArchiveProvider` registered and **no** `archive` capability — assert `ContainerFactory` produces a working container and `archive:manage` is **absent** from `commands:list` when `glueful/archive` is not installed.
- A core-only `composer install` (no archive extension) has **no** `Glueful\Services\Archive\*` references left (grep the tree) and core `composer.json` carries no archive-specific dependency.
- `php glueful migrate:run` on a core-only app does not attempt any `archive_*` table.
- With the extension installed: `archive:manage` appears, migrations apply (idempotent on an already-archived DB), and the integration test passes from the extension suite.

## Upgrade notes (for app authors)

This is a **breaking removal** in core. Apps using Archive must:

1. `composer require glueful/archive` (matching framework version).
2. Ensure the extension is registered/discovered (composer `extra.glueful` auto-discovery; no manual step if the skeleton uses discovery).
3. Re-run migrations: `php glueful migrate:run`. The `archive_registry` / `archive_search_index` / `archive_table_stats` tables are now owned by the extension. **Existing tables are unchanged** (identical, idempotent DDL — `if (!$schema->hasTable(...))`, `001_CreateArchiveSystemTables.php:18,46,71`), so the apply is a no-op on an already-archived database. **Expect the migration to show as "pending" and re-run once under the new source `glueful/archive`** — because applied-state is keyed on `(source, migration)`, the basename re-pends after the ownership handoff and a second (harmless) ledger row is written under the new source. This is intended (Decision §9), not a fault.
4. Move config: copy any local overrides from the old core `config/archive.php` to the extension-published config (or set the `ARCHIVE_*` env vars; the keys are unchanged).
5. Enablement: set `ARCHIVE_DATABASE_SCHEMA=true` as before — it now backs the extension's `archive.enabled` config default (`config/archive.php`: `'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false)`), which is the only gate the extension reads (`config($context, 'archive.enabled', false)`). Apps that set `capabilities.archive` directly in a published `config/capabilities.php` must drop it (core no longer reads it).
6. Code references: update imports `Glueful\Services\Archive\*` → `Glueful\Extensions\Archive\*` (DTOs: `Glueful\Services\Archive\DTOs\*` → `Glueful\Extensions\Archive\DTOs\*`). The CLI verb `archive:manage` is unchanged.

Apps **not** using Archive: nothing to do — it was default-off, and core simply stops shipping it.

## Decisions (resolved)

1. **Whole-subsystem move; no core seam.** Verified there is no core or second-package consumer of `ArchiveServiceInterface` (only DI wiring + CLI + one test). Per the seam rule, the contract + DTOs move with the implementation. *The load-bearing decision.*
2. **Schema follows code.** The `archive_*` migration moves to the extension and is owned by it; core deletes the dir, the gate, and the `config/capabilities.php` entry. This removes the one live violation of "no core capability ships schema core itself doesn't read/write."
3. **Clean break, no shims.** Pre-1.0 with few users → no `class_alias` back-compat, no deprecation cycle. Upgrade notes carry the burden.
4. **Namespace flattens `Services\` away.** New root `Glueful\Extensions\Archive\` (not `Glueful\Extensions\Archive\Services\`) — archive *is* the package, so the `Services` segment is noise.
5. **Self-owned opt-in flag — one canonical expression.** `config/archive.php` ships `'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false)`; the provider gates solely on `config($context, 'archive.enabled', false)` (no direct env reads). `ARCHIVE_DATABASE_SCHEMA` is retained only as that config default. Default-off is a packaging nicety now, not a core gate.
6. **Application-priority migration with an explicit source.** Archive is non-foundational and has no dependents; it registers at `MigrationPriority::DEFAULT` (not core `FOUNDATION`) and **must pass `source: 'glueful/archive'` explicitly** to `loadMigrationsFrom()` — the helper does not derive the package name (it would otherwise default to the dir segment `migrations`).
7. **CLI keeps `archive:manage`.** Command name and verbs are preserved; only the registration path changes (extension `discoverCommands` instead of core's `ConsoleProvider` scan). Note `discoverCommands()` only registers under console boot — tests must assert via a console application, not a bare container.
8. **Normalize the config keys during extraction (fix, don't preserve the bug).** Today `ManageCommand` reads `archive.storage_path` and `ArchiveProvider` reads `archive.config` — neither exists in `config/archive.php` (which uses `archive.storage.path`), so both silently use defaults/empty. The extension reads the canonical `archive.storage.*` keys and passes the full `archive` config array to `ArchiveService`. This is the one intentional behavior change (it makes configured storage paths actually take effect); call it out in the extension CHANGELOG.
9. **Accept the migration-ledger re-pend as the source handoff.** Moving the migration to source `glueful/archive` re-pends it (composite `(source,migration)` key); the idempotent DDL makes the single re-run harmless, leaving one extra ledger row. Accepted and documented; **no** ledger-repair/`migrate:adopt` tooling is built (over-engineering for a default-off, rarely-installed subsystem).
10. **Mark superseding docs.** Add "Superseded (archive)" banners to `2026-06-04-users-extension-extraction-design.md` and the two `platform-schema-ownership` docs so they don't keep asserting archive-in-core; prune the `MIGRATIONS_AND_CAPABILITIES.md` rows.

## Risks

- **R1 — Silent loss of `archive:manage` for current users.** If an app relied on the bundled command, it disappears until `glueful/archive` is installed. *Mitigation:* prominent UPGRADE/CHANGELOG entry; the command name/behavior is unchanged after install.
- **R2 — Migration-ledger re-pend (verified, accepted).** Applied-state is keyed on `unique(['source','migration'])` (`MigrationManager.php:206,296-299`), so the moved migration **re-runs once** under the new `glueful/archive` source and writes a duplicate ledger row. Verified, not hypothetical. *Mitigation:* idempotent `hasTable`-guarded up() makes the re-apply a no-op; accepted as the source-ownership handoff (Decision §9) and documented in upgrade step 3. No ledger-repair tooling is built.
- **R3 — `ArchiveHealthChecker` is `new`-ed, not injected** (`ManageCommand.php:397`). Fine after the move (both land in the extension); the optional cleanup (DI-register it in `services()`, noted under New extension layout) would require updating the command accordingly.
- **R4 — Stale command cache.** `ConsoleProvider` caches the discovered manifest in production (`ConsoleProvider.php:52-65`). After removing the core command, apps must clear the command cache (`php glueful commands:clear` / `commands:cache`) or they'll reference a missing class. *Mitigation:* note in upgrade steps.
- **R5 — Config publish drift.** Apps with a published `config/capabilities.php` listing `archive` will carry a dead key. Harmless (core stops reading it) but should be called out so it's removed.
- **R6 — Docs/spec references.** Multiple docs enumerate archive as a core/skeleton capability and will mislead if left: `docs/MIGRATIONS_AND_CAPABILITIES.md:55,79,89` (prune), `docs/superpowers/specs/2026-06-04-users-extension-extraction-design.md:234` and the two `platform-schema-ownership` docs (add "Superseded (archive)" banners — see Capability-gate relocation / Decision §10).
