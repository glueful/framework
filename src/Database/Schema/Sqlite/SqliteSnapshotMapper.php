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
