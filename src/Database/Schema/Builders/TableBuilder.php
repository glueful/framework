<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\TableBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderContextInterface;
use Glueful\Database\Schema\Interfaces\ColumnBuilderInterface;
use Glueful\Database\Schema\Interfaces\ForeignKeyBuilderInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Sqlite\SqliteAlterationPlan;

/**
 * Concrete Table Builder Implementation
 *
 * Provides fluent interface for defining table structure with columns,
 * indexes, and constraints. Handles both table creation and alteration.
 *
 * Features:
 * - Fluent method chaining for all operations
 * - Database-agnostic column types
 * - Automatic index name generation
 * - Foreign key relationship management
 * - Table option configuration
 * - Validation and error handling
 *
 * Example usage:
 * ```php
 * $table->id()
 *     ->string('name', 100)->index()
 *     ->string('email')->unique()
 *     ->boolean('is_active')->default(true)
 *     ->timestamps()
 *     ->create();
 * ```
 */
class TableBuilder implements TableBuilderInterface, TableBuilderContextInterface
{
    /**
     * @var SchemaBuilderInterface Parent schema builder
     */
    private SchemaBuilderInterface $schemaBuilder;

    /**
     * @var SqlGeneratorInterface SQL generator
     */
    private SqlGeneratorInterface $sqlGenerator;

    /**
     * @var string Table name
     */
    private string $tableName;

    /**
     * @var bool Whether this is an alteration (true) or creation (false)
     */
    private bool $isAlteration;

    /**
     * @var array<ColumnDefinition> Column definitions
     */
    private array $columns = [];

    /**
     * @var array<IndexDefinition> Index definitions
     */
    private array $indexes = [];

    /**
     * @var array<ForeignKeyDefinition> Foreign key definitions
     */
    private array $foreignKeys = [];

    /**
     * @var array<string> Primary key column names
     */
    private array $primaryKey = [];

    /**
     * @var array<string, mixed> Table options
     */
    private array $options = [];

    /**
     * @var string|null Table comment
     */
    private ?string $comment = null;

    /**
     * Create a new table builder
     *
     * @param SchemaBuilderInterface $schemaBuilder Parent schema builder
     * @param SqlGeneratorInterface  $sqlGenerator  SQL generator
     * @param string                 $tableName     Table name
     * @param bool                   $isAlteration  Whether this is an alteration
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        SqlGeneratorInterface $sqlGenerator,
        string $tableName,
        bool $isAlteration = false
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->sqlGenerator = $sqlGenerator;
        $this->tableName = $tableName;
        $this->isAlteration = $isAlteration;
    }

    // ===========================================
    // Column Type Methods
    // ===========================================

    /**
     * Add auto-incrementing primary key column
     *
     * @param  string $name Column name (default: 'id')
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function id(string $name = 'id'): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'id',
            [
            'autoIncrement' => true,
            'primary' => true,
            'nullable' => false
            ]
        );
    }

    /**
     * Add string/varchar column
     *
     * @param  string $name   Column name
     * @param  int    $length Maximum length (default: 255)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function string(string $name, int $length = 255): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'string', ['length' => $length]);
    }

    /**
     * Add text column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function text(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'text');
    }

    /**
     * Add integer column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function integer(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'integer');
    }

    /**
     * Add big integer column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function bigInteger(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'bigInteger');
    }

    /**
     * Add boolean column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function boolean(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'boolean');
    }

    /**
     * Add decimal column
     *
     * @param  string $name      Column name
     * @param  int    $precision Total digits (default: 8)
     * @param  int    $scale     Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'decimal',
            [
            'precision' => $precision,
            'scale' => $scale
            ]
        );
    }

    /**
     * Add timestamp column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function timestamp(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'timestamp');
    }

    /**
     * Add datetime column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function dateTime(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'datetime');
    }

    /**
     * Add date column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function date(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'date');
    }

    /**
     * Add time column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function time(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'time');
    }

    /**
     * Add JSON column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function json(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'json');
    }

    /**
     * Add UUID column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function uuid(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'uuid');
    }

    /**
     * Add float column
     *
     * @param  string $name      Column name
     * @param  int    $precision Total digits (default: 8)
     * @param  int    $scale     Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'float',
            [
            'precision' => $precision,
            'scale' => $scale
            ]
        );
    }

    /**
     * Add double column
     *
     * @param  string $name      Column name
     * @param  int    $precision Total digits (default: 15)
     * @param  int    $scale     Decimal places (default: 8)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function double(string $name, int $precision = 15, int $scale = 8): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'double',
            [
            'precision' => $precision,
            'scale' => $scale
            ]
        );
    }

    /**
     * Add enum column
     *
     * @param  string      $name    Column name
     * @param  array<int, string>       $values  Allowed enum values
     * @param  string|null $default Default value (must be one of the allowed values)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function enum(string $name, array $values, ?string $default = null): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'enum',
            [
            'values' => $values,
            'default' => $default
            ]
        );
    }

    /**
     * Add binary column
     *
     * @param  string   $name   Column name
     * @param  int|null $length Fixed length for binary data (null for variable length)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function binary(string $name, ?int $length = null): ColumnBuilderInterface
    {
        return $this->addColumnBuilder(
            $name,
            'binary',
            [
            'length' => $length
            ]
        );
    }

    /**
     * Add foreign ID column (bigInteger with foreign key)
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with constrained() method
     */
    public function foreignId(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'foreignId');
    }

    // ===========================================
    // Convenience Methods
    // ===========================================

    /**
     * Add created_at and updated_at timestamp columns
     *
     * @return self For method chaining
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->useCurrent()->end();
        $this->timestamp('updated_at')->nullable()->end();
        return $this;
    }

    /**
     * Add deleted_at timestamp column for soft deletes
     *
     * @return self For method chaining
     */
    public function softDeletes(): self
    {
        $this->timestamp('deleted_at')->nullable()->end();
        return $this;
    }

    /**
     * Add remember_token string column for authentication
     *
     * @return self For method chaining
     */
    public function rememberToken(): self
    {
        $this->string('remember_token', 100)->nullable()->end();
        return $this;
    }

    // ===========================================
    // Column Operations (for alterations)
    // ===========================================

    /**
     * Add a new column (generic method)
     *
     * @param  string $name    Column name
     * @param  string $type    Column type
     * @param  array<string, mixed>  $options Column options
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function addColumn(string $name, string $type, array $options = []): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, $type, $options);
    }

    /**
     * Modify an existing column
     *
     * @param  string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with new definition
     */
    public function modifyColumn(string $name): ColumnBuilderInterface
    {
        // For modification, we create a column builder that will replace the existing definition
        return new ColumnBuilder($this, $name, 'string', ['_modify' => true]);
    }

    /**
     * Rename a column
     *
     * @param  string $from Current column name
     * @param  string $to   New column name
     * @return self For method chaining
     */
    public function renameColumn(string $from, string $to): self
    {
        // Store rename operation for later execution
        $this->options['_renames'] = $this->options['_renames'] ?? [];
        $this->options['_renames'][] = ['from' => $from, 'to' => $to];
        return $this;
    }

    /**
     * Drop a column
     *
     * @param  string $name Column name
     * @return self For method chaining
     */
    public function dropColumn(string $name): self
    {
        // Store drop operation for later execution
        $this->options['_drops'] = $this->options['_drops'] ?? [];
        $this->options['_drops'][] = $name;
        return $this;
    }

    // ===========================================
    // Index Operations
    // ===========================================

    /**
     * Add an index
     *
     * @param  array<int, string>|string $columns Column(s) to index
     * @param  string|null  $name    Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function index(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name !== null ? $name : $this->generateIndexName($columns, 'index');

        $this->indexes[] = new IndexDefinition($columns, $name, 'index');
        return $this;
    }

    /**
     * Add a unique index
     *
     * @param  array<int, string>|string $columns Column(s) for unique constraint
     * @param  string|null  $name    Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function unique(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name !== null ? $name : $this->generateIndexName($columns, 'unique');

        $this->indexes[] = new IndexDefinition($columns, $name, 'unique', true);
        return $this;
    }

    /**
     * Set primary key
     *
     * @param  array<int, string>|string $columns Column(s) for primary key
     * @return self For method chaining
     */
    public function primary(array|string $columns): self
    {
        $this->primaryKey = is_string($columns) ? [$columns] : $columns;
        return $this;
    }

    /**
     * Add a fulltext index (where supported)
     *
     * @param  array<int, string>|string $columns Column(s) for fulltext index
     * @param  string|null  $name    Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function fulltext(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name !== null ? $name : $this->generateIndexName($columns, 'fulltext');

        $this->indexes[] = new IndexDefinition($columns, $name, 'fulltext');
        return $this;
    }

    /**
     * Drop an index
     *
     * @param  string $name Index name
     * @return self For method chaining
     */
    public function dropIndex(string $name): self
    {
        $this->options['_drop_indexes'] = $this->options['_drop_indexes'] ?? [];
        $this->options['_drop_indexes'][] = $name;
        return $this;
    }

    /**
     * Drop a unique constraint
     *
     * @param  string $name Constraint name
     * @return self For method chaining
     */
    public function dropUnique(string $name): self
    {
        return $this->dropIndex($name);
    }

    /**
     * Drop primary key
     *
     * @return self For method chaining
     */
    public function dropPrimary(): self
    {
        $this->options['_drop_primary'] = true;
        return $this;
    }

    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Create a foreign key constraint
     *
     * @param  string $column Local column name
     * @return ForeignKeyBuilderInterface For fluent foreign key definition
     */
    public function foreign(string $column): ForeignKeyBuilderInterface
    {
        return new ForeignKeyBuilder($this, $column);
    }

    /**
     * Drop a foreign key constraint
     *
     * @param  string $name Constraint name
     * @return self For method chaining
     */
    public function dropForeign(string $name): self
    {
        $this->options['_drop_foreign_keys'] = $this->options['_drop_foreign_keys'] ?? [];
        $this->options['_drop_foreign_keys'][] = $name;
        return $this;
    }

    // ===========================================
    // Table Operations (for alterations)
    // ===========================================

    /**
     * Rename the table (alteration only)
     *
     * @param  string $newName New table name
     * @return self For method chaining
     */
    public function rename(string $newName): self
    {
        if ($newName === '') {
            throw new \InvalidArgumentException('New table name cannot be empty');
        }
        $this->options['_rename_table'] = $newName;

        return $this;
    }

    // ===========================================
    // Table Options
    // ===========================================

    /**
     * Set table engine (MySQL)
     *
     * @param  string $engine Engine name (InnoDB, MyISAM, etc.)
     * @return self For method chaining
     */
    public function engine(string $engine): self
    {
        $this->options['engine'] = $engine;
        return $this;
    }

    /**
     * Set table charset (MySQL)
     *
     * @param  string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self
    {
        $this->options['charset'] = $charset;
        return $this;
    }

    /**
     * Set table collation (MySQL)
     *
     * @param  string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self
    {
        $this->options['collation'] = $collation;
        return $this;
    }

    /**
     * Add table comment
     *
     * @param  string $comment Table comment
     * @return self For method chaining
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    // ===========================================
    // Execution Methods
    // ===========================================

    /**
     * Create the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function create(): SchemaBuilderInterface
    {
        $tableDefinition = new TableDefinition(
            name: $this->tableName,
            columns: $this->columns,
            indexes: $this->indexes,
            foreignKeys: $this->foreignKeys,
            primaryKey: $this->primaryKey,
            options: $this->options,
            comment: $this->comment
        );

        $sql = $this->sqlGenerator->createTable($tableDefinition);
        $this->schemaBuilder->addPendingOperation($sql);

        // Plain (non-unique) indexes are emitted as follow-up CREATE INDEX operations on
        // every driver. Only UNIQUE constraints belong inside CREATE TABLE: SQLite and
        // PostgreSQL never supported inline plain indexes (they were silently discarded),
        // and standalone statements make create-time indexes real, droppable artifacts —
        // identical to the alterTable path. Fulltext stays generator-specific (MySQL
        // inlines it; other drivers do not support it).
        foreach ($this->indexes as $index) {
            if ($index->type === 'index') {
                $this->schemaBuilder->addPendingOperation(
                    $this->sqlGenerator->createIndex($this->tableName, $index)
                );
            }
        }

        return $this->schemaBuilder;
    }

    /**
     * Execute alterations and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function execute(): SchemaBuilderInterface
    {
        if ($this->isAlteration) {
            // Handle table alterations
            $this->executeAlterations();
        } else {
            // Create new table
            $this->create();
        }

        return $this->schemaBuilder;
    }

    /**
     * Drop the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function drop(): SchemaBuilderInterface
    {
        return $this->schemaBuilder->dropTable($this->tableName);
    }

    /**
     * Drop the table if it exists and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function dropIfExists(): SchemaBuilderInterface
    {
        return $this->schemaBuilder->dropTableIfExists($this->tableName);
    }

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get the table name
     *
     * @return string Table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Check if this is a table creation (vs alteration)
     *
     * @return bool True if creating new table
     */
    public function isCreating(): bool
    {
        return !$this->isAlteration;
    }

    // ===========================================
    // Internal Helper Methods
    // ===========================================

    /**
     * Create a column builder and add it to the table
     *
     * @param  string $name    Column name
     * @param  string $type    Column type
     * @param  array<string, mixed>  $options Column options
     * @return ColumnBuilderInterface Column builder
     */
    private function addColumnBuilder(string $name, string $type, array $options = []): ColumnBuilderInterface
    {
        return new ColumnBuilder($this, $name, $type, $options);
    }

    /**
     * Add a column definition to the table
     *
     * @param  ColumnDefinition $column Column definition
     * @return void
     */
    public function addColumnDefinition(ColumnDefinition $column): void
    {
        $this->columns[] = $column;

        // Auto-add to primary key if marked as primary — but only for a genuinely
        // new column. A modifyColumn() replacement carries options['_modify'] === true;
        // treating its ->primary() the same as a brand-new column's would make
        // preservePrimaryKeyMembership()'s forwarding indistinguishable from a real
        // primary-key change and trip the add_or_modify_primary guard with no escape
        // hatch. A modify replacement that claims NEW primary-key membership still
        // fails closed — just at the rebuilder's own audit, with the correct message.
        if ($column->primary && ($column->options['_modify'] ?? false) !== true) {
            $this->primaryKey[] = $column->name;
        }
    }

    /**
     * Add a foreign key definition to the table
     *
     * @param  ForeignKeyDefinition $foreignKey Foreign key definition
     * @return void
     */
    public function addForeignKeyDefinition(ForeignKeyDefinition $foreignKey): void
    {
        $this->foreignKeys[] = $foreignKey;
    }

    /**
     * Generate automatic index name
     *
     * @param  array<string> $columns Column names
     * @param  string        $type    Index type
     * @return string Generated index name
     */
    private function generateIndexName(array $columns, string $type): string
    {
        $suffix = match ($type) {
            'unique' => 'unique',
            'fulltext' => 'fulltext',
            default => 'index'
        };

        return $this->tableName . '_' . implode('_', $columns) . '_' . $suffix;
    }

    /**
     * Execute table alterations
     *
     * @return void
     */
    private function executeAlterations(): void
    {
        $tableDefinition = new TableDefinition(
            name: $this->tableName,
            comment: $this->comment
        );

        $addColumns = [];
        $modifyColumns = [];
        foreach ($this->columns as $column) {
            if (($column->options['_modify'] ?? false) === true) {
                $modifyColumns[] = $column;
            } else {
                $addColumns[] = $column;
            }
        }

        if ($modifyColumns !== []) {
            $modifyColumns = $this->preservePrimaryKeyMembership($modifyColumns);
        }

        $renames = [];
        foreach ($this->options['_renames'] ?? [] as $rename) {
            $renames[$rename['from']] = $rename['to'];
        }

        // Fail-closed on every driver until these have complete generator coverage:
        // today's silent success for drop/add-primary, comments, and
        // engine/charset/collation-style alteration options is replaced with an
        // explicit rejection, before any change-set is built or dispatched.
        if (($this->options['_drop_primary'] ?? false) === true) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'drop_primary',
                'primary key',
                'the fluent alter seam does not yet implement primary-key removal'
            );
        }
        if ($this->primaryKey !== []) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'add_or_modify_primary',
                'primary key',
                'primary-key alteration is not implemented by the fluent alter seam'
            );
        }
        if ($this->comment !== null) {
            throw UnsupportedSchemaOperationException::forFeature(
                $this->tableName,
                'comment',
                'table comment',
                'table-comment alteration is not implemented by the fluent alter seam'
            );
        }
        $handledOptionKeys = [
            '_drops', '_renames', '_drop_indexes', '_drop_foreign_keys',
            '_drop_primary', '_rename_table',
        ];
        foreach (array_keys($this->options) as $optionKey) {
            if (!in_array($optionKey, $handledOptionKeys, true)) {
                throw UnsupportedSchemaOperationException::forFeature(
                    $this->tableName,
                    'table_option',
                    (string) $optionKey,
                    'this alteration-time table option has no implemented SQL path'
                );
            }
        }

        $changes = [
            'add_columns'       => $addColumns,
            'modify_columns'    => $modifyColumns,
            'drop_columns'      => $this->options['_drops'] ?? [],
            'rename_columns'    => $renames,
            'add_indexes'       => $this->indexes,
            'drop_indexes'      => $this->options['_drop_indexes'] ?? [],
            'add_foreign_keys'  => $this->foreignKeys,
            'drop_foreign_keys' => $this->options['_drop_foreign_keys'] ?? [],
            'rename_table'      => $this->options['_rename_table'] ?? null,
        ];
        $changes = array_filter($changes, static fn ($v) => $v !== [] && $v !== null);

        if ($this->sqlGenerator instanceof SQLiteSqlGenerator) {
            $plan = SqliteAlterationPlan::fromChanges($this->tableName, $changes);
            if ($plan->requiresRebuild()) {
                $this->schemaBuilder->executeSqliteRebuild($plan);
                return;
            }

            $statements = array_values($this->sqlGenerator->alterTable($tableDefinition, $changes));
            $this->schemaBuilder->executeSqliteNativeAlteration($statements);
            return;
        }

        $sqlStatements = $this->sqlGenerator->alterTable($tableDefinition, $changes);
        foreach ($sqlStatements as $sql) {
            $this->schemaBuilder->addPendingOperation($sql);
        }
    }

    /**
     * Carry a modified column's current primary-key membership (and, best
     * effort, its auto-increment status) onto the replacement definition.
     *
     * modifyColumn() starts every replacement from scratch (primary: false),
     * but the SQLite rebuilder freezes primary-key membership: a replacement
     * that declares primary: false for a column that is currently part of
     * the primary key is rejected outright. Without this, an ordinary type
     * change on e.g. "id" would be impossible unless the caller also
     * re-declared ->primary() (and ->autoIncrement()) just to describe the
     * status quo. Introspection failure is not fatal here — this is a
     * best-effort convenience, not a source of truth; any real conflict is
     * still caught downstream by the generator or the rebuilder's own audit.
     *
     * @param  list<ColumnDefinition> $modifyColumns
     * @return list<ColumnDefinition>
     */
    private function preservePrimaryKeyMembership(array $modifyColumns): array
    {
        // Flush any already-queued pending operations first — e.g. a table created
        // via the fluent builder without a callback (`$schema->table('x')->...->create()`)
        // only queues its CREATE TABLE; it does not execute until the next flush.
        // Without this, introspecting an unflushed table returns an empty PRAGMA
        // result and forwarding is silently skipped, landing on the frozen-PK
        // rejection for a table that in fact already has the column as primary.
        $this->schemaBuilder->execute();

        try {
            $existingColumns = $this->schemaBuilder->getTableColumns($this->tableName);
        } catch (\Throwable) {
            return $modifyColumns;
        }

        $existingByName = [];
        foreach ($existingColumns as $existingColumn) {
            if (isset($existingColumn['name']) && is_string($existingColumn['name'])) {
                $existingByName[strtolower($existingColumn['name'])] = $existingColumn;
            }
        }

        foreach ($modifyColumns as $index => $column) {
            if ($column->primary) {
                continue;
            }

            $existing = $existingByName[strtolower($column->name)] ?? null;
            if ($existing === null || !(bool) ($existing['is_primary'] ?? false)) {
                continue;
            }

            $autoIncrement = $column->autoIncrement
                || ($column->supportsAutoIncrement() && $this->existingColumnIsAutoIncrement($existing));

            // Primary key columns cannot be nullable (enforced by ColumnDefinition
            // itself); forcing it here mirrors ColumnBuilder::primary(), which does
            // the same when the column is declared primary directly.
            $modifyColumns[$index] = $column->with([
                'primary' => true,
                'nullable' => false,
                'autoIncrement' => $autoIncrement,
            ]);
        }

        return $modifyColumns;
    }

    /**
     * Best-effort, driver-neutral auto-increment detection from the shape
     * SchemaBuilderInterface::getTableColumns() returns: MySQL's 'extra'
     * field or PostgreSQL's 'is_identity' flag. SQLite exposes neither —
     * its rebuild derives the AUTOINCREMENT flag from the source table
     * directly, independent of the replacement definition, so returning
     * false here is a safe no-op for that driver.
     *
     * @param array<string, mixed> $existingColumn
     */
    private function existingColumnIsAutoIncrement(array $existingColumn): bool
    {
        if (isset($existingColumn['extra']) && is_string($existingColumn['extra'])) {
            return str_contains(strtolower($existingColumn['extra']), 'auto_increment');
        }

        return (bool) ($existingColumn['is_identity'] ?? false);
    }
}
