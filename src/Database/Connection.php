<?php

namespace Glueful\Database;

use PDO;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Generators\MySQLSqlGenerator;
use Glueful\Database\Schema\Generators\PostgreSQLSqlGenerator;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\ConnectionPoolManager;
use Glueful\Database\PooledConnection;
use Glueful\Exceptions\BusinessLogicException;

/**
 * Database Connection Manager
 *
 * Provides centralized database connection management with features:
 * - Connection pooling with lazy instantiation
 * - Multi-engine support (MySQL, PostgreSQL, SQLite)
 * - Automatic driver resolution
 * - Schema management integration
 * - Configuration-based initialization
 *
 * Design patterns:
 * - Singleton pool for connection reuse
 * - Factory method for driver creation
 * - Strategy pattern for database operations
 *
 * Requirements:
 * - PHP PDO extension
 * - Database-specific PDO drivers
 * - Valid configuration settings
 * - Appropriate database permissions
 */
class Connection implements DatabaseInterface
{
    /**
     * @phpstan-type MySqlConfig array{
     *   host?: string, db?: string, port?: int, charset?: string,
     *   user?: string|null, pass?: string|null, strict?: bool
     * }
     * @phpstan-type PgSqlConfig array{
     *   host?: string, db?: string, port?: int, sslmode?: string,
     *   schema?: string, user?: string|null, pass?: string|null
     * }
     * @phpstan-type SqliteConfig array{primary: string}
     * @phpstan-type PoolingConfig array{enabled?: bool}
     * @phpstan-type DatabaseConfig array{
     *   engine?: string,
     *   pooling?: PoolingConfig,
     *   mysql?: MySqlConfig,
     *   pgsql?: PgSqlConfig,
     *   sqlite?: SqliteConfig
     * }
     */
    /**
     * @var array<string, PDO> Connection pool indexed by engine type
     */
    protected static array $instances = [];

    /**
     * @var ConnectionPoolManager|null Pool manager instance
     */
    private static ?ConnectionPoolManager $poolManager = null;

    /**
     * @var PDO Active database connection
     */
    protected PDO $pdo;

    /**
     * @var DatabaseDriver Database-specific driver instance
     */
    protected DatabaseDriver $driver;

    /**
     * @var SchemaBuilderInterface|null Schema builder instance (initialized lazily)
     */
    protected ?SchemaBuilderInterface $schemaBuilder = null;

    /**
     * @var string Current database engine
     */
    protected string $engine;

    /**
     * @var array<string, mixed> Database configuration
     */
    protected array $config;
    private ?ApplicationContext $context;

    /**
     * @var ConnectionPool|null Active connection pool
     */
    private ?ConnectionPool $pool = null;

    /**
     * @var PooledConnection|null Current pooled connection
     */
    private ?PooledConnection $pooledConnection = null;


    /**
     * Initialize database connection with optional pooling
     *
     * Creates or reuses database connections based on engine type.
     * Supports both legacy connection reuse and modern connection pooling.
     * Automatically resolves appropriate driver and schema manager.
     *
     * Connection lifecycle:
     * 1. Check if pooling is enabled
     * 2. Use connection pool if available
     * 3. Fall back to legacy connection reuse
     * 4. Initialize driver and schema manager
     *
     * @param  array<string, mixed> $config Optional configuration override
     * @throws \Glueful\Exceptions\DatabaseException On connection failure or invalid configuration
     */
    public function __construct(array $config = [], ?ApplicationContext $context = null)
    {
        $this->context = $context;
        $this->config = array_merge($this->loadConfig(), $config);
        // Fallback to env() when config is not available (e.g., during CLI bootstrap)
        $this->engine = $this->config['engine']
            ?? $this->getConfig('database.engine')
            ?? env('DB_DRIVER', 'sqlite');

        // Initialize pool manager if pooling is enabled
        $poolingEnabled = (bool) ($this->config['pooling']['enabled'] ?? false);
        if ($poolingEnabled === true) {
            self::$poolManager ??= new ConnectionPoolManager($this->context);
            $this->pool = self::$poolManager->getPool($this->engine);
        }

        $this->driver = $this->resolveDriver($this->engine);

        // Initialize PDO connection only if pooling is disabled
        if ($poolingEnabled === false) {
            $this->pdo = $this->createPDOConnection($this->engine);
        }

        // Note: Schema manager is initialized lazily when first accessed
    }

    /**
     * Create a Connection instance from an ApplicationContext
     *
     * @param ApplicationContext|null $context The application context
     * @param array<string, mixed> $config Optional configuration override
     * @return self
     */
    public static function fromContext(?ApplicationContext $context, array $config = []): self
    {
        return new self($config, $context);
    }

    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    public function getContext(): ?ApplicationContext
    {
        return $this->context;
    }

    /**
     * Load database configuration
     *
     * Falls back to env() values when context/config is not available.
     *
     * @return array<string, mixed> Complete database configuration
     */
    private function loadConfig(): array
    {
        $config = $this->getConfig('database', []);

        // If config is empty (no context), build from env() values
        if ($config === [] || $config === null) {
            $config = $this->buildConfigFromEnv();
        }

        return $config;
    }

    /**
     * Build database configuration from environment variables
     *
     * Used as fallback when ApplicationContext is not available.
     *
     * @return array<string, mixed>
     */
    private function buildConfigFromEnv(): array
    {
        return [
            'engine' => env('DB_DRIVER', 'sqlite'),

            'mysql' => [
                'host' => env('DB_HOST', env('DB_MYSQL_HOST', '127.0.0.1')),
                'port' => (int) env('DB_PORT', env('DB_MYSQL_PORT', 3306)),
                'db' => env('DB_DATABASE', env('DB_MYSQL_DATABASE', '')),
                'user' => env('DB_USERNAME', env('DB_MYSQL_USERNAME', 'root')),
                'pass' => env('DB_PASSWORD', env('DB_MYSQL_PASSWORD', '')),
                'charset' => 'utf8mb4',
                'strict' => true,
            ],

            'pgsql' => [
                'host' => env('DB_PGSQL_HOST', env('DB_HOST', '127.0.0.1')),
                'port' => (int) env('DB_PGSQL_PORT', env('DB_PORT', 5432)),
                'db' => env('DB_PGSQL_DATABASE', env('DB_DATABASE', '')),
                'user' => env('DB_PGSQL_USERNAME', env('DB_USERNAME', 'postgres')),
                'pass' => env('DB_PGSQL_PASSWORD', env('DB_PASSWORD', '')),
                'schema' => env('DB_PGSQL_SCHEMA', 'public'),
            ],

            'sqlite' => [
                'primary' => env('DB_SQLITE_DATABASE', 'storage/database/glueful.sqlite'),
            ],

            'pooling' => [
                'enabled' => (bool) env('DB_POOLING_ENABLED', false),
            ],
        ];
    }

    /**
     * Create PDO connection with engine-specific options
     *
     * Establishes database connection with:
     * - Engine-specific PDO options
     * - Error handling configuration
     * - Character set settings
     * - Strict mode (MySQL)
     * - SSL configuration (PostgreSQL)
     *
     * @param  string $engine Target database engine
     * @return PDO Configured PDO instance
     * @throws \Glueful\Exceptions\DatabaseException On connection failure or invalid credentials
     */
    private function createPDOConnection(string $engine): PDO
    {
        // Get engine-specific configuration from already-loaded config
        $dbConfig = $this->config[$engine] ?? [];

        // Set common PDO options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add engine-specific options
        if ($engine === 'mysql' && ($dbConfig['strict'] ?? true) === true) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode='STRICT_ALL_TABLES'";
        }

        $pdo = new PDO(
            $this->buildDSN($engine, $dbConfig),
            $dbConfig['user'] ?? null,
            $dbConfig['pass'] ?? null,
            $options
        );

        // Set PostgreSQL search_path after connection
        if ($engine === 'pgsql' && isset($dbConfig['schema'])) {
            $schema = $dbConfig['schema'] ?? 'public';
            $pdo->exec("SET search_path TO " . $pdo->quote($schema));
        }

        return $pdo;
    }

    /**
     * Build database-specific connection DSN
     *
     * Generates connection string with support for:
     * MySQL:
     * - Host, port, database name
     * - Character set configuration
     * - SSL settings
     *
     * PostgreSQL:
     * - Host, port, database name
     * - Schema search path
     * - SSL mode configuration
     *
     * SQLite:
     * - File path handling
     * - Directory creation
     * - Journal mode settings
     *
     * @param  string $engine Database engine type
     * @param  array  $config Engine-specific configuration
     * @return string Formatted DSN string
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
     */
    /**
     * @param array<string, mixed> $config
     */
    private function buildDSN(string $engine, array $config): string
    {
        return match ($engine) {
            'mysql' => sprintf(
                'mysql:host=%s;dbname=%s;port=%d;charset=%s',
                $config['host'] ?? '127.0.0.1',
                $config['db'] ?? '',
                $config['port'] ?? 3306,
                $config['charset'] ?? 'utf8mb4'
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 5432,
                $config['db'] ?? 'postgres'
            ),
            'sqlite' => $this->prepareSQLiteDSN(
                (isset($config['primary']) && is_string($config['primary']) && $config['primary'] !== '')
                    ? $config['primary']
                    : $this->resolveSQLitePath()
            ),
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    /**
     * Prepare SQLite database storage
     *
     * Ensures database file location is:
     * - Accessible
     * - Has proper permissions
     * - Parent directory exists
     *
     * @param  string $dbPath Target database file path
     * @return string SQLite connection string
     * @throws \Glueful\Exceptions\BusinessLogicException If path is invalid or inaccessible
     */
    private function prepareSQLiteDSN(string $dbPath): string
    {
        @mkdir(dirname($dbPath), 0755, true); // Ensure directory exists
        return "sqlite:{$dbPath}";
    }

    /**
     * Resolve a fallback SQLite database path when config is not available.
     */
    private function resolveSQLitePath(): string
    {
        $path = function_exists('env')
            ? env('DB_SQLITE_DATABASE', 'storage/database/glueful.sqlite')
            : ($_ENV['DB_SQLITE_DATABASE'] ?? 'storage/database/glueful.sqlite');

        if (!is_string($path) || $path === '') {
            $path = 'storage/database/glueful.sqlite';
        }

        // If absolute, use as-is
        if (
            str_starts_with($path, '/') || str_starts_with($path, DIRECTORY_SEPARATOR) ||
            (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:/', $path))
        ) {
            return $path;
        }

        $basePath = $this->context?->getBasePath() ?? (getcwd() ?: dirname(__DIR__, 2));
        return rtrim($basePath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Factory method for database driver resolution
     *
     * Creates appropriate driver instance based on engine type.
     * Supports extensibility for additional engines.
     *
     * @param  string $engine Target database engine
     * @return DatabaseDriver Initialized driver instance
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
     */
    private function resolveDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mysql' => new MySQLDriver(),
            'pgsql' => new PostgreSQLDriver(),
            'sqlite' => new SQLiteDriver(),
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    /**
     * Factory method for SQL generator resolution
     *
     * Creates database-specific SQL generator instance.
     * Used by the fluent schema builder.
     *
     * @param  string $engine Target database engine
     * @return SqlGeneratorInterface Initialized SQL generator
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
     */
    private function resolveSqlGenerator(string $engine): SqlGeneratorInterface
    {
        return match ($engine) {
            'mysql' => new MySQLSqlGenerator(),
            'pgsql' => new PostgreSQLSqlGenerator(),
            'sqlite' => new SQLiteSqlGenerator(),
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    /**
     * Access fluent schema builder instance
     *
     * Initializes schema builder lazily on first access to ensure
     * PDO connection is available. Returns the new fluent schema builder.
     *
     * @return SchemaBuilderInterface Fluent schema builder
     * @throws \Glueful\Exceptions\DatabaseException If schema builder initialization fails
     */
    public function getSchemaBuilder(): SchemaBuilderInterface
    {
        if (!isset($this->schemaBuilder)) {
            $sqlGenerator = $this->resolveSqlGenerator($this->engine);
            $this->schemaBuilder = new SchemaBuilder($this, $sqlGenerator);
        }
        return $this->schemaBuilder;
    }

    /**
     * Access active PDO connection
     *
     * Returns the underlying PDO instance from pooled connection if available,
     * otherwise falls back to legacy connection.
     *
     * @return PDO Active database connection
     * @throws \Glueful\Exceptions\DatabaseException If connection lost
     */
    public function getPDO(): PDO
    {
        // Use pooled connection if available
        if ($this->pool !== null) {
            if ($this->pooledConnection === null) {
                $this->pooledConnection = $this->pool->acquire();
            }
            return $this->pooledConnection->getPDO();
        }

        // Fallback to legacy connection reuse
        if (!isset($this->pdo)) {
            // Use existing connection if available (Legacy Pooling)
            if (isset(self::$instances[$this->engine])) {
                $this->pdo = self::$instances[$this->engine];
            } else {
                $this->pdo = $this->createPDOConnection($this->engine);
                self::$instances[$this->engine] = $this->pdo; // Store connection
            }
        }

        return $this->pdo;
    }

    /**
     * Access current database driver
     *
     * Returns engine-specific driver instance.
     *
     * @return DatabaseDriver Active database driver
     * @throws \Glueful\Exceptions\DatabaseException If driver not initialized
     */
    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    /**
     * Get the name of the current database driver
     *
     * Returns the database engine name (mysql, pgsql, sqlite)
     *
     * @return string Database driver name
     */
    public function getDriverName(): string
    {
        return $this->driver->getDriverName();
    }

    /**
     * Get connection pool manager
     *
     * @return ConnectionPoolManager|null Pool manager instance
     */
    public static function getPoolManager(): ?ConnectionPoolManager
    {
        return self::$poolManager;
    }

    /**
     * Create a new QueryBuilder instance for the specified table
     *
     * Creates and configures a new QueryBuilder instance with all required dependencies
     * and sets the primary table for the query. This is the main entry point for
     * fluent database operations.
     *
     * @param  string $table The table name to query
     * @return QueryBuilder Configured QueryBuilder instance ready for query building
     * @throws \InvalidArgumentException If table name is empty, contains invalid characters, or SQL injection patterns
     * @throws \Glueful\Exceptions\DatabaseException If connection or QueryBuilder initialization fails
     * @throws \RuntimeException If any required QueryBuilder component cannot be instantiated
     */
    public function table(string $table): QueryBuilder
    {
        return $this->createQueryBuilder()->from($table);
    }

    /**
     * Create a new QueryBuilder instance
     *
     * @return QueryBuilder Configured QueryBuilder instance
     */
    public function query(): QueryBuilder
    {
        return $this->createQueryBuilder();
    }

    /**
     * Create a properly configured QueryBuilder with all dependencies
     *
     * Instantiates and wires together all QueryBuilder components using the current
     * database connection and driver. Each QueryBuilder instance gets its own set
     * of component dependencies to ensure thread safety and isolation.
     *
     * Component initialization:
     * - Uses connection pooling via getPDO() for optimal performance
     * - Configures database-specific driver for SQL generation
     * - Sets up transaction management with savepoint support
     * - Enables query logging and parameter binding
     * - Configures soft delete functionality
     *
     * @return QueryBuilder Fully configured QueryBuilder instance ready for use
     * @throws \Glueful\Exceptions\DatabaseException If connection or driver initialization fails
     * @throws \RuntimeException If any required component cannot be instantiated
     */
    private function createQueryBuilder(): QueryBuilder
    {
        // Create shared dependencies
        $parameterBinder = new \Glueful\Database\Execution\ParameterBinder();
        $queryLogger = new \Glueful\Database\QueryLogger();

        // Create all the component dependencies with proper constructors
        $state = new \Glueful\Database\Query\QueryState();
        $whereClause = new \Glueful\Database\Query\WhereClause($this->driver);
        $selectBuilder = new \Glueful\Database\Query\SelectBuilder($this->driver, $state);
        $joinClause = new \Glueful\Database\Query\JoinClause($this->driver);
        $queryModifiers = new \Glueful\Database\Query\QueryModifiers($this->driver);

        // QueryExecutor needs PDO, ParameterBinder, and QueryLogger
        $queryExecutor = new \Glueful\Database\Execution\QueryExecutor(
            $this->getPDO(),  // Use getPDO() to leverage connection pooling
            $parameterBinder,
            $queryLogger
        );

        $resultProcessor = new \Glueful\Database\Execution\ResultProcessor();
        $queryValidator = new \Glueful\Database\Features\QueryValidator();
        $queryPurpose = new \Glueful\Database\Features\QueryPurpose();

        // Create builders with proper constructors - need to check actual constructors
        $insertBuilder = new \Glueful\Database\Query\InsertBuilder($this->driver, $queryExecutor);
        $updateBuilder = new \Glueful\Database\Query\UpdateBuilder($this->driver, $queryExecutor);
        $deleteBuilder = new \Glueful\Database\Query\DeleteBuilder($this->driver, $queryExecutor);

        // SoftDeleteHandler needs PDO, driver, and UpdateBuilder
        $softDeleteHandler = new \Glueful\Database\Features\SoftDeleteHandler(
            $this->getPDO(),  // Use getPDO() to leverage connection pooling
            $this->driver,
            $updateBuilder
        );

        // SavepointManager for TransactionManager
        $savepointManager = new \Glueful\Database\Transaction\SavepointManager($this->getPDO());

        // TransactionManager needs PDO, SavepointManager, and QueryLogger
        $transactionManager = new \Glueful\Database\Transaction\TransactionManager(
            $this->getPDO(),  // Use getPDO() to leverage connection pooling
            $savepointManager,
            $queryLogger
        );

        // PaginationBuilder needs executor and logger
        $paginationBuilder = new \Glueful\Database\Features\PaginationBuilder(
            $queryExecutor,
            $queryLogger
        );

        // Create and return the QueryBuilder with all dependencies
        return new QueryBuilder(
            $state,
            $whereClause,
            $selectBuilder,
            $insertBuilder,
            $updateBuilder,
            $deleteBuilder,
            $joinClause,
            $queryModifiers,
            $transactionManager,
            $queryExecutor,
            $resultProcessor,
            $paginationBuilder,
            $softDeleteHandler,
            $queryValidator,
            $queryPurpose
        );
    }

    /**
     * Destructor - Release pooled connection
     */
    public function __destruct()
    {
        // Release pooled connection
        if ($this->pooledConnection !== null && $this->pool !== null) {
            $this->pool->release($this->pooledConnection);
            $this->pooledConnection = null;
        }
    }
}
