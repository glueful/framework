# Extract Archive → `glueful/archive` — Implementation Plan

> **For agentic workers:** implement task-by-task with TDD (failing test first, run it red, implement, run it green, commit). Each task is independently green and reviewable; don't start a task before the previous is green. Design (authoritative — do not re-litigate its Decisions/Guardrails): `docs/superpowers/specs/2026-06-06-extract-archive-design.md` (v2). This is the **lowest-risk extraction** in the boundary program: there is **no core consumer** of `ArchiveServiceInterface`, so the contract + DTOs move whole and no core seam is left behind.

**Goal:** Move the self-contained Archive subsystem (`src/Services/Archive/**`, its CLI command, config, capability gate, and `archive_*` migration) out of framework core into a new standalone `glueful/archive` extension that owns its schema, config, opt-in flag, provider, and command — removing the one live violation of "no core capability ships schema core itself doesn't read/write."

**Compatibility/Tech:** PHP 8.3, PHPUnit 10.5 (library/extension tests extend `PHPUnit\Framework\TestCase`, lightweight SQLite harness). This is a **breaking clean-break removal** per spec G3/Decision §3 — no `class_alias` shims, no deprecation cycle; app authors get upgrade notes. One intentional behavior change: the pre-existing config-key bug is fixed (Decision §8). Verification per task: `composer test` (targeted green), `composer run analyse` (PHPStan, src clean), `composer run phpcs`.

> **Note on package location.** The new `glueful/archive` package is a *separate composer package*. Where it physically lives during development (sibling checkout, path repo, or symlink into the framework worktree like the aegis cross-repo setup) is an environment detail. All paths below prefixed `glueful/archive/` are relative to the new package root; all unprefixed `src/…`, `config/…`, `migrations/…`, `tests/…`, `docs/…` paths are relative to `/Users/michaeltawiahsowah/Sites/glueful/framework`.

> **Core stays green every task — copy-first, then one atomic removal.** Tasks 2–6 **copy** code into `glueful/archive` and leave the core originals **untouched** — so core compiles and its full suite passes after every one of those tasks (the extension is built/tested standalone against the framework as a dependency). **Task 7 is the single, atomic core-removal commit** that deletes all the now-duplicated core sources, the provider registration, the migration dir, the config, and the capability gate together. This keeps the "each task independently green" guarantee literally true: at no point is core left referencing a class that no longer exists. (Do **not** `git mv` out of core in Tasks 2–6 — that would break core until Task 7.)

---

## Task 0 — Acceptance pre-flight: re-confirm "no core seam"

The load-bearing decision (Decision §1, Guardrail G1) is that nothing in core calls `ArchiveServiceInterface`. Re-prove it before moving anything, so the plan starts from verified ground.

**Steps**
- [ ] Run the seam grep and confirm the **only** hits outside `src/Services/Archive/` and `tests/Integration/Services/Archive/` are wiring (provider registration, CLI command, gate, capabilities config, test):
  ```bash
  grep -rn --include="*.php" 'Services\\Archive\|ArchiveServiceInterface\|ArchiveHealthChecker' src/ config/ tests/ \
    | grep -v 'src/Services/Archive/' | grep -v 'tests/Integration/Services/Archive/'
  ```
  Expected hits (and nothing domain-level):
  - `src/Container/Bootstrap/ContainerFactory.php:136` — `ArchiveProvider::class` registration.
  - `src/Console/Commands/Archive/ManageCommand.php:7,8,9,38,185,397` — CLI imports/uses.
  - (the capability gate at `src/Container/Providers/CoreProvider.php:495` and `config/capabilities.php:31` reference the string `archive`, not the FQCN — confirm separately.)
- [ ] Confirm no core retention/cleanup path archives first (they hard-delete): spot-check `src/Repository/NotificationRepository.php` (`deleteOldNotifications()`), `src/Tasks/SessionCleanupTask.php`, `src/Tasks/LogCleanupTask.php`, `src/Tasks/DatabaseBackupTask.php` — none reference Archive.
- [ ] If any **domain caller** is found, STOP and escalate: the spec's premise (whole-move, no seam) would be wrong and the plan needs revision. Otherwise proceed.

**Rollback risk:** None — read-only verification. If this fails, the entire extraction strategy changes.

---

## Task 1 — Stand up the `glueful/archive` package skeleton

Create the empty extension shell so subsequent moves have a home. No archive logic yet.

**Create**
- `glueful/archive/composer.json` — package `glueful/archive`; `"type": "glueful-extension"`. Use the canonical Glueful extension manifest shape (strict JSON — no comments):
  ```json
  {
      "name": "glueful/archive",
      "description": "Data-lifecycle archiving for Glueful (table archiving, retention, compression, restore).",
      "type": "glueful-extension",
      "license": "MIT",
      "authors": [{ "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }],
      "keywords": ["archive", "retention", "data-lifecycle", "glueful"],
      "require": {
          "php": "^8.3"
      },
      "require-dev": {
          "glueful/framework": "^1.52.0",
          "phpunit/phpunit": "^10.5",
          "squizlabs/php_codesniffer": "^3.6",
          "phpstan/phpstan": "^1.0"
      },
      "homepage": "https://github.com/glueful/archive",
      "autoload": {
          "psr-4": { "Glueful\\Extensions\\Archive\\": "src/" },
          "classmap": ["migrations/"]
      },
      "autoload-dev": {
          "psr-4": { "Glueful\\Extensions\\Archive\\Tests\\": "tests/" }
      },
      "scripts": {
          "test": "vendor/bin/phpunit",
          "phpcs": "vendor/bin/phpcs --standard=Squiz src",
          "phpcbf": "vendor/bin/phpcbf --standard=Squiz src",
          "analyze": "vendor/bin/phpstan analyze src --level=8"
      },
      "extra": {
          "glueful": {
              "name": "Archive",
              "displayName": "Archive",
              "description": "Data-lifecycle archiving for Glueful.",
              "version": "1.0.0",
              "categories": ["data-lifecycle", "archive"],
              "publisher": "glueful-team",
              "provider": "Glueful\\Extensions\\Archive\\ArchiveServiceProvider",
              "requires": { "glueful": ">=1.52.0", "extensions": [] }
          }
      },
      "config": { "sort-packages": true }
  }
  ```
  `glueful/framework ^1.52.0` is the coordinated breaking release that performs the core removal (Task 7) — fill in the real version when set. For **local development before that release is published**, register a path repository to the framework checkout in your *project/app* composer (as `php glueful create:extension` does) — it is an environment detail, **not** committed to the extension's composer.json.

  (Namespace root is `Glueful\Extensions\Archive\` — the `Services\` segment is dropped per Decision §4. The package ships its own `test`/`phpcs`/`phpcbf`/`analyze` scripts + dev tooling so the per-task gates run **from the package root**; they do not depend on the framework's composer scripts.)
- `glueful/archive/src/ArchiveServiceProvider.php` — minimal `final class ArchiveServiceProvider extends \Glueful\Extensions\ServiceProvider` with empty `services(): array { return []; }`, empty `register(ApplicationContext $context): void {}`, empty `boot(ApplicationContext $context): void {}`. (Fleshed out in Tasks 4–6.)
- `glueful/archive/phpunit.xml` — minimal config with a `tests/` test suite.
- `glueful/archive/tests/SkeletonTest.php` — a trivial `final class SkeletonTest extends \PHPUnit\Framework\TestCase` asserting the provider class exists and is a `ServiceProvider` subclass.

**Steps**
- [ ] Write `SkeletonTest` asserting `class_exists(\Glueful\Extensions\Archive\ArchiveServiceProvider::class)` and `is_subclass_of(... , \Glueful\Extensions\ServiceProvider::class)`. Run it red (class missing).
- [ ] Write `composer.json`, the provider stub, `phpunit.xml`. Run `composer install` in the package; run the test green.
- [ ] Commit (`feat(archive): scaffold glueful/archive extension package`).

**Rollback risk:** Low — new isolated package, no core changes yet. Revert = delete the package directory.

---

## Task 2 — Move the service, contract, DTOs, and health checker

Relocate all of `src/Services/Archive/**` (except the `ServiceProvider/` subdir, handled in Task 4) into the extension under `Glueful\Extensions\Archive\`, dropping the `Services\` segment. Because there is no core caller (Task 0), the interface and DTOs move too.

**Copy (core originals stay until Task 7's atomic removal)**
- `src/Services/Archive/ArchiveService.php` → `glueful/archive/src/ArchiveService.php`
- `src/Services/Archive/ArchiveServiceInterface.php` → `glueful/archive/src/ArchiveServiceInterface.php`
- `src/Services/Archive/ArchiveHealthChecker.php` → `glueful/archive/src/ArchiveHealthChecker.php`
- `src/Services/Archive/DTOs/ArchiveFile.php` → `glueful/archive/src/DTOs/ArchiveFile.php`
- `src/Services/Archive/DTOs/ArchiveRestoreOptions.php` → `glueful/archive/src/DTOs/ArchiveRestoreOptions.php`
- `src/Services/Archive/DTOs/ArchiveResult.php` → `glueful/archive/src/DTOs/ArchiveResult.php`
- `src/Services/Archive/DTOs/ArchiveSearchQuery.php` → `glueful/archive/src/DTOs/ArchiveSearchQuery.php`
- `src/Services/Archive/DTOs/ArchiveSearchResult.php` → `glueful/archive/src/DTOs/ArchiveSearchResult.php`
- `src/Services/Archive/DTOs/ArchiveSummary.php` → `glueful/archive/src/DTOs/ArchiveSummary.php`
- `src/Services/Archive/DTOs/ExportResult.php` → `glueful/archive/src/DTOs/ExportResult.php`
- `src/Services/Archive/DTOs/HealthCheckResult.php` → `glueful/archive/src/DTOs/HealthCheckResult.php`
- `src/Services/Archive/DTOs/RestoreResult.php` → `glueful/archive/src/DTOs/RestoreResult.php`
- `src/Services/Archive/DTOs/TableArchiveStats.php` → `glueful/archive/src/DTOs/TableArchiveStats.php`

**Re-namespace (in every moved file — use Edit, not sed; macOS sed double-escapes backslashes):**
- `namespace Glueful\Services\Archive;` → `namespace Glueful\Extensions\Archive;`
- `namespace Glueful\Services\Archive\DTOs;` → `namespace Glueful\Extensions\Archive\DTOs;`
- All `use Glueful\Services\Archive\DTOs\…;` and `use Glueful\Services\Archive\…;` imports → `Glueful\Extensions\Archive\…`.
- External deps stay **unchanged** (they are stable core contracts the extension depends on, G4): `Glueful\Database\Connection`, `Glueful\Database\Schema\Interfaces\SchemaBuilderInterface`, `Glueful\Security\RandomStringGenerator`, `Glueful\Storage\StorageManager`, `Glueful\Storage\PathGuard`, `Glueful\Helpers\Utils`, `Glueful\Http\Exceptions\Domain\{DatabaseException,BusinessLogicException}`, `Glueful\Bootstrap\ApplicationContext`.
- Update each DTO/class docblock `@package Glueful\Services\Archive*` → `@package Glueful\Extensions\Archive*`.

**Steps**
- [ ] **Copy** the 13 files into the package (do **not** delete from core — Task 7 removes the originals atomically). Re-namespace each via Edit.
- [ ] Verify `ArchiveService.php:60` still reads `archive.storage.path` via `$this->getConfig('archive.storage.path', …)` — this read is already correct; the config array passed to the ctor is fixed in Task 4. Leave the `getConfig()` fallback as-is for now.
- [ ] Run `composer dump-autoload` in the package; run `php -l` (or PHPStan) on each moved file to confirm no unresolved `Glueful\Services\Archive\…` symbols remain in the package.
- [ ] Grep the package for any residual `Services\\Archive` reference: `grep -rn 'Services\\Archive' glueful/archive/src` → expect zero.
- [ ] Run the package test suite (still just `SkeletonTest`) green.
- [ ] Commit (`feat(archive): move service, interface, DTOs, health checker into extension`).

**Rollback risk:** Low — **core is untouched** (its `Glueful\Services\Archive\*` originals remain), so core stays green; the copies live only in the package and are exercised by the package suite. Revert = delete the copied files from the package.

---

## Task 3 — Move + own the migration (explicit source)

Relocate the capability migration into the extension and re-namespace it. Ownership metadata (`source`) is wired in Task 4's provider; this task just relocates the file.

**Copy (core original stays until Task 7)**
- `migrations/archive/001_CreateArchiveSystemTables.php` → `glueful/archive/migrations/001_CreateArchiveSystemTables.php`

**Re-namespace**
- `namespace Glueful\Migrations\Archive;` → `namespace Glueful\Extensions\Archive\Migrations;` (class `CreateArchiveSystemTables` unchanged).
- Update the class docblock (currently says "Owned by framework core; registered only when `archive.database_schema` is true") → "Owned by the `glueful/archive` extension; registered only when `archive.enabled` is true (default off)."
- The `up()`/`down()` bodies are **unchanged**: idempotent `if (!$schema->hasTable(...))` guards at lines 18, 46, 71 stay; the intra-capability FK `archive_search_index.archive_uuid → archive_registry.uuid` (lines 64–67) is portable (no cross-package FK).

**Steps**
- [ ] **Copy** the migration into `glueful/archive/migrations/` (leave the core `migrations/archive/` copy in place until Task 7) and re-namespace via Edit.
- [ ] Confirm no other file in core references `Glueful\Migrations\Archive\CreateArchiveSystemTables` (grep): expect zero (migrations are discovered by path, not imported).
- [ ] `php -l` the copied file.
- [ ] Commit (`feat(archive): copy archive_* migration into extension`).

**Rollback risk:** Low — core's migration stays in place; migration files are path-discovered, not imported. Revert = delete the package copy.

---

## Task 4 — Build the `ArchiveServiceProvider` (DI + config + self-gated migration), fixing the config-key bug

Flesh out the provider stub from Task 1: wire DI (`services()`), merge config + self-gate the migration (`register()`), and fix the two config-key bugs (Decision §8). Command discovery is Task 5.

**Create**
- `glueful/archive/config/archive.php` — moved from core `config/archive.php` (Task 7 deletes the core copy), **with one addition** at the top of the returned array:
  ```php
  // Canonical opt-in gate (Decision §5). Legacy env retained only as the default.
  'enabled' => env('ARCHIVE_DATABASE_SCHEMA', false),
  ```
  Canonical storage keys stay `archive.storage.path` / `archive.storage.temp_path` (lines 23–25); do **not** introduce a flat `storage_path`.
- `glueful/archive/tests/Unit/ArchiveServiceProviderTest.php`

**Modify**
- `glueful/archive/src/ArchiveServiceProvider.php`:
  - `services()` — mirror today's `ArchiveProvider::defs()` (old `src/Services/Archive/ServiceProvider/ArchiveProvider.php:15-40`) returning:
    - a `FactoryDefinition` for `\Glueful\Extensions\Archive\ArchiveServiceInterface::class` that builds `new \Glueful\Extensions\Archive\ArchiveService($c->get('database'), $c->get(SchemaBuilderInterface::class), $c->get(RandomStringGenerator::class), $cfg)`.
      **Bug fix (Decision §8):** the old factory passed `config($ctx, 'archive.config', [])` — a key that does not exist → `ArchiveService` always got `[]`. Pass the **full** archive config array instead, resolving the context **from the container** (the factory closure receives `$c` — `services()` is a static method with no `$this->context`):
      ```php
      $context = $c->get(\Glueful\Bootstrap\ApplicationContext::class);
      $cfg = (array) config($context, 'archive', []);
      ```
    - an `AliasDefinition` `\Glueful\Extensions\Archive\ArchiveService::class` → `\Glueful\Extensions\Archive\ArchiveServiceInterface::class`.
    - (Note: the framework `ServiceProvider::services()` is a `static` array-returning method per CLAUDE.md, whereas the old core `BaseServiceProvider::defs()` was instance-based with `$this->context`. Resolve config inside the factory closure using the container/`ApplicationContext` available at build time — match the surrounding extension-provider convention; do **not** invent a `$this->context` that the extension base class doesn't expose.)
  - `register(ApplicationContext $context)`:
    - `$this->mergeConfig('archive', require __DIR__ . '/../config/archive.php');`
    - then **only if** `config($context, 'archive.enabled', false)` is true:
      `$this->loadMigrationsFrom(__DIR__ . '/../migrations', \Glueful\Database\Migrations\MigrationPriority::DEFAULT, 'glueful/archive');`
      The explicit `'glueful/archive'` source is **required** — `loadMigrationsFrom()` (`src/Extensions/ServiceProvider.php:177-188`) forwards `$source` straight to `MigrationManager::addMigrationPath()` and defaults to `null` (→ the dir segment `migrations`, useless as ownership). `MigrationPriority::DEFAULT` (= `0`) is correct: archive is non-foundational with no dependents (not `FOUNDATION = -200`).
- `glueful/archive/src/Console/ManageCommand.php` config reads (this file is moved in Task 5, but the bug fix is tracked here as a Decision §8 item — apply it in Task 5): `archive.storage_path` (old lines 465, 631) → `archive.storage.path`.

**Steps**
- [ ] Write `ArchiveServiceProviderTest`:
  - asserts `services()` returns definitions keyed by `ArchiveServiceInterface::class` and `ArchiveService::class`.
  - builds a tiny container from those defs (with stub `database` / `SchemaBuilderInterface` / `RandomStringGenerator`) and asserts `get(ArchiveServiceInterface::class) instanceof ArchiveService`.
  - asserts the factory passes the **full** `archive` config array through (e.g. seed config with `archive.storage.path = /tmp/x` and assert the constructed `ArchiveService`'s effective base path reflects it — proving the `archive.config` → `archive` fix). Run red.
- [ ] Implement `services()` + `register()` config-merge + self-gated `loadMigrationsFrom`. Run green.
- [ ] Add a test asserting `register()` does **not** call `loadMigrationsFrom` when `archive.enabled` is false, and **does** when true (use a fake/booted container that records `addMigrationPath($dir, $priority, $source)` and assert `$source === 'glueful/archive'` and `$priority === MigrationPriority::DEFAULT`). Run red → green.
- [ ] Run package suite green; `composer run analyse` clean on the package.
- [ ] Commit (`feat(archive): provider DI + self-gated migration; fix archive.config/storage_path keys`).

**Rollback risk:** Medium — this is where the config-key behavior intentionally changes (configured storage paths now actually take effect). Mitigated by the explicit test proving the full-config pass-through. Revert = restore the `archive.config` read.

---

## Task 5 — Move the CLI command (`archive:manage`) into the extension

Relocate `ManageCommand` into the extension's `Console/` and register it via `discoverCommands()` in `boot()`. Removing it from `src/Console/Commands/` is done in Task 7 (so core's `ConsoleProvider` scan stops finding it).

**Copy (core original stays until Task 7)**
- `src/Console/Commands/Archive/ManageCommand.php` → `glueful/archive/src/Console/ManageCommand.php`

**Re-namespace + fix (in the package copy)**
- `namespace Glueful\Console\Commands\Archive;` → `namespace Glueful\Extensions\Archive\Console;`
- Imports: `use Glueful\Services\Archive\ArchiveServiceInterface;` → `Glueful\Extensions\Archive\ArchiveServiceInterface`; `use Glueful\Services\Archive\DTOs\ArchiveSearchQuery;` → `Glueful\Extensions\Archive\DTOs\ArchiveSearchQuery`; `use Glueful\Services\Archive\ArchiveHealthChecker;` → `Glueful\Extensions\Archive\ArchiveHealthChecker`.
- Keep external deps unchanged: `Glueful\Services\FileFinder`, `Glueful\Http\Exceptions\Domain\BusinessLogicException`, `Glueful\Console\BaseCommand`, Symfony console classes, `Psr\Log\LoggerInterface`.
- Keep `#[AsCommand(name: 'archive:manage', …)]` (old lines 32-34) and the resolve-from-container at `:185` (`$this->getService(ArchiveServiceInterface::class)`) and `new ArchiveHealthChecker(...)` at `:397` (both classes now in the extension).
- **Config-key fix (Decision §8):** the two `config($this->getContext(), 'archive.storage_path', $storagePath)` reads (old lines 465, 631) → `config($this->getContext(), 'archive.storage.path', $storagePath)`. The `base_path(...)` fallback `$storagePath` is unchanged.
- Update `@package Glueful\Console\Commands\Archive` docblock → `@package Glueful\Extensions\Archive\Console`.

**Modify**
- `glueful/archive/src/ArchiveServiceProvider.php` — `boot(ApplicationContext $context)`:
  `$this->discoverCommands('Glueful\\Extensions\\Archive\\Console', __DIR__ . '/Console');`
  **Caveat (Decision §7):** `discoverCommands()` (`src/Extensions/ServiceProvider.php:385`) is a no-op outside console boot — it early-returns when not running in console / when `console.application` is absent. So `archive:manage` registers only during CLI runs. Tests must assert via a console-application path (e.g. `commands:list`), **not** a bare container.

**Create**
- `glueful/archive/tests/Unit/ManageCommandTest.php`

**Steps**
- [ ] **Copy** the command into `glueful/archive/src/Console/` (leave the core original until Task 7). Re-namespace + apply the two `storage_path` → `storage.path` fixes via Edit. (No duplicate-command risk: core's `ConsoleProvider` scan and the extension's `discoverCommands` run in different installs — the extension isn't registered into the framework checkout.)
- [ ] Write `ManageCommandTest`: assert the class carries `#[AsCommand(name: 'archive:manage')]` (reflect the attribute) and that its namespace/imports resolve (instantiable or at least `class_exists` + reflection of the `archiveService` property type `ArchiveServiceInterface`). Run red → green.
- [ ] Add a `boot()` assertion: in a console-booted harness (or a fake `console.application` recording registrations) `discoverCommands` registers `archive:manage`; in a non-console context it's a no-op (no throw). Run red → green.
- [ ] Run package suite green; `composer run phpcs` clean on the package.
- [ ] Commit (`feat(archive): move archive:manage command into extension; fix storage.path reads`).

**Rollback risk:** Low while copying — core keeps its own `archive:manage` until Task 7, so nothing user-visible changes yet. (The user-visible loss + stale-cache concerns, R1/R4, land at Task 7's removal, mitigated by upgrade notes.) Revert = delete the package copy.

---

## Task 6 — Move the integration test into the extension suite

Relocate the round-trip test and re-namespace it.

**Copy (core original stays until Task 7)**
- `tests/Integration/Services/Archive/ArchiveRestoreTest.php` → `glueful/archive/tests/Integration/ArchiveRestoreTest.php`

**Re-namespace**
- `namespace Glueful\Tests\Integration\Services\Archive;` → `Glueful\Extensions\Archive\Tests\Integration;` (match the package's autoload-dev PSR-4 from Task 1).
- Imports: `use Glueful\Services\Archive\ArchiveService;` → `Glueful\Extensions\Archive\ArchiveService`; `use Glueful\Services\Archive\DTOs\ArchiveRestoreOptions;` → `Glueful\Extensions\Archive\DTOs\ArchiveRestoreOptions`. `use Glueful\Database\Connection;` unchanged. It already extends `PHPUnit\Framework\TestCase` and runs on its own temp SQLite db (no app boot) — keep that harness.

**Steps**
- [ ] **Copy** the test into `glueful/archive/tests/Integration/` and re-namespace via Edit (leave core's copy in place — it still passes against core's not-yet-removed classes; Task 7 deletes it).
- [ ] Run the copied test green from the package suite: `composer test` (or `vendor/bin/phpunit tests/Integration/ArchiveRestoreTest.php`) in the package.
- [ ] Commit (`test(archive): copy ArchiveRestoreTest into extension suite`).

**Rollback risk:** Low — test-only, core untouched. Revert = delete the package copy.

---

## Task 7 — Core removals + capability-gate/config relocation + docs + upgrade notes

Strip Archive from core: delete the now-duplicated sources, remove the provider registration, the migration dir, the config, the capability entry, and the `CoreProvider` gate. Update docs and write upgrade/CHANGELOG notes. **This is the single, atomic core-removal commit** — every core deletion + edit below lands together so core goes from green (with archive) to green (without archive) in one step; there is no intermediate broken state (the copies made in Tasks 2–6 are already proven green in the package suite).

**Delete (core)**
- `src/Services/Archive/` — the whole directory (service, interface, health checker, DTOs, and the `ServiceProvider/ArchiveProvider.php` subdir).
- `src/Console/Commands/Archive/ManageCommand.php` (and the now-empty `src/Console/Commands/Archive/` dir) — so `ConsoleProvider`'s recursive `#[AsCommand]` scan (`src/Console/Providers/ConsoleProvider.php:79-115`) no longer auto-registers `archive:manage`.
- `migrations/archive/` — the whole directory.
- `config/archive.php`.
- `tests/Integration/Services/Archive/` — the whole directory (the core copy of `ArchiveRestoreTest`, now living in the package per Task 6).

**Modify (core)**
- `src/Container/Bootstrap/ContainerFactory.php:136` — remove the line `\Glueful\Services\Archive\ServiceProvider\ArchiveProvider::class,`.
- `src/Container/Providers/CoreProvider.php:495` — remove the `'archive' => (bool) $cfg('capabilities.archive', false),` entry from the `$gates` map. The `foreach` loop is otherwise unchanged (`addMigrationPath` already no-ops on absent dirs, so removing the entry is sufficient — and the `migrations/archive/` dir is gone anyway).
- `config/capabilities.php` — remove lines 30-31 (the `// Archive subsystem …` comment + `'archive' => env('ARCHIVE_DATABASE_SCHEMA', false),`). Leave the file's doc comment intact (it already describes only core-owned capabilities).
- `docs/MIGRATIONS_AND_CAPABILITIES.md` — remove the archive rows/examples at lines 55 (the `**archive**` table row), 79 (the `'archive' => env(...)` config example), and 89 (the `ARCHIVE_DATABASE_SCHEMA=true` env example). If a sentence becomes dangling, point the reader to the `glueful/archive` extension instead.

**Modify (docs — superseded banners, per Decision §10 / Capability-gate relocation)**
- `docs/superpowers/specs/2026-06-04-users-extension-extraction-design.md` (around line 234, where archive is listed among "system tables" core/skeleton keeps) — add a one-line banner: `**Superseded (archive): see 2026-06-06-extract-archive-design.md** — archive is now the glueful/archive extension, not a core/skeleton system table.`
- `docs/superpowers/specs/2026-06-04-platform-schema-ownership-design.md` — add the same "Superseded (archive)" banner near where it places archive's migration in core (it already flagged `glueful/archive` as the eventual home — Option B at `:25,:36`; this banner records that it's now done).
- `docs/superpowers/plans/2026-06-04-platform-schema-ownership.md` — add the same banner.

**Create / append (upgrade + changelog)**
- `CHANGELOG.md` `[Unreleased]` — a **breaking change** entry: Archive subsystem extracted to `glueful/archive` (removed from core: `src/Services/Archive`, `archive:manage`, `migrations/archive`, `config/archive.php`, the `archive` capability). Note the one intentional fix: configured `archive.storage.path` now takes effect (the old `archive.config`/`storage_path` keys were dead).
- `UPGRADE.md` (or the repo's upgrade-notes location — append a section) covering the app-author steps from the spec's "Upgrade notes":
  1. `composer require glueful/archive` (matching framework version).
  2. Extension auto-discovers via composer `extra.glueful` — no manual registration.
  3. Re-run `php glueful migrate:run`. **Expect the migration to show "pending" and re-run once** under the new source `glueful/archive`: applied-state is keyed on `unique(['source','migration'])` (`src/Database/Migrations/MigrationManager.php:206,296-299`), so the basename re-pends after the source handoff and a second, **harmless** ledger row is written. The DDL is idempotent (`hasTable` guards), so it's a no-op on an already-archived DB. **This is intended (Decision §9), not a fault — no repair tooling is provided.**
  4. Move any local `config/archive.php` overrides to the extension's published config (or use the unchanged `ARCHIVE_*` env vars).
  5. Enablement unchanged: set `ARCHIVE_DATABASE_SCHEMA=true` — it now backs the extension's `archive.enabled` default; the only gate read is `config($context, 'archive.enabled', false)`. Apps with a published `config/capabilities.php` listing `archive` must drop the dead key (core no longer reads it).
  6. Clear the command cache (`php glueful commands:clear` / `commands:cache`) so a cached manifest doesn't reference the removed core command (R4).
  7. Update code imports `Glueful\Services\Archive\*` → `Glueful\Extensions\Archive\*` (DTOs: `…\DTOs\*` → `Glueful\Extensions\Archive\DTOs\*`). The `archive:manage` verb is unchanged.

**Steps**
- [ ] Delete the five core paths (`src/Services/Archive/`, `src/Console/Commands/Archive/ManageCommand.php`, `migrations/archive/`, `config/archive.php`, `tests/Integration/Services/Archive/`).
- [ ] Apply the four core-modify edits (`ContainerFactory:136`, `CoreProvider:495`, `config/capabilities.php`, `docs/MIGRATIONS_AND_CAPABILITIES.md`).
- [ ] Add the three superseded-banner edits.
- [ ] Write the CHANGELOG `[Unreleased]` + UPGRADE entries.
- [ ] Run core `composer run analyse` (PHPStan, src) — must be clean (no dangling `Glueful\Services\Archive\*` references). Run `composer run phpcs` clean.
- [ ] Run core `composer test` — green (the moved integration test is gone from core; container boots without `ArchiveProvider`).
- [ ] Commit (`feat(archive)!: remove Archive subsystem from core (extracted to glueful/archive)`). Per repo convention, **do not stage `CLAUDE.md`**; use explicit `git add` of the touched files.

**Rollback risk:** High — this is the breaking commit (deletes core code, removes the provider/gate, changes the public capability surface). Mitigated by Tasks 2–6 having already proven the code works in the extension. Revert = `git revert` the commit (restores all deleted files and the provider/gate registration).

---

## Task 8 — Acceptance gate (post-extraction verification)

Prove the spec's acceptance criteria hold, on both a core-only tree and a core+extension tree.

**Steps (core-only — no `glueful/archive` installed)**
- [ ] Container boots clean with **no** `ArchiveProvider`: build via `ContainerFactory` (a smoke test or `php glueful` boot) succeeds.
- [ ] `archive:manage` is **absent** from `php glueful list` (a.k.a. `commands:list`).
- [ ] No `Glueful\Services\Archive\*` references remain in the runtime/core source (exclude docs/specs/tests-of-history):
  ```bash
  grep -rn --include="*.php" 'Services\\Archive\|ArchiveServiceInterface\|ArchiveHealthChecker' src/ config/
  ```
  → expect **zero** hits.
- [ ] Core `composer.json` carries no archive-specific dependency.
- [ ] `php glueful migrate:run` on a core-only app touches no `archive_*` table (inspect the migration plan / applied list).
- [ ] Core `composer test` + `composer run analyse` (src) + `composer run phpcs` all green.

**Steps (with `glueful/archive` installed)**
- [ ] With the extension present and `ARCHIVE_DATABASE_SCHEMA=true`: `archive:manage` appears in `commands:list`.
- [ ] `migrate:run` applies the archive migration (and is a harmless no-op on an already-archived DB; confirm the one re-pended ledger row under source `glueful/archive` per Decision §9).
- [ ] The relocated `ArchiveRestoreTest` passes from the extension suite.
- [ ] Extension `composer run analyse` + `phpcs` green.

**Rollback risk:** None — verification only. A failure here points back to the responsible task (2–7) for repair.

---

## Cross-cutting

- **Verification per task:** `composer test` (targeted green), `composer run analyse` (PHPStan, src — no new errors), `composer run phpcs`. Run them in whichever tree (core or package) the task touched.
- **Sequencing:** Tasks 2–6 **copy** package code while **core remains untouched** (core stays green throughout); **Task 7 is the single atomic core-removal** (the breaking commit). Keep the series on one branch and ship core only at/after Task 7 — never mid-series.
- **No production code outside the listed files.** This plan introduces no new core seam, no compatibility shim, no `migrate:adopt`/ledger-repair tooling (explicitly rejected, Decision §9).
- **Commit cadence:** per the per-task commits above; do not also commit `CLAUDE.md`.

## Self-review (completed during planning)

- **Spec coverage:** no-seam re-grep (Task 0 ✓); package skeleton (Task 1 ✓); move service/interface/DTOs/healthchecker (Task 2 ✓); move + own migration with explicit source (Tasks 3–4 ✓); provider DI + canonical `archive.enabled` gate + both config-key fixes `archive.config`→full array and `storage_path`→`storage.path` (Tasks 4–5, Decision §8 ✓); move CLI via `discoverCommands` (Task 5, Decision §7 ✓); move test (Task 6 ✓); core removals checklist + capabilities/CoreProvider gate removal + docs prune + superseded banners + upgrade/CHANGELOG with ledger re-pend note (Task 7, Decisions §2/§9/§10 ✓); acceptance gate (Task 8 ✓).
- **Grounded line numbers verified against the repo:** `ContainerFactory.php:136`, `CoreProvider.php:495`, `config/capabilities.php:30-31`, `config/archive.php:23-25` (no `enabled`, no flat `storage_path`), `ArchiveProvider.php:19-37` (reads `archive.config`), `ManageCommand.php:7-9,32-34,185,397,465,631`, `001_CreateArchiveSystemTables.php:18,46,71` (idempotent guards) and `:64-67` (intra-capability FK), `ServiceProvider.php:177-188` (`loadMigrationsFrom` defaults `$source` to null), `:385` (`discoverCommands` console-only), `MigrationPriority::DEFAULT = 0`, `MigrationManager.php:206,296-299` (composite `(source,migration)` key).
- **No placeholders:** every step names concrete files/paths and a concrete test or command.
