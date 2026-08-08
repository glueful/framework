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
