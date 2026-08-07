<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

use PDO;

/**
 * Reads a table's complete schema state from PRAGMAs and sqlite_schema.
 * Pure reads — never mutates anything.
 *
 * Deliberately NOT final: the rebuilder's version-gate test stubs
 * sqliteVersionAtLeast() to exercise the sub-3.26.0 preflight rejection on
 * a modern SQLite library.
 */
class SqliteSchemaIntrospector
{
    private SqliteSqlScanner $scanner;

    public function __construct(
        private readonly PDO $pdo,
        ?SqliteSqlScanner $scanner = null,
    ) {
        $this->scanner = $scanner ?? new SqliteSqlScanner();
    }

    public function snapshot(string $table): SqliteTableSnapshot
    {
        $createSql = $this->tableSql($table);
        if ($createSql === null) {
            throw new \RuntimeException("Table \"{$table}\" does not exist");
        }

        $columns = [];
        $primaryKey = [];
        foreach ($this->pragmaRows("PRAGMA table_xinfo({$this->quote($table)})") as $row) {
            $columns[] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'notNull' => (bool) $row['notnull'],
                'default' => $row['dflt_value'] === null ? null : (string) $row['dflt_value'],
                'pkOrdinal' => (int) $row['pk'],
                'hidden' => (int) ($row['hidden'] ?? 0),
            ];
        }
        $pkColumns = array_filter($columns, static fn (array $c): bool => $c['pkOrdinal'] > 0);
        usort($pkColumns, static fn (array $a, array $b): int => $a['pkOrdinal'] <=> $b['pkOrdinal']);
        $primaryKey = array_values(array_map(static fn (array $c): string => $c['name'], $pkColumns));

        $indexes = [];
        foreach ($this->pragmaRows("PRAGMA index_list({$this->quote($table)})") as $row) {
            $name = (string) $row['name'];
            $memberColumns = [];
            foreach ($this->pragmaRows("PRAGMA index_xinfo({$this->quote($name)})") as $member) {
                if ((int) $member['key'] === 1) {
                    $memberColumns[] = $member['name'] === null ? null : (string) $member['name'];
                }
            }
            $indexes[] = [
                'name' => $name,
                'unique' => (bool) $row['unique'],
                'origin' => (string) $row['origin'],
                'partial' => (bool) ($row['partial'] ?? false),
                'sql' => $this->indexSql($name),
                'columns' => $memberColumns,
            ];
        }

        $foreignKeys = [];
        foreach ($this->pragmaRows("PRAGMA foreign_key_list({$this->quote($table)})") as $row) {
            $id = (int) $row['id'];
            if (!isset($foreignKeys[$id])) {
                $foreignKeys[$id] = [
                    'id' => $id,
                    'table' => (string) $row['table'],
                    'from' => [],
                    'to' => [],
                    'onUpdate' => (string) $row['on_update'],
                    'onDelete' => (string) $row['on_delete'],
                    'match' => (string) $row['match'],
                ];
            }
            $seq = (int) $row['seq'];
            $foreignKeys[$id]['from'][$seq] = (string) $row['from'];
            $foreignKeys[$id]['to'][$seq] = $row['to'] === null ? '' : (string) $row['to'];
        }
        foreach ($foreignKeys as &$foreignKey) {
            ksort($foreignKey['from']);
            ksort($foreignKey['to']);
            $foreignKey['from'] = array_values($foreignKey['from']);
            $foreignKey['to'] = array_values($foreignKey['to']);
        }
        unset($foreignKey);

        return new SqliteTableSnapshot(
            table: $table,
            createSql: $createSql,
            columns: $columns,
            checks: $this->scanner->extractChecks($createSql),
            primaryKey: $primaryKey,
            autoIncrement: $this->scanner->containsKeyword($createSql, 'AUTOINCREMENT'),
            foreignKeys: array_values($foreignKeys),
            indexes: $indexes,
            triggers: $this->triggers($table),
            withoutRowid: $this->scanner->hasKeywordOutsideParens($createSql, 'WITHOUT ROWID'),
            strict: $this->scanner->hasKeywordOutsideParens($createSql, 'STRICT'),
            sequenceValue: $this->sequenceValue($table),
        );
    }

    /** @return list<array{name: string, sql: string}> */
    public function allViews(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name, sql FROM sqlite_schema WHERE type = 'view' AND sql IS NOT NULL"
        );
        $views = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $views[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $views;
    }

    /** @return list<array{name: string, table: string, sql: string}> */
    public function allTriggers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name, tbl_name, sql FROM sqlite_schema "
            . "WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name"
        );
        $triggers = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $triggers[] = [
                'name' => (string) $row['name'],
                'table' => (string) $row['tbl_name'],
                'sql' => (string) $row['sql'],
            ];
        }

        return $triggers;
    }

    /**
     * @return list<array{
     *   childTable: string, id: int, from: list<string>, to: list<string>
     * }>
     */
    public function inboundForeignKeys(string $table): array
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        $inbound = [];
        foreach ($tables !== false ? $tables->fetchAll(PDO::FETCH_COLUMN) : [] as $childTable) {
            $groups = [];
            foreach ($this->pragmaRows('PRAGMA foreign_key_list(' . $this->quote((string) $childTable) . ')') as $row) {
                if (strcasecmp((string) $row['table'], $table) !== 0) {
                    continue;
                }
                $id = (int) $row['id'];
                $seq = (int) $row['seq'];
                $groups[$id] ??= [
                    'childTable' => (string) $childTable,
                    'id' => $id,
                    'from' => [],
                    'to' => [],
                ];
                $groups[$id]['from'][$seq] = (string) $row['from'];
                $groups[$id]['to'][$seq] = (string) $row['to'];
            }
            foreach ($groups as $group) {
                ksort($group['from']);
                ksort($group['to']);
                $group['from'] = array_values($group['from']);
                $group['to'] = array_values($group['to']);
                $inbound[] = $group;
            }
        }

        return $inbound;
    }

    public function sqliteVersionAtLeast(string $minimum): bool
    {
        $stmt = $this->pdo->query('SELECT sqlite_version()');
        $version = $stmt !== false ? (string) $stmt->fetchColumn() : '0.0.0';

        return version_compare($version, $minimum, '>=');
    }

    private function tableSql(string $table): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : null;
    }

    private function indexSql(string $index): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT sql FROM sqlite_schema WHERE type = 'index' AND name = ?"
        );
        $stmt->execute([$index]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : null;
    }

    /** @return list<array{name: string, sql: string}> */
    private function triggers(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT name, sql FROM sqlite_schema WHERE type = 'trigger' AND tbl_name = ? AND sql IS NOT NULL"
        );
        $stmt->execute([$table]);
        $triggers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $triggers[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $triggers;
    }

    private function sequenceValue(string $table): ?int
    {
        $exists = $this->pdo->query(
            "SELECT 1 FROM sqlite_schema WHERE type = 'table' AND name = 'sqlite_sequence'"
        );
        if ($exists === false || $exists->fetchColumn() === false) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
        $stmt->execute([$table]);
        $seq = $stmt->fetchColumn();

        return $seq === false ? null : (int) $seq;
    }

    /** @return list<array<string, mixed>> */
    private function pragmaRows(string $pragma): array
    {
        $stmt = $this->pdo->query($pragma);

        return $stmt === false ? [] : array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
