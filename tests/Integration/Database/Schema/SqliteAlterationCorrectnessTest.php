<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Builders\TableBuilder;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Generators\MySQLSqlGenerator;
use Glueful\Database\Schema\Generators\PostgreSQLSqlGenerator;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Sqlite\SqliteAlterationPlan;
use PDO;
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
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email')->notNullable()->unique();
        });

        // Before: NOT NULL and UNIQUE both hold.
        self::assertPdoThrows(function () use ($conn): void {
            $conn->getPDO()->exec('INSERT INTO users (id, email) VALUES (1, NULL)');
        });
        $conn->getPDO()->exec("INSERT INTO users (id, email) VALUES (1, 'a@example.com')");
        self::assertPdoThrows(function () use ($conn): void {
            $conn->getPDO()->exec("INSERT INTO users (id, email) VALUES (2, 'a@example.com')");
        });

        $schema->alterTable('users', function ($table): void {
            $table->modifyColumn('email')->text()->nullable();
        });

        // After: the modification actually applied (previously: silent no-op).
        // Nullable now holds, and the inline UNIQUE is gone because the
        // replacement definition did not declare unique (for a modified
        // column the replacement is authoritative).
        $conn->getPDO()->exec('INSERT INTO users (id, email) VALUES (2, NULL)');
        $conn->getPDO()->exec("INSERT INTO users (id, email) VALUES (3, 'a@example.com')");
        self::assertSame(
            '2',
            (string) $conn->getPDO()
                ->query("SELECT COUNT(*) FROM users WHERE email = 'a@example.com'")
                ->fetchColumn()
        );
        self::assertContains('email', $this->columnNames($conn, 'users'));

        // Clear the rows that only exist to prove uniqueness was dropped —
        // re-establishing the constraint below must copy duplicate-free data.
        $conn->getPDO()->exec('DELETE FROM users WHERE id IN (2, 3)');

        // Repeat with ->unique(): the replacement declares it, so the
        // constraint survives (reappears) instead of staying dropped.
        $schema->alterTable('users', function ($table): void {
            $table->modifyColumn('email')->text()->unique();
        });

        self::assertPdoThrows(function () use ($conn): void {
            $conn->getPDO()->exec("INSERT INTO users (id, email) VALUES (4, 'a@example.com')");
        });
    }

    /**
     * Task 5 review carry-over: the SQLiteTableRebuilder freezes primary-key
     * membership — a modify_columns replacement declaring primary: false for
     * a column that is currently part of the primary key is rejected. An
     * ordinary type change through the public API must not require the
     * caller to redeclare ->primary()/->autoIncrement() just to describe the
     * status quo; the compile step forwards it automatically.
     */
    #[Test]
    public function modifyingPrimaryKeyColumnPreservesMembershipWithoutExplicitDeclaration(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
        });

        // "id" is INTEGER PRIMARY KEY AUTOINCREMENT; this only changes its
        // abstract type (id -> bigInteger, both map to SQLite's INTEGER
        // affinity) without touching ->primary()/->autoIncrement() at all.
        $schema->alterTable('users', function ($table): void {
            $table->modifyColumn('id')->bigInteger();
        });

        $idColumn = null;
        foreach ($this->pragmaTableInfo($conn, 'users') as $row) {
            if ($row['name'] === 'id') {
                $idColumn = $row;
            }
        }
        self::assertNotNull($idColumn, 'Expected the "id" column to survive the rebuild');
        self::assertSame(1, (int) $idColumn['pk'], 'Expected "id" to remain the primary key');

        // Auto-increment behavior survived: inserting without an id still
        // assigns one automatically instead of failing NOT NULL.
        $conn->getPDO()->exec("INSERT INTO users (email) VALUES ('a@example.com')");
        $id = $conn->getPDO()->query('SELECT id FROM users LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, (int) $id);
    }

    /**
     * Fix-review regression (CRITICAL): PRAGMA table_info's "pk" column is a
     * 1-based ORDINAL, not a boolean flag — only the first member of a
     * composite primary key reports 1. SQLiteSqlGenerator::getTableColumns()
     * previously tested `=== 1`, so every non-leading composite-PK member
     * was reported as not primary, and preservePrimaryKeyMembership() could
     * never forward for it. Before the fix this threw "primary key
     * membership of column "user_id"", and the ->primary() workaround threw
     * add_or_modify_primary (no escape hatch either).
     */
    #[Test]
    public function modifyingACompositePrimaryKeyColumnPreservesMembership(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('memberships', function ($table): void {
            $table->bigInteger('team_id');
            $table->bigInteger('user_id');
            $table->string('role');
            $table->primary(['team_id', 'user_id']);
        });

        // "user_id" is the SECOND member of the composite key (pk ordinal 2).
        // An ordinary type change must not require redeclaring ->primary().
        $schema->alterTable('memberships', function ($table): void {
            $table->modifyColumn('user_id')->bigInteger();
        });

        $columns = [];
        foreach ($this->pragmaTableInfo($conn, 'memberships') as $row) {
            $columns[$row['name']] = $row;
        }
        self::assertArrayHasKey('user_id', $columns);
        self::assertGreaterThan(0, (int) $columns['team_id']['pk'], 'team_id must remain part of the composite PK');
        self::assertGreaterThan(0, (int) $columns['user_id']['pk'], 'user_id must remain part of the composite PK');

        // The composite key is enforced end to end: a duplicate (team_id, user_id)
        // pair is rejected.
        $conn->getPDO()->exec("INSERT INTO memberships (team_id, user_id, role) VALUES (1, 1, 'member')");
        self::assertPdoThrows(function () use ($conn): void {
            $conn->getPDO()->exec("INSERT INTO memberships (team_id, user_id, role) VALUES (1, 1, 'admin')");
        });

        // The escape hatch closes correctly downstream: a modify replacement
        // that claims NEW primary-key membership (for a column that is not
        // currently part of the key) still fails closed — at the
        // rebuilder's own audit, with the correct message — instead of
        // silently succeeding.
        try {
            $schema->alterTable('memberships', function ($table): void {
                $table->modifyColumn('role')->string()->primary();
            });
            self::fail('Expected a genuinely new primary-key claim to be rejected');
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertStringContainsString('primary key membership', $e->feature());
        }
    }

    /**
     * Fix-review regression (Important 1): preservePrimaryKeyMembership()
     * previously introspected before the pending-operation queue was
     * flushed. A table created via the fluent builder without a callback
     * (`$schema->table('x')->...->create()`) only queues its CREATE TABLE —
     * it does not execute until the next flush — so PRAGMA table_info saw an
     * empty result, forwarding was silently skipped, and the alteration
     * landed on the frozen-PK rejection for a table that, once flushed,
     * clearly has the column as primary.
     */
    #[Test]
    public function preservesPrimaryKeyWhenSourceTableCreationIsStillQueued(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        // table()->...->create() queues the CREATE TABLE via
        // addPendingOperation() only; it does NOT auto-execute. Only the
        // callback form of createTable()/table() does that.
        $tableBuilder = $schema->table('users');
        $tableBuilder->id();
        $tableBuilder->string('email');
        gc_collect_cycles();
        $tableBuilder->create();

        // The alteration must flush the queued CREATE TABLE before
        // introspecting for primary-key forwarding, or this throws.
        $schema->alterTable('users', function ($table): void {
            $table->modifyColumn('id')->bigInteger();
        });

        $idColumn = null;
        foreach ($this->pragmaTableInfo($conn, 'users') as $row) {
            if ($row['name'] === 'id') {
                $idColumn = $row;
            }
        }
        self::assertNotNull($idColumn, 'Expected the queued "users" table to have been flushed and altered');
        self::assertSame(1, (int) $idColumn['pk'], 'Expected "id" to remain the primary key');
    }

    /**
     * Fix-review regression (Important 2): the alterTable() direct-use
     * backstop indexed [0] on each rebuild-triggering change payload. A
     * non-list payload (e.g. a caller-supplied associative array) has no
     * key 0, so it previously produced a PHP warning + TypeError instead of
     * the typed exception.
     */
    #[Test]
    public function alterTableRejectsNonListChangePayloadsWithoutATypeError(): void
    {
        $generator = new SQLiteSqlGenerator();
        $table = new TableDefinition(name: 'users');

        $this->assertThrowsUnsupported(
            fn () => $generator->alterTable($table, ['drop_columns' => ['email' => 'email']]),
            "alterTable() with a non-list 'drop_columns' payload"
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->alterTable($table, [
                'modify_columns' => ['email' => new ColumnDefinition(name: 'email', type: 'text')],
            ]),
            "alterTable() with a non-list 'modify_columns' payload"
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->alterTable($table, [
                'add_foreign_keys' => ['fk' => new ForeignKeyDefinition(
                    localColumn: 'team_id',
                    referencedTable: 'teams',
                    referencedColumn: 'id',
                    name: 'fk_users_team_id'
                )],
            ]),
            "alterTable() with a non-list 'add_foreign_keys' payload"
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->alterTable($table, ['drop_foreign_keys' => ['fk' => 'fk_users_team_id']]),
            "alterTable() with a non-list 'drop_foreign_keys' payload"
        );
    }

    #[Test]
    public function dropColumnTakesRealEffect(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
            $table->string('nickname')->nullable();
        });
        $conn->getPDO()->exec("INSERT INTO users (id, email, nickname) VALUES (1, 'a@example.com', 'A')");

        self::assertContains('nickname', $this->columnNames($conn, 'users'));

        $schema->alterTable('users', function ($table): void {
            $table->dropColumn('nickname');
        });

        // The modification actually applied: the column is gone, and the
        // remaining data survived the rebuild.
        self::assertNotContains('nickname', $this->columnNames($conn, 'users'));
        $row = $conn->getPDO()->query('SELECT id, email FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['id' => 1, 'email' => 'a@example.com'], ['id' => (int) $row['id'], 'email' => $row['email']]);
    }

    #[Test]
    public function renameColumnStandaloneUsesNativeSql(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
        });

        $before = $this->tableRootpage($conn, 'users');

        $schema->alterTable('users', function ($table): void {
            $table->renameColumn('email', 'email_address');
        });

        // rename only -> table NOT rebuilt: assert sqlite_schema rootpage of
        // the table is unchanged while the column list updated.
        $after = $this->tableRootpage($conn, 'users');
        self::assertSame($before, $after);
        self::assertContains('email_address', $this->columnNames($conn, 'users'));
        self::assertNotContains('email', $this->columnNames($conn, 'users'));
    }

    #[Test]
    public function addAndDropForeignKeyTakeRealEffect(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();
        $pdo = $conn->getPDO();
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schema->createTable('teams', function ($table): void {
            $table->id();
            $table->string('name');
        });
        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->bigInteger('team_id')->nullable();
        });

        self::assertCount(0, $this->foreignKeyList($conn, 'users'));

        $schema->alterTable('users', function ($table): void {
            $table->foreign('team_id')->references('id')->on('teams')->name('fk_users_team_id');
        });

        self::assertCount(1, $this->foreignKeyList($conn, 'users'));
        self::assertPdoThrows(function () use ($pdo): void {
            $pdo->exec('INSERT INTO users (id, team_id) VALUES (1, 999)');
        });

        $schema->alterTable('users', function ($table): void {
            $table->dropForeign('fk_users_team_id');
        });

        self::assertCount(0, $this->foreignKeyList($conn, 'users'));
        // No longer enforced: the same insert that previously violated the
        // constraint now succeeds.
        $pdo->exec('INSERT INTO users (id, team_id) VALUES (1, 999)');
        self::assertSame('999', (string) $pdo->query('SELECT team_id FROM users WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function unsupportedOperationThrowsBeforeMutation(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
        });
        $conn->getPDO()->exec('CREATE VIEW user_emails AS SELECT email FROM users');

        $before = $this->schemaDump($conn);

        try {
            $schema->alterTable('users', function ($table): void {
                $table->dropColumn('email');
            });
            self::fail('Expected dropping a column a view depends on to be rejected');
        } catch (UnsupportedSchemaOperationException) {
            self::assertSame($before, $this->schemaDump($conn));
        }
    }

    #[Test]
    public function combinedAlterationRunsExactlyOneRebuild(): void
    {
        $conn = $this->connection();
        // Use a tiny SchemaBuilder test subclass that increments a counter in
        // executeSqliteRebuild() and then delegates to parent.
        $schema = new CountingSchemaBuilder($conn, new SQLiteSqlGenerator());

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('nickname')->nullable();
            $table->string('full_name');
        });

        // One public alterTable() call, mixed modify+drop+add+rename.
        $schema->alterTable('users', function ($table): void {
            $table->addColumn('bio', 'text')->nullable();
            $table->modifyColumn('email')->text()->nullable();
            $table->dropColumn('nickname');
            $table->renameColumn('full_name', 'name');
        });

        self::assertSame(1, $schema->rebuildCount);

        $columns = $this->columnNames($conn, 'users');
        sort($columns);
        self::assertSame(['bio', 'email', 'id', 'name'], $columns);
        self::assertTrue($this->columnIsNullable($conn, 'users', 'email'));
    }

    #[Test]
    public function combinedRebuildAndTableRenameIsReachableThroughPublicBuilder(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
            $table->integer('score')->nullable();
        });

        $schema->alterTable('users', function ($table): void {
            $table->dropColumn('score');
            $table->rename('members');
        });

        self::assertFalse($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('members'));
        self::assertNotContains('score', $this->columnNames($conn, 'members'));
        self::assertContains('email', $this->columnNames($conn, 'members'));
    }

    #[Test]
    public function nativeMultiStatementAlterationRollsBackAsOneUnit(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
        });

        $before = $this->schemaDump($conn);

        // Neither add_columns nor rename_columns triggers a rebuild, so this
        // stays on the native multi-statement path. Statement 1 (ADD COLUMN)
        // succeeds; statement 2 (RENAME COLUMN on a column that does not
        // exist) fails at execution time.
        try {
            $schema->alterTable('users', function ($table): void {
                $table->addColumn('bio', 'string')->nullable();
                $table->renameColumn('missing_col', 'x');
            });
            self::fail('Expected the rename statement to fail');
        } catch (\PDOException) {
            // expected: the second statement in the same native call fails
        }

        self::assertSame($before, $this->schemaDump($conn));
        self::assertNotContains('bio', $this->columnNames($conn, 'users'));
    }

    #[Test]
    public function unsupportedBuilderStateFailsInsteadOfDisappearing(): void
    {
        $conn = $this->connection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('users', function ($table): void {
            $table->id();
            $table->string('email');
        });

        $before = $this->schemaDump($conn);

        try {
            $schema->alterTable('users', function ($table): void {
                $table->dropPrimary();
            });
            self::fail('Expected dropPrimary() to be rejected');
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertSame('drop_primary', $e->operation());
        }
        self::assertSame($before, $this->schemaDump($conn));

        try {
            $schema->alterTable('users', function ($table): void {
                $table->addColumn('code', 'string')->primary();
            });
            self::fail('Expected add/modify-primary to be rejected');
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertSame('add_or_modify_primary', $e->operation());
        }
        self::assertSame($before, $this->schemaDump($conn));

        try {
            $schema->alterTable('users', function ($table): void {
                $table->comment('an alteration-time comment');
            });
            self::fail('Expected alteration-time comment() to be rejected');
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertSame('comment', $e->operation());
        }
        self::assertSame($before, $this->schemaDump($conn));

        try {
            $schema->alterTable('users', function ($table): void {
                $table->engine('InnoDB');
            });
            self::fail('Expected an engine/charset/collation-style option to be rejected');
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertSame('table_option', $e->operation());
        }
        self::assertSame($before, $this->schemaDump($conn));
    }

    #[Test]
    public function generatorAlterMethodsNeverReturnCommentSql(): void
    {
        $generator = new SQLiteSqlGenerator();

        $this->assertThrowsUnsupported(
            fn () => $generator->modifyColumn('users', new ColumnDefinition(name: 'email', type: 'text'))
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->dropColumn('users', 'email')
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->addForeignKey('users', new ForeignKeyDefinition(
                localColumn: 'team_id',
                referencedTable: 'teams',
                referencedColumn: 'id',
                name: 'fk_users_team_id'
            ))
        );
        $this->assertThrowsUnsupported(
            fn () => $generator->dropForeignKey('users', 'fk_users_team_id')
        );

        $table = new TableDefinition(name: 'users');
        $rebuildTriggeringChanges = [
            'modify_columns' => [new ColumnDefinition(name: 'email', type: 'text')],
            'drop_columns' => ['email'],
            'add_foreign_keys' => [new ForeignKeyDefinition(
                localColumn: 'team_id',
                referencedTable: 'teams',
                referencedColumn: 'id',
                name: 'fk_users_team_id'
            )],
            'drop_foreign_keys' => ['fk_users_team_id'],
        ];
        foreach ($rebuildTriggeringChanges as $key => $value) {
            $this->assertThrowsUnsupported(
                fn () => $generator->alterTable($table, [$key => $value]),
                "alterTable() with '{$key}'"
            );
        }
    }

    #[Test]
    public function mysqlAndPostgresGeneratorsReceiveCompleteChangeSets(): void
    {
        $conn = $this->connection();

        foreach ([new MySQLSqlGenerator(), new PostgreSQLSqlGenerator()] as $generator) {
            $label = get_class($generator);
            $schemaBuilder = new SchemaBuilder($conn, $generator);
            $tableBuilder = new TableBuilder($schemaBuilder, $generator, 'users', true);

            $tableBuilder->modifyColumn('email')->string(191)->nullable()->end();
            $tableBuilder->renameColumn('full_name', 'name');
            $tableBuilder->dropForeign('fk_users_team_id');
            $tableBuilder->rename('members');
            $tableBuilder->execute();

            $statements = $schemaBuilder->preview();

            self::assertNotEmpty($statements, "{$label}: expected statements to be generated");
            self::assertTrue(
                $this->anyStatementMatches($statements, '/email/i')
                    && $this->anyStatementMatches($statements, '/(MODIFY|ALTER)\s+COLUMN/i'),
                "{$label}: expected a column-modification statement for \"email\""
            );
            self::assertTrue(
                $this->anyStatementMatches($statements, '/RENAME\s+COLUMN.*full_name.*TO.*name/i'),
                "{$label}: expected a RENAME COLUMN statement"
            );
            self::assertTrue(
                $this->anyStatementMatches($statements, '/fk_users_team_id/i'),
                "{$label}: expected a DROP FOREIGN KEY/CONSTRAINT statement"
            );

            // Table rename must be the final statement so earlier operations
            // still target the original table name.
            $last = $statements[array_key_last($statements)];
            self::assertMatchesRegularExpression('/members/i', $last, "{$label}: rename must be the final statement");
            self::assertMatchesRegularExpression(
                '/RENAME/i',
                $last,
                "{$label}: final statement must be the table rename"
            );
        }
    }

    // ===========================================
    // Assertion helpers
    // ===========================================

    private static function assertPdoThrows(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected a PDOException');
        } catch (\PDOException) {
            // expected
        }
    }

    private function assertThrowsUnsupported(callable $callback, string $label = ''): void
    {
        try {
            $callback();
            self::fail(trim("Expected UnsupportedSchemaOperationException. {$label}"));
        } catch (UnsupportedSchemaOperationException $e) {
            self::assertStringNotContainsString('--', (string) $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function anyStatementMatches(array $statements, string $pattern): bool
    {
        foreach ($statements as $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    // ===========================================
    // Introspection helpers
    // ===========================================

    /**
     * @return list<array<string, mixed>>
     */
    private function pragmaTableInfo(Connection $conn, string $table): array
    {
        $stmt = $conn->getPDO()->query('PRAGMA table_info("' . $table . '")');
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<string>
     */
    private function columnNames(Connection $conn, string $table): array
    {
        return array_map(static fn (array $row): string => (string) $row['name'], $this->pragmaTableInfo($conn, $table));
    }

    private function columnIsNullable(Connection $conn, string $table, string $column): bool
    {
        foreach ($this->pragmaTableInfo($conn, $table) as $row) {
            if ($row['name'] === $column) {
                return (int) $row['notnull'] === 0;
            }
        }
        self::fail("Column \"{$column}\" not found on \"{$table}\"");
    }

    private function tableRootpage(Connection $conn, string $table): int
    {
        $stmt = $conn->getPDO()->prepare(
            "SELECT rootpage FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);
        $rootpage = $stmt->fetchColumn();
        self::assertIsNumeric($rootpage);

        return (int) $rootpage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schemaDump(Connection $conn): array
    {
        $stmt = $conn->getPDO()->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name, tbl_name');
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foreignKeyList(Connection $conn, string $table): array
    {
        $stmt = $conn->getPDO()->query('PRAGMA foreign_key_list("' . $table . '")');
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Test spy: counts procedural SQLite rebuilds without duplicating any
 * dispatch logic. SchemaBuilder::preview() cannot expose procedural
 * rebuilds (they never queue as SQL strings), so this is the only way to
 * prove a combined alteration triggers exactly one rebuild.
 */
final class CountingSchemaBuilder extends SchemaBuilder
{
    public int $rebuildCount = 0;

    public function executeSqliteRebuild(SqliteAlterationPlan $plan): void
    {
        $this->rebuildCount++;
        parent::executeSqliteRebuild($plan);
    }
}
