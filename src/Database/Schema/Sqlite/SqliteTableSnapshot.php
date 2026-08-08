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

    /**
     * Canonical comparable form: everything semantic, nothing cosmetic.
     *
     * Auto-index names are positional (sqlite_autoindex_<table>_N) and carry
     * no meaning of their own, so indexes compare on shape; declared types and
     * identifiers compare case-insensitively; CHECK ownership (scope + owning
     * column) is semantic and included; the raw createSql is deliberately
     * excluded — it is evidence, not semantics.
     *
     * Used by both equivalence gates: the test-time model round-trip and the
     * rebuilder's post-swap runtime verification.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        $indexes = array_map(static fn (array $ix): array => [
            'unique' => $ix['unique'],
            'origin' => $ix['origin'],
            'partial' => $ix['partial'],
            'columns' => array_map(
                static fn (?string $c): ?string => $c === null ? null : strtolower($c),
                $ix['columns']
            ),
        ], $this->indexes);
        usort($indexes, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        $foreignKeys = array_map(static fn (array $fk): array => [
            'table' => strtolower($fk['table']),
            'from' => array_map('strtolower', $fk['from']),
            'to' => array_map('strtolower', $fk['to']),
            'onUpdate' => strtoupper($fk['onUpdate']),
            'onDelete' => strtoupper($fk['onDelete']),
        ], $this->foreignKeys);

        $checks = array_map(static fn (array $c): array => [
            'identifiers' => $c['identifiers'],
            'scope' => $c['scope'],
            'column' => $c['column'] === null ? null : strtolower($c['column']),
            'normalized' => strtolower(preg_replace('/\s+/', ' ', $c['expression']) ?? $c['expression']),
        ], $this->checks);

        return [
            'columns' => array_map(static fn (array $c): array => [
                'name' => strtolower($c['name']),
                'type' => strtolower($c['type']),
                'notNull' => $c['notNull'],
                'default' => $c['default'] === null ? null : strtolower($c['default']),
                'pkOrdinal' => $c['pkOrdinal'],
            ], $this->columns),
            'primaryKey' => array_map('strtolower', $this->primaryKey),
            'autoIncrement' => $this->autoIncrement,
            'checks' => $checks,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
            'withoutRowid' => $this->withoutRowid,
            'strict' => $this->strict,
        ];
    }
}
