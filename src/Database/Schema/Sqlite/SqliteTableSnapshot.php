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
     * @param list<array{
     *   name: string, type: string, notNull: bool, default: ?string,
     *   pkOrdinal: int, hidden: int
     * }> $columns
     * @param list<array{
     *   expression: string, identifiers: list<string>,
     *   scope: 'column'|'table', column: ?string
     * }> $checks
     * @param list<string> $primaryKey
     * @param list<array{
     *   id: int, table: string, from: list<string>, to: list<string>,
     *   onUpdate: string, onDelete: string, match: string
     * }> $foreignKeys
     * @param list<array{
     *   name: string, unique: bool, origin: string, partial: bool,
     *   sql: ?string, columns: list<?string>
     * }> $indexes
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

    /**
     * @return list<array{
     *   name: string, unique: bool, origin: string, partial: bool,
     *   sql: ?string, columns: list<?string>
     * }>
     */
    public function namedIndexes(): array
    {
        return array_values(array_filter($this->indexes, static fn (array $ix): bool => $ix['sql'] !== null));
    }

    /**
     * @return list<array{
     *   name: string, unique: bool, origin: string, partial: bool,
     *   sql: ?string, columns: list<?string>
     * }>
     */
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
