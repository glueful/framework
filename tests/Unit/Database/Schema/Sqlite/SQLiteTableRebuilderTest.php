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
use PHPUnit\Framework\Attributes\DataProvider;
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
        // The result set MUST be consumed: SQLite's OP_Destroy (DROP TABLE) returns
        // SQLITE_LOCKED "database table is locked" while any read cursor is still
        // active on the connection, and every create-copy-swap rebuild has to drop
        // the original table. fetchColumn() alone does not release it.
        $this->assertSame(1, (int) $before->fetchColumn());
        $before->closeCursor();

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

    #[Test]
    public function unscannableTableDefinitionFailsClosed(): void
    {
        // A bare-keyword column name defeats CREATE TABLE clause-ownership
        // classification, so CHECK ownership cannot be established. Uncertainty
        // about the table's own definition is fatal, not best-effort.
        $this->pdo->exec('CREATE TABLE t (key INTEGER, x TEXT)');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['x'],
            ]));
            $this->fail('Expected unscannable-definition rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertSame('introspection', $e->operation());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    /** @return iterable<string, array{ddl: list<string>, feature: string}> */
    public static function unrepresentableTableShapes(): iterable
    {
        yield 'on conflict clause' => ['ddl' => [
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "e" TEXT UNIQUE ON CONFLICT REPLACE, "x" TEXT)',
        ], 'feature' => 'ON CONFLICT'];
        yield 'foreign key match clause' => ['ddl' => [
            'CREATE TABLE parents ("id" INTEGER PRIMARY KEY)',
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "p" INTEGER REFERENCES parents("id") MATCH SIMPLE, "x" TEXT)',
        ], 'feature' => 'MATCH'];
        yield 'deferrable constraint clause' => ['ddl' => [
            'CREATE TABLE parents ("id" INTEGER PRIMARY KEY)',
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, '
            . '"p" INTEGER REFERENCES parents("id") DEFERRABLE INITIALLY DEFERRED, "x" TEXT)',
        ], 'feature' => 'DEFERRABLE'];
        yield 'explicitly named constraint' => ['ddl' => [
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "q" INTEGER, "x" TEXT, CONSTRAINT ck_q CHECK ("q" > 0))',
        ], 'feature' => 'CONSTRAINT'];
        yield 'unmappable declared type' => ['ddl' => [
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "amount" NUMERIC, "x" TEXT)',
        ], 'feature' => 'declared type'];
        yield 'virtual table' => ['ddl' => [
            'CREATE VIRTUAL TABLE t USING fts5(x)',
        ], 'feature' => 'virtual table'];
    }

    /** @param list<string> $ddl */
    #[Test]
    #[DataProvider('unrepresentableTableShapes')]
    public function unrepresentableTableShapesFailPreflight(array $ddl, string $feature): void
    {
        foreach ($ddl as $statement) {
            $this->pdo->exec($statement);
        }
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['x'],
            ]));
            $this->fail("Expected rejection naming {$feature}");
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString($feature, $e->feature());
            $this->assertSame($before, $this->schemaDump(), 'no mutation before the throw');
        }
    }

    #[Test]
    public function schemaQualifiedAndTemporaryTargetsFailBeforeIntrospection(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "x" TEXT)');
        $this->pdo->exec('CREATE TEMP TABLE tmp_t ("id" INTEGER, "x" TEXT)');
        $before = $this->schemaDump();

        foreach ([['main.t', null], ['t', 'other.t'], ['tmp_t', null]] as [$table, $rename]) {
            $changes = ['drop_columns' => ['x']];
            if ($rename !== null) {
                $changes['rename_table'] = $rename;
            }

            try {
                $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges($table, $changes));
                $this->fail("Expected addressing rejection for \"{$table}\"");
            } catch (UnsupportedSchemaOperationException) {
                $this->assertSame($before, $this->schemaDump());
            }
        }
    }

    #[Test]
    public function untouchedExpressionPartialIndexesAndAttachedTriggerSurviveVerbatim(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "keep" TEXT, "flag" TEXT, "gone" TEXT)');
        $this->pdo->exec('CREATE INDEX t_keep_length ON t (LENGTH("keep"))');
        $this->pdo->exec('CREATE INDEX t_flag_partial ON t ("flag") WHERE "flag" = \'on\'');
        $this->pdo->exec('CREATE TRIGGER t_touch AFTER INSERT ON t BEGIN SELECT NEW.keep; END');
        $introspector = new SqliteSchemaIntrospector($this->pdo);
        $before = $introspector->snapshot('t');

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        $after = $introspector->snapshot('t');
        $beforeIndexes = array_column($before->namedIndexes(), 'sql', 'name');
        $afterIndexes = array_column($after->namedIndexes(), 'sql', 'name');
        ksort($beforeIndexes);
        ksort($afterIndexes);
        $this->assertSame(
            $beforeIndexes,
            $afterIndexes,
            'expression and partial index DDL replays verbatim'
        );
        $this->assertSame(
            array_column($before->triggers, 'sql', 'name'),
            array_column($after->triggers, 'sql', 'name'),
            'attached trigger replays verbatim'
        );
    }

    #[Test]
    public function externalTriggerReferencingAChangedColumnFailsClosed(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "gone" TEXT)');
        $this->pdo->exec('CREATE TABLE child ("t_id" INTEGER, "note" TEXT)');
        $this->pdo->exec(
            'CREATE TRIGGER child_write AFTER UPDATE ON child '
            . 'BEGIN UPDATE t SET gone = NEW.note WHERE id = NEW.t_id; END'
        );
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['gone'],
            ]));
            $this->fail('Expected external-trigger rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('child_write', $e->feature());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function wildcardViewDependencyFailsClosed(): void
    {
        // The view names no changed column, so only the wildcard makes the
        // dependency unprovable — SQLite exposes no column metadata for views.
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "gone" TEXT)');
        $this->pdo->exec('CREATE VIEW t_all AS SELECT * FROM t');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'drop_columns' => ['gone'],
            ]));
            $this->fail('Expected wildcard-view rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('t_all', $e->feature());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function inboundForeignKeyParentColumnChangeFailsClosed(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "code" TEXT UNIQUE, "x" TEXT)');
        $this->pdo->exec('CREATE TABLE child ("code" TEXT REFERENCES t("code"))');
        $before = $this->schemaDump();

        foreach ([
            ['drop_columns' => ['code']],
            ['rename_columns' => ['code' => 'slug']],
        ] as $changes) {
            try {
                $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', $changes));
                $this->fail('Expected inbound foreign-key rejection');
            } catch (UnsupportedSchemaOperationException $e) {
                $this->assertStringContainsString('inbound foreign key', $e->feature());
                $this->assertSame($before, $this->schemaDump());
            }
        }
    }

    #[Test]
    public function standaloneModeRestoresForeignKeysOffWhenInitiallyOff(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT, "b" TEXT)');

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['b'],
        ]));

        $this->assertSame(['id', 'a'], (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->columnNames());
        $foreignKeys = $this->pdo->query('PRAGMA foreign_keys');
        $this->assertSame(0, (int) ($foreignKeys !== false ? $foreignKeys->fetchColumn() : 1), 'prior OFF restored');
    }

    #[Test]
    public function foreignKeyViolationIntroducedByTheRebuildRollsBack(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER)');
        $this->pdo->exec('INSERT INTO teams (id) VALUES (1)');
        $this->pdo->exec('INSERT INTO t (team_id) VALUES (999)'); // legal until the FK exists
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'add_foreign_keys' => [new ForeignKeyDefinition(
                    localColumn: 'team_id',
                    referencedTable: 'teams',
                    referencedColumn: 'id',
                    name: 't_team_id_fk'
                )],
            ]));
            $this->fail('Expected post-change foreign-key rejection');
        } catch (\RuntimeException $e) {
            // The pre-existing check passed (no constraint existed yet); this is
            // the pre-commit check catching a violation the rebuild introduced.
            $this->assertNotInstanceOf(UnsupportedSchemaOperationException::class, $e);
            $this->assertStringContainsString('foreign_key_check', $e->getMessage());
            $this->assertSame($before, $this->schemaDump(), 'original table intact after rollback');
        }
    }

    #[Test]
    public function sequenceHighWaterMarkIsRestoredExactlyWhenEveryRowIsGone(): void
    {
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "keep" TEXT, "gone" TEXT)');
        $this->pdo->exec("INSERT INTO t (keep, gone) VALUES ('a','1'),('b','2'),('c','3'),('d','4'),('e','5')");
        $this->pdo->exec('DELETE FROM t'); // no surviving row can imply the mark

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        $seq = $this->pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 't'");
        $this->assertSame(5, (int) ($seq !== false ? $seq->fetchColumn() : 0), 'exactly the captured mark');
        $this->pdo->exec("INSERT INTO t (keep) VALUES ('f')");
        $next = $this->pdo->query('SELECT MAX(id) FROM t');
        $this->assertSame(6, (int) ($next !== false ? $next->fetchColumn() : 0));
    }

    #[Test]
    public function plainRebuildUnderDependentsRestoresLegacyAlterTableWhenInitiallyOn(): void
    {
        $this->pdo->exec('PRAGMA legacy_alter_table = ON');
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
        $this->assertSame(1, (int) ($legacy !== false ? $legacy->fetchColumn() : 0), 'prior ON state restored');
    }

    #[Test]
    public function integerPrimaryKeyAliasIsCopiedOnceAsItsNamedColumn(): void
    {
        // The alias IS the rowid: listing it again as "rowid" would be a
        // duplicate column in the copy statement.
        $this->pdo->exec('CREATE TABLE t ("id" INTEGER PRIMARY KEY, "keep" TEXT, "gone" TEXT)');
        $this->pdo->exec("INSERT INTO t (id, keep, gone) VALUES (7,'a','x'), (9,'b','y')");

        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_columns' => ['gone'],
        ]));

        // "rowid" is aliased out: SQLite reports the result column under the
        // alias column's own name, which would collide with "id" in FETCH_ASSOC.
        $rows = $this->pdo->query('SELECT rowid AS row_id, id, keep FROM t ORDER BY id');
        $this->assertSame(
            [['row_id' => 7, 'id' => 7, 'keep' => 'a'], ['row_id' => 9, 'id' => 9, 'keep' => 'b']],
            array_map(
                static fn (array $r): array => [
                    'row_id' => (int) $r['row_id'],
                    'id' => (int) $r['id'],
                    'keep' => $r['keep'],
                ],
                $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : []
            )
        );
    }

    #[Test]
    public function introducingARowidAliasFailsClosed(): void
    {
        // A TEXT primary key is not a rowid alias; turning it into an INTEGER
        // one would silently reseat the table's rowids.
        $this->pdo->exec('CREATE TABLE t ("a" TEXT PRIMARY KEY, "x" TEXT)');
        $before = $this->schemaDump();

        try {
            $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                'modify_columns' => [
                    new ColumnDefinition(name: 'a', type: 'integer', nullable: false, primary: true),
                ],
            ]));
            $this->fail('Expected rowid-alias rejection');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertStringContainsString('INTEGER PRIMARY KEY alias', $e->feature());
            $this->assertSame($before, $this->schemaDump());
        }
    }

    #[Test]
    public function foreignKeyDropMatchesGeneratedConstraintName(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id"))'
        );

        // ColumnBuilder generates exactly fk_{table}_{column}; the local column
        // name resolves the same constraint (proved by addAndDropForeignKeysRebuild).
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_foreign_keys' => ['fk_t_team_id'],
        ]));

        $this->assertCount(0, (new SqliteSchemaIntrospector($this->pdo))->snapshot('t')->foreignKeys);
    }

    #[Test]
    public function unmatchedForeignKeyDropNameFailsClosed(): void
    {
        $this->pdo->exec('CREATE TABLE teams ("id" INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "team_id" INTEGER, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id"))'
        );
        $before = $this->schemaDump();

        foreach (['t_team_id_fk0', 'fk_t_nope'] as $name) {
            try {
                $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
                    'drop_foreign_keys' => [$name],
                ]));
                $this->fail("Expected rejection for unmatched constraint \"{$name}\"");
            } catch (UnsupportedSchemaOperationException $e) {
                $this->assertSame('drop_foreign_keys', $e->operation());
                $this->assertSame($before, $this->schemaDump());
            }
        }
    }

    #[Test]
    public function autoIndexDropRebuildsWhileNamedIndexDropDoesNot(): void
    {
        $this->pdo->exec(
            'CREATE TABLE t ("id" INTEGER PRIMARY KEY, "a" TEXT, "b" TEXT, UNIQUE ("a", "b"))'
        );
        $this->pdo->exec('CREATE INDEX t_b_index ON t ("b")');
        $introspector = new SqliteSchemaIntrospector($this->pdo);
        $autoIndex = $introspector->snapshot('t')->autoIndexes()[0]['name'];

        // SQLite refuses DROP INDEX on a constraint-backed index, so only that
        // entry forces the plan through the rebuilder.
        $this->assertTrue(
            SqliteAlterationPlan::fromChanges('t', ['drop_indexes' => [$autoIndex]])->requiresRebuild()
        );
        $this->assertFalse(
            SqliteAlterationPlan::fromChanges('t', ['drop_indexes' => ['t_b_index']])->requiresRebuild()
        );

        $this->pdo->exec("INSERT INTO t (a, b) VALUES ('1', '2')");
        $this->rebuilder()->rebuild(SqliteAlterationPlan::fromChanges('t', [
            'drop_indexes' => [$autoIndex],
        ]));

        $after = $introspector->snapshot('t');
        $this->assertSame([], $after->autoIndexes(), 'the constraint is gone');
        $this->assertSame(['t_b_index'], array_column($after->namedIndexes(), 'name'), 'named index survives');
        $this->pdo->exec("INSERT INTO t (a, b) VALUES ('1', '2')"); // previously rejected
        $count = $this->pdo->query('SELECT COUNT(*) FROM t');
        $this->assertSame(2, (int) ($count !== false ? $count->fetchColumn() : 0));
    }
}
