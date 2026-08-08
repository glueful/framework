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
use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\ExceptionClassifier;
use Glueful\Database\Resilience\RetryBudget;
use Glueful\Database\Resilience\UsleepSleeper;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;

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
     * @var array<string, PDO> Process-global handles indexed by connectionKey()
     *                         (full connection identity), never by engine alone.
     */
    protected static array $instances = [];

    /**
     * @var ConnectionPoolManager|null Pool manager instance
     */
    private static ?ConnectionPoolManager $poolManager = null;

    /**
     * @var int Monotonic sequence used to mint a unique soft-delete cache namespace
     *          per Connection instance (never reused, unlike spl_object_id).
     */
    private static int $instanceSequence = 0;

    /**
     * @var string|null Lazily-assigned per-connection namespace for the soft-delete
     *                  column-existence cache.
     */
    private ?string $softDeleteCacheNamespace = null;

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
     * @var \Glueful\Database\Transaction\TransactionManager|null Transaction manager (initialized lazily)
     */
    private ?\Glueful\Database\Transaction\TransactionManager $transactionManager = null;

    /**
     * @var QueryLogger Logger owned by this connection: every resilience event
     *                  (invalidation, reconnect, replay) and the memoized
     *                  TransactionManager share this one instance.
     */
    private QueryLogger $resilienceLogger;

    /**
     * @var RetryBudget|null Budget governing the operation currently in flight.
     *                       Set for the duration of the OUTERMOST transaction()
     *                       call so nested calls share one allowance instead of
     *                       minting their own.
     */
    private ?RetryBudget $activeRetryBudget = null;

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
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException On connection failure or invalid configuration
     */
    public function __construct(array $config = [], ?ApplicationContext $context = null)
    {
        $this->context = $context;
        $this->resilienceLogger = new QueryLogger(null, $this->context);
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

        // Initialize PDO connection only if pooling is disabled. Adoption of the
        // process-global handle (and the exclusions from it) lives in one place —
        // see sharesStaticHandle() — so this path and the lazy getPDO() fallback
        // can never drift apart.
        if ($poolingEnabled === false) {
            $this->pdo = $this->openOrAdoptSharedPdo();
        }

        // Note: Schema manager is initialized lazily when first accessed
    }

    /**
     * Whether this connection participates in the process-global handle cache.
     *
     * FRAMEWORK-MANAGED connections (constructed WITH an ApplicationContext — the DI
     * container's 'database' factory and other framework paths) reuse a process-global
     * PDO keyed by the FULL connection identity (DSN + user + schema): without pooling,
     * each new Connection otherwise leaks a PDO that is only released on GC — and cached
     * test-harness app contexts (and cyclic container graphs) keep those objects alive,
     * exhausting the server's connection ceiling ("too many clients") once enough
     * containers are booted. The identity key (NOT engine alone) still guards a managed
     * connection built for a different schema/host/db/user getting its OWN backend.
     *
     * AD-HOC connections (constructed WITHOUT a context — `new Connection([...])` in
     * application/test code) always open a FRESH backend. A caller hand-building a
     * Connection is asking for an independent session — e.g. a second session to hold a
     * lock/transaction open while another session contends with it. Silently collapsing
     * such pairs into one backend when their configs happen to match turns session-level
     * semantics (advisory locks, transactions) into self-interactions and deadlocks the
     * caller (a pair of "racing" sessions that are secretly one session can block forever).
     *
     * SQLite is excluded from reuse entirely: a ':memory:' database is private to each
     * connection and file databases are cheap to open, so reuse there would wrongly share
     * one in-memory schema across connections meant to be isolated. This keeps SQLite's
     * pooling-off behaviour exactly as before.
     *
     * An excluded connection bypasses the cache READ **and** the cache WRITE: it always
     * opens a fresh backend and never publishes it — at construction time, on the lazy
     * fallback, and after an invalidation.
     */
    private function sharesStaticHandle(): bool
    {
        return $this->engine !== 'sqlite' && $this->context !== null;
    }

    /**
     * Canonical key for the process-global handle cache: this connection's FULL
     * identity (engine|dsn|user|schema, never the password).
     *
     * The constructor, the lazy getPDO() fallback and invalidate() all key off this
     * one method, so they cannot disagree about which cached handle is "ours".
     */
    private function connectionKey(): string
    {
        $dbConfig = $this->engineConfig();
        $user = $dbConfig['user'] ?? '';
        $schema = $dbConfig['schema'] ?? '';

        return $this->engine . '|' . $this->buildDSN($this->engine, $dbConfig)
            . '|' . (is_scalar($user) ? (string) $user : '')
            . '|' . (is_scalar($schema) ? (string) $schema : '');
    }

    /**
     * Engine-specific slice of the database configuration.
     *
     * @return array<string, mixed>
     */
    private function engineConfig(): array
    {
        $dbConfig = $this->config[$this->engine] ?? [];

        return is_array($dbConfig) ? $dbConfig : [];
    }

    /**
     * Establish this connection's PDO handle: adopt (or publish) the shared handle
     * when this connection participates in sharing, otherwise open a private backend.
     */
    private function openOrAdoptSharedPdo(): PDO
    {
        if (!$this->sharesStaticHandle()) {
            return $this->createPDOConnection($this->engine);
        }

        return self::$instances[$this->connectionKey()] ??= $this->createPDOConnection($this->engine);
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

            'retry' => [
                'max_attempts' => (int) env('DB_RETRY_MAX_ATTEMPTS', 3),
                'backoff_base_ms' => (int) env('DB_RETRY_BACKOFF_MS', 500),
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
     * @throws \PDOException On connection failure or invalid credentials (raw, unclassified)
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException For unsupported engines
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

        // Optional connect timeout. The installer's ConnectionTester sets a short one so an
        // unreachable host fails fast instead of hanging on the OS default (~75s). MySQL honors
        // PDO::ATTR_TIMEOUT for connect; PostgreSQL uses connect_timeout in the DSN (see buildDSN).
        if (isset($dbConfig['timeout']) && (int) $dbConfig['timeout'] > 0) {
            $options[PDO::ATTR_TIMEOUT] = (int) $dbConfig['timeout'];
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
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException For unsupported engines
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
                'pgsql:host=%s;port=%d;dbname=%s%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 5432,
                $config['db'] ?? 'postgres',
                $this->pgsqlDsnExtras($config)
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

    /**
     * Optional pgsql DSN parameters appended only when configured: SSL mode and connect timeout.
     * (pdo_pgsql honors neither via PDO::ATTR_* for connect, so they belong in the DSN.)
     *
     * @param array<string, mixed> $config
     */
    private function pgsqlDsnExtras(array $config): string
    {
        $extras = '';
        if (isset($config['sslmode']) && is_string($config['sslmode']) && $config['sslmode'] !== '') {
            $extras .= ';sslmode=' . $config['sslmode'];
        }
        if (isset($config['timeout']) && (int) $config['timeout'] > 0) {
            $extras .= ';connect_timeout=' . (int) $config['timeout'];
        }
        return $extras;
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
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException If path is invalid or inaccessible
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
            (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:/', $path) === 1)
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
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException For unsupported engines
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
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException For unsupported engines
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
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If schema builder initialization fails
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
     * @throws \PDOException If a new connection cannot be established (raw, unclassified)
     * @throws \RuntimeException If a pooled connection has no active PDO handle
     */
    public function getPDO(): PDO
    {
        // Use pooled connection if available
        if ($this->pool !== null) {
            if ($this->pooledConnection === null) {
                $this->pooledConnection = $this->pool->acquire();
            }
            $pdo = $this->pooledConnection->getPDO();
            if ($pdo === null) {
                throw new \RuntimeException('Pooled connection has no active PDO handle.');
            }
            return $pdo;
        }

        // Lazy (re)establishment: the constructor skipped it (pooling was enabled at
        // build time) or invalidate() dropped a dead handle. Same identity rules as
        // the constructor — an excluded connection opens a private backend here too.
        if (!isset($this->pdo)) {
            $this->pdo = $this->openOrAdoptSharedPdo();
        }

        return $this->pdo;
    }

    /**
     * Open a non-pooled, independent PDO session using this connection's resolved configuration.
     * The caller owns its lifetime; it is never stored in the shared instance or connection pool.
     */
    public function newPdo(): PDO
    {
        return $this->createPDOConnection($this->getDriverName());
    }

    /**
     * Access current database driver
     *
     * Returns engine-specific driver instance.
     *
     * @return DatabaseDriver Active database driver
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If driver not initialized
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
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If connection or QueryBuilder initialization fails
     * @throws \RuntimeException If any required QueryBuilder component cannot be instantiated
     */
    /**
     * Chainable, process-level hooks applied to the QueryBuilder returned by table().
     *
     * Registered once at boot (e.g. by an extension's service provider). All run, in
     * registration order, so multiple extensions can decorate table() without clobbering
     * each other. Each receives (QueryBuilder $qb, string $table, Connection $conn).
     *
     * @var array<int, \Closure(QueryBuilder, string, Connection):void>
     */
    private static array $tableHooks = [];

    public static function addTableHook(\Closure $hook): void
    {
        self::$tableHooks[] = $hook;
    }

    public static function clearTableHooks(): void
    {
        self::$tableHooks = [];
    }

    /**
     * Chainable, process-level hooks applied to associative row data on write (insert/insertBatch/upsert).
     *
     * Registered once at boot (e.g. by an extension's service provider). All run, in registration
     * order, so multiple extensions can decorate writes without clobbering each other. Each receives
     * (string $table, array $data) and MUST return the (possibly modified) associative row.
     *
     * @var array<int, \Closure(string, array<string,mixed>):array<string,mixed>>
     */
    private static array $insertHooks = [];

    public static function addInsertHook(\Closure $hook): void
    {
        self::$insertHooks[] = $hook;
    }

    public static function clearInsertHooks(): void
    {
        self::$insertHooks = [];
    }

    /**
     * Run every registered insert hook over $data in registration order, returning the final row.
     *
     * A hook must return an associative array (column => value); a list-shaped return would be bound
     * positionally against the wrong columns downstream, so reject it loudly instead of corrupting the
     * write. An empty row is left untouched (nothing to misbind).
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function applyInsertHooks(string $table, array $data): array
    {
        foreach (self::$insertHooks as $hook) {
            $data = $hook($table, $data);
            if ($data !== [] && array_is_list($data)) {
                throw new \UnexpectedValueException(sprintf(
                    'An insert hook for "%s" returned a non-associative (list-shaped) row.',
                    $table
                ));
            }
        }
        return $data;
    }

    public function table(string $table): QueryBuilder
    {
        $qb = $this->createQueryBuilder()->from($table);
        foreach (self::$tableHooks as $hook) {
            $hook($qb, $table, $this);
        }
        return $qb;
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
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If connection or driver initialization fails
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

        // SoftDeleteHandler needs PDO, driver, and UpdateBuilder. The fourth arg scopes
        // its (process-static) deleted_at-column cache to THIS connection, so connections
        // to different databases that share a table name cannot poison each other's
        // soft-vs-hard-delete decision.
        $softDeleteHandler = new \Glueful\Database\Features\SoftDeleteHandler(
            $this->getPDO(),  // Use getPDO() to leverage connection pooling
            $this->driver,
            $updateBuilder,
            $this->softDeleteCacheNamespace()
        );

        // Get shared TransactionManager (lazy-initialized)
        $transactionManager = $this->getTransactionManager();

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
     * A stable, unique namespace for THIS connection's soft-delete column cache.
     *
     * SoftDeleteHandler is recreated per query and its column-existence cache is
     * process-static, so the cache must be partitioned by connection. A monotonic
     * per-instance id guarantees two connections never share a partition (so a
     * same-named table in a different database cannot poison the decision), while
     * a single connection reuses one namespace across all its queries (so the cache
     * still amortizes the schema lookup).
     */
    private function softDeleteCacheNamespace(): string
    {
        return $this->softDeleteCacheNamespace ??= 'conn:' . (++self::$instanceSequence);
    }

    /**
     * Get the shared TransactionManager instance.
     *
     * Lazily initializes the TransactionManager on first access. The same instance
     * is shared across all QueryBuilders created from this connection to ensure
     * transaction state and callbacks are properly tracked.
     *
     * @return \Glueful\Database\Transaction\TransactionManager
     */
    public function getTransactionManager(): \Glueful\Database\Transaction\TransactionManager
    {
        if ($this->transactionManager === null) {
            $savepointManager = new \Glueful\Database\Transaction\SavepointManager($this->getPDO());

            $this->transactionManager = new \Glueful\Database\Transaction\TransactionManager(
                $this->getPDO(),
                $savepointManager,
                $this->resilienceLogger,
                $this->getDriverName(),
                new UsleepSleeper()
            );
        }

        return $this->transactionManager;
    }

    /**
     * Register a callback to execute after the current transaction commits.
     *
     * If not currently in a transaction, the callback is executed immediately.
     * For nested transactions (savepoints), callbacks are promoted to the parent
     * level and only fire when the outermost transaction commits.
     *
     * Use cases:
     * - Search index updates (only index committed data)
     * - Cache invalidation (only invalidate after data is persisted)
     * - Event dispatching (only dispatch when changes are final)
     * - Sending notifications (only notify after successful commit)
     *
     * @param callable $callback The callback to execute after commit
     * @return $this For method chaining
     */
    public function afterCommit(callable $callback): self
    {
        $this->getTransactionManager()->afterCommit($callback);
        return $this;
    }

    /**
     * Register a callback to execute after the current transaction rolls back.
     *
     * If not currently in a transaction, the callback is ignored.
     * For nested transactions, callbacks are discarded if the nested
     * transaction is rolled back (not promoted to parent).
     *
     * @param callable $callback The callback to execute after rollback
     * @return $this For method chaining
     */
    public function afterRollback(callable $callback): self
    {
        $this->getTransactionManager()->afterRollback($callback);
        return $this;
    }

    /**
     * Check if currently inside a database transaction.
     *
     * Alias for getTransactionManager()->isActive().
     *
     * @return bool True if a transaction is active
     */
    public function withinTransaction(): bool
    {
        return $this->getTransactionManager()->isActive();
    }

    /**
     * Get the current transaction nesting level.
     *
     * Returns 0 if no transaction is active. For nested transactions using
     * savepoints, returns the depth of nesting (1 for outermost, 2 for first
     * nested savepoint, etc.).
     *
     * Alias for getTransactionManager()->getLevel().
     *
     * @return int Current transaction nesting level
     */
    public function transactionLevel(): int
    {
        return $this->getTransactionManager()->getLevel();
    }

    /**
     * Execute a callback within a database transaction.
     *
     * The OUTERMOST call owns one shared RetryBudget: the TransactionManager consumes
     * it for retryable failures (deadlock, lock contention) and this wrapper consumes
     * it for connection losses, reconnecting and replaying the whole transaction.
     * Nested calls delegate to the manager with that same budget, so nesting can never
     * multiply the allowance.
     *
     * Replay callbacks MUST build their query chains inside the callback from this
     * Connection (e.g. via table()) — a QueryBuilder or executor captured before the
     * call retains the pre-reconnect PDO and will fail on replay.
     *
     * @param callable $callback The callback to execute within the transaction
     * @return mixed The return value of the callback
     * @throws \Exception If the transaction fails after max retries or callback throws
     */
    public function transaction(callable $callback): mixed
    {
        // Nested call (or an outer wrapper already active): delegate with the
        // active budget — a null here would mint a second retry allowance.
        if ($this->activeRetryBudget !== null || $this->transactionManager?->isActive() === true) {
            return $this->getTransactionManager()->transaction($callback, $this->activeRetryBudget);
        }

        $retryConfig = $this->config['retry'] ?? [];
        $budget = RetryBudget::fromConfig(
            is_array($retryConfig) ? $retryConfig : [],
            new UsleepSleeper()
        );
        $this->activeRetryBudget = $budget;

        try {
            while (true) {
                $manager = null;
                try {
                    // Manager construction is inside the catch boundary: on a
                    // pooled/lazy connection it may itself detect connection loss.
                    $manager = $this->getTransactionManager();
                    return $manager->transaction($callback, $budget);
                } catch (\Throwable $failure) {
                    // ONE arm, deliberately. Invalidation FIRST, dispatch second:
                    // every classified database failure here is a PDOException
                    // subclass, so type-ordered arms cannot separate them safely.
                    if ($manager?->connectionPresumedDead() === true) {
                        $this->invalidate();
                    }

                    $loss = null;
                    if ($failure instanceof ConnectionLostException) {
                        $loss = $failure;
                    } elseif ($failure instanceof \PDOException && !$failure instanceof DatabaseException) {
                        // Defensive boundary for a RAW failure during lazy
                        // manager/PDO construction — the only unclassified
                        // shape that reaches here. Manager callback failures
                        // classify internally.
                        $classified = (new ExceptionClassifier())->classify($failure, $this->getDriverName());
                        if (!$classified instanceof ConnectionLostException) {
                            throw $classified;
                        }
                        $loss = $classified;
                    }

                    if ($loss === null) {
                        // Already-classified non-loss failures — including
                        // CommitOutcomeUnknownException and rule-2 primaries —
                        // propagate unchanged, unmasked, never replayed. The
                        // dead handle was already dropped above.
                        throw $failure;
                    }

                    $this->reconnectWithinBudget($budget, $loss, 'transaction');
                    $this->resilienceLogger->logEvent(
                        'connection.transaction.replay',
                        ['attempt' => $budget->attemptsUsed()],
                        'warning'
                    );
                    // Fall out of the catch: the while(true) loop replays.
                }
            }
        } finally {
            $this->activeRetryBudget = null;
        }
    }

    /**
     * Re-run a caller-declared IDEMPOTENT read, reconnecting after a connection loss.
     *
     * Only the caller knows a read is safe to repeat, so this is opt-in: nothing
     * replays a statement implicitly. Build query chains inside the callback from
     * the supplied Connection — prebuilt builders retain the stale PDO on replay.
     *
     * @param callable $fn Receives this Connection; must be free of side effects
     * @return mixed The callback's return value
     * @throws \LogicException If called inside an active transaction
     */
    public function idempotentRead(callable $fn): mixed
    {
        if ($this->hasActiveTransaction()) {
            throw new \LogicException(
                'idempotentRead() cannot run inside a transaction: a reconnect would abandon it'
            );
        }

        $retryConfig = $this->config['retry'] ?? [];
        $budget = RetryBudget::fromConfig(
            is_array($retryConfig) ? $retryConfig : [],
            new UsleepSleeper()
        );

        while (true) {
            try {
                return $fn($this);
            } catch (ConnectionLostException $loss) {
                $this->reconnectWithinBudget($budget, $loss, 'idempotent_read');
            }
        }
    }

    /**
     * Reconnect under the shared budget, or rethrow the newest classified loss.
     *
     * Every recovery cycle consumes exactly one attempt, and a failed establishment
     * loops back here instead of falling through to an unguarded manager/callback
     * invocation on a handle that was never restored.
     */
    private function reconnectWithinBudget(
        RetryBudget $budget,
        ConnectionLostException $lastLoss,
        string $surface
    ): void {
        while (true) {
            if (!$budget->tryConsume()) {
                $this->resilienceLogger->logEvent(
                    'connection.retry.exhausted',
                    ['surface' => $surface, 'attempts' => $budget->attemptsUsed()],
                    'error'
                );
                throw $lastLoss; // exact final classified failure, unchanged
            }

            $this->resilienceLogger->logEvent(
                'connection.reconnect.attempt',
                [
                    'surface' => $surface,
                    'attempt' => $budget->attemptsUsed(),
                    'delay_ms' => $budget->lastDelayMilliseconds(),
                ],
                'warning'
            );

            try {
                $this->reconnect();
                $this->resilienceLogger->logEvent(
                    'connection.reconnect.success',
                    ['surface' => $surface, 'attempt' => $budget->attemptsUsed()],
                    'info'
                );

                return;
            } catch (ConnectionLostException $reconnectLoss) {
                $lastLoss = $reconnectLoss;
                $this->resilienceLogger->logEvent(
                    'connection.reconnect.failure',
                    ['surface' => $surface, 'attempt' => $budget->attemptsUsed()],
                    'warning'
                );
                // Loop back: the NEXT reconnect is gated by another tryConsume().
            } catch (\LogicException $guardRefusal) {
                // The guard refused because a transaction is still open on the raw
                // handle — e.g. the manager's rollback failed with a NON-loss error,
                // which resets its bookkeeping but leaves the server-side transaction
                // untouched. Recovery is impossible, but the classified loss is the
                // truthful failure: never let the guard's LogicException mask it.
                $this->resilienceLogger->logEvent(
                    'connection.reconnect.refused',
                    [
                        'surface' => $surface,
                        'attempt' => $budget->attemptsUsed(),
                        'reason' => $guardRefusal->getMessage(),
                    ],
                    'error'
                );

                throw $lastLoss;
            }
        }
    }

    /**
     * Drop the current handle and establish a new one.
     *
     * Refused while a transaction is open: reconnecting would silently abandon it.
     *
     * @throws \LogicException If a transaction is active on the current handle
     * @throws ConnectionLostException If the new connection cannot be established
     */
    public function reconnect(): void
    {
        // The guard is deliberately non-lazy (see hasActiveTransaction()): asking
        // transactionLevel()/getTransactionManager()/getPDO() would create or acquire
        // a handle merely to discard it.
        if ($this->hasActiveTransaction()) {
            throw new \LogicException(
                'reconnect() refused: a transaction is active on this connection and '
                . 'reconnecting would silently abandon it.'
            );
        }

        $this->resilienceLogger->logEvent(
            'connection.reconnect.establishing',
            ['engine' => $this->engine],
            'warning'
        );

        $this->invalidate();

        try {
            $this->getPDO();
        } catch (\PDOException $e) {
            $classified = $e instanceof DatabaseException
                ? $e
                : (new ExceptionClassifier())->classify($e, $this->getDriverName());

            // A failure to ESTABLISH a connection IS a connection loss, whatever the
            // driver calls it: MySQL refuses a connect with 2002/2003/2005 under
            // SQLSTATE HY000, which the STATEMENT-level classifier deliberately maps
            // to a generic failure (there is no family rule for HY000). Left
            // unwrapped, the bounded recovery loop — which only catches losses —
            // would abort after one consumed attempt and surface the connect error
            // in place of the original loss. Wrapping here keeps the classifier's
            // statement semantics untouched; the classified failure is chained as
            // previous and its SQLSTATE/vendor code/errorInfo are preserved.
            $loss = $classified instanceof ConnectionLostException
                ? $classified
                : ConnectionLostException::fromPdo($classified, $this->getDriverName());

            $this->resilienceLogger->logEvent(
                'connection.reconnect.establish_failed',
                [
                    'engine' => $this->engine,
                    'error' => $loss->getMessage(),
                    'sqlstate' => $loss->sqlState(),
                ],
                'error'
            );

            throw $loss;
        }

        $this->resilienceLogger->logEvent(
            'connection.reconnect.established',
            ['engine' => $this->engine],
            'info'
        );
    }

    /**
     * Discard the handle this connection is holding WITHOUT touching it.
     *
     * The handle is presumed dead: no rollback, no session reset, no ping. A pooled
     * handle is discarded from the pool (never released back into it); a non-pooled
     * one is unpublished from the shared cache — but only when that cache entry is
     * still THIS handle, so a replacement installed by another Connection survives.
     */
    private function invalidate(): void
    {
        $deadPdo = $this->currentPdo();

        if ($this->pool !== null) {
            if ($this->pooledConnection !== null) {
                $this->pool->discard($this->pooledConnection);
            }
            // Clearing the reference keeps __destruct() and getPDO() off the dead
            // handle; the next getPDO() acquires a fresh one.
            $this->pooledConnection = null;
        } else {
            if ($deadPdo !== null) {
                $key = $this->connectionKey();
                if ((self::$instances[$key] ?? null) === $deadPdo) {
                    unset(self::$instances[$key]);
                }
            }
            unset($this->pdo);
        }

        $this->transactionManager = null;

        $this->resilienceLogger->logEvent(
            'connection.invalidated',
            ['engine' => $this->engine, 'pooled' => $this->pool !== null],
            'warning'
        );
    }

    /**
     * The PDO handle this connection is CURRENTLY holding, or null.
     *
     * Never acquires from the pool and never opens a connection: it reports state,
     * it does not create it.
     */
    private function currentPdo(): ?PDO
    {
        if ($this->pool !== null) {
            // PooledConnection::getPDO() is a pure accessor — no acquisition.
            return $this->pooledConnection?->getPDO();
        }

        return isset($this->pdo) ? $this->pdo : null;
    }

    /**
     * Whether a transaction is open on the handle this connection is holding.
     *
     * Consults the EXISTING transaction manager and the CURRENT raw handle only —
     * including the pooled one, whose transactions are invisible to the manager when
     * a borrower used the raw PDO directly. Nothing here may construct a manager or
     * acquire a connection.
     */
    public function hasActiveTransaction(): bool
    {
        if ($this->transactionManager?->isActive() === true) {
            return true;
        }

        $pdo = $this->currentPdo();
        if ($pdo === null) {
            return false;
        }

        try {
            return $pdo->inTransaction();
        } catch (\PDOException $e) {
            $classified = $e instanceof DatabaseException
                ? $e
                : (new ExceptionClassifier())->classify($e, $this->getDriverName());
            if ($classified instanceof ConnectionLostException) {
                // The handle is already unusable, so it cannot be holding a
                // transaction a reconnect could abandon: invalidation may proceed.
                return false;
            }

            throw $classified;
        }
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
