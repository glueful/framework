# SQLite Alteration Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace SQLite's six silently-failing alter operations with one audited, atomic create-copy-swap rebuild — an alteration either produces the requested schema completely or fails explicitly before mutation.

**Architecture:** New `Glueful\Database\Schema\Sqlite` namespace: a quote/comment-aware
SQL scanner, a lossless per-table snapshot built from PRAGMAs + `sqlite_schema`, a
canonical alteration plan, and a rebuilder that audits → plans → executes → verifies.
`TableBuilder::executeAlterations()` gets a driver-neutral compile fix and dispatches
rebuild-triggering plans to `SchemaBuilder::executeSqliteRebuild()`; native-only SQLite
plans go through `executeSqliteNativeAlteration()` so a multi-statement call is atomic.
Both seams flush earlier queued SQL first. The public builder gains reachable table
rename, unsupported stored directives fail loudly, and generator comment no-ops become
throws.

**Tech Stack:** PHP 8.3, PDO SQLite, PHPUnit 10 attributes, PHPStan level 8 (`phpVersion: 80300`, no baseline), PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-07-sqlite-alteration-correctness-design.md` — the authority. Read it before starting.

## Global Constraints

- PHP 8.3; no new composer dependencies. PHPStan level 8, no baseline/suppressions. PSR-12, 120 cols (note: `tests/` is excluded from phpcs).
- Gates before every commit (full-tree, CI-equivalent): `vendor/bin/phpunit` && `composer run phpcs` && `vendor/bin/phpstan clear-result-cache && composer run analyse`.
- Work on `dev`; never push; no AI/Claude attribution; stage exact paths; never stage `CLAUDE.md`; spec + plan stay uncommitted. Commits only at the three checkpoints marked below.
- **The contract (spec):** an alteration on SQLite either produces the requested schema completely, or fails explicitly and atomically, before or without mutating the original table. No comment-SQL, no silent no-op, anywhere.
- **No SQL text splicing.** The stored `sqlite_schema` SQL is evidence and verbatim-replay material only; the new table's DDL comes exclusively from `SQLiteSqlGenerator::createTable()`.
- `PRAGMA defer_foreign_keys` must NOT be used (DROP TABLE executes CASCADE/SET NULL actions regardless of deferral).
- Exception: `Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException` carrying table, operation, feature, reason.
- **`legacy_alter_table` is per-rename, not per-operation.** Capture the prior value once
  before any mutation and restore + verify it in `finally`. The rebuild's *internal* swap
  rename (`ALTER TABLE <table>__rebuild_<hex> RENAME TO <table>`, right after
  `DROP TABLE <table>`) runs with it forced **ON** (read-back verified) — with the modern
  default OFF, SQLite rewrites dependent view/trigger bodies at rename time and fails with
  `error in view …: no such table: main.<table>` because the original table was just
  dropped, which would break every rebuild of a table any view or external trigger
  references. The *final user-visible* rename (`rename_table`) runs with it forced **OFF**
  (read-back verified) and requires SQLite ≥ 3.26.0, because there the non-legacy rewrite
  of inbound FKs and dependent trigger/view bodies is the wanted behavior.
- `PRAGMA foreign_key_check` always global (never table-scoped), run before mutation and before commit.

---

### Task 1: UnsupportedSchemaOperationException + SqliteAlterationPlan

**Files:**
- Create: `src/Database/Schema/Exceptions/UnsupportedSchemaOperationException.php`
- Create: `src/Database/Schema/Sqlite/SqliteAlterationPlan.php`
- Test: `tests/Unit/Database/Schema/Sqlite/SqliteAlterationPlanTest.php`

**Interfaces:**
- Consumes: existing DTOs `Glueful\Database\Schema\DTOs\{ColumnDefinition, IndexDefinition, ForeignKeyDefinition}`.
- Produces (later tasks depend on these exact names):
  `UnsupportedSchemaOperationException::forFeature(string $table, string $operation, string $feature, string $reason): self` plus accessors `table()`, `operation()`, `feature()`, `reason()`;
  `SqliteAlterationPlan::fromChanges(string $table, array $changes): self`, accessors `table(): string`, `addColumns(): array`, `modifyColumns(): array`, `dropColumns(): array`, `renameColumns(): array` (map from→to), `addIndexes(): array`, `dropIndexes(): array`, `addForeignKeys(): array`, `dropForeignKeys(): array`, `renameTable(): ?string`, `requiresRebuild(): bool`, `isEmpty(): bool`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Schema/Sqlite/SqliteAlterationPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Sqlite\SqliteAlterationPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteAlterationPlanTest extends TestCase
{
    #[Test]
    public function fromChangesAcceptsTheFullVocabulary(): void
    {
        $plan = SqliteAlterationPlan::fromChanges('users', [
            'add_columns' => [new ColumnDefinition(name: 'age', type: 'integer')],
            'modify_columns' => [new ColumnDefinition(name: 'email', type: 'string')],
            'drop_columns' => ['legacy'],
            'rename_columns' => ['old_name' => 'new_name'],
            'add_indexes' => [new IndexDefinition(columns: ['age'], name: 'users_age_index')],
            'drop_indexes' => ['users_legacy_index'],
            'add_foreign_keys' => [new ForeignKeyDefinition(
                localColumn: 'team_id',
                referencedTable: 'teams',
                referencedColumn: 'id',
                name: 'users_team_id_fk'
            )],
            'drop_foreign_keys' => ['users_team_id_fk'],
            'rename_table' => 'members',
        ]);

        $this->assertSame('users', $plan->table());
        $this->assertCount(1, $plan->addColumns());
        $this->assertCount(1, $plan->modifyColumns());
        $this->assertSame(['legacy'], $plan->dropColumns());
        $this->assertSame(['old_name' => 'new_name'], $plan->renameColumns());
        $this->assertCount(1, $plan->addIndexes());
        $this->assertSame(['users_legacy_index'], $plan->dropIndexes());
        $this->assertCount(1, $plan->addForeignKeys());
        $this->assertSame(['users_team_id_fk'], $plan->dropForeignKeys());
        $this->assertSame('members', $plan->renameTable());
    }

    #[Test]
    public function unknownChangeTypesThrow(): void
    {
        $this->expectException(UnsupportedSchemaOperationException::class);
        SqliteAlterationPlan::fromChanges('users', ['recolor_columns' => ['a']]);
    }

    #[Test]
    public function malformedKnownChangePayloadThrows(): void
    {
        $this->expectException(UnsupportedSchemaOperationException::class);
        SqliteAlterationPlan::fromChanges('users', ['drop_columns' => [42]]);
    }

    #[Test]
    public function requiresRebuildOnlyForRebuildTriggeringKeys(): void
    {
        $native = SqliteAlterationPlan::fromChanges('users', [
            'add_columns' => [new ColumnDefinition(name: 'age', type: 'integer')],
            'add_indexes' => [],
            'drop_indexes' => ['users_x_index'],
            'rename_columns' => ['a' => 'b'],
            'rename_table' => 'members',
        ]);
        $this->assertFalse($native->requiresRebuild());

        foreach (
            [
                ['modify_columns' => [new ColumnDefinition(name: 'e', type: 'text')]],
                ['drop_columns' => ['e']],
                ['add_foreign_keys' => [new ForeignKeyDefinition(
                    localColumn: 'x',
                    referencedTable: 't',
                    referencedColumn: 'id',
                    name: 'fk_x'
                )]],
                ['drop_foreign_keys' => ['fk_x']],
                // Dropping a constraint-backed auto-index is impossible natively
                // (SQLite refuses DROP INDEX on it), so it is a rebuild trigger.
                ['drop_indexes' => ['sqlite_autoindex_users_1']],
            ] as $changes
        ) {
            $this->assertTrue(SqliteAlterationPlan::fromChanges('users', $changes)->requiresRebuild());
        }
    }

    #[Test]
    public function emptyPlanReportsEmpty(): void
    {
        $this->assertTrue(SqliteAlterationPlan::fromChanges('users', [])->isEmpty());
    }

    #[Test]
    public function exceptionCarriesItsFourFields(): void
    {
        $e = UnsupportedSchemaOperationException::forFeature(
            'users',
            'modify_columns',
            'generated column "total"',
            'SQLite generated columns cannot be recreated by the rebuild'
        );

        $this->assertSame('users', $e->table());
        $this->assertSame('modify_columns', $e->operation());
        $this->assertSame('generated column "total"', $e->feature());
        $this->assertStringContainsString('users', $e->getMessage());
        $this->assertStringContainsString('generated column "total"', $e->getMessage());
        $this->assertStringContainsString('cannot be recreated', $e->getMessage());
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/phpunit tests/Unit/Database/Schema/Sqlite/SqliteAlterationPlanTest.php` → class-not-found ERROR.

- [ ] **Step 3: Implement the exception**

`src/Database/Schema/Exceptions/UnsupportedSchemaOperationException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Exceptions;

/**
 * A schema alteration cannot be performed safely.
 *
 * Thrown during preflight, before any DDL executes — the original table is
 * never touched. Carries enough structure for callers and logs to say
 * exactly what was rejected and why preservation cannot be guaranteed.
 */
class UnsupportedSchemaOperationException extends \RuntimeException
{
    protected string $tableName = '';
    protected string $operationName = '';
    protected string $featureName = '';
    protected string $reasonText = '';

    public static function forFeature(string $table, string $operation, string $feature, string $reason): self
    {
        $exception = new self(sprintf(
            'Unsupported schema operation on table "%s" (%s): %s — %s',
            $table,
            $operation,
            $feature,
            $reason
        ));
        $exception->tableName = $table;
        $exception->operationName = $operation;
        $exception->featureName = $feature;
        $exception->reasonText = $reason;

        return $exception;
    }

    public function table(): string
    {
        return $this->tableName;
    }

    public function operation(): string
    {
        return $this->operationName;
    }

    public function feature(): string
    {
        return $this->featureName;
    }

    public function reason(): string
    {
        return $this->reasonText;
    }
}
```

- [ ] **Step 4: Implement the plan DTO**

`src/Database/Schema/Sqlite/SqliteAlterationPlan.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;

/**
 * Canonical, validated alteration change-set for one SQLite table.
 *
 * The single dispatch artifact: built from the builder's compiled changes
 * before deciding between native SQL and a table rebuild. Unknown change
 * types throw — nothing is ever ignored.
 */
final class SqliteAlterationPlan
{
    private const KNOWN_KEYS = [
        'add_columns', 'modify_columns', 'drop_columns', 'rename_columns',
        'add_indexes', 'drop_indexes', 'add_foreign_keys', 'drop_foreign_keys',
        'rename_table',
    ];

    /**
     * @param list<ColumnDefinition> $addColumns
     * @param list<ColumnDefinition> $modifyColumns
     * @param list<string> $dropColumns
     * @param array<string, string> $renameColumns
     * @param list<IndexDefinition> $addIndexes
     * @param list<string> $dropIndexes
     * @param list<ForeignKeyDefinition> $addForeignKeys
     * @param list<string> $dropForeignKeys
     */
    private function __construct(
        private readonly string $table,
        private readonly array $addColumns,
        private readonly array $modifyColumns,
        private readonly array $dropColumns,
        private readonly array $renameColumns,
        private readonly array $addIndexes,
        private readonly array $dropIndexes,
        private readonly array $addForeignKeys,
        private readonly array $dropForeignKeys,
        private readonly ?string $renameTable,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function fromChanges(string $table, array $changes): self
    {
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw UnsupportedSchemaOperationException::forFeature(
                    $table,
                    (string) $key,
                    'unknown change type',
                    'the alteration vocabulary does not include this change; refusing to ignore it'
                );
            }
        }

        $renameTable = null;
        if (array_key_exists('rename_table', $changes)) {
            if (!is_string($changes['rename_table']) || $changes['rename_table'] === '') {
                self::malformed($table, 'rename_table');
            }
            $renameTable = $changes['rename_table'];
        }

        $addColumns = self::instances($table, 'add_columns', $changes['add_columns'] ?? [], ColumnDefinition::class);
        $modifyColumns = self::instances(
            $table,
            'modify_columns',
            $changes['modify_columns'] ?? [],
            ColumnDefinition::class
        );
        $addIndexes = self::instances($table, 'add_indexes', $changes['add_indexes'] ?? [], IndexDefinition::class);
        $addForeignKeys = self::instances(
            $table,
            'add_foreign_keys',
            $changes['add_foreign_keys'] ?? [],
            ForeignKeyDefinition::class
        );

        return new self(
            table: $table,
            addColumns: $addColumns,
            modifyColumns: $modifyColumns,
            dropColumns: self::strings($table, 'drop_columns', $changes['drop_columns'] ?? []),
            renameColumns: self::renameMap($table, $changes['rename_columns'] ?? []),
            addIndexes: $addIndexes,
            dropIndexes: self::strings($table, 'drop_indexes', $changes['drop_indexes'] ?? []),
            addForeignKeys: $addForeignKeys,
            dropForeignKeys: self::strings($table, 'drop_foreign_keys', $changes['drop_foreign_keys'] ?? []),
            renameTable: $renameTable,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function instances(string $table, string $key, mixed $value, string $class): array
    {
        if (!is_array($value)) {
            self::malformed($table, $key);
        }
        foreach ($value as $item) {
            if (!$item instanceof $class) {
                self::malformed($table, $key);
            }
        }

        return array_values($value);
    }

    /** @return list<string> */
    private static function strings(string $table, string $key, mixed $value): array
    {
        if (!is_array($value)) {
            self::malformed($table, $key);
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                self::malformed($table, $key);
            }
        }

        return array_values($value);
    }

    /** @return array<string, string> */
    private static function renameMap(string $table, mixed $value): array
    {
        if (!is_array($value)) {
            self::malformed($table, 'rename_columns');
        }
        foreach ($value as $from => $to) {
            if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
                self::malformed($table, 'rename_columns');
            }
        }

        return $value;
    }

    private static function malformed(string $table, string $key): never
    {
        throw UnsupportedSchemaOperationException::forFeature(
            $table,
            $key,
            'malformed change payload',
            'the change value does not match the canonical alteration-plan shape'
        );
    }

    public function table(): string
    {
        return $this->table;
    }

    /** @return list<ColumnDefinition> */
    public function addColumns(): array
    {
        return $this->addColumns;
    }

    /** @return list<ColumnDefinition> */
    public function modifyColumns(): array
    {
        return $this->modifyColumns;
    }

    /** @return list<string> */
    public function dropColumns(): array
    {
        return $this->dropColumns;
    }

    /** @return array<string, string> */
    public function renameColumns(): array
    {
        return $this->renameColumns;
    }

    /** @return list<IndexDefinition> */
    public function addIndexes(): array
    {
        return $this->addIndexes;
    }

    /** @return list<string> */
    public function dropIndexes(): array
    {
        return $this->dropIndexes;
    }

    /** @return list<ForeignKeyDefinition> */
    public function addForeignKeys(): array
    {
        return $this->addForeignKeys;
    }

    /** @return list<string> */
    public function dropForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    public function renameTable(): ?string
    {
        return $this->renameTable;
    }

    public function requiresRebuild(): bool
    {
        return $this->modifyColumns !== []
            || $this->dropColumns !== []
            || $this->addForeignKeys !== []
            || $this->dropForeignKeys !== []
            || $this->dropsAutoIndex();
    }

    /**
     * SQLite refuses DROP INDEX on a constraint-backed auto-index, so removing
     * one is only possible by regenerating the table.
     */
    private function dropsAutoIndex(): bool
    {
        foreach ($this->dropIndexes as $index) {
            if (stripos($index, 'sqlite_autoindex_') === 0) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->addColumns === [] && $this->modifyColumns === [] && $this->dropColumns === []
            && $this->renameColumns === [] && $this->addIndexes === [] && $this->dropIndexes === []
            && $this->addForeignKeys === [] && $this->dropForeignKeys === [] && $this->renameTable === null;
    }
}
```

The rebuild-triggering set is expressed once, directly in `requiresRebuild()`; there is
no unused `REBUILD_KEYS` constant. A `drop_indexes` entry whose name matches
`sqlite_autoindex_%` belongs to that set: SQLite refuses `DROP INDEX` on an index that
backs a UNIQUE/PRIMARY KEY constraint, so a native drop cannot work and the constraint
can only be removed by rebuilding the table. Drops of ordinary `CREATE INDEX` indexes
stay native. `fromChanges()` must also validate the value shape of
every known key (DTO instances, string lists, and string→string rename map) and convert a
malformed known key into `UnsupportedSchemaOperationException` rather than allowing a
later `TypeError` or silently coercing it.

- [ ] **Step 5: Run to verify pass** — `vendor/bin/phpunit tests/Unit/Database/Schema/Sqlite/` → PASS.

---

### Task 2: SqliteSqlScanner

**Files:**
- Create: `src/Database/Schema/Sqlite/SqliteSqlScanner.php`
- Test: `tests/Unit/Database/Schema/Sqlite/SqliteSqlScannerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  `identifiers(string $sql): array` — list of every bare/quoted identifier token (lower-cased, deduplicated), skipping string literals, comments, and SQL keywords;
  `extractChecks(string $createTableSql): array` — list of
  `array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string}`;
  ownership is derived from quote/comment-aware top-level CREATE TABLE clause splitting,
  never inferred from identifier count; throws `\RuntimeException` when scanning cannot
  complete (caller converts to the schema exception);
  `isEnumCheckShape(string $expression, string $column): bool` — exactly the framework's enum shape `<col> IN (…)` (optionally with quoted `<col>`);
  `rewriteEnumCheckColumn(string $expression, string $from, string $to): string`;
  `hasKeywordOutsideParens(string $createTableSql, string $keyword): bool` — for `AUTOINCREMENT` (anywhere) and trailing `WITHOUT ROWID` / `STRICT` detection;
  `containsKeyword(string $sql, string $keyword): bool` — quote/comment-aware whole-word search (for `COLLATE`, `MATCH`, `ON CONFLICT` detection).
  `containsUnquotedAsterisk(string $sql): bool` — true only for a structural `*` token,
  used to make view `SELECT *` dependencies fail closed.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Schema/Sqlite/SqliteSqlScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\Sqlite\SqliteSqlScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteSqlScannerTest extends TestCase
{
    private SqliteSqlScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new SqliteSqlScanner();
    }

    #[Test]
    public function extractsColumnLevelAndTableLevelChecks(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE "orders" (
          "id" INTEGER PRIMARY KEY AUTOINCREMENT,
          "status" TEXT NOT NULL CHECK ("status" IN ('draft', 'sent')),
          "qty" INTEGER NOT NULL,
          "price" REAL,
          CHECK ("qty" > 0 AND "price" >= 0)
        )
        SQL;

        $checks = $this->scanner->extractChecks($sql);

        $this->assertCount(2, $checks);
        $this->assertSame('"status" IN (\'draft\', \'sent\')', $checks[0]['expression']);
        $this->assertSame(['status'], $checks[0]['identifiers']);
        $this->assertSame('column', $checks[0]['scope']);
        $this->assertSame('status', $checks[0]['column']);
        $this->assertSame('"qty" > 0 AND "price" >= 0', $checks[1]['expression']);
        $this->assertSame(['qty', 'price'], $checks[1]['identifiers']);
        $this->assertSame('table', $checks[1]['scope']);
        $this->assertNull($checks[1]['column']);
    }

    #[Test]
    public function checkScannerSurvivesQuotesCommentsAndNesting(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE t (
          a TEXT CHECK (a NOT IN ('it''s', 'we(ird)', 'x -- not a comment')), -- real comment CHECK (bogus)
          /* CHECK (also bogus) */
          b INTEGER CHECK ((b + 1) > (0))
        )
        SQL;

        $checks = $this->scanner->extractChecks($sql);

        $this->assertCount(2, $checks);
        $this->assertStringContainsString("'it''s'", $checks[0]['expression']);
        $this->assertSame(['a'], $checks[0]['identifiers']);
        $this->assertSame('(b + 1) > (0)', $checks[1]['expression']);
        $this->assertSame(['b'], $checks[1]['identifiers']);
    }

    #[Test]
    public function unterminatedConstructsThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->scanner->extractChecks("CREATE TABLE t (a TEXT CHECK (a IN ('unterminated))");
    }

    #[Test]
    public function ownershipDoesNotDependOnIdentifierCount(): void
    {
        $checks = $this->scanner->extractChecks(<<<'SQL'
        CREATE TABLE t (
          a INTEGER CHECK (a < b),
          b INTEGER,
          CHECK (a > 0)
        )
        SQL);

        $this->assertSame('column', $checks[0]['scope']);
        $this->assertSame('a', $checks[0]['column']);
        $this->assertSame(['a', 'b'], $checks[0]['identifiers']);
        $this->assertSame('table', $checks[1]['scope']);
        $this->assertNull($checks[1]['column']);
        $this->assertSame(['a'], $checks[1]['identifiers']);
    }

    #[Test]
    public function checkScannerHandlesCommentsAndBracketIdentifiersInsideExpression(): void
    {
        $checks = $this->scanner->extractChecks(
            'CREATE TABLE t ([a] INTEGER CHECK ([a] /* ) ignored */ > (0)))'
        );

        $this->assertSame(['a'], $checks[0]['identifiers']);
        $this->assertSame('column', $checks[0]['scope']);
    }

    #[Test]
    public function identifiersSkipsLiteralsCommentsAndKeywords(): void
    {
        $ids = $this->scanner->identifiers(
            'SELECT "colA", colB FROM t WHERE colB = \'not_an_id\' -- ghost_id' . "\n" . '/* ghost2 */'
        );

        $this->assertContains('cola', $ids);
        $this->assertContains('colb', $ids);
        $this->assertContains('t', $ids);
        $this->assertNotContains('not_an_id', $ids);
        $this->assertNotContains('ghost_id', $ids);
        $this->assertNotContains('ghost2', $ids);
        $this->assertNotContains('select', $ids);
        $this->assertNotContains('where', $ids);
    }

    #[Test]
    public function enumShapeRecognitionIsExact(): void
    {
        $this->assertTrue($this->scanner->isEnumCheckShape('"status" IN (\'a\', \'b\')', 'status'));
        $this->assertTrue($this->scanner->isEnumCheckShape("status IN ('a')", 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('"status" IN (\'a\') OR 1', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('"other" IN (\'a\')', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('length("status") > 2', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('status IN ()', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('status IN (1, 2)', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape("status IN ('a' 'b')", 'status'));
    }

    #[Test]
    public function enumRewriteRenamesOnlyTheColumn(): void
    {
        $this->assertSame(
            '"state" IN (\'a\', \'status\')',
            $this->scanner->rewriteEnumCheckColumn('"status" IN (\'a\', \'status\')', 'status', 'state')
        );
    }

    #[Test]
    public function keywordDetectionIsQuoteAware(): void
    {
        $sql = 'CREATE TABLE t (a TEXT DEFAULT \'COLLATE\', b TEXT) WITHOUT ROWID';

        $this->assertFalse($this->scanner->containsKeyword($sql, 'COLLATE'));
        $this->assertTrue($this->scanner->hasKeywordOutsideParens($sql, 'WITHOUT ROWID'));
        $this->assertFalse($this->scanner->hasKeywordOutsideParens($sql, 'STRICT'));
        $this->assertTrue($this->scanner->containsKeyword(
            'CREATE TABLE t (a TEXT COLLATE NOCASE)',
            'COLLATE'
        ));
        $this->assertFalse($this->scanner->containsKeyword(
            'CREATE TABLE t ("collate" TEXT)',
            'COLLATE'
        ));
        $this->assertTrue($this->scanner->containsUnquotedAsterisk('SELECT * FROM t'));
        $this->assertFalse($this->scanner->containsUnquotedAsterisk("SELECT '*' FROM t"));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — class-not-found ERROR.

- [ ] **Step 3: Implement the scanner**

`src/Database/Schema/Sqlite/SqliteSqlScanner.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

/**
 * Character-level scanner for SQLite DDL/SQL text.
 *
 * Understands single-quoted string literals, double/backtick/bracket quoted
 * identifiers (including doubled quote escapes), -- and slash-star comments, and
 * parenthesis depth. It is NOT a SQL parser: it answers the narrow
 * questions the rebuild audit needs, and throws rather than guessing when
 * a construct is unterminated.
 */
final class SqliteSqlScanner
{
    private const KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'in', 'is', 'null', 'like', 'glob',
        'between', 'case', 'when', 'then', 'else', 'end', 'exists', 'cast', 'as', 'on',
        'create', 'table', 'view', 'trigger', 'index', 'if', 'temp', 'temporary',
        'primary', 'key', 'unique', 'check', 'default', 'references', 'foreign',
        'constraint', 'collate', 'autoincrement', 'without', 'rowid', 'strict',
        'conflict', 'match', 'deferrable', 'initially', 'virtual',
        'integer', 'text', 'real', 'blob', 'numeric', 'varchar', 'boolean', 'datetime',
        'insert', 'update', 'delete', 'begin', 'instead', 'of', 'for', 'each', 'row',
        'values', 'set', 'join', 'left', 'inner', 'outer', 'group', 'by', 'order',
        'asc', 'desc', 'limit', 'distinct', 'union', 'all', 'current_timestamp',
        'current_date', 'current_time', 'true', 'false',
    ];

    /**
     * Tokenize into structural events. Each event is one of:
     * ['ident', string $nameLower], ['word', string $wordLower],
     * ['literal', string $rawLiteral], ['punct', string $char]. Comments
     * are consumed silently.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // -- line comment
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            // /* block comment */
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            // 'string literal' with '' escape. Keep a literal event so the
            // enum-shape validator can prove the IN-list contains exactly
            // comma-separated string literals; dependency scans still ignore it.
            if ($char === "'") {
                $end = $this->consumeQuoted($sql, $i, "'");
                $tokens[] = ['literal', substr($sql, $i, $end - $i)];
                $i = $end;
                continue;
            }
            // "quoted identifier" with "" escape
            if ($char === '"') {
                $end = $this->consumeQuoted($sql, $i, '"');
                $raw = substr($sql, $i + 1, $end - $i - 2);
                $tokens[] = ['ident', strtolower(str_replace('""', '"', $raw))];
                $i = $end;
                continue;
            }
            // `backtick identifier`
            if ($char === '`') {
                $end = $this->consumeQuoted($sql, $i, '`');
                $tokens[] = ['ident', strtolower(substr($sql, $i + 1, $end - $i - 2))];
                $i = $end;
                continue;
            }
            // [bracket identifier]
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $tokens[] = ['ident', strtolower(substr($sql, $i + 1, $close - $i - 1))];
                $i = $close + 1;
                continue;
            }
            // bare word
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $j = $i + 1;
                while ($j < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                    $j++;
                }
                $word = strtolower(substr($sql, $i, $j - $i));
                $tokens[] = [in_array($word, self::KEYWORDS, true) ? 'word' : 'ident', $word];
                $i = $j;
                continue;
            }
            if ($char === '(' || $char === ')' || $char === ',' || $char === '*') {
                $tokens[] = ['punct', $char];
            }
            $i++;
        }

        return $tokens;
    }

    /** @return int Position just past the closing quote */
    private function consumeQuoted(string $sql, int $start, string $quote): int
    {
        $i = $start + 1;
        $length = strlen($sql);
        while ($i < $length) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }

        throw new \RuntimeException("Unterminated {$quote}-quoted token in SQL");
    }

    /**
     * Every identifier referenced in the SQL, lower-cased, deduplicated.
     *
     * @return list<string>
     */
    public function identifiers(string $sql): array
    {
        $out = [];
        foreach ($this->tokenize($sql) as [$kind, $value]) {
            if ($kind === 'ident' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Extract every CHECK (...) expression from a CREATE TABLE statement.
     * Ownership comes from the top-level clause containing the CHECK, not
     * from how many identifiers happen to occur in its expression.
     *
     * @return list<array{
     *   expression: string,
     *   identifiers: list<string>,
     *   scope: 'column'|'table',
     *   column: ?string
     * }>
     */
    public function extractChecks(string $createTableSql): array
    {
        $checks = [];
        foreach ($this->topLevelTableClauses($createTableSql) as $clause) {
            [$scope, $column] = $this->classifyTableClause($clause);
            foreach ($this->extractCheckExpressions($clause) as $check) {
                $checks[] = [
                    ...$check,
                    'scope' => $scope,
                    'column' => $column,
                ];
            }
        }

        return $checks;
    }

    /**
     * @return list<array{expression: string, identifiers: list<string>}>
     */
    private function extractCheckExpressions(string $sql): array
    {
        $checks = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in CREATE TABLE SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in CREATE TABLE SQL');
                }
                $i = $close + 1;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $j = $i + 1;
                while ($j < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                    $j++;
                }
                $word = strtolower(substr($sql, $i, $j - $i));
                $i = $j;
                if ($word === 'check') {
                    $open = $this->nextCodePosition($sql, $i);
                    if ($open === null || $sql[$open] !== '(') {
                        throw new \RuntimeException('CHECK keyword without parenthesized expression');
                    }
                    $close = $this->matchParen($sql, $open);
                    $expression = trim(substr($sql, $open + 1, $close - $open - 1));
                    $checks[] = [
                        'expression' => $expression,
                        'identifiers' => $this->identifiers($expression),
                    ];
                    $i = $close + 1;
                }
                continue;
            }
            $i++;
        }

        return $checks;
    }

    private function nextCodePosition(string $sql, int $from): ?int
    {
        $length = strlen($sql);
        $i = $from;
        while ($i < $length) {
            if (preg_match('/\s/', $sql[$i]) === 1) {
                $i++;
                continue;
            }
            if ($sql[$i] === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @return list<string> */
    private function topLevelTableClauses(string $sql): array
    {
        $tokensStart = $this->nextCodePosition($sql, 0);
        if ($tokensStart === null) {
            throw new \RuntimeException('Empty CREATE TABLE SQL');
        }

        // Find the CREATE TABLE column-list opener while honoring every
        // quoted/comment form. Parentheses in comments or identifiers do not count.
        $open = null;
        $length = strlen($sql);
        for ($i = $tokensStart; $i < $length;) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $open = $i;
                break;
            }
            $i++;
        }
        if ($open === null) {
            throw new \RuntimeException('CREATE TABLE SQL has no column list');
        }

        $close = $this->matchParen($sql, $open);
        $body = substr($sql, $open + 1, $close - $open - 1);
        $clauses = [];
        $start = 0;
        $depth = 0;
        $bodyLength = strlen($body);
        for ($i = 0; $i < $bodyLength;) {
            $char = $body[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($body, $i, $char);
                continue;
            }
            if ($char === '[') {
                $end = strpos($body, ']', $i + 1);
                if ($end === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in table clause');
                }
                $i = $end + 1;
                continue;
            }
            if ($char === '-' && ($body[$i + 1] ?? '') === '-') {
                $newline = strpos($body, "\n", $i + 2);
                $i = $newline === false ? $bodyLength : $newline + 1;
                continue;
            }
            if ($char === '/' && ($body[$i + 1] ?? '') === '*') {
                $end = strpos($body, '*/', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException('Unterminated block comment in table clause');
                }
                $i = $end + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('Unbalanced table-clause parentheses');
                }
            } elseif ($char === ',' && $depth === 0) {
                $clauses[] = trim(substr($body, $start, $i - $start));
                $start = $i + 1;
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new \RuntimeException('Unbalanced table-clause parentheses');
        }
        $clauses[] = trim(substr($body, $start));

        return array_values(array_filter($clauses, static fn (string $clause): bool => $clause !== ''));
    }

    /** @return array{0: 'column'|'table', 1: ?string} */
    private function classifyTableClause(string $clause): array
    {
        $tokens = $this->tokenize($clause);
        if ($tokens === []) {
            throw new \RuntimeException('Empty CREATE TABLE clause');
        }
        [$kind, $value] = $tokens[0];
        if ($kind === 'ident') {
            return ['column', $value];
        }
        if ($kind === 'word' && in_array($value, ['constraint', 'check', 'primary', 'unique', 'foreign'], true)) {
            return ['table', null];
        }

        throw new \RuntimeException('Cannot determine CREATE TABLE clause ownership');
    }

    /** @return int Position of the matching close paren */
    private function matchParen(string $sql, int $openPos): int
    {
        $depth = 0;
        $length = strlen($sql);
        $i = $openPos;

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }

        throw new \RuntimeException('Unbalanced parentheses in SQL');
    }

    /**
     * Exactly the framework's enum-emulation shape: `<col> IN (<literals>)`,
     * where <col> may be bare or quoted, and nothing else surrounds it.
     */
    public function isEnumCheckShape(string $expression, string $column): bool
    {
        $tokens = $this->tokenize($expression);
        if (count($tokens) < 5) {
            return false;
        }
        [$kind0, $value0] = $tokens[0];
        [$kind1, $value1] = $tokens[1];
        if ($kind0 !== 'ident' || $value0 !== strtolower($column)) {
            return false;
        }
        if ($kind1 !== 'word' || $value1 !== 'in') {
            return false;
        }
        if ($tokens[2] !== ['punct', '(']) {
            return false;
        }
        $last = $tokens[count($tokens) - 1];
        if ($last !== ['punct', ')']) {
            return false;
        }
        // Exactly: literal (',' literal)*. Empty lists, numeric expressions,
        // adjacent literals, identifiers and subqueries are not framework enums.
        for ($i = 3, $n = count($tokens) - 1; $i < $n; $i++) {
            $offset = $i - 3;
            $expectsLiteral = $offset % 2 === 0;
            if ($expectsLiteral && $tokens[$i][0] !== 'literal') {
                return false;
            }
            if (!$expectsLiteral && $tokens[$i] !== ['punct', ',']) {
                return false;
            }
        }

        if (($n - 3) % 2 === 0) {
            return false;
        }

        return true;
    }

    /**
     * Rewrite the leading column identifier of a verified enum-shape CHECK.
     * Only call after isEnumCheckShape() returned true for $from.
     */
    public function rewriteEnumCheckColumn(string $expression, string $from, string $to): string
    {
        $quoted = '"' . str_replace('"', '""', $to) . '"';
        $pattern = '/^\s*(?:"' . preg_quote($from, '/') . '"|`' . preg_quote($from, '/') . '`|\['
            . preg_quote($from, '/') . '\]|' . preg_quote($from, '/') . ')/i';
        $result = preg_replace($pattern, $quoted, $expression, 1);
        if (!is_string($result)) {
            throw new \RuntimeException('Enum CHECK rewrite failed');
        }

        return $result;
    }

    /** Whole-word keyword present outside string literals and comments? */
    public function containsKeyword(string $sql, string $keyword): bool
    {
        $wanted = array_map('strtolower', preg_split('/\s+/', trim($keyword)) ?: []);
        $tokens = $this->tokenize($sql);
        $count = count($wanted);
        foreach (array_keys($tokens) as $i) {
            $matched = true;
            foreach ($wanted as $offset => $word) {
                if (($tokens[$i + $offset] ?? null) !== ['word', $word]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched && $count > 0) {
                return true;
            }
        }

        return false;
    }

    public function containsUnquotedAsterisk(string $sql): bool
    {
        return in_array(['punct', '*'], $this->tokenize($sql), true);
    }

    /** Keyword present at parenthesis depth zero (i.e. table options / column list tail)? */
    public function hasKeywordOutsideParens(string $sql, string $keyword): bool
    {
        $depth = 0;
        $length = strlen($sql);
        $i = 0;
        $stripped = '';

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('Unbalanced parentheses in SQL');
                }
            } elseif ($depth === 0) {
                $stripped .= $char;
            }
            $i++;
        }

        return $this->containsKeyword($stripped, $keyword);
    }
}
```

Implementation note for `extractChecks` ordering: `AUTOINCREMENT` detection uses
`containsKeyword($sql, 'AUTOINCREMENT')` (it appears inside the column list, i.e.
inside parens — `hasKeywordOutsideParens` is only for `WITHOUT ROWID`/`STRICT`, which
trail the closing paren).

- [ ] **Step 4: Run to verify pass** — `vendor/bin/phpunit tests/Unit/Database/Schema/Sqlite/` → PASS. Also run `vendor/bin/phpstan analyse src/Database/Schema/Sqlite --level=8 --memory-limit=512M --no-progress` → clean.

---

### Task 3: SqliteTableSnapshot + SqliteSchemaIntrospector

**Files:**
- Create: `src/Database/Schema/Sqlite/SqliteTableSnapshot.php`
- Create: `src/Database/Schema/Sqlite/SqliteSchemaIntrospector.php`
- Test: `tests/Unit/Database/Schema/Sqlite/SqliteSchemaIntrospectorTest.php`

**Interfaces:**
- Consumes: Task 2 `SqliteSqlScanner` (all methods).
- Produces:
  `SqliteSchemaIntrospector::__construct(\PDO $pdo, ?SqliteSqlScanner $scanner = null)`;
  `snapshot(string $table): SqliteTableSnapshot` (throws `\RuntimeException` if the table does not exist);
  `allViews(): array` — list of `array{name: string, sql: string}` for every view in `sqlite_schema`;
  `allTriggers(): array` — list of `array{name: string, table: string, sql: string}`
  for every trigger, including triggers attached to other tables whose bodies may
  reference the altered table;
  `inboundForeignKeys(string $table): array` — list of grouped FK records declared by
  every other table whose referenced table is `$table`;
  `sqliteVersionAtLeast(string $minimum): bool`.
  `SqliteTableSnapshot` public readonly properties:
  `string $table`; `string $createSql`;
  `array $columns` — list of `array{name: string, type: string, notNull: bool, default: ?string, pkOrdinal: int, hidden: int}` (from `table_xinfo`; `default` is the raw SQL text or null);
  `array $checks` — list of
  `array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string}`;
  `array $primaryKey` — list of column names in PK order;
  `bool $autoIncrement`;
  `array $foreignKeys` — list of `array{id: int, table: string, from: list<string>, to: list<string>, onUpdate: string, onDelete: string, match: string}` (grouped from `foreign_key_list`, composite-capable);
  `array $indexes` — list of `array{name: string, unique: bool, origin: string, partial: bool, sql: ?string, columns: list<?string>}` (from `index_list` + `index_xinfo`; `sql` null for auto-indexes; a null column entry means an expression index member);
  `array $triggers` — list of `array{name: string, sql: string}`;
  `bool $withoutRowid`; `bool $strict`; `?int $sequenceValue`;
  and methods `isRowidTable(): bool` (`!$withoutRowid`), `namedIndexes(): array` (indexes with non-null sql), `autoIndexes(): array`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Database/Schema/Sqlite/SqliteSchemaIntrospectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\Sqlite\SqliteSchemaIntrospector;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteSchemaIntrospectorTest extends TestCase
{
    private PDO $pdo;
    private SqliteSchemaIntrospector $introspector;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->introspector = new SqliteSchemaIntrospector($this->pdo);
    }

    #[Test]
    public function snapshotCapturesColumnsChecksPkAndAutoincrement(): void
    {
        $this->pdo->exec(<<<'SQL'
        CREATE TABLE "orders" (
          "id" INTEGER PRIMARY KEY AUTOINCREMENT,
          "status" TEXT NOT NULL DEFAULT 'draft' CHECK ("status" IN ('draft', 'sent')),
          "qty" INTEGER NOT NULL DEFAULT 1,
          "note" TEXT
        )
        SQL);
        $this->pdo->exec("INSERT INTO orders (status, qty) VALUES ('draft', 2)");

        $snapshot = $this->introspector->snapshot('orders');

        $this->assertSame('orders', $snapshot->table);
        $this->assertCount(4, $snapshot->columns);
        $this->assertSame('id', $snapshot->columns[0]['name']);
        $this->assertSame(1, $snapshot->columns[0]['pkOrdinal']);
        $this->assertTrue($snapshot->columns[1]['notNull']);
        $this->assertSame("'draft'", $snapshot->columns[1]['default']);
        $this->assertFalse($snapshot->columns[3]['notNull']);
        $this->assertSame(['id'], $snapshot->primaryKey);
        $this->assertTrue($snapshot->autoIncrement);
        $this->assertSame(1, $snapshot->sequenceValue);
        $this->assertCount(1, $snapshot->checks);
        $this->assertSame(['status'], $snapshot->checks[0]['identifiers']);
        $this->assertFalse($snapshot->withoutRowid);
        $this->assertFalse($snapshot->strict);
        $this->assertTrue($snapshot->isRowidTable());
    }

    #[Test]
    public function snapshotCapturesIndexesUniqueOriginsAndForeignKeys(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY, "region" TEXT UNIQUE)');
        $this->pdo->exec(<<<'SQL'
        CREATE TABLE "players" (
          "id" INTEGER PRIMARY KEY,
          "team_id" INTEGER NOT NULL REFERENCES "teams"("id") ON DELETE CASCADE,
          "email" TEXT UNIQUE,
          "score" INTEGER
        )
        SQL);
        $this->pdo->exec('CREATE INDEX "players_score_index" ON "players" ("score") WHERE "score" > 0');

        $snapshot = $this->introspector->snapshot('players');

        $named = $snapshot->namedIndexes();
        $this->assertCount(1, $named);
        $this->assertSame('players_score_index', $named[0]['name']);
        $this->assertTrue($named[0]['partial']);
        $this->assertIsString($named[0]['sql']);

        $auto = $snapshot->autoIndexes();
        $this->assertCount(1, $auto);
        $this->assertTrue($auto[0]['unique']);
        $this->assertSame(['email'], $auto[0]['columns']);

        $this->assertCount(1, $snapshot->foreignKeys);
        $fk = $snapshot->foreignKeys[0];
        $this->assertSame('teams', $fk['table']);
        $this->assertSame(['team_id'], $fk['from']);
        $this->assertSame(['id'], $fk['to']);
        $this->assertSame('CASCADE', $fk['onDelete']);
    }

    #[Test]
    public function snapshotCapturesTriggersOptionsAndCompositeForeignKeys(): void
    {
        $this->pdo->exec('CREATE TABLE parents (a TEXT, b TEXT, PRIMARY KEY (a, b)) WITHOUT ROWID');
        $this->pdo->exec(<<<'SQL'
        CREATE TABLE children (
          x TEXT,
          y TEXT,
          FOREIGN KEY (x, y) REFERENCES parents (a, b)
        )
        SQL);
        $this->pdo->exec(
            'CREATE TRIGGER children_touch AFTER INSERT ON children BEGIN SELECT 1; END'
        );

        $parents = $this->introspector->snapshot('parents');
        $this->assertTrue($parents->withoutRowid);
        $this->assertSame(['a', 'b'], $parents->primaryKey);
        $this->assertNull($parents->sequenceValue);

        $children = $this->introspector->snapshot('children');
        $this->assertCount(1, $children->foreignKeys);
        $this->assertSame(['x', 'y'], $children->foreignKeys[0]['from']);
        $this->assertSame(['a', 'b'], $children->foreignKeys[0]['to']);
        $this->assertCount(1, $children->triggers);
        $this->assertSame('children_touch', $children->triggers[0]['name']);
    }

    #[Test]
    public function allViewsReturnsEveryViewRegardlessOfTblName(): void
    {
        $this->pdo->exec('CREATE TABLE t (a TEXT)');
        $this->pdo->exec('CREATE VIEW v_direct AS SELECT a FROM t');
        $this->pdo->exec('CREATE VIEW v_indirect AS SELECT * FROM v_direct');

        $views = $this->introspector->allViews();

        $names = array_column($views, 'name');
        $this->assertContains('v_direct', $names);
        $this->assertContains('v_indirect', $names);
    }

    #[Test]
    public function databaseWideDependenciesIncludeExternalTriggersAndInboundForeignKeys(): void
    {
        $this->pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY, legacy TEXT)');
        $this->pdo->exec(
            'CREATE TABLE child (parent_id INTEGER REFERENCES parent(id), note TEXT)'
        );
        $this->pdo->exec(
            'CREATE TRIGGER child_touch AFTER UPDATE ON child '
            . 'BEGIN UPDATE parent SET legacy = NEW.note WHERE id = NEW.parent_id; END'
        );

        $triggers = $this->introspector->allTriggers();
        $this->assertSame(['child_touch'], array_column($triggers, 'name'));
        $this->assertSame('child', $triggers[0]['table']);

        $inbound = $this->introspector->inboundForeignKeys('parent');
        $this->assertCount(1, $inbound);
        $this->assertSame('child', $inbound[0]['childTable']);
        $this->assertSame(['parent_id'], $inbound[0]['from']);
        $this->assertSame(['id'], $inbound[0]['to']);
    }

    #[Test]
    public function missingTableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->introspector->snapshot('nope');
    }

    #[Test]
    public function versionComparisonWorks(): void
    {
        $this->assertTrue($this->introspector->sqliteVersionAtLeast('3.0.0'));
        $this->assertFalse($this->introspector->sqliteVersionAtLeast('99.0.0'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — class-not-found ERROR.

- [ ] **Step 3: Implement the snapshot**

`src/Database/Schema/Sqlite/SqliteTableSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

/**
 * Lossless value object of one SQLite table's current schema, built from
 * PRAGMA introspection plus targeted scans of the stored CREATE SQL.
 *
 * The stored SQL ($createSql) is evidence only — never a mutation
 * substrate. The snapshot is deliberately more expressive than the core
 * cross-driver DTOs (composite FKs, partial/expression indexes, table
 * options) so the preservation AUDIT — not the introspector — decides
 * what is fatal.
 */
final class SqliteTableSnapshot
{
    /**
     * @param list<array{name: string, type: string, notNull: bool, default: ?string, pkOrdinal: int, hidden: int}> $columns
     * @param list<array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string}> $checks
     * @param list<string> $primaryKey
     * @param list<array{id: int, table: string, from: list<string>, to: list<string>, onUpdate: string, onDelete: string, match: string}> $foreignKeys
     * @param list<array{name: string, unique: bool, origin: string, partial: bool, sql: ?string, columns: list<?string>}> $indexes
     * @param list<array{name: string, sql: string}> $triggers
     */
    public function __construct(
        public readonly string $table,
        public readonly string $createSql,
        public readonly array $columns,
        public readonly array $checks,
        public readonly array $primaryKey,
        public readonly bool $autoIncrement,
        public readonly array $foreignKeys,
        public readonly array $indexes,
        public readonly array $triggers,
        public readonly bool $withoutRowid,
        public readonly bool $strict,
        public readonly ?int $sequenceValue,
    ) {
    }

    public function isRowidTable(): bool
    {
        return !$this->withoutRowid;
    }

    /** @return list<array{name: string, unique: bool, origin: string, partial: bool, sql: ?string, columns: list<?string>}> */
    public function namedIndexes(): array
    {
        return array_values(array_filter($this->indexes, static fn (array $ix): bool => $ix['sql'] !== null));
    }

    /** @return list<array{name: string, unique: bool, origin: string, partial: bool, sql: ?string, columns: list<?string>}> */
    public function autoIndexes(): array
    {
        return array_values(array_filter($this->indexes, static fn (array $ix): bool => $ix['sql'] === null));
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (array $c): string => $c['name'], $this->columns);
    }

    /**
     * @return array{
     *   name: string, type: string, notNull: bool, default: ?string,
     *   pkOrdinal: int, hidden: int
     * }|null
     */
    public function column(string $name): ?array
    {
        foreach ($this->columns as $column) {
            if (strcasecmp($column['name'], $name) === 0) {
                return $column;
            }
        }

        return null;
    }
}
```

PHPStan gate note: every array-returning member of this namespace must carry an explicit
shape/`list<>` docblock — level 8 with no baseline rejects a bare `array` return here. The
`SqliteAlterationPlan` accessors from Task 1 already do (`@return list<ColumnDefinition>`,
`@return list<string>`, `@return array<string, string>`); `SqliteTableSnapshot::column()`
is the one that needs the `array{...}|null` shape above, because `SqliteSnapshotMapper`
indexes into its result (`$snapshot->column($pk)['type']`) and would otherwise be reading
`mixed`.

- [ ] **Step 4: Implement the introspector**

`src/Database/Schema/Sqlite/SqliteSchemaIntrospector.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

use PDO;

/**
 * Reads a table's complete schema state from PRAGMAs and sqlite_schema.
 * Pure reads — never mutates anything.
 *
 * Deliberately NOT final: the rebuilder's version-gate test stubs
 * sqliteVersionAtLeast() to exercise the sub-3.26.0 preflight rejection on
 * a modern SQLite library.
 */
class SqliteSchemaIntrospector
{
    private SqliteSqlScanner $scanner;

    public function __construct(
        private readonly PDO $pdo,
        ?SqliteSqlScanner $scanner = null,
    ) {
        $this->scanner = $scanner ?? new SqliteSqlScanner();
    }

    public function snapshot(string $table): SqliteTableSnapshot
    {
        $createSql = $this->tableSql($table);
        if ($createSql === null) {
            throw new \RuntimeException("Table \"{$table}\" does not exist");
        }

        $columns = [];
        $primaryKey = [];
        foreach ($this->pragmaRows("PRAGMA table_xinfo({$this->quote($table)})") as $row) {
            $columns[] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'notNull' => (bool) $row['notnull'],
                'default' => $row['dflt_value'] === null ? null : (string) $row['dflt_value'],
                'pkOrdinal' => (int) $row['pk'],
                'hidden' => (int) ($row['hidden'] ?? 0),
            ];
        }
        $pkColumns = array_filter($columns, static fn (array $c): bool => $c['pkOrdinal'] > 0);
        usort($pkColumns, static fn (array $a, array $b): int => $a['pkOrdinal'] <=> $b['pkOrdinal']);
        $primaryKey = array_values(array_map(static fn (array $c): string => $c['name'], $pkColumns));

        $indexes = [];
        foreach ($this->pragmaRows("PRAGMA index_list({$this->quote($table)})") as $row) {
            $name = (string) $row['name'];
            $memberColumns = [];
            foreach ($this->pragmaRows("PRAGMA index_xinfo({$this->quote($name)})") as $member) {
                if ((int) $member['key'] === 1) {
                    $memberColumns[] = $member['name'] === null ? null : (string) $member['name'];
                }
            }
            $indexes[] = [
                'name' => $name,
                'unique' => (bool) $row['unique'],
                'origin' => (string) $row['origin'],
                'partial' => (bool) ($row['partial'] ?? false),
                'sql' => $this->indexSql($name),
                'columns' => $memberColumns,
            ];
        }

        $foreignKeys = [];
        foreach ($this->pragmaRows("PRAGMA foreign_key_list({$this->quote($table)})") as $row) {
            $id = (int) $row['id'];
            if (!isset($foreignKeys[$id])) {
                $foreignKeys[$id] = [
                    'id' => $id,
                    'table' => (string) $row['table'],
                    'from' => [],
                    'to' => [],
                    'onUpdate' => (string) $row['on_update'],
                    'onDelete' => (string) $row['on_delete'],
                    'match' => (string) $row['match'],
                ];
            }
            $seq = (int) $row['seq'];
            $foreignKeys[$id]['from'][$seq] = (string) $row['from'];
            $foreignKeys[$id]['to'][$seq] = $row['to'] === null ? '' : (string) $row['to'];
        }
        foreach ($foreignKeys as &$foreignKey) {
            ksort($foreignKey['from']);
            ksort($foreignKey['to']);
            $foreignKey['from'] = array_values($foreignKey['from']);
            $foreignKey['to'] = array_values($foreignKey['to']);
        }
        unset($foreignKey);

        return new SqliteTableSnapshot(
            table: $table,
            createSql: $createSql,
            columns: $columns,
            checks: $this->scanner->extractChecks($createSql),
            primaryKey: $primaryKey,
            autoIncrement: $this->scanner->containsKeyword($createSql, 'AUTOINCREMENT'),
            foreignKeys: array_values($foreignKeys),
            indexes: $indexes,
            triggers: $this->triggers($table),
            withoutRowid: $this->scanner->hasKeywordOutsideParens($createSql, 'WITHOUT ROWID'),
            strict: $this->scanner->hasKeywordOutsideParens($createSql, 'STRICT'),
            sequenceValue: $this->sequenceValue($table),
        );
    }

    /** @return list<array{name: string, sql: string}> */
    public function allViews(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name, sql FROM sqlite_schema WHERE type = 'view' AND sql IS NOT NULL"
        );
        $views = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $views[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $views;
    }

    /** @return list<array{name: string, table: string, sql: string}> */
    public function allTriggers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name, tbl_name, sql FROM sqlite_schema "
            . "WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name"
        );
        $triggers = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $triggers[] = [
                'name' => (string) $row['name'],
                'table' => (string) $row['tbl_name'],
                'sql' => (string) $row['sql'],
            ];
        }

        return $triggers;
    }

    /**
     * @return list<array{
     *   childTable: string, id: int, from: list<string>, to: list<string>
     * }>
     */
    public function inboundForeignKeys(string $table): array
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        $inbound = [];
        foreach ($tables !== false ? $tables->fetchAll(PDO::FETCH_COLUMN) : [] as $childTable) {
            $groups = [];
            foreach ($this->pragmaRows('PRAGMA foreign_key_list(' . $this->quote((string) $childTable) . ')') as $row) {
                if (strcasecmp((string) $row['table'], $table) !== 0) {
                    continue;
                }
                $id = (int) $row['id'];
                $seq = (int) $row['seq'];
                $groups[$id] ??= [
                    'childTable' => (string) $childTable,
                    'id' => $id,
                    'from' => [],
                    'to' => [],
                ];
                $groups[$id]['from'][$seq] = (string) $row['from'];
                $groups[$id]['to'][$seq] = (string) $row['to'];
            }
            foreach ($groups as $group) {
                ksort($group['from']);
                ksort($group['to']);
                $group['from'] = array_values($group['from']);
                $group['to'] = array_values($group['to']);
                $inbound[] = $group;
            }
        }

        return $inbound;
    }

    public function sqliteVersionAtLeast(string $minimum): bool
    {
        $stmt = $this->pdo->query('SELECT sqlite_version()');
        $version = $stmt !== false ? (string) $stmt->fetchColumn() : '0.0.0';

        return version_compare($version, $minimum, '>=');
    }

    private function tableSql(string $table): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : null;
    }

    private function indexSql(string $index): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT sql FROM sqlite_schema WHERE type = 'index' AND name = ?"
        );
        $stmt->execute([$index]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : null;
    }

    /** @return list<array{name: string, sql: string}> */
    private function triggers(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT name, sql FROM sqlite_schema WHERE type = 'trigger' AND tbl_name = ? AND sql IS NOT NULL"
        );
        $stmt->execute([$table]);
        $triggers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $triggers[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $triggers;
    }

    private function sequenceValue(string $table): ?int
    {
        $exists = $this->pdo->query(
            "SELECT 1 FROM sqlite_schema WHERE type = 'table' AND name = 'sqlite_sequence'"
        );
        if ($exists === false || $exists->fetchColumn() === false) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
        $stmt->execute([$table]);
        $seq = $stmt->fetchColumn();

        return $seq === false ? null : (int) $seq;
    }

    /** @return list<array<string, mixed>> */
    private function pragmaRows(string $pragma): array
    {
        $stmt = $this->pdo->query($pragma);

        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
```

- [ ] **Step 5: Run to verify pass** — `vendor/bin/phpunit tests/Unit/Database/Schema/Sqlite/` → PASS. PHPStan on `src/Database/Schema/Sqlite` clean.

---

### Task 4: Model gate — snapshot→DTO mapping, generator options, round-trip test

**Files:**
- Create: `src/Database/Schema/Sqlite/SqliteSnapshotMapper.php`
- Modify: `src/Database/Schema/Generators/SQLiteSqlGenerator.php` (`createTable()` ~line 52 — emit table options)
- Test: `tests/Unit/Database/Schema/Sqlite/SqliteModelRoundTripTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  `SqliteSnapshotMapper::toTableDefinition(SqliteTableSnapshot $snapshot): TableDefinition` — maps snapshot → core DTOs for regeneration via `createTable()`. Throws `UnsupportedSchemaOperationException` for structures the generator cannot emit (composite FK, expression-only auto-index members). Mapping rules:
  - columns → `ColumnDefinition` with `name`, an exact supported SQLite storage type
    mapping (`INTEGER→integer`, `TEXT→text`, `REAL→real`, `BLOB→blob`), `nullable`,
    `defaultRaw` (raw SQL text verbatim), and `primary`/`autoIncrement` for a
    single-column INTEGER PK. Empty, `NUMERIC`, `VARCHAR(...)`, `ANY`, or any other
    declared type the generator cannot reproduce exactly throws during mapping. Column
    CHECK ownership comes from `check.scope/check.column`, never identifier count;
  - multi-column PK → `TableDefinition::$primaryKey`;
  - unique auto-indexes (origin `u`) → `IndexDefinition(type: 'unique')` so `createTable()` re-emits table-level `UNIQUE (...)`;
  - table-level checks (`scope === 'table'`) → carried in
    `TableDefinition::$options['table_checks']` (list of expressions); the generator
    gains emission for it (below);
  - FKs → `ForeignKeyDefinition` (single-column only; composite throws), name synthesized `"{table}_{from}_fk{id}"`;
  - `withoutRowid`/`strict` → `TableDefinition::$options['without_rowid'] / ['strict']`.
  `SQLiteSqlGenerator::createTable()` additionally emits: `CHECK (...)` parts from `$table->options['table_checks']`, and the suffix `WITHOUT ROWID` / `STRICT` (comma-joined when both) from the options.

- [ ] **Step 1: Write the failing round-trip test**

`tests/Unit/Database/Schema/Sqlite/SqliteModelRoundTripTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Sqlite\SqliteSchemaIntrospector;
use Glueful\Database\Schema\Sqlite\SqliteSnapshotMapper;
use Glueful\Database\Schema\Sqlite\SqliteTableSnapshot;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The model gate from the spec: introspect(original) -> regenerate DDL in a
 * scratch database -> introspect again must equal the original canonical
 * snapshot, for every table shape the framework's builder can produce.
 */
final class SqliteModelRoundTripTest extends TestCase
{
    /** @return iterable<string, array{ddl: list<string>}> */
    public static function tableShapes(): iterable
    {
        yield 'plain columns with defaults' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL, '
            . '"age" INTEGER DEFAULT 0, "bio" TEXT DEFAULT NULL)',
        ]];
        yield 'enum check emulation' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, '
            . '"status" TEXT NOT NULL DEFAULT \'draft\' CHECK ("status" IN (\'draft\', \'sent\')))',
        ]];
        yield 'explicit column check' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "qty" INTEGER CHECK ("qty" > 0))',
        ]];
        yield 'inline and table-level unique' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "email" TEXT UNIQUE, '
            . '"a" TEXT, "b" TEXT, UNIQUE ("a", "b"))',
        ]];
        yield 'foreign keys with actions' => ['ddl' => [
            'CREATE TABLE "teams" ("id" INTEGER PRIMARY KEY)',
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "team_id" INTEGER NOT NULL, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id") ON DELETE CASCADE ON UPDATE RESTRICT)',
        ]];
        yield 'composite primary key without rowid' => ['ddl' => [
            'CREATE TABLE "t" ("a" TEXT NOT NULL, "b" TEXT NOT NULL, PRIMARY KEY ("a", "b")) WITHOUT ROWID',
        ]];
        yield 'table-level check' => ['ddl' => [
            'CREATE TABLE "t" ("lo" INTEGER, "hi" INTEGER, CHECK ("lo" < "hi"))',
        ]];
        yield 'real blob and raw expression defaults' => ['ddl' => [
            'CREATE TABLE "t" ("ratio" REAL DEFAULT 1.5, "payload" BLOB, '
            . '"created_at" TEXT DEFAULT CURRENT_TIMESTAMP)',
        ]];
        yield 'strict table' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "body" TEXT) STRICT',
        ]];
        yield 'strict without-rowid table' => ['ddl' => [
            'CREATE TABLE "t" ("a" TEXT NOT NULL, "b" INTEGER NOT NULL, '
            . 'PRIMARY KEY ("a", "b")) WITHOUT ROWID, STRICT',
        ]];
    }

    /** @param list<string> $ddl */
    #[Test]
    #[DataProvider('tableShapes')]
    public function introspectRegenerateIntrospectIsLossless(array $ddl): void
    {
        $original = new PDO('sqlite::memory:');
        $original->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($ddl as $statement) {
            $original->exec($statement);
        }

        $snapshot = (new SqliteSchemaIntrospector($original))->snapshot('t');
        $definition = (new SqliteSnapshotMapper())->toTableDefinition($snapshot);
        $regeneratedSql = (new SQLiteSqlGenerator())->createTable($definition);

        $scratch = new PDO('sqlite::memory:');
        $scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Referenced tables must exist for FK DDL to be accepted at CREATE time.
        foreach (array_slice($ddl, 0, -1) as $statement) {
            $scratch->exec($statement);
        }
        $scratch->exec($regeneratedSql);

        $regenerated = (new SqliteSchemaIntrospector($scratch))->snapshot('t');

        $this->assertSame(
            $this->canonical($snapshot),
            $this->canonical($regenerated),
            "Round-trip drift.\nOriginal SQL: {$snapshot->createSql}\nRegenerated SQL: {$regeneratedSql}"
        );
    }

    /**
     * Canonical comparable form: everything semantic, nothing cosmetic.
     * Auto-index names are positional (sqlite_autoindex_t_N) and already
     * comparable; declared types compare case-insensitively; the raw
     * createSql is deliberately excluded (it is evidence, not semantics).
     *
     * @return array<string, mixed>
     */
    private function canonical(SqliteTableSnapshot $s): array
    {
        $indexes = array_map(static fn (array $ix): array => [
            'unique' => $ix['unique'],
            'origin' => $ix['origin'],
            'partial' => $ix['partial'],
            'columns' => array_map(
                static fn (?string $c): ?string => $c === null ? null : strtolower($c),
                $ix['columns']
            ),
        ], $s->indexes);
        usort($indexes, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        $fks = array_map(static fn (array $fk): array => [
            'table' => strtolower($fk['table']),
            'from' => array_map('strtolower', $fk['from']),
            'to' => array_map('strtolower', $fk['to']),
            'onUpdate' => strtoupper($fk['onUpdate']),
            'onDelete' => strtoupper($fk['onDelete']),
        ], $s->foreignKeys);

        $checks = array_map(static fn (array $c): array => [
            'identifiers' => $c['identifiers'],
            'scope' => $c['scope'],
            'column' => $c['column'],
            'normalized' => strtolower(preg_replace('/\s+/', ' ', $c['expression']) ?? $c['expression']),
        ], $s->checks);

        return [
            'columns' => array_map(static fn (array $c): array => [
                'name' => strtolower($c['name']),
                'type' => strtolower($c['type']),
                'notNull' => $c['notNull'],
                'default' => $c['default'] === null ? null : strtolower($c['default']),
                'pkOrdinal' => $c['pkOrdinal'],
            ], $s->columns),
            'primaryKey' => array_map('strtolower', $s->primaryKey),
            'autoIncrement' => $s->autoIncrement,
            'checks' => $checks,
            'indexes' => $indexes,
            'foreignKeys' => $fks,
            'withoutRowid' => $s->withoutRowid,
            'strict' => $s->strict,
        ];
    }
}
```

- [ ] **Step 2: Run to verify it fails** — mapper class not found; after creating it, expect genuine round-trip failures until the generator emits table checks and options.

- [ ] **Step 3: Implement the mapper**

`src/Database/Schema/Sqlite/SqliteSnapshotMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;

/**
 * Maps a SqliteTableSnapshot onto the core cross-driver DTOs so the table
 * can be regenerated through SQLiteSqlGenerator::createTable() — the single
 * source of DDL truth. Structures the generator cannot emit throw here.
 */
final class SqliteSnapshotMapper
{
    public function toTableDefinition(SqliteTableSnapshot $snapshot): TableDefinition
    {
        $columnChecks = [];
        $tableChecks = [];
        foreach ($snapshot->checks as $check) {
            if ($check['scope'] === 'column' && $check['column'] !== null) {
                $columnChecks[strtolower($check['column'])][] = $check['expression'];
            } else {
                $tableChecks[] = $check['expression'];
            }
        }

        $singleIntegerPk = count($snapshot->primaryKey) === 1
            && ($snapshot->column($snapshot->primaryKey[0])['type'] ?? '') !== ''
            && strtolower((string) $snapshot->column($snapshot->primaryKey[0])['type']) === 'integer';

        $columns = [];
        foreach ($snapshot->columns as $column) {
            $name = $column['name'];
            $isPkAlias = $singleIntegerPk && strcasecmp($name, $snapshot->primaryKey[0]) === 0;
            $checksForColumn = $columnChecks[strtolower($name)] ?? [];
            if (count($checksForColumn) > 1) {
                throw UnsupportedSchemaOperationException::forFeature(
                    $snapshot->table,
                    'introspection',
                    "multiple CHECK constraints on column \"{$name}\"",
                    'the generator emits at most one CHECK per column; cannot regenerate faithfully'
                );
            }

            $declaredType = strtoupper(trim($column['type']));
            $mappedType = match ($declaredType) {
                'INTEGER' => 'integer',
                'TEXT' => 'text',
                'REAL' => 'real',
                'BLOB' => 'blob',
                default => throw UnsupportedSchemaOperationException::forFeature(
                    $snapshot->table,
                    'introspection',
                    "declared type \"{$column['type']}\" on column \"{$name}\"",
                    'SQLiteSqlGenerator cannot reproduce this declared type exactly'
                ),
            };

            $columns[] = new ColumnDefinition(
                name: $name,
                type: $mappedType,
                nullable: !$column['notNull'] && !$isPkAlias,
                defaultRaw: $column['default'],
                autoIncrement: $isPkAlias && $snapshot->autoIncrement,
                primary: $isPkAlias,
                check: $checksForColumn[0] ?? null,
            );
        }

        $indexes = [];
        foreach ($snapshot->autoIndexes() as $auto) {
            if (!$auto['unique']) {
                continue;
            }
            $memberColumns = [];
            foreach ($auto['columns'] as $member) {
                if ($member === null) {
                    throw UnsupportedSchemaOperationException::forFeature(
                        $snapshot->table,
                        'introspection',
                        "expression member in unique constraint \"{$auto['name']}\"",
                        'unique constraints over expressions cannot be regenerated'
                    );
                }
                $memberColumns[] = $member;
            }
            // Skip the auto-index that backs a WITHOUT ROWID composite PK —
            // the PRIMARY KEY clause regenerates it.
            if ($memberColumns === $snapshot->primaryKey && $auto['origin'] === 'pk') {
                continue;
            }
            $indexes[] = new IndexDefinition(
                columns: $memberColumns,
                name: $auto['name'],
                type: 'unique',
                unique: true,
            );
        }

        $foreignKeys = [];
        foreach ($snapshot->foreignKeys as $fk) {
            if (count($fk['from']) !== 1 || count($fk['to']) !== 1 || $fk['to'][0] === '') {
                throw UnsupportedSchemaOperationException::forFeature(
                    $snapshot->table,
                    'introspection',
                    'composite or implicit-column foreign key to "' . $fk['table'] . '"',
                    'the generator emits explicit single-column foreign keys only'
                );
            }
            $foreignKeys[] = new ForeignKeyDefinition(
                localColumn: $fk['from'][0],
                referencedTable: $fk['table'],
                referencedColumn: $fk['to'][0],
                name: "{$snapshot->table}_{$fk['from'][0]}_fk{$fk['id']}",
                onDelete: strtoupper($fk['onDelete']) === 'NO ACTION' ? null : $fk['onDelete'],
                onUpdate: strtoupper($fk['onUpdate']) === 'NO ACTION' ? null : $fk['onUpdate'],
            );
        }

        $options = [];
        if ($tableChecks !== []) {
            $options['table_checks'] = $tableChecks;
        }
        if ($snapshot->withoutRowid) {
            $options['without_rowid'] = true;
        }
        if ($snapshot->strict) {
            $options['strict'] = true;
        }

        return new TableDefinition(
            name: $snapshot->table,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            primaryKey: $singleIntegerPk ? [] : $snapshot->primaryKey,
            options: $options,
        );
    }
}
```

- [ ] **Step 4: Extend `createTable()`**

In `src/Database/Schema/Generators/SQLiteSqlGenerator.php`, `createTable()` — after the
foreign-key loop and before `implode`:

```php
        // Table-level CHECK constraints (round-tripped from introspection)
        $tableChecks = $table->options['table_checks'] ?? [];
        if (is_array($tableChecks)) {
            foreach ($tableChecks as $checkExpression) {
                if (is_string($checkExpression) && $checkExpression !== '') {
                    $parts[] = '  CHECK (' . $checkExpression . ')';
                }
            }
        }
```

and replace the closing lines:

```php
        $sql .= implode(",\n", $parts) . "\n";
        $sql .= ')';

        $suffixes = [];
        if (($table->options['without_rowid'] ?? false) === true) {
            $suffixes[] = 'WITHOUT ROWID';
        }
        if (($table->options['strict'] ?? false) === true) {
            $suffixes[] = 'STRICT';
        }
        if ($suffixes !== []) {
            $sql .= ' ' . implode(', ', $suffixes);
        }

        return $sql . ';';
```

Also make the two fidelity fixes required by the mapper and model gate:

```php
        // In mapColumnType(): an introspected REAL must regenerate as REAL,
        // not fall through to the default TEXT mapping.
        'decimal', 'numeric', 'float', 'double', 'real' => 'REAL',
```

In `buildColumnDefinition()`, raw defaults are already SQL and must not be quoted a
second time:

```php
        if ($column->defaultRaw !== null && !$column->autoIncrement) {
            $parts[] = 'DEFAULT ' . $column->defaultRaw;
        } elseif ($column->default !== null && !$column->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->formatDefaultValue($column->default, $column->type);
        }
```

This replaces the existing `hasDefault()/getDefaultValue()` branch. The mapper accepts
only declared types that this generator can reproduce byte-semantically; it never
silently maps an empty or unfamiliar declared type to TEXT.

- [ ] **Step 5: Iterate until the round-trip provider is fully green.** Every failure
is a genuine model gap — fix it in the mapper or generator, never by weakening the
canonical comparison. Known wrinkles the code above handles: raw defaults remain raw,
REAL/BLOB retain their storage types, STRICT options round-trip, PK-alias columns
(`INTEGER PRIMARY KEY`) report `nullable` false via the alias, `NO ACTION` normalizes
to null actions, WITHOUT-ROWID composite-PK auto-index is skipped.

- [ ] **Step 6: Full-tree gates, then Commit checkpoint 1**

```bash
git add src/Database/Schema/Exceptions/UnsupportedSchemaOperationException.php src/Database/Schema/Sqlite/SqliteAlterationPlan.php src/Database/Schema/Sqlite/SqliteSqlScanner.php src/Database/Schema/Sqlite/SqliteTableSnapshot.php src/Database/Schema/Sqlite/SqliteSchemaIntrospector.php src/Database/Schema/Sqlite/SqliteSnapshotMapper.php src/Database/Schema/Generators/SQLiteSqlGenerator.php tests/Unit/Database/Schema/Sqlite/SqliteAlterationPlanTest.php tests/Unit/Database/Schema/Sqlite/SqliteSqlScannerTest.php tests/Unit/Database/Schema/Sqlite/SqliteSchemaIntrospectorTest.php tests/Unit/Database/Schema/Sqlite/SqliteModelRoundTripTest.php
git commit -m "feat(schema): add SQLite schema model — scanner, snapshot, introspector, round-trip gate"
```

---

### Task 5: SQLiteTableRebuilder

**Files:**
- Create: `src/Database/Schema/Sqlite/SQLiteTableRebuilder.php`
- Test: `tests/Unit/Database/Schema/Sqlite/SQLiteTableRebuilderTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4 (exact names as produced).
- Produces:
  `SQLiteTableRebuilder::__construct(\PDO $pdo, ?SQLiteSqlGenerator $generator = null, ?SqliteSqlScanner $scanner = null, ?SqliteSchemaIntrospector $introspector = null)`;
  `rebuild(SqliteAlterationPlan $plan): void` — full audited atomic rebuild; throws
  `UnsupportedSchemaOperationException` (preflight) or `\RuntimeException`
  (execution/verification, after rollback). Constructor defaults are assigned in the
  body so named `introspector:` injection remains stable and no contradictory signature
  appears later in the task.

The rebuilder implements, in order (each numbered block is a private method; signatures given so the class assembles unambiguously):

1. `preflight(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot, array $views): void` — every audit rule from the spec:
   - `journal_mode` is `OFF` → throw (`PRAGMA journal_mode` query).
   - In transaction (`$pdo->inTransaction()`) with `PRAGMA foreign_keys` = 1 → throw. Record mode: `self::MODE_STANDALONE` / `self::MODE_SAVEPOINT`.
   - Rename-pragma capability: `legacy_alter_table` must be settable **and read-back
     verifiable** — ON for the internal swap rename on every rebuild, and additionally
     OFF for a final user-visible rename. Combined `renameTable()` ≠ null also requires
     `sqliteVersionAtLeast('3.26.0')` or throw.
   - Generated columns: any snapshot column with `hidden >= 2` → throw.
   - `COLLATE` / `ON CONFLICT` / `MATCH` / `DEFERRABLE` / `INITIALLY` /
     explicit `CONSTRAINT <name>` present in `createSql` (via quote-aware keyword
     scanning) → throw each with its own feature string. These are not recoverable from
     the PRAGMA model. `CREATE VIRTUAL TABLE`, temp schemas, and schema-qualified or
     attached-database targets also throw explicitly.
   - Composite FK in snapshot → the mapper will throw during target compilation; preflight calls the mapper first so this surfaces before DDL (see step 2).
   - CHECK decisions use recorded ownership. A column-level CHECK owned by a dropped
     column is removed with that column. A column-level enum CHECK owned by a renamed
     column may be rewritten only when `isEnumCheckShape()` proves the exact framework
     shape. Any table-level CHECK, cross-column CHECK, or other expression referencing a
     dropped/renamed identifier throws. Ownership is never inferred from identifier
     count.
   - For each named index: `identifiers($sql)` ∩ (modified ∪ dropped ∪ renamed-from columns) ≠ ∅ and the index is not in `dropIndexes()` → throw.
   - Unique auto-indexes (`origin = 'u'`) versus `modifyColumns()`: a **single-column**
     unique auto-index over a modified column is decided by the replacement
     `ColumnDefinition` (compileTarget rule below) and never rejected. A **composite**
     unique auto-index that includes a modified column throws unless the same call names
     that `sqlite_autoindex_%` index in `dropIndexes()` — the replacement definition
     cannot express a constraint spanning other columns, and the constraint has no other
     handle (`dropUnique(name)` cannot target a constraint-backed auto-index). The
     message must say so and point at restating it with `unique([...])` after the
     modification.
   - Scan **every database trigger**, not only triggers attached to the rebuilt table.
     If its SQL references the target table and intersects modified/dropped/renamed-from
     columns, throw. Attached triggers referencing only untouched identifiers replay
     verbatim; external triggers remain installed and are verified unchanged. A scanner
     failure on any trigger is dependency uncertainty and throws.
   - For every view (all `sqlite_schema` views): if its SQL references the table and a
     renamed/dropped column, or references the table through `SELECT *`, throw. A scanner
     failure on any view means dependency cannot be disproved and therefore throws;
     there is no impossible "contains table and scanner threw" conditional.
   - Inspect every inbound FK declared by other tables. A parent-column modification,
     drop, or rebuild-folded rename intersecting its referenced `to` columns throws
     before DDL; this slice does not rebuild dependent child tables. A final native
     `rename_table` is the only inbound-FK rewrite permitted, under the 3.26/non-legacy
     gate.
   - Modify/drop/rename targets must exist in the snapshot; add-column names must not; rename targets must not collide → throw with precise messages.
   - Rowid safety is derived for both source and target. Introducing a new single-column
     `INTEGER PRIMARY KEY` alias where the source had none throws. For an implicit-rowid
     source or target, choose the first unshadowed pseudocolumn from `rowid`, `_rowid_`,
     `oid`; if all three are shadowed, throw. An existing INTEGER-PK alias is copied by
     its named column and is never inserted a second time as `rowid`.
2. `compileTarget(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array{definition: TableDefinition, copyMap: array<string, string>, rowidCopy: ?array{target: string, source: string}}` — clone the snapshot in memory: apply renames (column list + enum-check rewrite via `rewriteEnumCheckColumn`), drops, modifications (a `modifyColumns()` `ColumnDefinition` replaces the introspected column wholesale), added columns appended, FK adds/drops (matching rule below), index adds are NOT part of the CREATE (they run as post-swap `CREATE INDEX` via the generator), unique auto-index survival per the rule below. Build target through a snapshot-clone + `SqliteSnapshotMapper`. `copyMap` maps target column → source column (renames mapped; added columns absent). `rowidCopy` is null when `WITHOUT ROWID` applies or a target INTEGER-PK alias is already copied as a named column; otherwise it maps an unshadowed target pseudocolumn to the source's unshadowed pseudocolumn or existing INTEGER-PK alias.

   **Unique auto-index survival.** For a column in `modifyColumns()` the replacement
   `ColumnDefinition` is authoritative for that column's uniqueness: a single-column
   unique auto-index covering it is carried into the target **only** when the
   replacement has `unique: true`; otherwise it is dropped. That is the inline-unique
   drop mechanism — the roadmap's original gap — and it needs no `dropIndexes()` entry.
   A unique auto-index over columns the plan does not modify survives unless its
   `sqlite_autoindex_%` name appears in `dropIndexes()` (also a rebuild trigger, since
   SQLite cannot `DROP INDEX` a constraint-backed index). A composite unique auto-index
   that includes a modified column reaches this point only when preflight accepted it —
   i.e. its name is in `dropIndexes()` — so it is simply not carried forward; every
   other case already threw.

   **Foreign-key drop matching.** Each `dropForeignKeys()` entry matches an existing
   snapshot FK in exactly two ways: (a) the entry equals the FK's local column name, or
   (b) the entry equals the framework builder's generated constraint name
   `fk_{table}_{column}` (`ColumnBuilder::…` builds precisely
   `'fk_' . $tableName . '_' . $columnName`, `src/Database/Schema/Builders/ColumnBuilder.php:870`),
   from which the local column is derived by stripping the `fk_{table}_` prefix and
   matched against the snapshot. Nothing else matches: the `{table}_{from}_fk{id}` names
   `SqliteSnapshotMapper` synthesizes are internal regeneration labels (SQLite does not
   store FK constraint names in `foreign_key_list`) and are never compared against user
   input. An entry that matches no FK by (a) or (b) throws rather than being ignored or
   guessed at.
3. `execute(...)`: the rebuild, optional table rename, all verification, and the final
   global FK check are one atomic unit — **the rename never occurs after commit**.
   - Before either mode, run the global pre-existing `foreign_key_check`. Capture
     `legacy_alter_table` **before any DDL** (every rebuild changes it — see the body),
     and restore + verify it in the outer `finally` on success or failure.
   - STANDALONE: capture `foreign_keys`; set OFF and verify 0; `BEGIN IMMEDIATE`; run the
     body; verify the rebuilt table under its original name; if requested, run the final
     native rename and its dependency verification; run the global post-change
     `foreign_key_check`; only then `COMMIT`. On any exception before commit, execute
     `ROLLBACK`; finally restore and verify `foreign_keys` and `legacy_alter_table`.
   - SAVEPOINT (already in a transaction with FKs verified OFF): create a **unique**
     savepoint name, run the same body → pre-rename verification → optional rename →
     post-rename dependency verification → global FK check sequence, then `RELEASE`.
     Any failure executes `ROLLBACK TO` + `RELEASE` and rethrows; legacy state is restored
     afterward. No fixed savepoint name can collide with a caller's savepoint.
   - Body: create a collision-checked temp table (`{$table}__rebuild_` + 8 hex chars)
     through `createTable()`; copy explicit named columns and prepend the independently
     derived `rowidCopy` pair only when non-null; drop the original; **force
     `legacy_alter_table = ON` with a verified read-back and rename temp to the original
     name** — the forcing brackets that single rename statement and nothing else;
     replay preserved named indexes and attached triggers; create added indexes; restore
     `sqlite_sequence` when AUTOINCREMENT remains. The forced-ON swap is mandatory: with the modern default OFF,
     `ALTER TABLE <temp> RENAME TO <table>` fails with
     `error in view …: no such table: main.<table>` whenever any view or any trigger on
     another table references the table, because non-legacy rename rewrites those bodies
     and the original table was dropped one statement earlier. The legacy rename is a
     bare rename, which is what the swap wants — the rebuilder replays the artifacts it
     dropped itself, and everything else still points at the unchanged final name.
   - The optional final `rename_table` runs with `legacy_alter_table` forced **OFF**
     (verified read-back) so SQLite rewrites inbound FKs and dependent trigger/view
     bodies onto the new name. Both forced values are transient; the `finally` restores
     the single captured original value and verifies the read-back.
4. `verify(...)`: re-introspect and canonically compare against an expectation built by
   applying the target DDL in a scratch in-memory PDO. **The scratch database covers the
   table shape only** (columns, CHECKs, PK, FKs, table options) plus the preserved
   named-index DDL. **Triggers are never replayed into the scratch database** — a trigger
   body may reference other tables that do not exist there and would throw inside
   verification. Triggers are verified instead by canonical SQL comparison of the *live*
   database's post-swap trigger rows against the preflight snapshot's trigger rows
   (compare `name` plus whitespace-normalized SQL; attached triggers must be present and
   unchanged, external triggers untouched). Verify named indexes by
   `(name, normalized sql)`, external dependency objects unchanged, options equal, and
   `sequenceValue` **exactly equal** to the captured high-water mark (not merely `>=`).
   Move canonicalization from the Task 4 test into
   `SqliteTableSnapshot::toCanonicalArray()` and include CHECK scope/owner. Mismatch
   throws while the transaction/savepoint is still rollback-capable.
5. Combined rename runs **inside the same transaction/savepoint**, after rebuilt-table
   verification and before the final global FK check/commit. Verify: new name exists,
   old name is absent, every previously inbound FK now references the new name, and each
   dependent trigger/view that referenced the old table now references the new table
   with otherwise-equivalent canonical tokens. Any failure rolls back the rebuild and
   rename together, leaving the original table and name intact.

- [ ] **Step 1: Write the failing tests.** Full test file (long; every scenario from the spec's Testing section that the rebuilder alone can prove — seam-level tests come in Task 6):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Sqlite\SqliteAlterationPlan;
use Glueful\Database\Schema\Sqlite\SqliteSchemaIntrospector;
use Glueful\Database\Schema\Sqlite\SQLiteTableRebuilder;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SQLiteTableRebuilderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function rebuilder(): SQLiteTableRebuilder
    {
        return new SQLiteTableRebuilder($this->pdo);
    }

    private function schemaDump(): string
    {
        $stmt = $this->pdo->query(
            "SELECT type, name, sql FROM sqlite_schema WHERE name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return json_encode($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function dropsInlineUniqueViaModifyColumn(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "email" TEXT NOT NULL UNIQUE)');
        $this->pdo->exec("INSERT INTO t (email) VALUES ('a@b.c')");

        // The replacement definition omits unique, and for a MODIFIED column the
        // replacement is authoritative -> the inline unique auto-index is dropped.
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'modify_columns' => [new ColumnDefinition(name: 'email', type: 'text', nullable: false)],
        ]));

        // Duplicate now allowed: the unique constraint is gone, data survived.
        $this->pdo->exec("INSERT INTO t (email) VALUES ('a@b.c')");
        $count = $this->pdo->query('SELECT COUNT(*) FROM t');
        $this->assertSame(2, (int) ($count !== false ? $count->fetchColumn() : 0));
    }

    #[Test]
    public function modifyColumnKeepsUniqueWhenReplacementDeclaresIt(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "email" TEXT NOT NULL UNIQUE)');
        $this->pdo->exec("INSERT INTO t (email) VALUES ('a@b.c')");

        // Same modification, but the replacement declares unique -> constraint survives.
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'modify_columns' => [
                new ColumnDefinition(name: 'email', type: 'text', nullable: false, unique: true),
            ],
        ]));

        $unique = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->autoIndexes();
        $this->assertSame([['email']], array_column($unique, 'columns'));

        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO t (email) VALUES ('a@b.c')");
    }

    #[Test]
    public function compositeUniqueOverAModifiedColumnFailsClosed(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT NOT NULL, "b" TEXT NOT NULL, '
            . 'UNIQUE ("a", "b"))'
        );
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'modify_columns' => [new ColumnDefinition(name: 'a', type: 'text', nullable: true)],
            ]));
            $this->fail('Expected composite-unique rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('unique', strtolower($e->getMessage()));
            $this->assertSame($before, $this->schemaDump(), 'no mutation before the throw');
        }

        // Naming the auto-index in the same call removes the constraint and succeeds.
        $autoIndex = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->autoIndexes()[0]['name'];
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'modify_columns' => [new ColumnDefinition(name: 'a', type: 'text', nullable: true)],
            'drop_indexes' => [$autoIndex],
        ]));
        $this->assertSame([], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->autoIndexes());
    }

    #[Test]
    public function rebuildSucceedsUnderDependentViewAndExternalTriggerWithLegacyPragmaOff(): void
    {
        // Regression guard for the internal swap rename: with legacy_alter_table at its
        // modern default (OFF), the post-DROP rename would fail with
        // "error in view t_ids: no such table: main.t". The rebuilder forces it ON for
        // the swap only, and restores the original value afterwards.
        $this->pdo->exec('PRAGMA legacy_alter_table = OFF');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "keep" TEXT, "gone" TEXT)');
        $this->pdo->exec('CREATE TABLE child ("t_id" INTEGER, "note" TEXT)');
        $this->pdo->exec('CREATE VIEW t_ids AS SELECT id FROM t');
        $this->pdo->exec(
            'CREATE TRIGGER child_touch AFTER INSERT ON child '
            . 'BEGIN SELECT id FROM t WHERE id = NEW.t_id; END'
        );

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        $this->assertSame(['id', 'keep'], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames());
        $legacy = $this->pdo->query('PRAGMA legacy_alter_table');
        $this->assertSame(0, (int) ($legacy !== false ? $legacy->fetchColumn() : 1), 'prior OFF state restored');
    }

    #[Test]
    public function dropColumnPreservesDataRowidsAndSequence(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "keep" TEXT, "gone" TEXT)'
        );
        $this->pdo->exec("INSERT INTO t (keep, gone) VALUES ('k1', 'g1'), ('k2', 'g2'), ('k3', 'g3')");
        $this->pdo->exec('DELETE FROM t WHERE id = 3'); // high-water mark now above MAX(id)

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        $rows = $this->pdo->query('SELECT id, keep FROM t ORDER BY id');
        $this->assertSame(
            [['id' => 1, 'keep' => 'k1'], ['id' => 2, 'keep' => 'k2']],
            array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'keep' => $r['keep']],
                $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : []
            )
        );
        $seq = $this->pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 't'");
        $this->assertSame(3, (int) ($seq !== false ? $seq->fetchColumn() : 0), 'high-water mark must not regress');
        $this->pdo->exec("INSERT INTO t (keep) VALUES ('k4')");
        $newId = $this->pdo->query('SELECT MAX(id) FROM t');
        $this->assertSame(4, (int) ($newId !== false ? $newId->fetchColumn() : 0), 'deleted id must not be reused');
    }

    #[Test]
    public function addAndDropForeignKeysRebuild(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER)');
        $this->pdo->exec('INSERT INTO teams (id) VALUES (1)');
        $this->pdo->exec('INSERT INTO t (team_id) VALUES (1)');

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'add_foreign_keys' => [new ForeignKeyDefinition(
                localColumn: 'team_id',
                referencedTable: 'teams',
                referencedColumn: 'id',
                name: 't_team_id_fk',
                onDelete: 'CASCADE'
            )],
        ]));

        $fks = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->foreignKeys;
        $this->assertCount(1, $fks);
        $this->assertSame('CASCADE', strtoupper($fks[0]['onDelete']));

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_foreign_keys' => ['team_id'],
        ]));
        $this->assertCount(0, (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->foreignKeys);
    }

    #[Test]
    public function combinedChangesRunAsOneRebuildPreservingEnumCheckAndPartialIndex(): void
    {
        $this->pdo->exec(<<<'SQL'
        CREATE TABLE t (
          "id" INTEGER PRIMARY KEY,
          "status" TEXT NOT NULL DEFAULT 'draft' CHECK ("status" IN ('draft', 'sent')),
          "score" INTEGER,
          "legacy" TEXT
        )
        SQL);
        // Partial index and trigger reference only untouched status, so both
        // are safe for verbatim replay while score is renamed.
        $this->pdo->exec(
            'CREATE INDEX t_status_partial ON t ("status") WHERE "status" = \'sent\''
        );
        $this->pdo->exec(
            'CREATE TRIGGER t_status_touch AFTER INSERT ON t BEGIN SELECT NEW.status; END'
        );
        $this->pdo->exec("INSERT INTO t (status, score, legacy) VALUES ('sent', 5, 'x')");
        $before = $this->pdo->query("SELECT COUNT(*) FROM sqlite_schema WHERE name = 't'");
        $this->assertNotFalse($before);

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['legacy'],
            'add_columns' => [new ColumnDefinition(name: 'note', type: 'text')],
            'rename_columns' => ['score' => 'points'],
        ]));

        $snapshot = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t');
        $this->assertSame(['id', 'status', 'points', 'note'], $snapshot->columnNames());
        $this->assertCount(1, $snapshot->checks);
        // Untouched enum check survives verbatim.
        $this->assertSame(['status'], $snapshot->checks[0]['identifiers']);
        $this->assertSame(['t_status_partial'], array_column($snapshot->namedIndexes(), 'name'));
        $this->assertSame(['t_status_touch'], array_column($snapshot->triggers, 'name'));
        $this->expectException(\PDOException::class); // enum check still enforced
        $this->pdo->exec("INSERT INTO t (status) VALUES ('bogus')");
    }

    #[Test]
    public function renamedColumnEnumCheckIsRewritten(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, '
            . '"status" TEXT CHECK ("status" IN (\'a\', \'b\')), "x" TEXT)'
        );

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'rename_columns' => ['status' => 'state'],
            'drop_columns' => ['x'],
        ]));

        $snapshot = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t');
        $this->assertSame(['state'], $snapshot->checks[0]['identifiers']);
    }

    #[Test]
    public function nonEnumCheckOnRenamedColumnFailsClosedBeforeMutation(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "qty" INTEGER CHECK ("qty" > 0), "x" TEXT)'
        );
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'rename_columns' => ['qty' => 'amount'],
                'drop_columns' => ['x'],
            ]));
            $this->fail('Expected preflight rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertSame('t', $e->table());
            $this->assertSame($before, $this->schemaDump(), 'no mutation before the throw');
        }
    }

    #[Test]
    public function indexOnDroppedColumnFailsClosedUnlessExplicitlyDropped(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "score" INTEGER)');
        $this->pdo->exec('CREATE INDEX t_score_index ON t ("score")');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['score'],
            ]));
            $this->fail('Expected preflight rejection');
        } catch (UnsupportedSchemaOperationException) {
            $this->assertSame($before, $this->schemaDump());
        }

        // Same drop WITH the index dropped in the same call succeeds.
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['score'],
            'drop_indexes' => ['t_score_index'],
        ]));
        $this->assertSame(['id'], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames());
    }

    #[Test]
    public function uncertainViewDependencyFailsClosed(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "gone" TEXT)');
        $this->pdo->exec('CREATE VIEW v AS SELECT "gone" FROM t');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['gone'],
            ]));
            $this->fail('Expected preflight rejection');
        } catch (UnsupportedSchemaOperationException) {
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function inTransactionWithForeignKeysOnFailsPreflight(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT)');
        $this->pdo->beginTransaction();

        try {
            $this->expectException(UnsupportedSchemaOperationException::class);
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['a'],
            ]));
        } finally {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function inTransactionWithForeignKeysOffUsesSavepointAndSurvivesOuterRollback(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT, "b" TEXT)');
        $this->pdo->exec("INSERT INTO t (a, b) VALUES ('x', 'y')");
        $this->pdo->beginTransaction();

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['b'],
        ]));
        $mid = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames();
        $this->assertSame(['id', 'a'], $mid);

        $this->pdo->rollBack();
        $after = (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames();
        $this->assertSame(['id', 'a', 'b'], $after, 'outer rollback must undo the savepoint rebuild');
    }

    #[Test]
    public function preExistingForeignKeyViolationsAreRejectedUpFront(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER, "x" TEXT, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id"))'
        );
        $this->pdo->exec('INSERT INTO t (team_id, x) VALUES (999, \'orphan\')'); // violation
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['x'],
            ]));
            $this->fail('Expected pre-existing violation rejection');
        } catch (\RuntimeException $e) {
            // UnsupportedSchemaOperationException extends \RuntimeException, so pin the
            // stage: this must be the execution-stage FK check, not a preflight audit.
            $this->assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e);
            $this->assertStringContainsString('foreign_key_check', $e->getMessage());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function copyFailureRollsBackAndRestoresForeignKeysState(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "v" TEXT)');
        $this->pdo->exec('INSERT INTO t (v) VALUES (NULL)');
        $before = $this->schemaDump();

        try {
            // Making v NOT NULL with a NULL row: copy INSERT must fail.
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'modify_columns' => [new ColumnDefinition(name: 'v', type: 'text', nullable: false)],
            ]));
            $this->fail('Expected copy failure');
        } catch (\RuntimeException $e) {
            // Must be an execution-stage failure, not a preflight rejection
            // (UnsupportedSchemaOperationException is itself a \RuntimeException).
            $this->assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e);
            $this->assertSame($before, $this->schemaDump(), 'original table intact after rollback');
            $fk = $this->pdo->query('PRAGMA foreign_keys');
            $this->assertSame(1, (int) ($fk !== false ? $fk->fetchColumn() : 0), 'foreign_keys restored to ON');
        }
    }

    #[Test]
    public function journalModeOffFailsPreflight(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'glueful_sqlite_');
        $this->assertIsString($file);
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT)');

        try {
            $this->expectException(UnsupportedSchemaOperationException::class);
            (new SQLiteTableRebuilder($pdo))->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['a'],
            ]));
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function generatedColumnsFailPreflight(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" INTEGER, '
            . '"double_a" INTEGER GENERATED ALWAYS AS ("a" * 2) VIRTUAL)'
        );

        $this->expectException(UnsupportedSchemaOperationException::class);
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['a'],
        ]));
    }

    #[Test]
    public function compositeForeignKeyFailsPreflight(): void
    {
        $this->pdo->exec('CREATE TABLE parents (a TEXT, b TEXT, PRIMARY KEY (a, b)) WITHOUT ROWID');
        $this->pdo->exec(
            'CREATE TABLE t (x TEXT, y TEXT, z TEXT, FOREIGN KEY (x, y) REFERENCES parents (a, b))'
        );
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['z'],
            ]));
            $this->fail('Expected composite-FK rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('composite', $e->getMessage());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function collateClauseFailsPreflight(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "name" TEXT COLLATE NOCASE, "x" TEXT)');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['x'],
            ]));
            $this->fail('Expected COLLATE rejection');
        } catch (UnsupportedSchemaOperationException) {
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function renameGateFailsPreflightWhenVersionTooOld(): void
    {
        // SqliteSchemaIntrospector is intentionally non-final and the rebuilder
        // accepts an override so the version gate's failure branch is testable
        // on a modern SQLite: stub sqliteVersionAtLeast() to return false.
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "x" TEXT)');
        $stub = new class ($this->pdo) extends SqliteSchemaIntrospector {
            public function sqliteVersionAtLeast(string $minimum): bool
            {
                return false;
            }
        };
        $rebuilder = new SQLiteTableRebuilder($this->pdo, introspector: $stub);
        $before = $this->schemaDump();

        try {
            $rebuilder->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['x'],
                'rename_table' => 'renamed',
            ]));
            $this->fail('Expected version-gate rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('3.26.0', $e->getMessage());
            $this->assertSame($before, $this->schemaDump());
        }

        // The same plan WITHOUT the rename still works on the stubbed version.
        $rebuilder->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['x'],
        ]));
        $this->assertSame(['id'], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames());
    }

    #[Test]
    public function combinedRenameRestoresLegacyAlterTableWhenInitiallyOn(): void
    {
        $this->pdo->exec('PRAGMA legacy_alter_table = ON');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "x" TEXT)');

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['x'],
            'rename_table' => 'renamed',
        ]));

        $this->assertSame(
            ['id'],
            (new SqliteSchemaIntrospector($this->pdo))->snapshot('renamed')->columnNames()
        );
        $legacy = $this->pdo->query('PRAGMA legacy_alter_table');
        $this->assertSame(1, (int) ($legacy !== false ? $legacy->fetchColumn() : 0), 'prior ON state restored');
    }

    #[Test]
    public function combinedRebuildAndRenameEndsUnderTheNewName(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER, "x" TEXT, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id"))'
        );
        $this->pdo->exec('CREATE TABLE watchers ("id" INTEGER PRIMARY KEY, "t_id" INTEGER, '
            . 'FOREIGN KEY ("t_id") REFERENCES "t" ("id"))');
        $this->pdo->exec('CREATE VIEW t_ids AS SELECT id FROM t');
        $this->pdo->exec(
            'CREATE TRIGGER watchers_touch AFTER INSERT ON watchers '
            . 'BEGIN SELECT id FROM t WHERE id = NEW.t_id; END'
        );

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['x'],
            'rename_table' => 'targets',
        ]));

        $introspector = new SqliteSchemaIntrospector($this->pdo);
        $this->assertSame(['id', 'team_id'], $introspector->snapshot('targets')->columnNames());
        // Inbound FK on watchers must now reference the new name.
        $watchers = $introspector->snapshot('watchers');
        $this->assertSame('targets', $watchers->foreignKeys[0]['table']);
        $viewSql = $this->pdo->query("SELECT sql FROM sqlite_schema WHERE name = 't_ids'");
        $triggerSql = $this->pdo->query("SELECT sql FROM sqlite_schema WHERE name = 'watchers_touch'");
        $this->assertStringContainsString('targets', (string) ($viewSql !== false ? $viewSql->fetchColumn() : ''));
        $this->assertStringContainsString('targets', (string) ($triggerSql !== false ? $triggerSql->fetchColumn() : ''));
        // legacy_alter_table restored to its default (0).
        $legacy = $this->pdo->query('PRAGMA legacy_alter_table');
        $this->assertSame(0, (int) ($legacy !== false ? $legacy->fetchColumn() : 1));
    }

    #[Test]
    public function implicitRowidAndStrictOptionSurviveRebuild(): void
    {
        $this->pdo->exec('CREATE TABLE t (keep TEXT, gone TEXT) STRICT');
        $this->pdo->exec("INSERT INTO t (rowid, keep, gone) VALUES (41, 'a', 'x'), (99, 'b', 'y')");

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        $rows = $this->pdo->query('SELECT rowid, keep FROM t ORDER BY rowid');
        $this->assertSame(
            [['rowid' => 41, 'keep' => 'a'], ['rowid' => 99, 'keep' => 'b']],
            array_map(
                static fn (array $row): array => ['rowid' => (int) $row['rowid'], 'keep' => $row['keep']],
                $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : []
            )
        );
        $this->assertTrue((new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->strict);
    }

    #[Test]
    public function shadowedRowidAliasesFailBeforeMutation(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("rowid" TEXT, "_rowid_" TEXT, "oid" TEXT, "gone" TEXT)'
        );
        $before = $this->schemaDump();

        $this->expectException(UnsupportedSchemaOperationException::class);
        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['gone'],
            ]));
        } finally {
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function droppingAColumnDropsItsOwnedCheckButNotATableCheck(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER, gone INTEGER CHECK (gone > 0))');
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));
        $this->assertSame([], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->checks);

        $this->pdo->exec('ALTER TABLE t ADD COLUMN other INTEGER');
        $this->pdo->exec('DROP TABLE t');
        $this->pdo->exec('CREATE TABLE t (id INTEGER, gone INTEGER, CHECK (gone > 0))');
        $this->expectException(UnsupportedSchemaOperationException::class);
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));
    }

    #[Test]
    public function externalTriggerInboundFkAndSelectStarViewDependenciesFailClosed(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, gone TEXT)');
        $this->pdo->exec('CREATE TABLE child (t_id INTEGER REFERENCES t(id), note TEXT)');
        $this->pdo->exec(
            'CREATE TRIGGER child_write AFTER UPDATE ON child '
            . 'BEGIN UPDATE t SET gone = NEW.note WHERE id = NEW.t_id; END'
        );
        $this->pdo->exec('CREATE VIEW t_all AS SELECT * FROM t');
        $before = $this->schemaDump();

        foreach ([
            ['drop_columns' => ['gone']],
            ['rename_columns' => ['id' => 'new_id'], 'drop_columns' => ['gone']],
        ] as $changes) {
            try {
                $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', $changes));
                $this->fail('Expected dependency rejection');
            } catch (UnsupportedSchemaOperationException) {
                $this->assertSame($before, $this->schemaDump());
            }
        }
    }

    #[Test]
    public function verificationMismatchRollsBackOriginalSchema(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, keep TEXT, gone TEXT)');
        $before = $this->schemaDump();
        $introspector = new class ($this->pdo) extends SqliteSchemaIntrospector {
            private int $snapshots = 0;

            public function snapshot(string $table): \Glueful\Database\Schema\Sqlite\SqliteTableSnapshot
            {
                $snapshot = parent::snapshot($table);
                $this->snapshots++;
                if ($this->snapshots < 2) {
                    return $snapshot;
                }

                return new \Glueful\Database\Schema\Sqlite\SqliteTableSnapshot(
                    table: $snapshot->table,
                    createSql: $snapshot->createSql,
                    columns: $snapshot->columns,
                    checks: $snapshot->checks,
                    primaryKey: $snapshot->primaryKey,
                    autoIncrement: $snapshot->autoIncrement,
                    foreignKeys: $snapshot->foreignKeys,
                    indexes: $snapshot->indexes,
                    triggers: $snapshot->triggers,
                    withoutRowid: $snapshot->withoutRowid,
                    strict: !$snapshot->strict,
                    sequenceValue: $snapshot->sequenceValue,
                );
            }
        };

        try {
            (new SQLiteTableRebuilder($this->pdo, introspector: $introspector))->rebuild(
                SqliteAlterationPlan::fromChanges('t', ['drop_columns' => ['gone']])
            );
            $this->fail('Expected verification mismatch');
        } catch (\RuntimeException $e) {
            // Verification runs after the swap: prove this is not a preflight rejection.
            $this->assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e);
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function failedCombinedRenameRollsBackRebuildAndRestoresLegacyPragma(): void
    {
        $this->pdo->exec('PRAGMA legacy_alter_table = ON');
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, gone TEXT)');
        $introspector = new class ($this->pdo) extends SqliteSchemaIntrospector {
            public function snapshot(string $table): \Glueful\Database\Schema\Sqlite\SqliteTableSnapshot
            {
                if ($table === 'renamed') {
                    throw new \RuntimeException('injected post-rename verification failure');
                }

                return parent::snapshot($table);
            }
        };

        try {
            (new SQLiteTableRebuilder($this->pdo, introspector: $introspector))->rebuild(
                SqliteAlterationPlan::fromChanges('t', [
                    'drop_columns' => ['gone'],
                    'rename_table' => 'renamed',
                ])
            );
            $this->fail('Expected post-rename verification failure');
        } catch (\RuntimeException $e) {
            // The injected failure happens after the rename, inside the transaction —
            // never a preflight rejection.
            $this->assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e);
            $this->assertSame(['id', 'gone'], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames());
            try {
                (new SqliteSchemaIntrospector($this->pdo))->snapshot('renamed');
                $this->fail('renamed table must not survive rollback');
            } catch (\RuntimeException) {
                // Expected: the original name and schema were restored atomically.
            }
        } finally {
            $legacy = $this->pdo->query('PRAGMA legacy_alter_table');
            $this->assertSame(1, (int) ($legacy !== false ? $legacy->fetchColumn() : 0));
        }
    }
}
```

The test file above is the core suite, not the ceiling. Before Step 2 is considered
complete, add explicit named tests for every remaining audit/restoration branch:

- malformed CHECK extraction; table-owned CHECK on a dropped column; `ON CONFLICT`,
  `MATCH`, `DEFERRABLE`/`INITIALLY`, named constraints, virtual tables, unsupported
  declared types, and temp/attached targets;
- untouched expression and partial indexes surviving verbatim; an attached trigger
  surviving; an external trigger dependency failing; `SELECT *` view uncertainty
  failing; and an inbound FK parent-column rename/drop failing before DDL;
- standalone mode with `foreign_keys` initially OFF restoring OFF; global
  post-rebuild FK violation rollback; exact (not lower-or-higher) sequence restoration;
- `legacy_alter_table` initially OFF and ON — for a combined rename *and* for a plain
  rebuild whose table is referenced by a view/external trigger (the forced-ON swap) —
  plus an injected post-rename verification failure proving the rebuild and rename both
  roll back and the prior pragma value returns;
- injected canonical verification mismatch rollback; implicit rowid preservation,
  INTEGER-PK alias non-duplication, and all three rowid aliases shadowed;
- FK-drop matching: an entry given as the local column name and an entry given as
  `fk_{table}_{column}` both resolve to the same constraint, while an unmatched name
  throws instead of silently dropping nothing;
- a standalone `drop_indexes` naming a `sqlite_autoindex_%` index routes through the
  rebuilder and removes the constraint, while dropping a named `CREATE INDEX` index does
  not trigger a rebuild.

Every fail-closed case compares the complete pre/post `sqlite_schema` dump and pragma
state. Because `UnsupportedSchemaOperationException` extends `\RuntimeException`, every
test that catches `\RuntimeException` to assert an **execution-stage** rollback must also
assert `assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e)` (or pin the
message); otherwise a preflight rejection would satisfy it spuriously. Coverage claims in
the task summary must be counted from these concrete test methods, not from the number
originally estimated.

- [ ] **Step 2: Run to verify failures** — class-not-found, then real behavior failures as the class grows.

- [ ] **Step 3: Implement `SQLiteTableRebuilder`** following the five-block structure in
the Interfaces section above. This is the largest single file of the feature (expect
~450–550 lines). Structural requirements the reviewer will hold you to:

- Constructor implements the exact nullable signature declared in **Interfaces**;
  assign generator/scanner/introspector defaults in the body to readonly properties.
  `SqliteSchemaIntrospector` remains non-final solely so version and verification gates
  can be exercised on the host SQLite library.
- `rebuild()` orchestration order: snapshot + all database-wide dependencies →
  `preflight()` (including a dry-run `compileTarget()`) → mode/state capture → global
  pre-check → begin transaction/savepoint → execute body → verify rebuilt target →
  optional native rename + dependency verification → global post-check → commit/release
  → restore pragmas. Every failure before commit/release rolls back the entire call.
- All pragma reads/writes go through small private helpers `pragmaInt(string $name): int`, `setPragma(string $name, string $value): void` with read-back verification everywhere the spec demands it: `foreign_keys` OFF, `legacy_alter_table` **ON** for the internal swap rename, `legacy_alter_table` **OFF** for the final user-visible rename, and every restoration. There is no single whole-operation value for `legacy_alter_table`.
- The copy statement quotes every identifier with the generator's `quoteIdentifier()`.
- Verification builds the expected **table shape** by executing the target DDL (plus the
  preserved named-index DDL) in a scratch `new \PDO('sqlite::memory:')` and comparing
  `toCanonicalArray()` outputs. Triggers are **never** replayed into the scratch
  database — a body referencing another table throws there; they are verified by
  comparing the live post-swap trigger rows against the preflight snapshot on
  `(name, whitespace-normalized SQL)`. Named-index SQL comparisons likewise use
  canonical tokens rather than raw text. `SqliteTableSnapshot`
  gains `public function toCanonicalArray(): array` (including CHECK scope/owner).
- The `finally` restoration of `foreign_keys` and `legacy_alter_table` throws `\RuntimeException` if the read-back does not match the captured value (never silently altered connection state).

- [ ] **Step 4: Run to verify pass** — `vendor/bin/phpunit tests/Unit/Database/Schema/Sqlite/` all green, plus the whole `tests/Unit/Database/Schema/` directory for collateral.

- [ ] **Step 5: Full-tree gates, then Commit checkpoint 2**

```bash
git add src/Database/Schema/Sqlite/SQLiteTableRebuilder.php src/Database/Schema/Sqlite/SqliteTableSnapshot.php tests/Unit/Database/Schema/Sqlite/SQLiteTableRebuilderTest.php tests/Unit/Database/Schema/Sqlite/SqliteModelRoundTripTest.php
git commit -m "feat(schema): add audited atomic SQLite table rebuilder"
```

---

### Task 6: Seam wiring — TableBuilder compile fix, dispatch, fail-closed generator

**Files:**
- Modify: `src/Database/Schema/Builders/TableBuilder.php` (`executeAlterations()` ~line 813)
- Modify: `src/Database/Schema/Interfaces/TableBuilderInterface.php` (expose table rename on the public builder)
- Modify: `src/Database/Schema/Interfaces/SchemaBuilderInterface.php` (procedural SQLite execution seams)
- Modify: `src/Database/Schema/Builders/SchemaBuilder.php` (add procedural SQLite rebuild/native execution; ~line 458 `addPendingOperation` region)
- Modify: `src/Database/Schema/Generators/SQLiteSqlGenerator.php` (`alterTable()` ~line 100, `modifyColumn()`/`dropColumn()` ~201-215, `addForeignKey()`/`dropForeignKey()` ~302-317)
- Modify: `src/Database/Schema/Generators/MySQLSqlGenerator.php` + `PostgreSQLSqlGenerator.php` (`alterTable()` — consume the newly-carried keys)
- Test: `tests/Integration/Database/Schema/SqliteAlterationCorrectnessTest.php`

**Interfaces:**
- Consumes: Tasks 1–5 exact names.
- Produces: the public contract — `$schema->alterTable('t', function ($table) { … })` on SQLite performs real rebuilds or throws; on MySQL/PG receives complete change-sets.

Before wiring, add `TableBuilderInterface::rename(string $newName): self` and the
matching `TableBuilder` method storing `_rename_table`; this mirrors the otherwise
disconnected `AlterTableBuilder::rename()` API and makes `rename_table` reachable from
the actual public builder used by `SchemaBuilder::alterTable()`. Any alteration-state
directive that is still unsupported (`dropPrimary()` and a non-null table comment in
this slice) throws `UnsupportedSchemaOperationException` during compile instead of
remaining absent from the change-set.

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Database/Schema/SqliteAlterationCorrectnessTest.php` — drives the
**public API** through a real `Connection` (follow the construction pattern used by
`tests/Unit/Database/Schema/SchemaBuilderAlterIndexTest.php` — read it first):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end: the six formerly-silent SQLite alteration paths now either
 * take real effect or throw — never a comment no-op. The concrete file-backed
 * Connection fixture below is transcribed from SchemaBuilderAlterIndexTest.
 */
final class SqliteAlterationCorrectnessTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    private function connection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'sqlite-alter-correctness-');
        self::assertIsString($this->dbPath);

        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    // Each test uses connection()->getSchemaBuilder(), creates its fixture
    // through the public builder, then drives alterTable(). This is the exact
    // harness pattern from SchemaBuilderAlterIndexTest; no nonexistent helper
    // class or invented fixture is imported.

    #[Test]
    public function modifyColumnTakesRealEffect(): void
    {
        // ->alterTable('users', fn ($t) => $t->modifyColumn('email')->text()->nullable())
        // then introspect: email is nullable, and its inline UNIQUE is gone because the
        // replacement definition did not declare unique (for a modified column the
        // replacement is authoritative). Repeat with ->unique() and assert the
        // constraint survives. Either way, assert the modification actually applied
        // (previously: silent no-op).
    }

    #[Test]
    public function dropColumnTakesRealEffect(): void
    {
    }

    #[Test]
    public function renameColumnStandaloneUsesNativeSql(): void
    {
        // rename only -> table NOT rebuilt: assert sqlite_schema rootpage of the
        // table is unchanged while the column list updated.
    }

    #[Test]
    public function addAndDropForeignKeyTakeRealEffect(): void
    {
    }

    #[Test]
    public function unsupportedOperationThrowsBeforeMutation(): void
    {
        // e.g. drop a column that a view depends on -> UnsupportedSchemaOperationException,
        // full sqlite_schema dump unchanged.
    }

    #[Test]
    public function combinedAlterationRunsExactlyOneRebuild(): void
    {
        // Use a tiny SchemaBuilder test subclass that increments a counter in
        // executeSqliteRebuild() and then delegates to parent. Drive one public
        // alterTable() call with mixed modify+drop+add+rename and assert the
        // counter is exactly 1 plus the final schema matches the combined target.
        // SchemaBuilder::preview() cannot expose procedural rebuilds and must not
        // be used as a proxy for this assertion.
    }

    #[Test]
    public function combinedRebuildAndTableRenameIsReachableThroughPublicBuilder(): void
    {
        // ->dropColumn('score')->rename('members') in one callback; assert users
        // no longer exists, members does, and the dropped column is absent.
    }

    #[Test]
    public function nativeMultiStatementAlterationRollsBackAsOneUnit(): void
    {
        // In one callback add a column, then request a rename of a missing
        // column so statement 2 fails. Assert statement 1 was rolled back and
        // the original schema is unchanged.
    }

    #[Test]
    public function unsupportedBuilderStateFailsInsteadOfDisappearing(): void
    {
        // dropPrimary(), add/modify-primary, alteration-time comment(), and
        // engine/charset/collation-style options each throw
        // UnsupportedSchemaOperationException before schema mutation.
    }

    #[Test]
    public function generatorAlterMethodsNeverReturnCommentSql(): void
    {
        // The spec's no-silent-success gate: call SQLiteSqlGenerator's
        // modifyColumn/dropColumn/addForeignKey/dropForeignKey directly and
        // alterTable() with each rebuild-triggering key — every one must throw
        // UnsupportedSchemaOperationException; none may return a string
        // beginning with "--".
    }

    #[Test]
    public function mysqlAndPostgresGeneratorsReceiveCompleteChangeSets(): void
    {
        // Unit-level: compile a TableBuilder with modify-column + rename-column
        // + dropForeign + rename-table against each generator and assert the
        // generated SQL contains the corresponding operations in safe order.
    }
}
```

The fixture is concrete. The remaining intent comments are mandatory assertions, not
optional examples: expand every body into executable setup/action/assertion code **before
editing production seam code**, run them to confirm the intended failures, and keep the
spy-based exactly-one-dispatch and native rollback tests. Do not replace them with a new
harness or with `SchemaBuilder::preview()`, which cannot observe procedural execution.

- [ ] **Step 2: Implement the TableBuilder compile fix** (driver-neutral). In
`executeAlterations()` replace the `$changes` construction:

First add the reachable table-rename method to both `TableBuilderInterface` and
`TableBuilder`:

```php
    public function rename(string $newName): self
    {
        if ($newName === '') {
            throw new \InvalidArgumentException('New table name cannot be empty');
        }
        $this->options['_rename_table'] = $newName;

        return $this;
    }
```

```php
        $addColumns = [];
        $modifyColumns = [];
        foreach ($this->columns as $column) {
            if (($column->options['_modify'] ?? false) === true) {
                $modifyColumns[] = $column;
            } else {
                $addColumns[] = $column;
            }
        }

        $renames = [];
        foreach ($this->options['_renames'] ?? [] as $rename) {
            $renames[$rename['from']] = $rename['to'];
        }

        $changes = [
            'add_columns'       => $addColumns,
            'modify_columns'    => $modifyColumns,
            'drop_columns'      => $this->options['_drops'] ?? [],
            'rename_columns'    => $renames,
            'add_indexes'       => $this->indexes,
            'drop_indexes'      => $this->options['_drop_indexes'] ?? [],
            'add_foreign_keys'  => $this->foreignKeys,
            'drop_foreign_keys' => $this->options['_drop_foreign_keys'] ?? [],
            'rename_table'      => $this->options['_rename_table'] ?? null,
        ];
        $changes = array_filter($changes, static fn ($v) => $v !== [] && $v !== null);
```

(Check how `ColumnBuilder` transfers its `_modify` option into the built
`ColumnDefinition` — if the option is consumed before the DTO is built, thread it
through `ColumnDefinition::$options['_modify']` when the builder finalizes; the
implementer must verify with a quick read of `ColumnBuilder::__destruct()` /
`finalize()` and note the mechanism in the report.)

Immediately before building `$changes`, reject currently unimplemented builder state
explicitly:

```php
        if (($this->options['_drop_primary'] ?? false) === true) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'drop_primary',
                'primary key',
                'the fluent alter seam does not yet implement primary-key removal'
            );
        }
        if ($this->primaryKey !== []) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'add_or_modify_primary',
                'primary key',
                'primary-key alteration is not implemented by the fluent alter seam'
            );
        }
        if ($this->comment !== null) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'comment',
                'table comment',
                'table-comment alteration is not implemented by the fluent alter seam'
            );
        }
        $handledOptionKeys = [
            '_drops', '_renames', '_drop_indexes', '_drop_foreign_keys',
            '_drop_primary', '_rename_table',
        ];
        foreach (array_keys($this->options) as $optionKey) {
            if (!in_array($optionKey, $handledOptionKeys, true)) {
                throw UnsupportedSchemaOperationException::forFeature(
                    $this->tableName,
                    'table_option',
                    (string) $optionKey,
                    'this alteration-time table option has no implemented SQL path'
                );
            }
        }
```

This is deliberately fail-closed on every driver until those operations have complete
generator coverage; it replaces today’s false success for drop/add-primary, comments,
and engine/charset/collation-style alteration options.

Then dispatch:

```php
        if ($this->sqlGenerator instanceof \Glueful\Database\Schema\Generators\SQLiteSqlGenerator) {
            $plan = \Glueful\Database\Schema\Sqlite\SqliteAlterationPlan::fromChanges($this->tableName, $changes);
            if ($plan->requiresRebuild()) {
                $this->schemaBuilder->executeSqliteRebuild($plan);
                return;
            }

            $statements = $this->sqlGenerator->alterTable($tableDefinition, $changes);
            $this->schemaBuilder->executeSqliteNativeAlteration($statements);
            return;
        }

        $sqlStatements = $this->sqlGenerator->alterTable($tableDefinition, $changes);
        foreach ($sqlStatements as $sql) {
            $this->schemaBuilder->addPendingOperation($sql);
        }
```

(Use `use` imports at the top of the file rather than inline FQCNs — project
convention.)

- [ ] **Step 3: Implement the procedural SQLite execution seams** (next to
`addPendingOperation()`):

```php
    /**
     * Run a procedural SQLite table rebuild.
     *
     * Rebuilds cannot be queued as SQL strings, so all previously queued
     * operations are flushed IN ORDER first, then the rebuild executes
     * immediately; subsequent operations queue as normal.
     */
    public function executeSqliteRebuild(\Glueful\Database\Schema\Sqlite\SqliteAlterationPlan $plan): void
    {
        $this->execute();

        $rebuilder = new \Glueful\Database\Schema\Sqlite\SQLiteTableRebuilder($this->connection->getPDO());
        $rebuilder->rebuild($plan);
    }

    /**
     * Execute one native SQLite alteration call atomically.
     *
     * Flush earlier queued operations first. The statements belonging to this
     * alterTable() call then execute inside an own transaction, or a unique
     * savepoint when the caller already owns a transaction. Any statement
     * failure rolls back every native change from this call.
     *
     * @param list<string> $statements
     */
    public function executeSqliteNativeAlteration(array $statements): void
    {
        $this->execute();
        if ($statements === []) {
            return;
        }

        $pdo = $this->connection->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'glueful_native_' . bin2hex(random_bytes(4));
        $scopeStarted = false;

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $scopeStarted = true;
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (\Throwable $failure) {
            try {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                } elseif (!$ownsTransaction && $scopeStarted) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'SQLite native alteration failed and rollback also failed: '
                    . $rollbackFailure->getMessage(),
                    0,
                    $failure
                );
            }

            throw $failure;
        }
    }
```

(with `use` imports). `SchemaBuilderInterface` already declares
`addPendingOperation()`, so **both methods must be added to the interface**; this is not
optional. Native statement ordering in `SQLiteSqlGenerator::alterTable()` is: add
columns → rename columns → drop/add indexes → final `rename_table`. Only named
`CREATE INDEX` indexes reach the native drop path — `SqliteAlterationPlan::requiresRebuild()`
routes any `drop_indexes` entry matching `sqlite_autoindex_%` to the rebuilder, because
SQLite refuses `DROP INDEX` on a constraint-backed index. Index statements
use the original table name and SQLite carries them with the final table rename. This
avoids creating an index against an already-renamed, now-missing old table.

- [ ] **Step 4: Flip the generator to fail-closed.** In `SQLiteSqlGenerator`:
`modifyColumn()`, `dropColumn()`, `addForeignKey()`, `dropForeignKey()` each throw:

```php
    public function modifyColumn(string $table, ColumnDefinition $column): string
    {
        throw UnsupportedSchemaOperationException::forFeature(
            $table,
            'modify_column',
            "column \"{$column->name}\"",
            'SQLite cannot modify columns natively; route through the schema builder, '
            . 'which performs an audited table rebuild'
        );
    }
```

(same shape for the other three, with `drop_column` / `add_foreign_key` /
`drop_foreign_key` operation strings). In `alterTable()`, replace the
comment-appending `modify/drop` branch with the same throw (triggered when those keys
are present), and add explicit throws for `add_foreign_keys`/`drop_foreign_keys` keys.
`rename_columns` is native and must not be included in that throw list:

```php
        if (isset($changes['rename_columns']) && $changes['rename_columns'] !== []) {
            foreach ($changes['rename_columns'] as $from => $to) {
                $statements[] = $this->renameColumn($table->name, (string) $from, (string) $to);
            }
        }
```

Move the existing `rename_table` block to the **end** of `alterTable()` so a combined
native plan operates on the old table until its final statement. The procedural native
seam makes the entire statement list atomic.

The rebuild-triggering keys throw in the generator because the schema-builder dispatch
routes them away before this point — the generator throw is the terminal backstop for
direct API use.

- [ ] **Step 5: MySQL/PG generators consume the newly-carried keys.** Read each
generator's `alterTable()`; where `modify_columns`, `rename_columns`,
`drop_foreign_keys`, or `rename_table` are not consumed, add loops/delegation to the
generator's existing `modifyColumn()` / `renameColumn()` / `dropForeignKey()` /
`renameTable()` methods. Assert all four through
`mysqlAndPostgresGeneratorsReceiveCompleteChangeSets`; table rename must be the final
statement so earlier operations still target the original name.

- [ ] **Step 6: Make the integration tests pass**; run
`vendor/bin/phpunit tests/Unit/Database/ tests/Integration/Database/` for collateral —
pre-existing schema tests must stay green (they use add-column/index paths that keep
native SQL).

---

### Task 7: Bookkeeping and final gates

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]`)
- Modify: `docs/DATABASE_NATIVE_ROADMAP.md` (item 3)
- Modify: `src/Database/Migrations/MigrationManager.php` (docblock only, ~lines 26 and 395)

- [ ] **Step 1: CHANGELOG** — under `## [Unreleased]`:

```markdown
### Added
- **SQLite table rebuilds** (`Glueful\Database\Schema\Sqlite`) — the schema builder now
  performs SQLite's documented create-copy-swap procedure for alterations SQLite cannot
  express natively: modify column, drop column, add/drop foreign key, drop inline
  unique constraint, and combinations of those in one `alterTable()` call (exactly one
  rebuild per call). The rebuild is audited before any DDL runs (a preservation audit
  fails closed on anything it cannot reconstruct: generated columns, COLLATE, composite
  foreign keys, expression uniques, indexes/triggers/views referencing changed columns,
  `journal_mode=OFF`, an open transaction with `foreign_keys` ON), atomic (own
  transaction or savepoint; global `PRAGMA foreign_key_check` before mutation and
  before commit; `foreign_keys`/`legacy_alter_table` state captured, restored, and
  verified), and verified (the rebuilt table is re-introspected and canonically
  compared against the planned target — including preserved indexes, triggers, table
  options, rowids, and the `sqlite_sequence` high-water mark). Combined
  rebuild+rename requires SQLite ≥ 3.26.0. New
  `UnsupportedSchemaOperationException` carries table, operation, feature, and reason.

### Fixed
- **Six SQLite alteration paths silently did nothing.** `modifyColumn`/`dropColumn`/
  `addForeignKey`/`dropForeignKey` generated SQL *comments* that executed as successful
  no-ops, and `alterTable()` discarded `rename_columns` and foreign-key changes
  entirely — migrations "passed" on SQLite while leaving the schema untouched. All six
  paths now take real effect (rebuild or native SQL) or throw before mutation.
- **`TableBuilder` compiled modified columns as additions** and dropped
  rename/drop-foreign-key changes from the change-set on every driver; MySQL and
  PostgreSQL generators now receive complete change-sets from the fluent alter path.
  The public builder now exposes table rename; `dropPrimary()` and alteration-time
  table comments, which remain outside this slice, throw explicitly instead of being
  silently discarded. Native multi-statement SQLite alteration calls execute as one
  transaction/savepoint.

### Upgrade Notes
- Migrations that previously "succeeded" on SQLite by silently doing nothing will now
  either take real effect or fail with `UnsupportedSchemaOperationException`. Audit
  SQLite-targeting migrations that modify/drop columns or foreign keys: their intent
  will now actually apply.
- Fluent `dropPrimary()` and table-comment alterations now fail closed until their
  cross-driver implementations are completed; they no longer report false success.
- `MigrationManager`'s docblock claimed migrations run inside a transaction; they never
  did, and the docblock now says so (behavior unchanged).
```

- [ ] **Step 2: Roadmap** — item 3 heading → `### 3. Complete SQLite rebuild operations — DONE (see CHANGELOG [Unreleased])`, and replace the body's "the known gap…" sentence with a one-paragraph summary of what shipped (audited atomic rebuild for the six formerly-silent paths; spec: `docs/superpowers/specs/2026-08-07-sqlite-alteration-correctness-design.md`).

- [ ] **Step 3: MigrationManager docblock** — line ~26: replace "Each migration is executed within a transaction and tracked in the" with "Each migration executes its schema operations immediately (no wrapping transaction) and is tracked in the"; line ~395: replace "3. Executes migration within transaction" with "3. Executes migration (schema operations apply immediately; there is no wrapping transaction)".

- [ ] **Step 4: Full CI-parity gates**

```bash
vendor/bin/phpunit
composer run phpcs
vendor/bin/phpstan clear-result-cache && composer run analyse
```

- [ ] **Step 5: Commit checkpoint 3**

```bash
git add src/Database/Schema/Interfaces/TableBuilderInterface.php src/Database/Schema/Interfaces/SchemaBuilderInterface.php src/Database/Schema/Builders/TableBuilder.php src/Database/Schema/Builders/SchemaBuilder.php src/Database/Schema/Generators/SQLiteSqlGenerator.php src/Database/Schema/Generators/MySQLSqlGenerator.php src/Database/Schema/Generators/PostgreSQLSqlGenerator.php src/Database/Migrations/MigrationManager.php tests/Integration/Database/Schema/SqliteAlterationCorrectnessTest.php CHANGELOG.md docs/DATABASE_NATIVE_ROADMAP.md
git commit -m "feat(schema): fail-closed SQLite alterations via audited rebuild dispatch"
```
