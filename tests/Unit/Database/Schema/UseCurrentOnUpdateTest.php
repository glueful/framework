<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Generators\MySQLSqlGenerator;
use Glueful\Database\Schema\Generators\PostgreSQLSqlGenerator;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * ColumnBuilder::useCurrentOnUpdate() must either emit MySQL's native
 * ON UPDATE CURRENT_TIMESTAMP or fail closed on drivers with no such clause.
 */
final class UseCurrentOnUpdateTest extends TestCase
{
    private function updatedAt(): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'updated_at',
            type: 'timestamp',
            nullable: true,
            defaultRaw: 'CURRENT_TIMESTAMP',
            options: ['onUpdate' => 'CURRENT_TIMESTAMP'],
        );
    }

    private function table(): TableDefinition
    {
        return new TableDefinition(name: 'posts', columns: [$this->updatedAt()]);
    }

    public function testMySqlCreateTableEmitsOnUpdateCurrentTimestamp(): void
    {
        $sql = (new MySQLSqlGenerator())->createTable($this->table());

        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    public function testMySqlAddColumnEmitsOnUpdateCurrentTimestamp(): void
    {
        $sql = (new MySQLSqlGenerator())->addColumn('posts', $this->updatedAt());

        self::assertStringContainsString('ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    public function testPostgresCreateTableRejectsOnUpdate(): void
    {
        $this->expectException(UnsupportedSchemaOperationException::class);
        (new PostgreSQLSqlGenerator())->createTable($this->table());
    }

    public function testPostgresAddColumnRejectsOnUpdate(): void
    {
        $this->expectException(UnsupportedSchemaOperationException::class);
        (new PostgreSQLSqlGenerator())->addColumn('posts', $this->updatedAt());
    }

    public function testSqliteCreateTableRejectsOnUpdate(): void
    {
        $this->expectException(UnsupportedSchemaOperationException::class);
        (new SQLiteSqlGenerator())->createTable($this->table());
    }

    public function testSqliteSchemaBuilderRejectsUseCurrentOnUpdate(): void
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $this->expectException(UnsupportedSchemaOperationException::class);
        $connection->getSchemaBuilder()->createTable('posts', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function testSqliteRebuildRejectsOnUpdateModification(): void
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $connection->getPDO()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, updated_at TEXT NULL)');

        $this->expectException(UnsupportedSchemaOperationException::class);
        $connection->getSchemaBuilder()->alterTable('posts', function ($table): void {
            $table->modifyColumn('updated_at')->nullable()->useCurrentOnUpdate();
        });
    }
}
