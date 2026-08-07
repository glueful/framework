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
