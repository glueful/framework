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
