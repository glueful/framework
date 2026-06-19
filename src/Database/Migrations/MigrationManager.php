<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;
use Glueful\Http\Exceptions\Domain\DatabaseException;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Services\FileFinder;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Database Migration Manager
 *
 * Manages database schema migrations including:
 * - Migration tracking using version table
 * - Forward and rollback migrations
 * - Batch management for grouped migrations
 * - Transaction handling for safe execution
 * - Checksum verification for file integrity
 * - Migration history tracking
 *
 * Each migration is executed within a transaction and tracked in the
 * migrations table. Supports rollback operations by batch number
 * and maintains migration order.
 *
 * Usage:
 * ```php
 * $manager = new MigrationManager();
 *
 * // Run pending migrations
 * $result = $manager->migrate();
 *
 * // Run specific migration
 * $result = $manager->migrate('/path/to/migration.php');
 *
 * // Rollback last batch
 * $result = $manager->rollback();
 * ```
 */
class MigrationManager
{
    /**
     * @var SchemaBuilderInterface Database schema builder for table operations
     */
    private SchemaBuilderInterface $schema;

    /**
     * @var Connection Database connection for fluent query operations
     */
    private Connection $db;

    /**
     * @var string Directory containing migration files
     */
    private string $migrationsPath;
    private ?ApplicationContext $context;


    /**
     * @var FileFinder File finder service for migration discovery
     */
    private FileFinder $fileFinder;

    /**
     * @var array<int, array{path: string, priority: int, source: string}>
     *      Additional migration sources from extensions.
     */
    private array $additionalMigrationPaths = [];

    /**
     * @var string Name of migrations tracking table
     */
    private const VERSION_TABLE = 'migrations';

    /**
     * Initialize migration manager
     *
     * Sets up schema manager and ensures version table exists.
     *
     * @param  string|null           $migrationsPath    Custom path to migrations directory
     * @param  FileFinder|null       $fileFinder        File finder service instance
     * @param  ApplicationContext|null $context         Application context for service resolution
     * @param  Connection|null       $connection        Optional injected connection (falls back to context)
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If database connection fails
     */
    public function __construct(
        ?string $migrationsPath = null,
        ?FileFinder $fileFinder = null,
        ?ApplicationContext $context = null,
        ?Connection $connection = null
    ) {
        $this->context = $context;
        $connection = $connection ?? Connection::fromContext($context);
        $this->db = $connection;
        $this->schema = $connection->getSchemaBuilder();

        $this->migrationsPath = $migrationsPath ?? $this->getConfig('app.paths.migrations');
        $this->fileFinder = $fileFinder ?? $this->resolveFileFinder();
        // echo $this->migrationsPath;
        // exit;
        $this->ensureVersionTable();
    }

    private function resolveFileFinder(): FileFinder
    {
        if ($this->context !== null) {
            return container($this->context)->get(FileFinder::class);
        }

        return new FileFinder();
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    /**
     * Add a migration path for extensions
     *
     * @param string $path Path to migration directory
     */
    public function addMigrationPath(
        string $path,
        int $priority = MigrationPriority::DEFAULT,
        ?string $source = null
    ): void {
        if (!is_dir($path)) {
            return;
        }
        if ($source === null) {
            $parts = explode('/', str_replace('\\', '/', rtrim($path, '/')));
            $source = end($parts) !== false ? (string) end($parts) : 'extension';
        }
        $this->additionalMigrationPaths[] = ['path' => $path, 'priority' => $priority, 'source' => $source];
    }

    /**
     * The complete, ordered set of migration sources: the main app path (source 'app',
     * DEFAULT priority) followed by all registered additional paths.
     *
     * @return array<int, array{path: string, priority: int, source: string}>
     */
    private function allSources(): array
    {
        $sources = [[
            'path' => $this->migrationsPath,
            'priority' => MigrationPriority::DEFAULT,
            'source' => 'app',
        ]];
        foreach ($this->additionalMigrationPaths as $entry) {
            $sources[] = $entry;
        }
        return $sources;
    }

    /**
     * True when $file lives inside $dir, compared by canonical realpath with a trailing
     * separator so sibling prefixes (e.g. /foo/pkg vs /foo/pkg2) never false-match.
     */
    private function fileBelongsToDir(string $file, string $dir): bool
    {
        $rf = realpath($file);
        $rd = realpath($dir);
        if ($rf === false || $rd === false) {
            return false;
        }
        $rd = rtrim($rd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($rf, $rd);
    }

    /**
     * Create migrations tracking table
     *
     * Ensures migrations table exists with required structure:
     * - id: Auto-incrementing primary key
     * - migration: Migration filename (unique)
     * - batch: Batch number for grouped rollbacks
     * - applied_at: Timestamp of execution
     * - checksum: File hash for integrity check
     * - description: Migration description
     * - extension: Extension name (NULL for core migrations)
     */
    private function ensureVersionTable(): void
    {
        if (!$this->schema->hasTable(self::VERSION_TABLE)) {
            $table = $this->schema->table(self::VERSION_TABLE);

            // Add columns
            $table->id();
            $table->string('migration', 255);
            $table->integer('batch');
            $table->timestamp('applied_at')->default('CURRENT_TIMESTAMP');
            $table->string('checksum', 64);
            $table->text('description')->nullable();
            $table->string('extension', 100)->nullable();
            $table->string('source', 191)->default('app'); // package name, or 'app' for skeleton

            // Package-scoped uniqueness: two sources may ship the same basename, so the
            // unique key is composite (source, migration) — NOT migration alone.
            $table->unique(['source', 'migration']);

            // Create the table
            $table->create()->execute();

            return;
        }

        // Upgrade path for existing version tables (pre-release dev DBs).
        if (!$this->schema->hasColumn(self::VERSION_TABLE, 'source')) {
            // Callback form runs the column builder, calls execute(), and flushes pending ops.
            $this->schema->alterTable(self::VERSION_TABLE, function ($table): void {
                $table->string('source', 191)->default('app');
            });
            // Backfill any pre-existing rows to the app source.
            $this->db->table(self::VERSION_TABLE)->whereNull('source')->update(['source' => 'app']);
            // IMPORTANT: existing tables still carry the legacy unique(migration). That constraint
            // contradicts package-scoped tracking and must be replaced with unique(source, migration)
            // via a clean migration-history reset (pre-release) — SQLite cannot portably drop it.
        }
    }

    /**
     * Get pending migrations
     *
     * Returns list of migration files that haven't been executed:
     * - Scans migrations directory for .php files
     * - Scans ENABLED extensions migration directories only
     * - Compares against applied migrations
     * - Returns array of pending migration paths
     *
     * @return array<string> List of pending migration file paths
     */
    public function getPendingMigrations(): array
    {
        $appliedKeys = $this->appliedKeys();

        // Collect candidate files with their source + priority.
        $candidates = []; // array<int, array{file:string, priority:int, source:string}>
        foreach ($this->allSources() as $src) {
            foreach ($this->fileFinder->findMigrations($src['path']) as $file) {
                $path = $file->getPathname();
                if (in_array($this->sourceKey($src['source'], basename($path)), $appliedKeys, true)) {
                    continue;
                }
                $candidates[] = ['file' => $path, 'priority' => $src['priority'], 'source' => $src['source']];
            }
        }

        // (priority ASC, basename ASC, source ASC) — source breaks ties so multiple sources
        // shipping the same basename at the same priority order deterministically.
        usort($candidates, function (array $a, array $b): int {
            return [$a['priority'], basename($a['file']), $a['source']]
                <=> [$b['priority'], basename($b['file']), $b['source']];
        });

        return array_map(fn(array $c) => $c['file'], $candidates);
    }

    /**
     * Get list of applied migrations
     *
     * @return array<string> List of applied migration filenames (basename-only view).
     */
    private function getAppliedMigrations(): array
    {
        $result = $this->db
            ->table(self::VERSION_TABLE)
            ->select(['migration'])
            ->get();

        return array_column($result, 'migration');
    }

    /**
     * Applied keys as "{source}\0{basename}" for package-scoped dedup.
     *
     * @return array<string>
     */
    private function appliedKeys(): array
    {
        $rows = $this->db->table(self::VERSION_TABLE)->select(['migration', 'source'])->get();
        $keys = [];
        foreach ($rows as $row) {
            $source = (string) ($row['source'] ?? 'app');
            $keys[] = $this->sourceKey($source, (string) $row['migration']);
        }
        return $keys;
    }

    private function sourceKey(string $source, string $migration): string
    {
        return $source . "\0" . $migration;
    }

    /**
     * Get list of applied migrations (public method)
     *
     * @return array<string> List of applied migration filenames
     */
    public function getAppliedMigrationsList(): array
    {
        return $this->getAppliedMigrations();
    }

    /**
     * Get both pending and applied migrations efficiently
     *
     * @return array{
     *     pending: array<string>,
     *     applied: array<string>
     * } Migration status
     */
    public function getMigrationStatus(): array
    {
        // Pending is computed with the same priority + package-scoped logic as getPendingMigrations().
        return [
            'pending' => $this->getPendingMigrations(),
            'applied' => $this->getAppliedMigrations(),
        ];
    }

    /**
     * Run migrations
     *
     * Executes pending migrations in order. Can run either:
     * - All pending migrations
     * - Specific migration file
     * - Provided list of pending migrations (to avoid duplicate queries)
     *
     * @param  string|array<string>|null $specificFileOrPendingMigrations
     *                                                            Optional specific migration file or array of pending
     *                                                            migrations
     * @return array{
     *     applied: array<string>,
     *     failed: array<string>
     * } Migration results
     */
    public function migrate($specificFileOrPendingMigrations = null): array
    {
        $results = ['applied' => [], 'failed' => []];
        // Handle specific file migration
        if (is_string($specificFileOrPendingMigrations)) {
            $batch = $this->getNextBatchNumber();
            $status = $this->runMigration($specificFileOrPendingMigrations, $batch);
            if ($status['success']) {
                $results['applied'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
            return $results;
        }

        // Handle provided pending migrations array or get pending migrations
        if (is_array($specificFileOrPendingMigrations)) {
            $pendingMigrations = $specificFileOrPendingMigrations;
        } else {
            $pendingMigrations = $this->getPendingMigrations();
        }

        // If no pending migrations, return early
        if (count($pendingMigrations) === 0) {
            return $results;
        }

        // For batch migration, get the batch number once and reuse it
        $batch = $this->getNextBatchNumber();

        foreach ($pendingMigrations as $file) {
            $status = $this->runMigration($file, $batch);
            if ($status['success']) {
                $results['applied'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
        }

        return $results;
    }

    /**
     * Execute single migration
     *
     * Runs a specific migration file:
     * 1. Loads migration class
     * 2. Verifies interface implementation
     * 3. Executes migration within transaction
     * 4. Records successful execution with extension tracking
     *
     * @param  string   $file  Migration file path
     * @param  int|null $batch Optional batch number
     * @return array{
     *     success: bool,
     *     file: string,
     *     error?: string
     * } Migration result
     */
    private function runMigration(string $file, ?int $batch = null): array
    {
        include_once $file;

        $className = pathinfo($file, PATHINFO_FILENAME);
        $className = preg_replace('/^\d+_/', '', $className); // Removes any leading digits and underscore

        // Try to determine if the file contains a namespaced class
        $fileContent = file_get_contents($file);
        $namespace = '';

        if (preg_match('/namespace\s+([^;]+);/i', $fileContent, $matches)) {
            $namespace = $matches[1] . '\\';
        }

        $fullClassName = $namespace . $className;

        if (!class_exists($fullClassName)) {
            // Fall back to non-namespaced class if namespace detection failed
            if (!class_exists($className)) {
                throw DatabaseException::queryFailed(
                    'MIGRATION_ERROR',
                    "Migration class $className not found in $file"
                );
            }
            $fullClassName = $className;
        }

        $migration = new $fullClassName();
        if (!$migration instanceof MigrationInterface) {
            throw BusinessLogicException::operationNotAllowed(
                'migration_validation',
                "Migration $fullClassName must implement MigrationInterface"
            );
        }

        $filename = basename($file);
        $checksum = hash_file('sha256', $file);

        // Determine the owning source (package name) + legacy extension label. Use realpath +
        // trailing-separator matching so "/foo/pkg2" does not match "/foo/pkg".
        $source = 'app';
        $extensionName = null;
        foreach ($this->additionalMigrationPaths as $entry) {
            if ($this->fileBelongsToDir($file, $entry['path'])) {
                $source = $entry['source'];
                $parts = explode('/', str_replace('\\', '/', rtrim($entry['path'], '/')));
                $extensionName = end($parts) !== false ? (string) end($parts) : 'extension';
                break;
            }
        }

        try {
            // Run the migration schema operations - these will execute immediately
            $migration->up($this->schema);

            // Insert migration record after schema operations complete
            $this->db
                ->table(self::VERSION_TABLE)
                ->insert(
                    [
                    'migration' => $filename,
                    'batch' => $batch,
                    'checksum' => $checksum,
                    'description' => $migration->getDescription(),
                    'extension' => $extensionName,
                    'source' => $source
                    ]
                );

            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            error_log("Migration failed: " . $e->getMessage());
            return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get next batch number
     *
     * @return int Next sequential batch number
     */
    private function getNextBatchNumber(): int
    {
        $maxBatch = $this->db
            ->table(self::VERSION_TABLE)
            ->max('batch');

        return (int)($maxBatch ?? 0) + 1;
    }

    /**
     * Rollback migrations
     *
     * Reverts most recent migrations:
     * - Rolls back by batch
     * - Maintains order within batch
     * - Removes from version history
     *
     * @param  int $steps Number of migrations to roll back
     * @return array{
     *     reverted: array<string>,
     *     failed: array<string>
     * } Rollback results
     */
    public function rollback(int $steps = 1): array
    {
        $results = ['reverted' => [], 'failed' => []];
        $migrations = $this->getMigrationsToRollback($steps);

        foreach ($migrations as $row) { // already ordered most-recent-first
            $status = $this->rollbackMigration($row['migration'], $row['source']);
            if ($status['success']) {
                $results['reverted'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
        }

        return $results;
    }

    /**
     * Get migrations for rollback
     *
     * Returns list of migrations to roll back (most recent batch first), each carrying its
     * owning source so rollback can resolve + delete by (source, migration).
     *
     * @param  int $steps Number of migrations to return
     * @return array<int, array{migration: string, source: string}>
     */
    private function getMigrationsToRollback(int $steps): array
    {
        $result = $this->db
            ->table(self::VERSION_TABLE)
            ->select(['migration', 'source'])
            ->orderBy('batch', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($steps)
            ->get();

        return array_map(
            fn(array $r) => [
                'migration' => (string) $r['migration'],
                'source' => (string) ($r['source'] ?? 'app'),
            ],
            $result
        );
    }

    /**
     * Revert single migration
     *
     * Rolls back a specific migration:
     * 1. Loads migration class
     * 2. Executes down() method
     * 3. Removes from version history
     *
     * @param  string $filename Migration filename
     * @param  string $source   Owning source (package name, or 'app')
     * @return array{
     *     success: bool,
     *     file: string,
     *     error?: string
     * } Rollback result
     */
    private function rollbackMigration(string $filename, string $source): array
    {
        if ($filename !== basename($filename) || str_contains($filename, "\0")) {
            return ['success' => false, 'file' => $filename, 'error' => 'Invalid migration filename'];
        }

        // Resolve the file within the directory that owns this source.
        $file = null;
        foreach ($this->allSources() as $src) {
            if ($src['source'] !== $source) {
                continue;
            }
            $candidate = rtrim($src['path'], '/') . '/' . $filename;
            if (file_exists($candidate)) {
                $file = $candidate;
                break;
            }
        }

        if ($file === null) {
            return ['success' => false, 'file' => $filename, 'error' => "File not found for source $source"];
        }

        include_once $file;

        // Namespace-aware class resolution (mirrors runMigration()).
        $className = preg_replace('/^\d+_/', '', pathinfo($file, PATHINFO_FILENAME));
        $fileContent = file_get_contents($file);
        $namespace = '';
        if (is_string($fileContent) && preg_match('/namespace\s+([^;]+);/i', $fileContent, $m) === 1) {
            $namespace = $m[1] . '\\';
        }
        $fullClassName = $namespace . $className;
        if (!class_exists($fullClassName)) {
            if (!class_exists((string) $className)) {
                throw DatabaseException::queryFailed(
                    'MIGRATION_ERROR',
                    "Migration class $className not found in $file"
                );
            }
            $fullClassName = (string) $className;
        }

        $migration = new $fullClassName();
        if (!$migration instanceof MigrationInterface) {
            throw BusinessLogicException::operationNotAllowed(
                'migration_validation',
                "Migration $fullClassName must implement MigrationInterface"
            );
        }

        try {
            // Run migration rollback - operations will execute immediately
            $migration->down($this->schema);

            // Delete the version row by (source, migration) — basename alone is not unique.
            // The migrations table has no deleted_at, so delete() now hard-deletes it (the
            // soft-delete handler is column-aware). Explicit operators: where() does not
            // normalize a 2-arg string value to equality.
            $this->db->table(self::VERSION_TABLE)
                ->where('migration', '=', $filename)
                ->where('source', '=', $source)
                ->delete();

            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            error_log("Rollback failed: " . $e->getMessage());
            return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute migration
     *
     * @param MigrationInterface $migration Migration to execute
     */
    public function executeMigration(MigrationInterface $migration): void
    {
        // Run migration - operations execute immediately
        $migration->up($this->schema);
    }

    /**
     * Execute migration rollback
     *
     * @param MigrationInterface $migration Migration to rollback
     */
    public function executeRollback(MigrationInterface $migration): void
    {
        // Run migration rollback - operations execute immediately
        $migration->down($this->schema);
    }
}
