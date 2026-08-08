<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use PDO;

/**
 * SQLite's documented create-copy-swap table rebuild, made auditable and atomic.
 *
 * An alteration either produces the requested schema completely, or fails
 * explicitly — before or without mutating the original table. The replacement
 * DDL comes exclusively from SQLiteSqlGenerator::createTable(); the stored
 * sqlite_schema SQL is evidence and verbatim-replay material only.
 *
 * The five stages, in order:
 *   1. preflight()     — fail-closed preservation audit, before any DDL.
 *   2. compileTarget() — snapshot clone -> mapper -> TableDefinition + copy map.
 *   3. execute()       — standalone transaction or savepoint; the swap body.
 *   4. verify()        — canonical comparison against a scratch-DB expectation.
 *   5. renameStage()   — optional user-visible rename, inside the same unit.
 *
 * PRAGMA notes that are load-bearing rather than incidental:
 *   - `defer_foreign_keys` is never used: DROP TABLE executes CASCADE/SET NULL
 *     actions regardless of deferral.
 *   - `foreign_key_check` is always the global form, run before mutation and
 *     again before commit.
 *   - `legacy_alter_table` is per-rename, not per-operation. The internal swap
 *     rename needs it ON (a bare rename; the modern rewrite would try to patch
 *     dependent view/trigger bodies against the just-dropped name and fail),
 *     while the final user-visible rename needs it OFF (so inbound FKs and
 *     dependent bodies are rewritten onto the new name).
 */
final class SQLiteTableRebuilder
{
    private const MODE_STANDALONE = 'standalone';
    private const MODE_SAVEPOINT = 'savepoint';

    /** Pseudocolumn spellings for an implicit rowid, in preference order. */
    private const ROWID_ALIASES = ['rowid', '_rowid_', 'oid'];

    /** Non-legacy RENAME rewrites inbound FKs and dependent bodies from here on. */
    private const RENAME_MINIMUM_SQLITE = '3.26.0';

    /**
     * CREATE TABLE constructs the PRAGMA model cannot recover, keyed by the
     * whole-word keyword the scanner looks for outside literals and comments.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const UNSUPPORTED_KEYWORDS = [
        'COLLATE' => [
            'COLLATE clause',
            'collations are not exposed by the PRAGMA model, so the rebuilt table would silently lose them',
        ],
        'CONFLICT' => [
            'ON CONFLICT clause',
            'constraint conflict clauses are not exposed by the PRAGMA model and cannot be regenerated',
        ],
        'MATCH' => [
            'foreign key MATCH clause',
            'the generator emits no MATCH clause, so the rebuilt table would silently lose it',
        ],
        'DEFERRABLE' => [
            'DEFERRABLE constraint clause',
            'the generator emits no deferrability clause, so the rebuilt table would silently lose it',
        ],
        'INITIALLY' => [
            'INITIALLY DEFERRED/IMMEDIATE clause',
            'the generator emits no deferrability clause, so the rebuilt table would silently lose it',
        ],
        'CONSTRAINT' => [
            'explicitly named CONSTRAINT',
            'SQLite does not expose constraint names through PRAGMAs, so the name cannot be preserved',
        ],
    ];

    private readonly SQLiteSqlGenerator $generator;
    private readonly SqliteSqlScanner $scanner;
    private readonly SqliteSchemaIntrospector $introspector;
    private readonly SqliteSnapshotMapper $mapper;

    /** Transaction strategy chosen by preflight for the current call. */
    private string $mode = self::MODE_STANDALONE;

    public function __construct(
        private readonly PDO $pdo,
        ?SQLiteSqlGenerator $generator = null,
        ?SqliteSqlScanner $scanner = null,
        ?SqliteSchemaIntrospector $introspector = null,
    ) {
        $this->generator = $generator ?? new SQLiteSqlGenerator();
        $this->scanner = $scanner ?? new SqliteSqlScanner();
        $this->introspector = $introspector ?? new SqliteSchemaIntrospector($this->pdo, $this->scanner);
        $this->mapper = new SqliteSnapshotMapper();
    }

    /**
     * Audit, plan, execute and verify one complete alteration.
     *
     * @throws UnsupportedSchemaOperationException Preflight rejection; nothing was mutated.
     * @throws \RuntimeException Execution or verification failure, after rollback.
     */
    public function rebuild(SqliteAlterationPlan $plan): void
    {
        // Addressing is checked before the snapshot: an attached-database or
        // temp-schema target is not a main-schema table to introspect at all.
        $this->auditTableAddressing($plan);

        $snapshot = $this->snapshotOrReject($plan->table());
        $views = $this->introspector->allViews();
        $triggers = $this->introspector->allTriggers();
        $inbound = $this->introspector->inboundForeignKeys($plan->table());

        $this->preflight($plan, $snapshot, $views);

        $target = $this->compileTarget($plan, $snapshot);
        $indexSql = $this->auditNamedIndexes($plan, $snapshot);
        foreach ($plan->addIndexes() as $index) {
            $indexSql[] = $this->generator->createIndex($plan->table(), $index);
        }

        $this->execute($plan, $snapshot, $target, $indexSql, $views, $triggers, $inbound);
    }

    // =========================================================================
    // 1. Preflight — the preservation audit. Nothing below mutates anything.
    // =========================================================================

    /**
     * @param list<array{name: string, sql: string}> $views
     */
    private function preflight(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot, array $views): void
    {
        $table = $plan->table();

        $this->auditJournalMode($table);
        $this->auditTransactionState($table);
        $this->auditTableKind($snapshot);
        $this->auditColumnKinds($snapshot);
        $this->auditUnsupportedKeywords($snapshot);
        $this->auditRenameCapability($plan);
        $this->auditPlanTargets($plan, $snapshot);
        $this->auditNamedIndexes($plan, $snapshot);
        $this->auditTriggers($plan, $snapshot);
        $this->auditViews($plan, $views);
        $this->auditInboundForeignKeys($plan, $snapshot);

        // Dry-run compile: the mapper rejects structures the generator cannot
        // emit (composite FKs, unmappable declared types), the CHECK/unique
        // resolution rejects unowned constraints, and rowid safety is derived
        // for source and target — all of it before a single DDL statement.
        $target = $this->compileTarget($plan, $snapshot);
        $this->auditAddedIndexes($plan, $target['definition']);
    }

    /**
     * The snapshot scans the stored CREATE TABLE SQL. A scanner failure on an
     * existing table means its own definition cannot be modelled, which is a
     * typed preservation rejection rather than an internal error.
     */
    private function snapshotOrReject(string $table): SqliteTableSnapshot
    {
        try {
            return $this->introspector->snapshot($table);
        } catch (\RuntimeException $exception) {
            if (!$this->schemaObjectExists($table)) {
                throw $exception;
            }
            $this->reject(
                $table,
                'introspection',
                'unscannable stored CREATE TABLE SQL',
                'the table definition could not be scanned (' . $exception->getMessage() . '), so preservation '
                . 'cannot be guaranteed'
            );
        }
    }

    private function auditJournalMode(string $table): void
    {
        $statement = $this->pdo->query('PRAGMA journal_mode');
        $mode = strtolower((string) ($statement !== false ? $statement->fetchColumn() : ''));
        if ($mode === 'off') {
            $this->reject(
                $table,
                'rebuild',
                'journal_mode = OFF',
                'SQLite leaves rollback behaviour undefined in this mode, so the atomicity guarantee cannot be given'
            );
        }
    }

    private function auditTransactionState(string $table): void
    {
        $inTransaction = $this->pdo->inTransaction();
        $foreignKeysOn = $this->pragmaInt('foreign_keys') === 1;

        if ($inTransaction && $foreignKeysOn) {
            $this->reject(
                $table,
                'rebuild',
                'active transaction with PRAGMA foreign_keys = ON',
                'foreign_keys cannot be switched off inside a transaction and DROP TABLE would run referential '
                . 'actions on referencing tables; commit first or disable foreign_keys before beginning'
            );
        }

        $this->mode = $inTransaction ? self::MODE_SAVEPOINT : self::MODE_STANDALONE;
    }

    private function auditTableAddressing(SqliteAlterationPlan $plan): void
    {
        $table = $plan->table();
        foreach ([$table, $plan->renameTable()] as $name) {
            if ($name !== null && str_contains($name, '.')) {
                $this->reject(
                    $table,
                    'rebuild',
                    "schema-qualified table name \"{$name}\"",
                    'the rebuild addresses the main schema only; attached databases are out of scope'
                );
            }
        }

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type = 'table' AND name = ?"
        );
        $statement->execute([$table]);
        if ((int) $statement->fetchColumn() > 0) {
            $this->reject(
                $table,
                'rebuild',
                'temporary table',
                'the create-copy-swap procedure applies to ordinary main-schema tables only'
            );
        }
    }

    private function auditTableKind(SqliteTableSnapshot $snapshot): void
    {
        $table = $snapshot->table;
        $createSql = $snapshot->createSql;
        foreach (
            [
                'CREATE VIRTUAL TABLE' => 'virtual table',
                'CREATE TEMP TABLE' => 'temporary table',
                'CREATE TEMPORARY TABLE' => 'temporary table',
            ] as $phrase => $feature
        ) {
            if ($this->scanner->containsKeyword($createSql, $phrase)) {
                $this->reject(
                    $table,
                    'rebuild',
                    $feature,
                    'the create-copy-swap procedure applies to ordinary main-schema tables only'
                );
            }
        }
    }

    private function auditColumnKinds(SqliteTableSnapshot $snapshot): void
    {
        foreach ($snapshot->columns as $column) {
            if ($column['hidden'] >= 2) {
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "generated column \"{$column['name']}\"",
                    'generated column expressions are not exposed by the PRAGMA model and cannot be regenerated'
                );
            }
            if ($column['hidden'] === 1) {
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "hidden column \"{$column['name']}\"",
                    'hidden columns belong to virtual tables, which the rebuild does not support'
                );
            }
        }
    }

    private function auditUnsupportedKeywords(SqliteTableSnapshot $snapshot): void
    {
        foreach (self::UNSUPPORTED_KEYWORDS as $keyword => [$feature, $reason]) {
            if ($this->scanner->containsKeyword($snapshot->createSql, $keyword)) {
                $this->reject($snapshot->table, 'rebuild', $feature, $reason);
            }
        }
    }

    /**
     * Every rebuild forces `legacy_alter_table` ON for its internal swap rename;
     * a plan carrying `rename_table` additionally forces it OFF for the final
     * rename. Both values must be settable AND read back correctly here, while
     * the schema is still untouched.
     */
    private function auditRenameCapability(SqliteAlterationPlan $plan): void
    {
        $table = $plan->table();
        $captured = $this->pragmaInt('legacy_alter_table');
        $required = $plan->renameTable() === null ? ['ON' => 1] : ['ON' => 1, 'OFF' => 0];

        try {
            foreach ($required as $value => $expected) {
                $this->setPragma('legacy_alter_table', $value);
                if ($this->pragmaInt('legacy_alter_table') !== $expected) {
                    $this->reject(
                        $table,
                        'rebuild',
                        "PRAGMA legacy_alter_table = {$value}",
                        'the pragma could not be set and read back, so neither rename stage can be performed safely'
                    );
                }
            }
        } finally {
            $this->setPragma('legacy_alter_table', $captured === 1 ? 'ON' : 'OFF');
        }

        if ($this->pragmaInt('legacy_alter_table') !== $captured) {
            $this->reject(
                $table,
                'rebuild',
                'PRAGMA legacy_alter_table restoration',
                'the captured pragma value could not be restored, so the connection state cannot be left unchanged'
            );
        }

        if ($plan->renameTable() !== null && !$this->introspector->sqliteVersionAtLeast(self::RENAME_MINIMUM_SQLITE)) {
            $this->reject(
                $table,
                'rename_table',
                'combined rebuild and table rename below SQLite ' . self::RENAME_MINIMUM_SQLITE,
                'older SQLite does not rewrite inbound foreign keys during a rename while foreign_keys is OFF; '
                . 'rebuild without rename_table, then rename separately'
            );
        }
    }

    private function auditPlanTargets(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): void
    {
        $table = $plan->table();
        $existing = array_map('strtolower', $snapshot->columnNames());

        $seen = [];
        foreach (
            [
                'drop_columns' => $plan->dropColumns(),
                'modify_columns' => array_map(
                    static fn (ColumnDefinition $c): string => $c->name,
                    $plan->modifyColumns()
                ),
                'rename_columns' => array_keys($plan->renameColumns()),
            ] as $operation => $names
        ) {
            foreach ($names as $name) {
                $lower = strtolower((string) $name);
                if (!in_array($lower, $existing, true)) {
                    $this->reject(
                        $table,
                        $operation,
                        "column \"{$name}\"",
                        'the column does not exist on the table being altered'
                    );
                }
                if (isset($seen[$lower])) {
                    $this->reject(
                        $table,
                        $operation,
                        "column \"{$name}\" targeted by both {$seen[$lower]} and {$operation}",
                        'a column may take part in at most one change per alteration'
                    );
                }
                $seen[$lower] = $operation;
            }
        }

        foreach ($plan->addColumns() as $column) {
            if (in_array(strtolower($column->name), $existing, true)) {
                $this->reject(
                    $table,
                    'add_columns',
                    "column \"{$column->name}\"",
                    'a column with that name already exists on the table'
                );
            }
            if ($column->primary) {
                $this->reject(
                    $table,
                    'add_columns',
                    "primary key column \"{$column->name}\"",
                    'primary key changes are not supported by the rebuild; the added column cannot claim the key'
                );
            }
        }

        foreach ([...$plan->addColumns(), ...$plan->modifyColumns()] as $column) {
            if ($column->collation !== null) {
                $this->reject(
                    $table,
                    'column definition',
                    "collation on column \"{$column->name}\"",
                    'the SQLite generator emits no COLLATE clause, so the collation would be silently dropped'
                );
            }
        }

        foreach ($plan->modifyColumns() as $column) {
            $source = $snapshot->column($column->name);
            if ($source !== null && $column->primary !== ($source['pkOrdinal'] > 0)) {
                $this->reject(
                    $table,
                    'modify_columns',
                    "primary key membership of column \"{$column->name}\"",
                    'primary key changes are not supported by the rebuild; keep the replacement definition\'s '
                    . 'primary flag equal to the current membership'
                );
            }
        }

        $knownIndexes = array_map(
            static fn (array $index): string => strtolower($index['name']),
            $snapshot->indexes
        );
        foreach ($plan->dropIndexes() as $index) {
            if (!in_array(strtolower($index), $knownIndexes, true)) {
                $this->reject(
                    $table,
                    'drop_indexes',
                    "index \"{$index}\"",
                    'the index does not exist on the table being altered'
                );
            }
        }

        $rename = $plan->renameTable();
        if ($rename !== null && $this->schemaObjectExists($rename)) {
            $this->reject(
                $table,
                'rename_table',
                "target name \"{$rename}\"",
                'a schema object with that name already exists'
            );
        }
    }

    /**
     * Named indexes replay verbatim, so every identifier they mention must be
     * unchanged. Returns the DDL of the indexes that survive the alteration.
     *
     * @return list<string>
     */
    private function auditNamedIndexes(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $changed = $this->changedIdentifiers($plan);
        $preserved = [];

        foreach ($snapshot->namedIndexes() as $index) {
            $sql = (string) $index['sql'];
            if ($this->namedIn($plan->dropIndexes(), $index['name'])) {
                continue;
            }
            $identifiers = $this->identifiersOrReject(
                $snapshot->table,
                'drop_indexes',
                "index \"{$index['name']}\"",
                $sql
            );
            $collisions = array_values(array_intersect($identifiers, $changed));
            if ($collisions !== []) {
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "index \"{$index['name']}\" over changed column(s) " . implode(', ', $collisions),
                    'the index replays verbatim and cannot be rewritten; drop it in the same call via drop_indexes '
                    . 'and recreate it afterwards'
                );
            }
            $preserved[] = $sql;
        }

        return $preserved;
    }

    private function auditTriggers(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): void
    {
        $changed = $this->changedIdentifiers($plan);
        $table = strtolower($snapshot->table);

        foreach ($this->introspector->allTriggers() as $trigger) {
            $identifiers = $this->identifiersOrReject(
                $snapshot->table,
                'rebuild',
                "trigger \"{$trigger['name']}\"",
                $trigger['sql']
            );
            if (!in_array($table, $identifiers, true)) {
                continue;
            }
            $collisions = array_values(array_intersect($identifiers, $changed));
            if ($collisions !== []) {
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "trigger \"{$trigger['name']}\" over changed column(s) " . implode(', ', $collisions),
                    'trigger bodies are replayed or left in place verbatim and cannot be rewritten; drop the trigger '
                    . 'before altering the column and recreate it afterwards'
                );
            }
        }
    }

    /**
     * @param list<array{name: string, sql: string}> $views
     */
    private function auditViews(SqliteAlterationPlan $plan, array $views): void
    {
        $table = strtolower($plan->table());
        $removed = array_merge(
            $this->lowered($plan->dropColumns()),
            $this->lowered(array_keys($plan->renameColumns()))
        );

        foreach ($views as $view) {
            $identifiers = $this->identifiersOrReject(
                $plan->table(),
                'rebuild',
                "view \"{$view['name']}\"",
                $view['sql']
            );
            if (!in_array($table, $identifiers, true)) {
                continue;
            }
            if ($this->scanner->containsUnquotedAsterisk($view['sql'])) {
                $this->reject(
                    $plan->table(),
                    'rebuild',
                    "view \"{$view['name']}\" selecting *",
                    'SQLite exposes no column dependency metadata for views, so a wildcard dependency on the '
                    . 'altered table cannot be proven safe'
                );
            }
            $collisions = array_values(array_intersect($identifiers, $removed));
            if ($collisions !== []) {
                $this->reject(
                    $plan->table(),
                    'rebuild',
                    "view \"{$view['name']}\" over removed column(s) " . implode(', ', $collisions),
                    'the view would break after the rebuild; drop the view before altering the column and recreate '
                    . 'it afterwards'
                );
            }
        }
    }

    private function auditInboundForeignKeys(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): void
    {
        $changed = $this->changedIdentifiers($plan);

        foreach ($this->introspector->inboundForeignKeys($snapshot->table) as $foreignKey) {
            $referenced = [];
            foreach ($foreignKey['to'] as $column) {
                // An empty "to" means the child references the parent's primary key implicitly.
                $referenced = $column === ''
                    ? array_merge($referenced, $this->lowered($snapshot->primaryKey))
                    : [...$referenced, strtolower($column)];
            }
            $collisions = array_values(array_intersect($referenced, $changed));
            if ($collisions !== []) {
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "inbound foreign key from \"{$foreignKey['childTable']}\" onto column(s) "
                    . implode(', ', $collisions),
                    'this slice does not rebuild dependent child tables, so the referenced parent column must '
                    . 'survive the alteration unchanged'
                );
            }
        }
    }

    private function auditAddedIndexes(SqliteAlterationPlan $plan, TableDefinition $definition): void
    {
        $columns = array_map(
            static fn (ColumnDefinition $column): string => strtolower($column->name),
            $definition->columns
        );

        foreach ($plan->addIndexes() as $index) {
            foreach ($index->columns as $column) {
                if (!in_array(strtolower($column), $columns, true)) {
                    $this->reject(
                        $plan->table(),
                        'add_indexes',
                        "index \"{$index->name}\" over column \"{$column}\"",
                        'the column does not exist on the rebuilt table'
                    );
                }
            }
        }
    }

    // =========================================================================
    // 2. compileTarget — the in-memory snapshot clone and its copy plan.
    // =========================================================================

    /**
     * @return array{
     *   definition: TableDefinition,
     *   copyMap: array<string, string>,
     *   rowidCopy: ?array{target: string, source: string}
     * }
     */
    private function compileTarget(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $dropped = $this->lowered($plan->dropColumns());
        $renames = $this->renameMap($plan);
        $modifications = [];
        foreach ($plan->modifyColumns() as $column) {
            $modifications[strtolower($column->name)] = $column;
        }

        $columns = [];
        $copyMap = [];
        foreach ($snapshot->columns as $column) {
            $lower = strtolower($column['name']);
            if (in_array($lower, $dropped, true)) {
                continue;
            }
            if (isset($modifications[$lower])) {
                $replacement = $modifications[$lower];
                $columns[] = $this->columnFromDefinition($replacement, $column);
                $copyMap[$replacement->name] = $column['name'];
                continue;
            }
            $name = $renames[$lower] ?? $column['name'];
            $columns[] = [...$column, 'name' => $name];
            $copyMap[$name] = $column['name'];
        }
        foreach ($plan->addColumns() as $column) {
            $columns[] = $this->columnFromDefinition($column, null);
        }
        $this->assertDistinctColumnNames($plan->table(), $columns);

        $primaryKey = array_values(array_filter($columns, static fn (array $c): bool => $c['pkOrdinal'] > 0));
        usort($primaryKey, static fn (array $a, array $b): int => $a['pkOrdinal'] <=> $b['pkOrdinal']);

        $clone = new SqliteTableSnapshot(
            table: $snapshot->table,
            createSql: $snapshot->createSql,
            columns: $columns,
            checks: $this->resolveTargetChecks($plan, $snapshot),
            primaryKey: array_map(static fn (array $c): string => $c['name'], $primaryKey),
            autoIncrement: $snapshot->autoIncrement,
            foreignKeys: $this->resolveTargetForeignKeys($plan, $snapshot),
            indexes: $this->resolveTargetAutoIndexes($plan, $snapshot),
            triggers: [],
            withoutRowid: $snapshot->withoutRowid,
            strict: $snapshot->strict,
            sequenceValue: $snapshot->sequenceValue,
        );

        $definition = $this->mapper->toTableDefinition($clone);

        return [
            'definition' => $definition,
            'copyMap' => $copyMap,
            'rowidCopy' => $this->deriveRowidCopy($snapshot, $definition),
        ];
    }

    /**
     * A modification's replacement definition owns the column wholesale — its
     * declared type, nullability, default and CHECK all replace the introspected
     * ones. Positions and primary-key ordinals come from the source column.
     *
     * @param array{name: string, type: string, notNull: bool, default: ?string,
     *   pkOrdinal: int, hidden: int}|null $source
     * @return array{name: string, type: string, notNull: bool, default: ?string,
     *   pkOrdinal: int, hidden: int}
     */
    private function columnFromDefinition(ColumnDefinition $column, ?array $source): array
    {
        $default = $column->defaultRaw;
        if ($default === null && $column->default !== null) {
            $default = $this->generator->formatDefaultValue($column->default, $column->type);
        }

        return [
            'name' => $column->name,
            'type' => $this->generator->mapColumnType($column->type),
            'notNull' => !$column->nullable,
            'default' => $default,
            'pkOrdinal' => $source !== null ? $source['pkOrdinal'] : ($column->primary ? 1 : 0),
            'hidden' => 0,
        ];
    }

    /**
     * CHECK survival by recorded ownership — never by identifier counting.
     *
     * @return list<array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string}>
     */
    private function resolveTargetChecks(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $dropped = $this->lowered($plan->dropColumns());
        $renames = $this->renameMap($plan);
        $modified = $this->lowered(
            array_map(static fn (ColumnDefinition $c): string => $c->name, $plan->modifyColumns())
        );
        $removed = array_merge($dropped, array_keys($renames));

        $checks = [];
        foreach ($snapshot->checks as $check) {
            $owner = $check['scope'] === 'column' && $check['column'] !== null ? strtolower($check['column']) : null;

            if ($owner !== null && in_array($owner, $dropped, true)) {
                continue; // Removed with the column that owns it.
            }
            if ($owner !== null && in_array($owner, $modified, true)) {
                continue; // The replacement definition owns this column's CHECK.
            }
            if ($owner !== null && isset($renames[$owner])) {
                $checks[] = $this->rewriteOwnedCheck(
                    $snapshot->table,
                    $check,
                    (string) $check['column'],
                    $renames[$owner]
                );
                continue;
            }
            $collisions = array_values(array_intersect($check['identifiers'], $removed));
            if ($collisions !== []) {
                $scope = $check['scope'] === 'table' ? 'table-level' : "column-level (owner \"{$check['column']}\")";
                $this->reject(
                    $snapshot->table,
                    'rebuild',
                    "{$scope} CHECK referencing changed column(s) " . implode(', ', $collisions),
                    'only the framework enum shape CHECK (<column> IN (<literals>)) owned by the renamed column '
                    . 'can be rewritten; every other expression must be restated by hand'
                );
            }
            $checks[] = $check;
        }

        foreach ([...$plan->modifyColumns(), ...$plan->addColumns()] as $column) {
            foreach ($this->declaredChecks($column) as $expression) {
                $checks[] = [
                    'expression' => $expression,
                    'identifiers' => $this->scanner->identifiers($expression),
                    'scope' => 'column',
                    'column' => $column->name,
                ];
            }
        }

        return $checks;
    }

    /**
     * @param array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string} $check
     * @return array{expression: string, identifiers: list<string>, scope: 'column'|'table', column: ?string}
     */
    private function rewriteOwnedCheck(string $table, array $check, string $from, string $to): array
    {
        // The rewrite regex matches a bare leading identifier, so it is only
        // ever reached behind this proof of the exact framework enum shape.
        if (!$this->scanner->isEnumCheckShape($check['expression'], $from)) {
            $this->reject(
                $table,
                'rename_columns',
                "CHECK constraint on renamed column \"{$from}\"",
                'only the framework enum shape CHECK (<column> IN (<literals>)) can be rewritten for a rename; '
                . "restate CHECK ({$check['expression']}) by hand"
            );
        }
        $expression = $this->scanner->rewriteEnumCheckColumn($check['expression'], $from, $to);

        return [
            'expression' => $expression,
            'identifiers' => $this->scanner->identifiers($expression),
            'scope' => 'column',
            'column' => $to,
        ];
    }

    /** @return list<string> */
    private function declaredChecks(ColumnDefinition $column): array
    {
        $checks = [];
        if ($column->check !== null && $column->check !== '') {
            $checks[] = $column->check;
        }
        if ($column->isEnum() && $column->getEnumValues() !== []) {
            $values = array_map([$this->generator, 'quoteValue'], $column->getEnumValues());
            $checks[] = $this->generator->quoteIdentifier($column->name) . ' IN (' . implode(', ', $values) . ')';
        }

        return $checks;
    }

    /**
     * @return list<array{id: int, table: string, from: list<string>, to: list<string>,
     *   onUpdate: string, onDelete: string, match: string}>
     */
    private function resolveTargetForeignKeys(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $droppedIds = $this->resolveDroppedForeignKeys($plan, $snapshot);
        $droppedColumns = $this->lowered($plan->dropColumns());
        $renames = $this->renameMap($plan);

        $foreignKeys = [];
        $nextId = 0;
        foreach ($snapshot->foreignKeys as $foreignKey) {
            if (in_array($foreignKey['id'], $droppedIds, true)) {
                continue;
            }
            $local = array_map('strtolower', $foreignKey['from']);
            if (array_intersect($local, $droppedColumns) !== []) {
                continue; // The constraint leaves with its local column.
            }
            $foreignKeys[] = [
                ...$foreignKey,
                'id' => $nextId++,
                'from' => array_map(
                    fn (string $column): string => $renames[strtolower($column)] ?? $column,
                    $foreignKey['from']
                ),
            ];
        }

        foreach ($plan->addForeignKeys() as $foreignKey) {
            $foreignKeys[] = [
                'id' => $nextId++,
                'table' => $foreignKey->referencedTable,
                'from' => [$foreignKey->localColumn],
                'to' => [$foreignKey->referencedColumn],
                'onUpdate' => $foreignKey->onUpdate ?? 'NO ACTION',
                'onDelete' => $foreignKey->onDelete ?? 'NO ACTION',
                'match' => 'NONE',
            ];
        }

        return $foreignKeys;
    }

    /**
     * Each drop_foreign_keys entry is either the FK's local column name or the
     * framework builder's generated `fk_{table}_{column}` constraint name. The
     * `{table}_{from}_fk{id}` labels SqliteSnapshotMapper synthesizes are
     * internal regeneration names and are never matched against user input.
     *
     * @return list<int>
     */
    private function resolveDroppedForeignKeys(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $prefix = 'fk_' . $snapshot->table . '_';
        $ids = [];

        foreach ($plan->dropForeignKeys() as $entry) {
            $candidates = [$entry];
            if (stripos($entry, $prefix) === 0) {
                $candidates[] = substr($entry, strlen($prefix));
            }
            $matched = null;
            foreach ($candidates as $candidate) {
                foreach ($snapshot->foreignKeys as $foreignKey) {
                    if (count($foreignKey['from']) === 1 && strcasecmp($foreignKey['from'][0], $candidate) === 0) {
                        $matched = $foreignKey['id'];
                        break 2;
                    }
                }
            }
            if ($matched === null) {
                $this->reject(
                    $snapshot->table,
                    'drop_foreign_keys',
                    "constraint \"{$entry}\"",
                    'no foreign key matches that local column name or fk_{table}_{column} constraint name'
                );
            }
            $ids[] = $matched;
        }

        return $ids;
    }

    /**
     * Unique auto-index survival. A modified column's replacement definition is
     * authoritative for its own uniqueness — that is the inline-unique drop
     * mechanism. A composite unique auto-index covering a modified column has no
     * other handle than its sqlite_autoindex_% name, so it fails closed unless
     * the same call drops it.
     *
     * @return list<array{name: string, unique: bool, origin: string, partial: bool,
     *   sql: ?string, columns: list<?string>}>
     */
    private function resolveTargetAutoIndexes(SqliteAlterationPlan $plan, SqliteTableSnapshot $snapshot): array
    {
        $dropped = $this->lowered($plan->dropColumns());
        $renames = $this->renameMap($plan);
        $modified = $this->lowered(
            array_map(static fn (ColumnDefinition $c): string => $c->name, $plan->modifyColumns())
        );

        $indexes = [];
        foreach ($snapshot->autoIndexes() as $index) {
            if ($this->namedIn($plan->dropIndexes(), $index['name'])) {
                continue;
            }
            $members = [];
            $touchesModified = false;
            $touchesDropped = false;
            foreach ($index['columns'] as $member) {
                if ($member === null) {
                    $members[] = null;
                    continue;
                }
                $lower = strtolower($member);
                if (in_array($lower, $dropped, true)) {
                    $touchesDropped = true;
                    continue;
                }
                $touchesModified = $touchesModified || in_array($lower, $modified, true);
                $members[] = $renames[$lower] ?? $member;
            }

            if ($index['origin'] === 'u' && $touchesModified) {
                if (count($index['columns']) > 1) {
                    $this->reject(
                        $snapshot->table,
                        'modify_columns',
                        "composite unique constraint \"{$index['name']}\" covering a modified column",
                        'the replacement column definition can only express that column\'s own uniqueness; drop the '
                        . "constraint in the same call by naming \"{$index['name']}\" in drop_indexes and restate it "
                        . 'afterwards with unique([...])'
                    );
                }
                if (!$this->replacementDeclaresUnique($plan, (string) $index['columns'][0])) {
                    continue;
                }
            }
            if ($touchesDropped) {
                if ($members !== []) {
                    $this->reject(
                        $snapshot->table,
                        'drop_columns',
                        "constraint index \"{$index['name']}\" spanning a dropped column",
                        'the remaining members cannot carry the constraint\'s meaning; drop it in the same call by '
                        . "naming \"{$index['name']}\" in drop_indexes"
                    );
                }
                continue;
            }
            $indexes[] = [...$index, 'columns' => $members];
        }

        foreach ([...$plan->modifyColumns(), ...$plan->addColumns()] as $column) {
            if (!$column->unique || $this->coveredByUniqueIndex($indexes, $column->name)) {
                continue;
            }
            $indexes[] = [
                // A label only: createTable() emits UNIQUE (...) and ignores the name.
                'name' => "{$snapshot->table}_{$column->name}_unique",
                'unique' => true,
                'origin' => 'u',
                'partial' => false,
                'sql' => null,
                'columns' => [$column->name],
            ];
        }

        return $indexes;
    }

    private function replacementDeclaresUnique(SqliteAlterationPlan $plan, string $column): bool
    {
        foreach ($plan->modifyColumns() as $replacement) {
            if (strcasecmp($replacement->name, $column) === 0) {
                return $replacement->unique;
            }
        }

        return false;
    }

    /**
     * @param list<array{name: string, unique: bool, origin: string, partial: bool,
     *   sql: ?string, columns: list<?string>}> $indexes
     */
    private function coveredByUniqueIndex(array $indexes, string $column): bool
    {
        foreach ($indexes as $index) {
            if (
                $index['unique']
                && count($index['columns']) === 1
                && $index['columns'][0] !== null
                && strcasecmp($index['columns'][0], $column) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rowid safety, derived for source and target independently.
     *
     * @return array{target: string, source: string}|null
     */
    private function deriveRowidCopy(SqliteTableSnapshot $snapshot, TableDefinition $definition): ?array
    {
        if ($snapshot->withoutRowid) {
            return null;
        }

        $sourceAlias = $this->sourceIntegerPrimaryKeyAlias($snapshot);
        $targetAlias = $this->targetIntegerPrimaryKeyAlias($definition);

        if ($targetAlias !== null && $sourceAlias === null) {
            $this->reject(
                $snapshot->table,
                'rebuild',
                "new INTEGER PRIMARY KEY alias \"{$targetAlias}\"",
                'the source table has no rowid alias, so the alias values cannot be preserved'
            );
        }
        if ($targetAlias !== null) {
            // The alias IS the rowid: it is copied once, by its column name.
            return null;
        }

        $targetNames = array_values(array_map(
            static fn (ColumnDefinition $column): string => $column->name,
            $definition->columns
        ));
        $target = $this->firstUnshadowedRowid($targetNames);
        if ($target === null) {
            $this->reject(
                $snapshot->table,
                'rebuild',
                'shadowed rowid pseudocolumns on the rebuilt table',
                'columns named rowid, _rowid_ and oid leave no spelling to copy the implicit rowid through'
            );
        }

        if ($sourceAlias !== null) {
            return ['target' => $target, 'source' => $sourceAlias];
        }

        $source = $this->firstUnshadowedRowid($snapshot->columnNames());
        if ($source === null) {
            $this->reject(
                $snapshot->table,
                'rebuild',
                'shadowed rowid pseudocolumns on the source table',
                'columns named rowid, _rowid_ and oid leave no spelling to read the implicit rowid through'
            );
        }

        return ['target' => $target, 'source' => $source];
    }

    private function sourceIntegerPrimaryKeyAlias(SqliteTableSnapshot $snapshot): ?string
    {
        if ($snapshot->withoutRowid || count($snapshot->primaryKey) !== 1) {
            return null;
        }
        $column = $snapshot->column($snapshot->primaryKey[0]);

        return $column !== null && strtolower($column['type']) === 'integer' ? $column['name'] : null;
    }

    private function targetIntegerPrimaryKeyAlias(TableDefinition $definition): ?string
    {
        if (($definition->options['without_rowid'] ?? false) === true || $definition->primaryKey !== []) {
            return null;
        }
        foreach ($definition->columns as $column) {
            if ($column->primary && $column->type === 'integer') {
                return $column->name;
            }
        }

        return null;
    }

    /**
     * @param list<string> $columns
     */
    private function firstUnshadowedRowid(array $columns): ?string
    {
        $taken = array_map('strtolower', $columns);
        foreach (self::ROWID_ALIASES as $alias) {
            if (!in_array($alias, $taken, true)) {
                return $alias;
            }
        }

        return null;
    }

    // =========================================================================
    // 3. execute — mode selection, transaction boundaries, the swap body.
    // =========================================================================

    /**
     * @param array{definition: TableDefinition, copyMap: array<string, string>,
     *   rowidCopy: ?array{target: string, source: string}} $target
     * @param list<string> $indexSql
     * @param list<array{name: string, sql: string}> $views
     * @param list<array{name: string, table: string, sql: string}> $triggers
     * @param list<array{childTable: string, id: int, from: list<string>, to: list<string>}> $inbound
     */
    private function execute(
        SqliteAlterationPlan $plan,
        SqliteTableSnapshot $snapshot,
        array $target,
        array $indexSql,
        array $views,
        array $triggers,
        array $inbound
    ): void {
        $this->assertNoForeignKeyViolations('pre-existing');
        $legacyBefore = $this->pragmaInt('legacy_alter_table');

        try {
            if ($this->mode === self::MODE_STANDALONE) {
                $foreignKeysBefore = $this->pragmaInt('foreign_keys');
                $this->setPragmaVerified('foreign_keys', 'OFF', 0);
                try {
                    $this->pdo->exec('BEGIN IMMEDIATE');
                    try {
                        $this->runUnitOfWork(
                            $plan,
                            $snapshot,
                            $target,
                            $indexSql,
                            $views,
                            $triggers,
                            $inbound,
                            $legacyBefore
                        );
                        $this->pdo->exec('COMMIT');
                    } catch (\Throwable $exception) {
                        $this->executeQuietly('ROLLBACK');
                        throw $this->executionFailure($exception);
                    }
                } finally {
                    $this->restorePragma('foreign_keys', $foreignKeysBefore);
                }
            } else {
                $savepoint = $this->generator->quoteIdentifier('glueful_rebuild_' . bin2hex(random_bytes(6)));
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $this->runUnitOfWork(
                        $plan,
                        $snapshot,
                        $target,
                        $indexSql,
                        $views,
                        $triggers,
                        $inbound,
                        $legacyBefore
                    );
                    $this->pdo->exec('RELEASE ' . $savepoint);
                } catch (\Throwable $exception) {
                    $this->executeQuietly('ROLLBACK TO ' . $savepoint);
                    $this->executeQuietly('RELEASE ' . $savepoint);
                    throw $this->executionFailure($exception);
                }
            }
        } finally {
            $this->restorePragma('legacy_alter_table', $legacyBefore);
        }
    }

    /**
     * Rebuild, verification, optional rename and the final global foreign-key
     * check are one unit: the rename never happens after commit.
     *
     * @param array{definition: TableDefinition, copyMap: array<string, string>,
     *   rowidCopy: ?array{target: string, source: string}} $target
     * @param list<string> $indexSql
     * @param list<array{name: string, sql: string}> $views
     * @param list<array{name: string, table: string, sql: string}> $triggers
     * @param list<array{childTable: string, id: int, from: list<string>, to: list<string>}> $inbound
     */
    private function runUnitOfWork(
        SqliteAlterationPlan $plan,
        SqliteTableSnapshot $snapshot,
        array $target,
        array $indexSql,
        array $views,
        array $triggers,
        array $inbound,
        int $legacyBefore
    ): void {
        $this->swap($plan, $snapshot, $target, $indexSql, $legacyBefore);
        $this->verify($plan, $snapshot, $target['definition'], $indexSql, $triggers);

        if ($plan->renameTable() !== null) {
            $this->renameStage($plan, $views, $triggers, $inbound, $legacyBefore);
        }

        $this->assertNoForeignKeyViolations('post-change');
    }

    /**
     * @param array{definition: TableDefinition, copyMap: array<string, string>,
     *   rowidCopy: ?array{target: string, source: string}} $target
     * @param list<string> $indexSql
     */
    private function swap(
        SqliteAlterationPlan $plan,
        SqliteTableSnapshot $snapshot,
        array $target,
        array $indexSql,
        int $legacyBefore
    ): void {
        $table = $plan->table();
        $temporary = $this->temporaryTableName($table);
        $definition = $target['definition'];

        $this->pdo->exec($this->generator->createTable(new TableDefinition(
            name: $temporary,
            columns: $definition->columns,
            indexes: $definition->indexes,
            foreignKeys: $definition->foreignKeys,
            primaryKey: $definition->primaryKey,
            options: $definition->options,
        )));

        $targetColumns = array_keys($target['copyMap']);
        $sourceColumns = array_values($target['copyMap']);
        if ($target['rowidCopy'] !== null) {
            array_unshift($targetColumns, $target['rowidCopy']['target']);
            array_unshift($sourceColumns, $target['rowidCopy']['source']);
        }
        if ($targetColumns !== []) {
            $this->pdo->exec(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->generator->quoteIdentifier($temporary),
                implode(', ', array_map([$this->generator, 'quoteIdentifier'], $targetColumns)),
                implode(', ', array_map([$this->generator, 'quoteIdentifier'], $sourceColumns)),
                $this->generator->quoteIdentifier($table)
            ));
        }

        $this->pdo->exec('DROP TABLE ' . $this->generator->quoteIdentifier($table));

        // The forced-ON bracket covers this single rename and nothing else: a
        // non-legacy rename would try to rewrite dependent view and external
        // trigger bodies against the name that was dropped one statement ago.
        $this->setPragmaVerified('legacy_alter_table', 'ON', 1);
        try {
            $this->pdo->exec($this->generator->renameTable($temporary, $table));
        } finally {
            $this->restorePragma('legacy_alter_table', $legacyBefore);
        }

        foreach ($indexSql as $sql) {
            $this->pdo->exec($sql);
        }
        foreach ($snapshot->triggers as $trigger) {
            $this->pdo->exec($trigger['sql']);
        }

        $this->restoreSequence($table, $temporary, $snapshot);
    }

    private function restoreSequence(string $table, string $temporary, SqliteTableSnapshot $snapshot): void
    {
        if (!$this->schemaObjectExists('sqlite_sequence')) {
            return;
        }
        $delete = $this->pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ? OR name = ?');
        $delete->execute([$table, $temporary]);

        if ($snapshot->sequenceValue !== null) {
            $insert = $this->pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)');
            $insert->execute([$table, $snapshot->sequenceValue]);
        }
    }

    // =========================================================================
    // 4. verify — canonical comparison against a scratch-database expectation.
    // =========================================================================

    /**
     * @param list<string> $indexSql
     * @param list<array{name: string, table: string, sql: string}> $triggersBefore
     */
    private function verify(
        SqliteAlterationPlan $plan,
        SqliteTableSnapshot $snapshot,
        TableDefinition $definition,
        array $indexSql,
        array $triggersBefore
    ): void {
        $actual = $this->introspector->snapshot($plan->table());

        // The scratch database materializes the table shape and its named
        // indexes only. Triggers are never replayed here: a body may reference
        // tables that do not exist in the scratch database.
        $scratch = new PDO('sqlite::memory:');
        $scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $scratch->exec($this->generator->createTable($definition));
        foreach ($indexSql as $sql) {
            $scratch->exec($sql);
        }
        $expected = (new SqliteSchemaIntrospector($scratch, $this->scanner))->snapshot($plan->table());

        if ($expected->toCanonicalArray() !== $actual->toCanonicalArray()) {
            throw new \RuntimeException(sprintf(
                'Rebuild verification failed for table "%s": the rebuilt table does not match the planned target. '
                . 'Expected %s, got %s',
                $plan->table(),
                $this->encode($expected->toCanonicalArray()),
                $this->encode($actual->toCanonicalArray())
            ));
        }

        if ($actual->sequenceValue !== $snapshot->sequenceValue) {
            throw new \RuntimeException(sprintf(
                'Rebuild verification failed for table "%s": sqlite_sequence high-water mark is %s, expected %s',
                $plan->table(),
                var_export($actual->sequenceValue, true),
                var_export($snapshot->sequenceValue, true)
            ));
        }

        $this->assertTriggersUnchanged($plan->table(), $triggersBefore, $this->introspector->allTriggers());
    }

    /**
     * @param list<array{name: string, table: string, sql: string}> $before
     * @param list<array{name: string, table: string, sql: string}> $after
     */
    private function assertTriggersUnchanged(string $table, array $before, array $after): void
    {
        $expected = $this->normalizedSqlByName($before);
        $actual = $this->normalizedSqlByName($after);
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                'Rebuild verification failed for table "%s": trigger definitions changed during the rebuild '
                . '(expected %s, got %s)',
                $table,
                $this->encode($expected),
                $this->encode($actual)
            ));
        }
    }

    // =========================================================================
    // 5. renameStage — the final user-visible rename, inside the same unit.
    // =========================================================================

    /**
     * @param list<array{name: string, sql: string}> $views
     * @param list<array{name: string, table: string, sql: string}> $triggers
     * @param list<array{childTable: string, id: int, from: list<string>, to: list<string>}> $inbound
     */
    private function renameStage(
        SqliteAlterationPlan $plan,
        array $views,
        array $triggers,
        array $inbound,
        int $legacyBefore
    ): void {
        $from = $plan->table();
        $to = (string) $plan->renameTable();

        // Forced OFF so SQLite rewrites inbound foreign keys and dependent
        // trigger/view bodies onto the new name — the point of a user rename.
        $this->setPragmaVerified('legacy_alter_table', 'OFF', 0);
        try {
            $this->pdo->exec($this->generator->renameTable($from, $to));
        } finally {
            $this->restorePragma('legacy_alter_table', $legacyBefore);
        }

        $this->introspector->snapshot($to);
        if ($this->schemaObjectExists($from)) {
            throw new \RuntimeException(
                "Rename verification failed: table \"{$from}\" still exists after renaming it to \"{$to}\""
            );
        }

        if ($this->introspector->inboundForeignKeys($from) !== []) {
            throw new \RuntimeException(
                "Rename verification failed: foreign keys still reference the old table name \"{$from}\""
            );
        }
        $after = $this->introspector->inboundForeignKeys($to);
        if (count($after) !== count($inbound)) {
            throw new \RuntimeException(sprintf(
                'Rename verification failed: %d inbound foreign key(s) referenced "%s" before the rename but %d '
                . 'reference "%s" afterwards',
                count($inbound),
                $from,
                count($after),
                $to
            ));
        }

        $this->assertDependentBodiesRenamed($from, $to, $views, $this->introspector->allViews(), 'view');
        $this->assertDependentBodiesRenamed($from, $to, $triggers, $this->introspector->allTriggers(), 'trigger');
    }

    /**
     * Each dependent object must reference the new name and nothing else may
     * have changed: comparing canonical identifier tokens with the old name
     * substituted proves both halves at once.
     *
     * @param list<array{name: string, sql: string}>|list<array{name: string, table: string, sql: string}> $before
     * @param list<array{name: string, sql: string}>|list<array{name: string, table: string, sql: string}> $after
     */
    private function assertDependentBodiesRenamed(
        string $from,
        string $to,
        array $before,
        array $after,
        string $kind
    ): void {
        $lowerFrom = strtolower($from);
        $lowerTo = strtolower($to);
        $afterByName = [];
        foreach ($after as $object) {
            $afterByName[$object['name']] = $object['sql'];
        }

        foreach ($before as $object) {
            $identifiers = $this->scanner->identifiers($object['sql']);
            if (!in_array($lowerFrom, $identifiers, true)) {
                continue;
            }
            if (!isset($afterByName[$object['name']])) {
                throw new \RuntimeException(
                    "Rename verification failed: dependent {$kind} \"{$object['name']}\" disappeared while renaming "
                    . "\"{$from}\" to \"{$to}\""
                );
            }
            $expected = array_unique(array_map(
                static fn (string $identifier): string => $identifier === $lowerFrom ? $lowerTo : $identifier,
                $identifiers
            ));
            $actual = $this->scanner->identifiers($afterByName[$object['name']]);
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw new \RuntimeException(sprintf(
                    'Rename verification failed: dependent %s "%s" does not reference "%s" with otherwise '
                    . 'equivalent tokens (expected %s, got %s)',
                    $kind,
                    $object['name'],
                    $to,
                    $this->encode($expected),
                    $this->encode($actual)
                ));
            }
        }
    }

    // =========================================================================
    // Shared helpers.
    // =========================================================================

    private function pragmaInt(string $name): int
    {
        $statement = $this->pdo->query('PRAGMA ' . $name);

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function setPragma(string $name, string $value): void
    {
        $this->pdo->exec('PRAGMA ' . $name . ' = ' . $value);
    }

    private function setPragmaVerified(string $name, string $value, int $expected): void
    {
        $this->setPragma($name, $value);
        if ($this->pragmaInt($name) !== $expected) {
            throw new \RuntimeException("PRAGMA {$name} could not be set to {$value} and read back");
        }
    }

    private function restorePragma(string $name, int $value): void
    {
        $this->setPragma($name, $value === 1 ? 'ON' : 'OFF');
        if ($this->pragmaInt($name) !== $value) {
            throw new \RuntimeException(
                "PRAGMA {$name} could not be restored to its captured value; connection state may be altered"
            );
        }
    }

    private function assertNoForeignKeyViolations(string $stage): void
    {
        $statement = $this->pdo->query('PRAGMA foreign_key_check');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) {
            throw new \RuntimeException(sprintf(
                'Global PRAGMA foreign_key_check reported %d %s violation(s); refusing to rebuild',
                count($rows),
                $stage
            ));
        }
    }

    private function temporaryTableName(string $table): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $table . '__rebuild_' . bin2hex(random_bytes(4));
            if (!$this->schemaObjectExists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Could not find a free temporary table name for \"{$table}\"");
    }

    private function schemaObjectExists(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM sqlite_schema WHERE name = ?');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function executeQuietly(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\Throwable) {
            // The original failure is the one worth reporting.
        }
    }

    private function executionFailure(\Throwable $exception): \RuntimeException
    {
        if ($exception instanceof \RuntimeException) {
            return $exception;
        }

        return new \RuntimeException(
            'SQLite table rebuild failed and was rolled back: ' . $exception->getMessage(),
            0,
            $exception
        );
    }

    /**
     * Every identifier the plan changes: modified, dropped, and renamed-from.
     *
     * @return list<string>
     */
    private function changedIdentifiers(SqliteAlterationPlan $plan): array
    {
        return array_values(array_unique(array_merge(
            $this->lowered(array_map(static fn (ColumnDefinition $c): string => $c->name, $plan->modifyColumns())),
            $this->lowered($plan->dropColumns()),
            $this->lowered(array_keys($plan->renameColumns()))
        )));
    }

    /** @return array<string, string> Lower-cased source name => target name. */
    private function renameMap(SqliteAlterationPlan $plan): array
    {
        $map = [];
        foreach ($plan->renameColumns() as $from => $to) {
            $map[strtolower($from)] = $to;
        }

        return $map;
    }

    /**
     * @param array<int, string> $values
     * @return list<string>
     */
    private function lowered(array $values): array
    {
        return array_values(array_map('strtolower', $values));
    }

    /**
     * @param list<string> $names
     */
    private function namedIn(array $names, string $candidate): bool
    {
        foreach ($names as $name) {
            if (strcasecmp($name, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A scanner failure on a dependency is uncertainty, and uncertainty is fatal.
     *
     * @return list<string>
     */
    private function identifiersOrReject(string $table, string $operation, string $feature, string $sql): array
    {
        try {
            return $this->scanner->identifiers($sql);
        } catch (\RuntimeException $exception) {
            $this->reject(
                $table,
                $operation,
                $feature,
                'its SQL could not be scanned (' . $exception->getMessage() . '), so its dependency on the altered '
                . 'table cannot be disproved'
            );
        }
    }

    /**
     * @param list<array{name: string, type: string, notNull: bool, default: ?string,
     *   pkOrdinal: int, hidden: int}> $columns
     */
    private function assertDistinctColumnNames(string $table, array $columns): void
    {
        $seen = [];
        foreach ($columns as $column) {
            $lower = strtolower($column['name']);
            if (isset($seen[$lower])) {
                $this->reject(
                    $table,
                    'rebuild',
                    "duplicate target column name \"{$column['name']}\"",
                    'the requested changes would produce two columns with the same name'
                );
            }
            $seen[$lower] = true;
        }
    }

    /**
     * @param list<array{name: string, table?: string, sql: string}> $objects
     * @return array<string, string>
     */
    private function normalizedSqlByName(array $objects): array
    {
        $normalized = [];
        foreach ($objects as $object) {
            $normalized[$object['name']] = trim((string) preg_replace('/\s+/', ' ', $object['sql']));
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value);
    }

    private function reject(string $table, string $operation, string $feature, string $reason): never
    {
        throw UnsupportedSchemaOperationException::forFeature($table, $operation, $feature, $reason);
    }
}
