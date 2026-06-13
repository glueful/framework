<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SchemaBuilderAlterIndexTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_alter_table_can_add_index_to_existing_column(): void
    {
        $conn = $this->sqliteConnection();
        $schema = $conn->getSchemaBuilder();

        $this->createItemsTable($conn);

        $schema->alterTable('items', function ($table): void {
            $table->index('status', 'idx_items_status');
        });

        self::assertTrue($this->sqliteIndexExists($conn, 'idx_items_status'));
    }

    public function test_alter_table_can_drop_existing_index(): void
    {
        $conn = $this->sqliteConnection();
        $schema = $conn->getSchemaBuilder();

        $this->createItemsTable($conn);
        $schema->alterTable('items', function ($table): void {
            $table->index('status', 'idx_items_status');
        });
        self::assertTrue($this->sqliteIndexExists($conn, 'idx_items_status'));

        $schema->alterTable('items', function ($table): void {
            $table->dropIndex('idx_items_status');
        });

        self::assertFalse($this->sqliteIndexExists($conn, 'idx_items_status'));
    }

    public function test_schema_builder_drop_index_helper_drops_existing_index(): void
    {
        $conn = $this->sqliteConnection();
        $schema = $conn->getSchemaBuilder();

        $this->createItemsTable($conn);
        $schema->alterTable('items', function ($table): void {
            $table->index('status', 'idx_items_status');
        });
        self::assertTrue($this->sqliteIndexExists($conn, 'idx_items_status'));

        self::assertTrue($schema->dropIndex('items', 'idx_items_status'));

        self::assertFalse($this->sqliteIndexExists($conn, 'idx_items_status'));
    }

    public function test_schema_builder_drop_index_helper_does_not_poison_later_operations_when_index_is_missing(): void
    {
        $conn = $this->sqliteConnection();
        $schema = $conn->getSchemaBuilder();

        $this->createItemsTable($conn);

        self::assertFalse($schema->dropIndex('items', 'idx_items_status'));

        $schema->alterTable('items', function ($table): void {
            $table->index('status', 'idx_items_status');
        });

        self::assertTrue($this->sqliteIndexExists($conn, 'idx_items_status'));
    }

    private function sqliteConnection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'schema-index-');
        self::assertIsString($this->dbPath);

        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    private function createItemsTable(Connection $conn): void
    {
        $conn->getSchemaBuilder()->createTable('items', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('status', 20);
        });
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
