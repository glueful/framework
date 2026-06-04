# Phase 1 — Migration Runner: Ordered Sources + Package-Scoped Tracking

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status: ✅ Implemented (commit `721353d`, on `dev`).** Full suite green (1039), phpcs + PHPStan clean.
>
> ### Execution notes (as built) — two deviations from the plan as written
> 1. **Test harness uses file-based SQLite + pooling disabled, not `:memory:`.** `MigrationManager` opens its own `Connection::fromContext()`, and `:memory:` is per-connection, so the manager's connection and the test's verification connection would see different empty DBs. The harness (`tests/Integration/Database/Migrations/Support/MigrationTestCase.php`) writes a `config/database.php` with `['engine'=>'sqlite','sqlite'=>['primary'=>$file],'pooling'=>['enabled'=>false]]` — pooling-off makes each `Connection` set its own PDO (bypassing the static `self::$instances` cache), so a **file** DB is shared by path with no cross-test leak; a unique file per test gives isolation. Context is obtained via `$this->app()->getContext()` (not `$this->app()`).
> 2. **Soft-delete made column-aware in core (Option A).** Rollback's version-row delete was a no-op because `QueryBuilder::delete()` soft-deleted unconditionally and the `migrations` table has no `deleted_at`. Fixed at the framework level: added `SoftDeleteHandler::appliesTo($table)` (enabled **and** table has `deleted_at`) and gated `QueryBuilder::delete()` on it, falling through to `DeleteBuilder::forceDelete()` otherwise — mirroring the already column-aware read path (`applyToWhereClause`). This is slightly beyond the original Phase-1 scope but was required for correct rollback; covered by the green full suite. Rollback's delete uses explicit 3-arg `where('col','=',$val)` (2-arg string values are not normalized to equality).

**Goal:** Make the framework migration runner support priority-ordered migration sources and package-scoped applied-tracking, so a foundational extension's migrations can be guaranteed to run first and two packages can ship the same filename without conflating.

**Architecture:** Introduce a `MigrationPriority` tier class. Refactor `MigrationManager`'s flat `additionalMigrationPaths: string[]` into structured entries `{path, priority, source}`, treat the main app path as `source = 'app'` at `priority = DEFAULT`, sort pending migrations by `(priority ASC, basename ASC)`, and dedup/record applied migrations by `source + basename` via a new `source` column on the `migrations` table. Extend `ServiceProvider::loadMigrationsFrom()` with `$priority` and `$source`.

**Tech Stack:** PHP 8.3, Glueful `Database\Migrations\MigrationManager`, `Database\Schema\Builders\SchemaBuilder`, PHPUnit (`Glueful\Testing\TestCase` over SQLite `:memory:`).

**Spec:** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md) §7.

---

## File structure

- **Create** `src/Database/Migrations/MigrationPriority.php` — named priority tiers.
- **Modify** `src/Database/Migrations/MigrationManager.php` — structured sources, version-table `source` column, priority sort, package-scoped dedup/record.
- **Modify** `src/Extensions/ServiceProvider.php` — `loadMigrationsFrom($dir, $priority, $source)`.
- **Create** `tests/Integration/Database/Migrations/MigrationOrderingTest.php` — end-to-end ordering + dedup over SQLite.

## Test harness note

`MigrationManager` calls `Connection::fromContext($context)` in its constructor, so tests need a booted app. Use the same bootstrap as `tests/Integration/Testing/ActingWithPermissionsTest.php`: extend `Glueful\Testing\TestCase`, write a temp `config/` with a SQLite `:memory:` connection, and point `migrationsPath` at a temp fixtures dir. Each test writes tiny fixture migration files (a class implementing `MigrationInterface`) into temp dirs that stand in for "app" and "package" sources.

---

## Task 1: `MigrationPriority` tiers

**Files:**
- Create: `src/Database/Migrations/MigrationPriority.php`
- Test: `tests/Unit/Database/Migrations/MigrationPriorityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Migrations\MigrationPriority;
use PHPUnit\Framework\TestCase;

final class MigrationPriorityTest extends TestCase
{
    public function test_tiers_are_strictly_ordered_low_to_high(): void
    {
        self::assertLessThan(MigrationPriority::IDENTITY, MigrationPriority::FOUNDATION);
        self::assertLessThan(MigrationPriority::DEFAULT, MigrationPriority::IDENTITY);
        self::assertLessThan(MigrationPriority::DEPENDENT, MigrationPriority::DEFAULT);
    }

    public function test_default_is_zero(): void
    {
        self::assertSame(0, MigrationPriority::DEFAULT);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Database/Migrations/MigrationPriorityTest.php`
Expected: FAIL — class `MigrationPriority` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

/**
 * Named migration ordering tiers. Lower runs first.
 *
 * These are *deterministic ordering* hints, NOT a dependency system — where a
 * real dependency exists it must be declared/enforced separately. `loadMigrationsFrom()`
 * also accepts any raw int for finer ordering between tiers.
 */
final class MigrationPriority
{
    /** Reserved for framework foundation migrations (core ships none today). */
    public const FOUNDATION = -200;

    /** Identity/auth schema (glueful/users). */
    public const IDENTITY = -100;

    /** App / skeleton and ordinary feature migrations. */
    public const DEFAULT = 0;

    /** Extensions commonly paired on top of the app (e.g. aegis), ordered for seeders. */
    public const DEPENDENT = 100;

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Database/Migrations/MigrationPriorityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationPriority.php tests/Unit/Database/Migrations/MigrationPriorityTest.php
git commit -m "feat(migrations): add MigrationPriority ordering tiers"
```

---

## Task 2: Add `source` column to the version table (with backfill)

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` — `ensureVersionTable()` (around lines 146–165)

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Database/Migrations/MigrationSourceColumnTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationSourceColumnTest extends MigrationTestCase
{
    public function test_version_table_has_source_column(): void
    {
        // Constructing the manager runs ensureVersionTable().
        new MigrationManager($this->tempMigrationsDir(), null, $this->context());

        $schema = Connection::fromContext($this->context())->getSchemaBuilder();
        self::assertTrue($schema->hasColumn('migrations', 'source'));
    }
}
```

Also create the shared harness `tests/Integration/Database/Migrations/Support/MigrationTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Testing\TestCase;

abstract class MigrationTestCase extends TestCase
{
    private string $appPath;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-migr-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing'];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'connections' => "
            . "['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n"
        );
        $this->migrationsDir = $this->appPath . '/database/migrations';
        mkdir($this->migrationsDir, 0755, true);
        parent::setUp();
    }

    protected function getBasePath(): string
    {
        return $this->appPath;
    }

    protected function context(): ApplicationContext
    {
        return $this->app();   // provided by Glueful\Testing\TestCase
    }

    protected function tempMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    /**
     * Write a fixture migration that creates a marker table, into $dir.
     * Returns the basename.
     *
     * The class name is derived from the filename exactly as MigrationManager::runMigration()
     * expects (leading digits + '_' stripped). CRITICAL: two sources can ship the same basename
     * (e.g. 001_create_tables.php), which would derive the same class name and cause a PHP
     * "cannot redeclare class" fatal. So each fixture is emitted in a UNIQUE namespace derived
     * from its dir+basename — runMigration() detects the `namespace` line and resolves the FQCN,
     * so the two classes never collide.
     */
    protected function writeFixture(string $dir, string $basename, string $createsTable): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $class = preg_replace('/^\d+_/', '', pathinfo($basename, PATHINFO_FILENAME));
        $ns = 'Glueful\\Tests\\Fixtures\\N' . substr(md5($dir . '|' . $basename), 0, 10);
        $php = <<<PHP
<?php
namespace {$ns};
use Glueful\\Database\\Migrations\\MigrationInterface;
use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;
class {$class} implements MigrationInterface
{
    public function up(SchemaBuilderInterface \$schema): void
    {
        \$t = \$schema->table('{$createsTable}');
        \$t->id();
        \$t->create()->execute();
    }
    public function down(SchemaBuilderInterface \$schema): void
    {
        \$schema->dropTableIfExists('{$createsTable}');
    }
    public function getDescription(): string { return '{$class}'; }
}
PHP;
        file_put_contents($dir . '/' . $basename, $php);
        return $basename;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
    }
}
```

> Note: confirm the accessor on `Glueful\Testing\TestCase` that returns the booted `ApplicationContext`. In `ActingWithPermissionsTest` the context is reachable via the base class; if the method is named differently than `app()`, adjust `context()` accordingly (e.g. `$this->getApp()` / a `protected $app` property). This is the only harness coupling point.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationSourceColumnTest.php`
Expected: FAIL — `hasColumn('migrations', 'source')` is `false`.

- [ ] **Step 3: Write minimal implementation**

In `ensureVersionTable()`, add the `source` column to the create path, and add an upgrade path for pre-existing tables. Replace the method body:

```php
private function ensureVersionTable(): void
{
    if (!$this->schema->hasTable(self::VERSION_TABLE)) {
        $table = $this->schema->table(self::VERSION_TABLE);

        $table->id();
        $table->string('migration', 255);
        $table->integer('batch');
        $table->timestamp('applied_at')->default('CURRENT_TIMESTAMP');
        $table->string('checksum', 64);
        $table->text('description')->nullable();
        $table->string('extension', 100)->nullable();
        $table->string('source', 191)->default('app'); // package name, or 'app' for skeleton

        // Package-scoped uniqueness: two sources may ship the same basename, so the
        // unique key is composite (source, migration) — NOT migration alone.
        $table->unique(['source', 'migration']);
        $table->create()->execute();

        return;
    }

    // Upgrade path for existing version tables (pre-release dev DBs).
    if (!$this->schema->hasColumn(self::VERSION_TABLE, 'source')) {
        // Use the CALLBACK form of alterTable(): it runs the column builder, forces column
        // finalization (gc_collect_cycles), calls TableBuilder::execute(), AND flushes pending
        // operations via $this->execute(). The no-callback form only returns an unexecuted
        // builder and does NOT flush on its own, so it must not be used here.
        $this->schema->alterTable(self::VERSION_TABLE, function ($table): void {
            $table->string('source', 191)->default('app');
        });
        // Backfill any pre-existing rows to the app source.
        $this->db->table(self::VERSION_TABLE)->whereNull('source')->update(['source' => 'app']);
        // IMPORTANT: existing tables still carry the legacy unique(migration). That constraint
        // contradicts package-scoped tracking and MUST be replaced with unique(source, migration).
        // Pre-release: the supported path is a clean migration-history reset (drop + recreate via
        // the create path above), since SQLite cannot portably drop a named unique. Document this
        // in the upgrade notes; do not silently leave the single-column unique in place.
    }
}
```

> Verified against source: `SchemaBuilder::alterTable($name, $callback)` (no `alter()` exists). The **callback form** used above runs the column builder, calls `TableBuilder::execute()`, then flushes with `$this->execute()` (`SchemaBuilder.php:130-142`) — this is the safe, complete path; the no-callback form returns an unexecuted builder and is intentionally avoided. `dropTableIfExists()` exists on the SchemaBuilder (`SchemaBuilder.php:164`), validating the fixture `down()`. `TableBuilder::unique(array $columns)` composite support is confirmed. The composite key is the core of this task — the create path must not emit `unique('migration')`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationSourceColumnTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationManager.php tests/Integration/Database/Migrations/
git commit -m "feat(migrations): add source column to migrations version table"
```

---

## Task 3: Structured migration sources + `addMigrationPath($path, $priority, $source)`

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` — the `$additionalMigrationPaths` property (line ~71) and `addMigrationPath()` (lines ~127–132); add a private `allSources()` helper.

This task changes internal representation only; ordering/recording behavior is wired in Tasks 4–5.

- [ ] **Step 1: Write the failing test**

Add to a new `tests/Integration/Database/Migrations/MigrationSourcesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationSourcesTest extends MigrationTestCase
{
    public function test_registered_sources_carry_priority_and_source_name(): void
    {
        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        mkdir($pkgDir, 0755, true);

        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');

        $ref = new \ReflectionMethod(MigrationManager::class, 'allSources');
        $ref->setAccessible(true);
        /** @var array<int,array{path:string,priority:int,source:string}> $sources */
        $sources = $ref->invoke($mm);

        // The main app path is always present as source 'app' at DEFAULT priority.
        $app = array_values(array_filter($sources, fn($s) => $s['source'] === 'app'));
        self::assertCount(1, $app);
        self::assertSame(MigrationPriority::DEFAULT, $app[0]['priority']);

        $users = array_values(array_filter($sources, fn($s) => $s['source'] === 'glueful/users'));
        self::assertCount(1, $users);
        self::assertSame(MigrationPriority::IDENTITY, $users[0]['priority']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationSourcesTest.php`
Expected: FAIL — `addMigrationPath()` does not accept 3 args / `allSources()` missing.

- [ ] **Step 3: Write minimal implementation**

Change the property declaration (line ~71):

```php
/**
 * @var array<int, array{path: string, priority: int, source: string}>
 *      Additional migration paths from extensions.
 */
private array $additionalMigrationPaths = [];
```

Replace `addMigrationPath()`:

```php
/**
 * Register an additional migration source.
 *
 * @param string      $path     Migration directory.
 * @param int         $priority Lower runs first (see MigrationPriority).
 * @param string|null $source   Composer package name (e.g. "glueful/users").
 *                              Defaults to the directory's last segment for back-compat.
 */
public function addMigrationPath(
    string $path,
    int $priority = MigrationPriority::DEFAULT,
    ?string $source = null
): void {
    if (!is_dir($path)) {
        return;
    }
    if ($source === null) {
        $parts = explode('/', str_replace('\\', '/', rtrim($path, '/')));
        $source = end($parts) !== false ? (string) end($parts) : 'extension';
    }
    $this->additionalMigrationPaths[] = ['path' => $path, 'priority' => $priority, 'source' => $source];
}

/**
 * The complete, ordered set of migration sources: the main app path (source 'app',
 * DEFAULT priority) followed by all registered additional paths.
 *
 * @return array<int, array{path: string, priority: int, source: string}>
 */
private function allSources(): array
{
    $sources = [[
        'path' => $this->migrationsPath,
        'priority' => MigrationPriority::DEFAULT,
        'source' => 'app',
    ]];
    foreach ($this->additionalMigrationPaths as $entry) {
        $sources[] = $entry;
    }
    return $sources;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationSourcesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationManager.php tests/Integration/Database/Migrations/MigrationSourcesTest.php
git commit -m "refactor(migrations): structured migration sources with priority + source"
```

---

## Task 4: Priority-ordered, package-scoped pending migrations

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` — `getPendingMigrations()` (lines ~179–204), `getAppliedMigrations()` (lines ~211–219); add `appliedKeys()` and a `sourceKey()` helper. Mirror the changes in `getMigrationStatus()` (Task 5).

- [ ] **Step 1: Write the failing test**

Add `tests/Integration/Database/Migrations/MigrationOrderingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationOrderingTest extends MigrationTestCase
{
    public function test_lower_priority_source_runs_before_higher_even_with_larger_basenames(): void
    {
        // App migration with a LOWER-sorting basename but DEFAULT (0) priority.
        $this->writeFixture($this->tempMigrationsDir(), '001_app_table.php', 'app_table');

        // Package migration with a HIGHER-sorting basename but IDENTITY (-100) priority.
        // Basename order alone would put the app file first; priority must override that.
        $pkgDir = $this->tempMigrationsDir() . '/../users';
        $this->writeFixture($pkgDir, '900_users.php', 'users');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');

        $pending = array_map('basename', $mm->getPendingMigrations());

        // IDENTITY (-100) sorts before DEFAULT (0) despite '900_' > '001_' by basename.
        self::assertSame(['900_users.php', '001_app_table.php'], $pending);
    }

    public function test_same_basename_from_two_sources_both_apply(): void
    {
        $this->writeFixture($this->tempMigrationsDir(), '001_create_tables.php', 'app_tbl');
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        $this->writeFixture($pkgDir, '001_create_tables.php', 'pkg_tbl');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::DEPENDENT, 'glueful/pkg');

        $mm->migrate(); // run everything

        // Both marker tables exist => both same-named migrations applied (no basename collision).
        $schema = \Glueful\Database\Connection::fromContext($this->context())->getSchemaBuilder();
        self::assertTrue($schema->hasTable('app_tbl'));
        self::assertTrue($schema->hasTable('pkg_tbl'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationOrderingTest.php`
Expected: FAIL — ordering uses basename only (first test wrong order); same-basename second migration is skipped (second table missing).

- [ ] **Step 3: Write minimal implementation**

Replace `getPendingMigrations()`:

```php
public function getPendingMigrations(): array
{
    $appliedKeys = $this->appliedKeys();

    // Collect candidate files with their source + priority.
    $candidates = []; // array<int, array{file:string, priority:int, source:string}>
    foreach ($this->allSources() as $src) {
        foreach ($this->fileFinder->findMigrations($src['path']) as $file) {
            $path = $file->getPathname();
            if (in_array($this->sourceKey($src['source'], basename($path)), $appliedKeys, true)) {
                continue;
            }
            $candidates[] = ['file' => $path, 'priority' => $src['priority'], 'source' => $src['source']];
        }
    }

    // (priority ASC, basename ASC)
    usort($candidates, function (array $a, array $b): int {
        return [$a['priority'], basename($a['file'])] <=> [$b['priority'], basename($b['file'])];
    });

    return array_map(fn(array $c) => $c['file'], $candidates);
}
```

Replace `getAppliedMigrations()` and add helpers:

```php
/**
 * @return array<string> Applied migration basenames (legacy/basename view).
 */
private function getAppliedMigrations(): array
{
    $result = $this->db->table(self::VERSION_TABLE)->select(['migration'])->get();
    return array_column($result, 'migration');
}

/**
 * @return array<string> Applied keys as "{source}\0{basename}" for package-scoped dedup.
 */
private function appliedKeys(): array
{
    $rows = $this->db->table(self::VERSION_TABLE)->select(['migration', 'source'])->get();
    $keys = [];
    foreach ($rows as $row) {
        $source = (string) ($row['source'] ?? 'app');
        $keys[] = $this->sourceKey($source, (string) $row['migration']);
    }
    return $keys;
}

private function sourceKey(string $source, string $migration): string
{
    return $source . "\0" . $migration;
}
```

> The `<=>` on two-element arrays compares element-by-element, giving `(priority, basename)` lexicographic order. Confirm `$this->fileFinder->findMigrations()` returns SplFileInfo-like objects with `getPathname()` (it does in the existing loops).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationOrderingTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationManager.php tests/Integration/Database/Migrations/MigrationOrderingTest.php
git commit -m "feat(migrations): priority-ordered, package-scoped pending migrations"
```

---

## Task 5: Record `source` on apply + status parity

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` — `runMigration()` source detection + insert (lines ~385–412); `getMigrationStatus()` (lines ~239–270) to collect via `allSources()` so status matches the new ordering/dedup.

- [ ] **Step 1: Write the failing test**

Add to `MigrationOrderingTest.php`:

```php
    public function test_applied_row_records_the_owning_source(): void
    {
        $pkgDir = $this->tempMigrationsDir() . '/../users';
        $this->writeFixture($pkgDir, '001_users.php', 'users');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');
        $mm->migrate();

        $row = \Glueful\Database\Connection::fromContext($this->context())
            ->table('migrations')->where('migration', '001_users.php')->first();

        self::assertSame('glueful/users', $row['source']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationOrderingTest.php --filter test_applied_row_records_the_owning_source`
Expected: FAIL — `source` recorded as `app`/null, not `glueful/users`.

- [ ] **Step 3: Write minimal implementation**

In `runMigration()`, replace the source/extension detection block (lines ~385–395) and the insert (lines ~402–412):

```php
        $filename = basename($file);
        $checksum = hash_file('sha256', $file);

        // Determine the owning source (package name) and legacy extension label.
        // Use realpath + trailing-separator matching so "/foo/pkg2" does not match "/foo/pkg".
        $source = 'app';
        $extensionName = null;
        foreach ($this->additionalMigrationPaths as $entry) {
            if ($this->fileBelongsToDir($file, $entry['path'])) {
                $source = $entry['source'];
                $parts = explode('/', str_replace('\\', '/', rtrim($entry['path'], '/')));
                $extensionName = end($parts) !== false ? (string) end($parts) : 'extension';
                break;
            }
        }
```

Add the helper (place it near `allSources()`):

```php
/**
 * True when $file lives inside $dir, compared by canonical realpath with a trailing
 * separator so sibling prefixes (e.g. /foo/pkg vs /foo/pkg2) never false-match.
 */
private function fileBelongsToDir(string $file, string $dir): bool
{
    $rf = realpath($file);
    $rd = realpath($dir);
    if ($rf === false || $rd === false) {
        return false;
    }
    $rd = rtrim($rd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($rf, $rd);
}
```

and the insert:

```php
            $this->db
                ->table(self::VERSION_TABLE)
                ->insert([
                    'migration' => $filename,
                    'batch' => $batch,
                    'checksum' => $checksum,
                    'description' => $migration->getDescription(),
                    'extension' => $extensionName,
                    'source' => $source,
                ]);
```

Then update `getMigrationStatus()` so its pending/applied collection mirrors `getPendingMigrations()` — collect candidate files via `allSources()` and dedup with `appliedKeys()`/`sourceKey()` instead of the basename-only loop. (Match the structure of the Task 4 `getPendingMigrations()` body; return whatever shape `getMigrationStatus()` already returns, just sourced from `allSources()`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/`
Expected: PASS (all migration tests).

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationManager.php tests/Integration/Database/Migrations/MigrationOrderingTest.php
git commit -m "feat(migrations): record owning source on apply + status parity"
```

---

## Task 6: Package-scoped rollback

Once duplicate basenames are allowed, rollback can no longer locate or delete by basename alone — it must carry `source`, resolve the file by `(source, migration)`, and delete with **both** columns. The current `rollbackMigration()` also iterates `additionalMigrationPaths` as flat strings (broken by Task 3) and lacks namespace-aware class loading (broken by the namespaced fixtures). This task fixes all three.

**Files:**
- Modify: `src/Database/Migrations/MigrationManager.php` — `getMigrationsToRollback()` (lines ~476–489), `rollback()` (lines ~449–464), `rollbackMigration()` (lines ~506–570).

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Database/Migrations/MigrationOrderingTest.php`:

```php
    public function test_rollback_is_source_scoped_for_duplicate_basenames(): void
    {
        $this->writeFixture($this->tempMigrationsDir(), '001_create_tables.php', 'app_tbl');
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        $this->writeFixture($pkgDir, '001_create_tables.php', 'pkg_tbl');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::DEPENDENT, 'glueful/pkg');
        $mm->migrate(); // pkg runs last (DEPENDENT), so it is the most-recent applied row

        $mm->rollback(1); // must revert ONLY the pkg copy

        $db = \Glueful\Database\Connection::fromContext($this->context());
        $schema = $db->getSchemaBuilder();

        // pkg migration reverted (table dropped, row gone); app copy untouched.
        self::assertFalse($schema->hasTable('pkg_tbl'), 'pkg migration should be rolled back');
        self::assertTrue($schema->hasTable('app_tbl'), 'app migration must remain');

        $appRow = $db->table('migrations')
            ->where('migration', '001_create_tables.php')->where('source', 'app')->first();
        $pkgRow = $db->table('migrations')
            ->where('migration', '001_create_tables.php')->where('source', 'glueful/pkg')->first();
        self::assertNotNull($appRow, 'app history row must remain');
        self::assertNull($pkgRow, 'pkg history row must be deleted by (source, migration)');
    }
```

> Confirmed: `SchemaBuilder::dropTableIfExists()` exists (`SchemaBuilder.php:164`), so the fixture `down()` is valid as written.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationOrderingTest.php --filter test_rollback_is_source_scoped`
Expected: FAIL — rollback resolves/deletes by basename only (wrong row deleted, or fatal on the flat-string path loop / non-namespaced class load).

- [ ] **Step 3: Write minimal implementation**

Replace `getMigrationsToRollback()` to carry `source`:

```php
/**
 * @return array<int, array{migration: string, source: string}>
 */
private function getMigrationsToRollback(int $steps): array
{
    $result = $this->db
        ->table(self::VERSION_TABLE)
        ->select(['migration', 'source'])
        ->orderBy('batch', 'DESC')
        ->orderBy('id', 'DESC')
        ->limit($steps)
        ->get();

    return array_map(
        fn(array $r) => ['migration' => (string) $r['migration'], 'source' => (string) ($r['source'] ?? 'app')],
        $result
    );
}
```

Update `rollback()` to pass the structured rows:

```php
public function rollback(int $steps = 1): array
{
    $results = ['reverted' => [], 'failed' => []];
    $migrations = $this->getMigrationsToRollback($steps);

    foreach ($migrations as $row) { // already ordered most-recent-first
        $status = $this->rollbackMigration($row['migration'], $row['source']);
        if ($status['success']) {
            $results['reverted'][] = $status['file'];
        } else {
            $results['failed'][] = $status['file'];
        }
    }

    return $results;
}
```

Replace `rollbackMigration()` to resolve by `(source, migration)` with namespace-aware loading and a two-column delete:

```php
/**
 * @return array{success: bool, file: string, error?: string}
 */
private function rollbackMigration(string $filename, string $source): array
{
    // Resolve the file within the directory that owns this source.
    $file = null;
    foreach ($this->allSources() as $src) {
        if ($src['source'] !== $source) {
            continue;
        }
        $candidate = rtrim($src['path'], '/') . '/' . $filename;
        if (file_exists($candidate)) {
            $file = $candidate;
            break;
        }
    }

    if ($file === null) {
        return ['success' => false, 'file' => $filename, 'error' => "File not found for source $source"];
    }

    include_once $file;

    // Namespace-aware class resolution (mirrors runMigration()).
    $className = preg_replace('/^\d+_/', '', pathinfo($file, PATHINFO_FILENAME));
    $fileContent = file_get_contents($file);
    $namespace = '';
    if (preg_match('/namespace\s+([^;]+);/i', (string) $fileContent, $m)) {
        $namespace = $m[1] . '\\';
    }
    $fullClassName = $namespace . $className;
    if (!class_exists($fullClassName)) {
        if (!class_exists($className)) {
            throw DatabaseException::queryFailed('MIGRATION_ERROR', "Migration class $className not found in $file");
        }
        $fullClassName = $className;
    }

    $migration = new $fullClassName();
    if (!$migration instanceof MigrationInterface) {
        throw BusinessLogicException::operationNotAllowed(
            'migration_validation',
            "Migration $fullClassName must implement MigrationInterface"
        );
    }

    try {
        $migration->down($this->schema);
        $this->db->table(self::VERSION_TABLE)
            ->where('migration', $filename)
            ->where('source', $source)
            ->delete();
        return ['success' => true, 'file' => $filename];
    } catch (\Exception $e) {
        error_log("Rollback failed: " . $e->getMessage());
        return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Database/Migrations/MigrationOrderingTest.php`
Expected: PASS (all ordering + rollback tests).

- [ ] **Step 5: Commit**

```bash
git add src/Database/Migrations/MigrationManager.php tests/Integration/Database/Migrations/MigrationOrderingTest.php
git commit -m "feat(migrations): package-scoped rollback by (source, migration)"
```

---

## Task 7: `ServiceProvider::loadMigrationsFrom($dir, $priority, $source)`

**Files:**
- Modify: `src/Extensions/ServiceProvider.php` — `loadMigrationsFrom()` (lines ~131–139)

- [ ] **Step 1: Write the failing test**

Add `tests/Unit/Extensions/LoadMigrationsFromTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class LoadMigrationsFromTest extends TestCase
{
    public function test_forwards_priority_and_source_to_manager(): void
    {
        $dir = sys_get_temp_dir() . '/lmf-' . uniqid();
        mkdir($dir, 0755, true);

        $mm = $this->createMock(MigrationManager::class);
        $mm->expects(self::once())
            ->method('addMigrationPath')
            ->with($dir, MigrationPriority::IDENTITY, 'glueful/users');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($mm);

        $provider = new class ($container) extends ServiceProvider {
            public function callLoad(string $d): void
            {
                $this->loadMigrationsFrom($d, MigrationPriority::IDENTITY, 'glueful/users');
            }
        };
        $provider->callLoad($dir);

        rmdir($dir);
    }
}
```

> `MigrationManager` is not `final`, so it is mockable. Confirm the `ServiceProvider` constructor accepts the container in the way this anonymous subclass uses it (mirror the constructor signature used by the provider stubs in `AggregatePermissionCatalogTest.php`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/LoadMigrationsFromTest.php`
Expected: FAIL — `loadMigrationsFrom()` ignores extra args / `addMigrationPath` called with 1 arg.

- [ ] **Step 3: Write minimal implementation**

Replace `loadMigrationsFrom()`:

```php
protected function loadMigrationsFrom(
    string $dir,
    int $priority = \Glueful\Database\Migrations\MigrationPriority::DEFAULT,
    ?string $source = null
): void {
    if (!is_dir($dir) || !$this->app->has(MigrationManager::class)) {
        return;
    }
    /** @var MigrationManager $mm */
    $mm = $this->app->get(MigrationManager::class);
    $mm->addMigrationPath($dir, $priority, $source);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/LoadMigrationsFromTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ServiceProvider.php tests/Unit/Extensions/LoadMigrationsFromTest.php
git commit -m "feat(extensions): loadMigrationsFrom accepts priority + source"
```

---

## Task 8: Full-suite verification + static analysis

- [ ] **Step 1: Run the migration test group**

Run: `vendor/bin/phpunit tests/Unit/Database/Migrations tests/Integration/Database/Migrations`
Expected: PASS.

- [ ] **Step 2: Run the full suite (catch regressions in existing migrate/rollback callers)**

Run: `composer test`
Expected: PASS. The two known consumers of `additionalMigrationPaths` as a flat string list — `getMigrationStatus()` and `rollbackMigration()` — are handled by Tasks 5 and 6. Grep for any remaining `additionalMigrationPaths` usage that still treats entries as strings (expects `$dir`, not `$entry['path']`) and fix it to the structured form.

- [ ] **Step 3: Static analysis + style**

Run: `composer run analyse:changed && composer run phpcs`
Expected: clean. Fix the `array<int,array{...}>` shape annotations on `$additionalMigrationPaths` and the new methods if PHPStan complains.

- [ ] **Step 4: Commit any fixups**

```bash
git add -A
git commit -m "test(migrations): phase 1 verification + analysis fixups"
```

---

## Phase 1 done-when

- `MigrationPriority` tiers exist; `loadMigrationsFrom()` accepts `$priority` + `$source`.
- Pending migrations sort by `(priority, basename)`; a lower-priority source provably runs before a higher one regardless of basename.
- Two sources can ship the same basename and both apply; applied rows record the owning `source`.
- `composer test`, `analyse:changed`, `phpcs` all green.
- **Do not start Phase 4 until this is merged on `dev`.** Phase 2 may begin now.
