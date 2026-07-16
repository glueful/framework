<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\Generators\MySQLSqlGenerator;
use Glueful\Database\Schema\Generators\PostgreSQLSqlGenerator;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Non-unique indexes declared inside a createTable() callback must actually
 * exist after the table is created — on every driver.
 *
 * Regression coverage: the SQLite/PostgreSQL createTable generators only ever
 * inlined UNIQUE constraints, so plain ->index(...) definitions were silently
 * discarded at create time (only the alterTable path emitted CREATE INDEX).
 */
final class CreateTableIndexTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_create_table_creates_plain_indexes_on_sqlite(): void
    {
        $conn = $this->sqliteConnection();

        $conn->getSchemaBuilder()->createTable('orders', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12)->default('');
            $table->string('status', 20);
            $table->string('reference', 64);

            $table->unique('reference');
            $table->index('tenant_uuid', 'idx_orders_tenant');
            $table->index(['tenant_uuid', 'status'], 'idx_orders_tenant_status');
        });

        self::assertTrue(
            $this->sqliteIndexExists($conn, 'idx_orders_tenant'),
            'Plain single-column index from createTable() must exist'
        );
        self::assertTrue(
            $this->sqliteIndexExists($conn, 'idx_orders_tenant_status'),
            'Plain composite index from createTable() must exist'
        );
    }

    public function test_create_table_index_is_droppable_via_alter_table(): void
    {
        $conn = $this->sqliteConnection();
        $schema = $conn->getSchemaBuilder();

        $schema->createTable('things', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('kind', 20);
            $table->index('kind', 'idx_things_kind');
        });

        self::assertTrue($this->sqliteIndexExists($conn, 'idx_things_kind'));

        $schema->alterTable('things', function ($table): void {
            $table->dropIndex('idx_things_kind');
        });

        self::assertFalse(
            $this->sqliteIndexExists($conn, 'idx_things_kind'),
            'Create-time plain index must be a real, droppable artifact'
        );
    }

    public function test_unique_constraints_still_reject_duplicates_on_sqlite(): void
    {
        $conn = $this->sqliteConnection();

        $conn->getSchemaBuilder()->createTable('refs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('reference', 64);
            $table->unique('reference');
            $table->index('reference', 'idx_refs_reference_lookup');
        });

        $pdo = $conn->getPDO();
        $pdo->exec("INSERT INTO refs (reference) VALUES ('abc')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO refs (reference) VALUES ('abc')");
    }

    public function test_postgresql_generator_pairs_create_table_with_create_index(): void
    {
        $generator = new PostgreSQLSqlGenerator();
        $index = new IndexDefinition(['tenant_uuid'], 'idx_orders_tenant', 'index');

        $sql = $generator->createIndex('orders', $index);

        self::assertStringContainsString('CREATE INDEX', $sql);
        self::assertStringContainsString('"idx_orders_tenant"', $sql);
        self::assertStringContainsString('"orders"', $sql);
    }

    public function test_mysql_create_table_does_not_inline_plain_indexes(): void
    {
        // Plain indexes are emitted as follow-up CREATE INDEX operations on all
        // drivers; MySQL must not ALSO inline them (duplicate index name error).
        $generator = new MySQLSqlGenerator();

        $createSql = $this->generateCreateTable($generator);

        self::assertStringNotContainsString(
            'KEY `idx_orders_tenant`',
            $createSql,
            'MySQL createTable must not inline the plain index that will be created as a follow-up statement'
        );
        self::assertStringContainsString(
            'UNIQUE KEY',
            $createSql,
            'Unique constraints stay inline in MySQL createTable'
        );
    }

    public function test_sqlite_create_index_sql_for_plain_index(): void
    {
        $generator = new SQLiteSqlGenerator();
        $index = new IndexDefinition(['tenant_uuid', 'status'], 'idx_orders_tenant_status', 'index');

        $sql = $generator->createIndex('orders', $index);

        self::assertStringContainsString('CREATE INDEX', $sql);
        self::assertStringNotContainsString('UNIQUE', $sql);
    }

    private function generateCreateTable(MySQLSqlGenerator $generator): string
    {
        $definition = new \Glueful\Database\Schema\DTOs\TableDefinition(
            name: 'orders',
            columns: [
                new \Glueful\Database\Schema\DTOs\ColumnDefinition(
                    name: 'reference',
                    type: 'string',
                    length: 64,
                ),
                new \Glueful\Database\Schema\DTOs\ColumnDefinition(
                    name: 'tenant_uuid',
                    type: 'string',
                    length: 12,
                ),
            ],
            indexes: [
                new IndexDefinition(['reference'], 'uniq_orders_reference', 'unique', true),
                new IndexDefinition(['tenant_uuid'], 'idx_orders_tenant', 'index'),
            ],
            foreignKeys: [],
            primaryKey: [],
            options: [],
            comment: null
        );

        return $generator->createTable($definition);
    }

    private function sqliteConnection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'schema-create-index-');
        self::assertIsString($this->dbPath);

        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    private function sqliteIndexExists(Connection $conn, string $indexName): bool
    {
        $stmt = $conn->getPDO()->prepare(
            'SELECT COUNT(*) FROM sqlite_master WHERE type = "index" AND name = ?'
        );
        $stmt->execute([$indexName]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
