<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\ConnectionPool;
use Glueful\Database\Exceptions\CommitOutcomeUnknownException;
use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\PooledConnection;
use Glueful\Database\QueryLogger;
use Glueful\Database\Resilience\UsleepSleeper;
use Glueful\Database\Transaction\SavepointManager;
use Glueful\Database\Transaction\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Connection-level resilience: canonical shared-handle key, invalidate/reconnect,
 * the outermost transaction replay wrapper, idempotentRead, and pool discard.
 *
 * Fault injection is done with callbacks that throw synthetic CLASSIFIED failures
 * (a raw PDOException with SQLSTATE 08006 cannot be injected into a real SQLite
 * statement), plus faulting PDO subclasses for the commit path where the real
 * manager machinery has to run.
 */
final class ConnectionResilienceTest extends TestCase
{
    /** @var list<string> */
    private array $dbPaths = [];

    /** @var array<string, PDO> */
    private array $instancesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->instancesBackup = self::staticInstances();
    }

    protected function tearDown(): void
    {
        self::setStaticInstances($this->instancesBackup);

        foreach ($this->dbPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->dbPaths = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------- replay

    #[Test]
    public function outermostTransactionReplaysAfterConnectionLoss(): void
    {
        $connection = $this->sqliteConnection();
        $this->createItemsTable($connection);
        $deadPdo = $connection->getPDO();
        $invocations = 0;

        $result = $connection->transaction(function () use ($connection, &$invocations): string {
            $invocations++;
            if ($invocations === 1) {
                throw $this->loss('lost mid-transaction');
            }

            $connection->table('items')->insert(['name' => 'replayed']);

            return 'committed';
        });

        self::assertSame('committed', $result, 'the replayed attempt returns the callback value');
        self::assertSame(2, $invocations, 'exactly one replay after the classified loss');
        self::assertNotSame($deadPdo, $connection->getPDO(), 'the dead handle must have been replaced');

        $rows = $connection->table('items')->get();
        self::assertCount(1, $rows, 'a fresh table() chain reads the committed row');
        self::assertSame('replayed', $rows[0]['name']);
    }

    #[Test]
    public function replayExhaustionRethrowsTheLastLossUnchanged(): void
    {
        $connection = $this->sqliteConnection(maxAttempts: 2);
        $losses = [$this->loss('first loss'), $this->loss('second loss')];
        $invocations = 0;

        try {
            $connection->transaction(function () use (&$invocations, $losses): never {
                throw $losses[$invocations++];
            });
            self::fail('Expected the exhausted budget to rethrow the loss');
        } catch (ConnectionLostException $caught) {
            self::assertSame($losses[1], $caught, 'the NEWEST classified loss is rethrown by identity, unwrapped');
        }

        self::assertSame(2, $invocations, 'max_attempts counts total executions, replays included');
    }

    #[Test]
    public function nestedTransactionCallsShareOneBudget(): void
    {
        $connection = $this->sqliteConnection(maxAttempts: 3);
        $this->createItemsTable($connection);
        $deadlock = $this->deadlock();
        $innerExecutions = 0;
        $outerExecutions = 0;

        try {
            $connection->transaction(function () use ($connection, $deadlock, &$innerExecutions, &$outerExecutions) {
                $outerExecutions++;

                return $connection->transaction(function () use ($deadlock, &$innerExecutions): never {
                    $innerExecutions++;
                    throw $deadlock;
                });
            });
            self::fail('Expected the deadlock to surface');
        } catch (DeadlockException $caught) {
            self::assertSame($deadlock, $caught, 'the deadlock surfaces unchanged');
        }

        self::assertSame(
            3,
            $innerExecutions,
            'nested calls receive the ACTIVE budget: 3 total inner executions, not max_attempts squared'
        );
        self::assertSame(1, $outerExecutions, 'the exhausted shared budget leaves nothing for an outer retry');
    }

    #[Test]
    public function sharedBudgetSpansExceptionTypes(): void
    {
        // A: deadlock (manager consumes) -> loss (Connection consumes) -> success.
        $connection = $this->sqliteConnection(maxAttempts: 3);
        $deadlock = $this->deadlock();
        $invocations = 0;

        $result = $connection->transaction(function () use ($deadlock, &$invocations): string {
            $invocations++;
            if ($invocations === 1) {
                throw $deadlock;
            }
            if ($invocations === 2) {
                throw $this->loss('loss after the deadlock retry');
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(3, $invocations, 'one budget spans both failure types');

        // B: the same sequence with max_attempts => 2 exhausts ON the loss.
        $connection = $this->sqliteConnection(maxAttempts: 2);
        $loss = $this->loss('loss with an empty budget');
        $invocations = 0;

        try {
            $connection->transaction(function () use ($deadlock, $loss, &$invocations): never {
                $invocations++;
                throw $invocations === 1 ? $deadlock : $loss;
            });
            self::fail('Expected the loss to exhaust the shared budget');
        } catch (ConnectionLostException $caught) {
            self::assertSame($loss, $caught, 'changing failure type must not reset the allowance');
        }

        self::assertSame(2, $invocations, 'the deadlock retry consumed the only spare attempt');

        // C: with max_attempts => 3 the budget is empty after the third execution.
        $connection = $this->sqliteConnection(maxAttempts: 3);
        $finalLoss = $this->loss('third-execution loss');
        $invocations = 0;

        try {
            $connection->transaction(function () use ($deadlock, $finalLoss, &$invocations): never {
                $invocations++;
                throw match ($invocations) {
                    1 => $deadlock,
                    2 => $this->loss('second-execution loss'),
                    default => $finalLoss,
                };
            });
            self::fail('Expected the third failure to exhaust the budget');
        } catch (ConnectionLostException $caught) {
            self::assertSame($finalLoss, $caught);
        }

        self::assertSame(3, $invocations, 'zero budget left after the third execution');
    }

    #[Test]
    public function commitOutcomeUnknownPropagatesWithoutReplayAndInvalidates(): void
    {
        $path = $this->newDbPath();
        $connection = $this->sqliteConnection(path: $path);
        $this->createItemsTable($connection);

        // The REAL manager commit path has to run: only it sets connectionPresumedDead.
        $faulting = new class ('sqlite:' . $path) extends PDO {
            public bool $failCommit = false;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function commit(): bool
            {
                if ($this->failCommit) {
                    $e = new \PDOException('server closed the connection unexpectedly');
                    $e->errorInfo = ['08006', 7, 'connection lost during commit'];
                    throw $e;
                }

                return parent::commit();
            }
        };

        self::setOn(Connection::class, $connection, 'pdo', $faulting);
        $manager = new TransactionManager(
            $faulting,
            new SavepointManager($faulting),
            new QueryLogger(),
            'sqlite',
            new UsleepSleeper()
        );
        self::setOn(Connection::class, $connection, 'transactionManager', $manager);

        $invocations = 0;

        try {
            $connection->transaction(function () use ($faulting, &$invocations): string {
                $invocations++;
                $faulting->failCommit = true;

                return 'value';
            });
            self::fail('Expected CommitOutcomeUnknownException');
        } catch (CommitOutcomeUnknownException $caught) {
            self::assertInstanceOf(
                ConnectionLostException::class,
                $caught->getPrevious(),
                'the classified loss is chained, unmasked'
            );
            self::assertSame('08006', $caught->sqlState(), 'the classified metadata survives the wrapper');
        }

        self::assertSame(1, $invocations, 'an ambiguous commit is NEVER replayed');
        self::assertTrue($manager->connectionPresumedDead(), 'precondition: the manager flagged the dead handle');
        self::assertFalse(
            (new \ReflectionProperty(Connection::class, 'pdo'))->isInitialized($connection),
            'the presumed-dead handle must be dropped before the rethrow'
        );
        self::assertNull(
            self::getOn(Connection::class, $connection, 'transactionManager'),
            'the stale manager must be dropped with its handle'
        );

        $rebound = $connection->getPDO();
        self::assertNotSame($faulting, $rebound, 'the next operation lazily binds a different PDO');
        self::assertSame([], $connection->table('items')->get(), 'the rebound handle is usable');
    }

    #[Test]
    public function failedReconnectConsumesEveryAttempt(): void
    {
        $path = $this->newDbPath();
        $connection = new class ([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $path],
            'pooling' => ['enabled' => false],
            'retry' => ['max_attempts' => 3, 'backoff_base_ms' => 0],
        ]) extends Connection {
            public int $reconnectCalls = 0;

            /** @var list<ConnectionLostException> */
            public array $reconnectFailures = [];

            public function reconnect(): void
            {
                $this->reconnectCalls++;
                throw $this->reconnectFailures[$this->reconnectCalls - 1];
            }
        };
        $connection->reconnectFailures = [
            $this->loss('first reconnect failed'),
            $this->loss('second reconnect failed'),
        ];
        $invocations = 0;

        try {
            $connection->transaction(function () use (&$invocations): never {
                $invocations++;
                throw $this->loss('initial loss');
            });
            self::fail('Expected the final reconnect failure to be rethrown');
        } catch (ConnectionLostException $caught) {
            self::assertSame(
                $connection->reconnectFailures[1],
                $caught,
                'the NEWEST reconnect loss is rethrown by identity'
            );
        }

        self::assertSame(2, $connection->reconnectCalls, 'each failed reconnect consumes exactly one attempt');
        self::assertSame(
            1,
            $invocations,
            'a failed reconnect must never fall through to the manager or the callback'
        );
    }

    // -------------------------------------------------------- idempotentRead

    #[Test]
    public function idempotentReadReplaysAndReturns(): void
    {
        $connection = $this->sqliteConnection();
        $this->createItemsTable($connection);
        $connection->table('items')->insert(['name' => 'persisted']);
        $before = $connection->getPDO();
        $calls = 0;

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $connection->idempotentRead(function (Connection $conn) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                throw $this->loss('read lost the connection');
            }

            return $conn->table('items')->get();
        });

        self::assertSame(2, $calls, 'the read is re-run once after reconnecting');
        self::assertCount(1, $rows);
        self::assertSame('persisted', $rows[0]['name']);
        self::assertNotSame($before, $connection->getPDO(), 'the read replayed on a fresh handle');
    }

    #[Test]
    public function idempotentReadRefusesInsideTransaction(): void
    {
        $connection = $this->sqliteConnection();
        $inner = 0;

        try {
            $connection->transaction(function () use ($connection, &$inner): mixed {
                return $connection->idempotentRead(function () use (&$inner): string {
                    $inner++;

                    return 'unreachable';
                });
            });
            self::fail('Expected a LogicException');
        } catch (\LogicException $caught) {
            self::assertStringContainsString('idempotentRead', $caught->getMessage());
        }

        self::assertSame(0, $inner, 'the read must not run inside a transaction that a reconnect would abandon');
    }

    #[Test]
    public function idempotentReadExhaustionRethrows(): void
    {
        $connection = $this->sqliteConnection(maxAttempts: 2);
        $losses = [$this->loss('read loss one'), $this->loss('read loss two')];
        $calls = 0;

        try {
            $connection->idempotentRead(function () use (&$calls, $losses): never {
                throw $losses[$calls++];
            });
            self::fail('Expected the exhausted budget to rethrow');
        } catch (ConnectionLostException $caught) {
            self::assertSame($losses[1], $caught, 'the newest loss is rethrown by identity');
        }

        self::assertSame(2, $calls, 'max_attempts bounds the total executions');
    }

    // ------------------------------------------------------------- reconnect

    #[Test]
    public function reconnectRefusesMidTransactionTrackedByTheManager(): void
    {
        $connection = $this->sqliteConnection();
        $before = $connection->getPDO();
        $manager = $connection->getTransactionManager();

        try {
            $connection->transaction(static function () use ($connection): void {
                $connection->reconnect();
            });
            self::fail('Expected a LogicException');
        } catch (\LogicException $caught) {
            self::assertStringContainsString('transaction', $caught->getMessage());
        }

        self::assertSame($before, $connection->getPDO(), 'the refused reconnect must not replace the handle');
        self::assertSame(
            $manager,
            $connection->getTransactionManager(),
            'the refused reconnect must not drop the manager'
        );
    }

    #[Test]
    public function reconnectRefusesARawTransactionOnThePooledHandle(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('acquire');
        $pool->expects(self::never())->method('discard');

        $raw = new PDO('sqlite:' . $this->newDbPath());
        $raw->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $raw->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pooled = new PooledConnection($raw, $pool, 'conn_guard');

        $connection = $this->sqliteConnection();
        self::setOn(Connection::class, $connection, 'pool', $pool);
        self::setOn(Connection::class, $connection, 'pooledConnection', $pooled);

        // Begin DIRECTLY on the raw PDO: the wrapper's own flag stays false, so only
        // the raw handle knows a transaction is open.
        $raw->beginTransaction();

        try {
            $connection->reconnect();
            self::fail('Expected a LogicException');
        } catch (\LogicException $caught) {
            self::assertStringContainsString('transaction', $caught->getMessage());
        }

        self::assertTrue($raw->inTransaction(), 'the guard must not roll back or discard the live transaction');
        self::assertSame(
            $pooled,
            self::getOn(Connection::class, $connection, 'pooledConnection'),
            'the guard must not replace the pooled handle'
        );

        $raw->rollBack();
    }

    #[Test]
    public function reconnectSurvivesSchemaAndData(): void
    {
        $connection = $this->sqliteConnection();
        $this->createItemsTable($connection);
        $connection->table('items')->insert(['name' => 'kept']);
        $before = $connection->getPDO();

        $connection->reconnect();

        self::assertNotSame($before, $connection->getPDO(), 'reconnect() establishes a new handle');

        $rows = $connection->table('items')->get();
        self::assertCount(1, $rows, 'file-backed schema and data survive the reconnect');
        self::assertSame('kept', $rows[0]['name']);
    }

    // --------------------------------------------------------- canonical key

    #[Test]
    public function canonicalKeyUnifiesConstructorAndFallbackCaches(): void
    {
        $mysql = $this->unconnectedConnection('mysql', [
            'engine' => 'mysql',
            'mysql' => [
                'host' => 'db.internal.test',
                'port' => 3307,
                'db' => 'glueful_app',
                'user' => 'app_user',
                'pass' => 'super-secret',
                'charset' => 'utf8mb4',
            ],
        ]);

        $key = self::callOn(Connection::class, $mysql, 'connectionKey');
        self::assertSame(
            'mysql|mysql:host=db.internal.test;dbname=glueful_app;port=3307;charset=utf8mb4|app_user|',
            $key,
            'the canonical key is engine|dsn|user|schema'
        );
        self::assertSame($key, self::callOn(Connection::class, $mysql, 'connectionKey'), 'the key is deterministic');
        self::assertStringNotContainsString('super-secret', (string) $key, 'the password never enters the key');

        $pgsql = $this->unconnectedConnection('pgsql', [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => 'pg.internal.test',
                'port' => 6543,
                'db' => 'glueful_app',
                'user' => 'app_user',
                'schema' => 'reporting',
                'sslmode' => 'require',
            ],
        ]);
        self::assertSame(
            'pgsql|pgsql:host=pg.internal.test;port=6543;dbname=glueful_app;sslmode=require|app_user|reporting',
            self::callOn(Connection::class, $pgsql, 'connectionKey'),
            'the schema participates in the identity'
        );

        $otherSchema = $this->unconnectedConnection('pgsql', [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => 'pg.internal.test',
                'port' => 6543,
                'db' => 'glueful_app',
                'user' => 'app_user',
                'schema' => 'tenant_b',
                'sslmode' => 'require',
            ],
        ]);
        self::assertNotSame(
            self::callOn(Connection::class, $pgsql, 'connectionKey'),
            self::callOn(Connection::class, $otherSchema, 'connectionKey'),
            'connections for different schemas must never collapse onto one handle'
        );

        // Source-level pin: the constructor and the lazy fallback must delegate to ONE
        // establishment helper instead of indexing the static cache independently.
        $bodies = $this->methodBodies(Connection::class);
        $owners = array_filter($bodies, static fn(string $body): bool => str_contains($body, 'self::$instances'));

        self::assertArrayNotHasKey('__construct', $owners, 'the constructor must not index self::$instances directly');
        self::assertArrayNotHasKey('getPDO', $owners, 'the lazy fallback must not index self::$instances directly');
        foreach ($owners as $name => $body) {
            self::assertStringContainsString(
                '$this->connectionKey()',
                $body,
                sprintf('%s() indexes the shared cache without the canonical key', $name)
            );
        }

        $shared = array_keys(array_filter(
            $owners,
            static fn(string $body, string $name): bool =>
                str_contains($bodies['__construct'], '$this->' . $name . '(')
                && str_contains($bodies['getPDO'], '$this->' . $name . '('),
            ARRAY_FILTER_USE_BOTH
        ));
        self::assertCount(
            1,
            $shared,
            'the constructor and the lazy fallback must share exactly one establishment helper'
        );
        self::assertStringNotContainsString(
            "=== 'sqlite'",
            $bodies['__construct'],
            'the sharing exclusion must live in the factored predicate, not inline in the constructor'
        );
        self::assertStringNotContainsString(
            "=== 'sqlite'",
            $bodies['getPDO'],
            'the sharing exclusion must live in the factored predicate, not inline in the fallback'
        );
    }

    #[Test]
    public function invalidateDoesNotEvictANewerSharedHandle(): void
    {
        $connection = $this->unconnectedConnection('mysql', [
            'engine' => 'mysql',
            'mysql' => [
                'host' => 'db.internal.test',
                'port' => 3306,
                'db' => 'glueful_app',
                'user' => 'app_user',
                'charset' => 'utf8mb4',
            ],
        ]);
        $dead = new PDO('sqlite::memory:');
        $replacement = new PDO('sqlite::memory:');
        self::setOn(Connection::class, $connection, 'pdo', $dead);

        $key = (string) self::callOn(Connection::class, $connection, 'connectionKey');
        self::setStaticInstances([$key => $replacement]);

        self::callOn(Connection::class, $connection, 'invalidate');

        self::assertSame(
            $replacement,
            self::staticInstances()[$key] ?? null,
            'a replacement handle installed by another Connection must survive the purge'
        );
        self::assertFalse(
            (new \ReflectionProperty(Connection::class, 'pdo'))->isInitialized($connection),
            'the dead handle is still dropped from the instance'
        );

        // The purge is not vacuous: when the cached entry IS the dead handle, it goes.
        self::setOn(Connection::class, $connection, 'pdo', $dead);
        self::setStaticInstances([$key => $dead]);

        self::callOn(Connection::class, $connection, 'invalidate');

        self::assertArrayNotHasKey(
            $key,
            self::staticInstances(),
            'the dead handle must be removed from the shared cache'
        );
    }

    #[Test]
    public function adHocConnectionNeverAdoptsACachedSharedHandle(): void
    {
        $connection = $this->unconnectedConnection('mysql', [
            'engine' => 'mysql',
            'mysql' => [
                'host' => 'db.internal.test',
                'port' => 3306,
                'db' => 'glueful_app',
                'user' => 'app_user',
                'charset' => 'utf8mb4',
            ],
        ]);
        $foreign = new PDO('sqlite::memory:');
        $key = (string) self::callOn(Connection::class, $connection, 'connectionKey');
        self::setStaticInstances([$key => $foreign]);

        self::assertFalse(
            self::callOn(Connection::class, $connection, 'sharesStaticHandle'),
            'a connection built without an ApplicationContext must never share a handle'
        );

        // The context is the discriminator: the same identity DOES share when framework-managed.
        $context = (new \ReflectionClass(ApplicationContext::class))->newInstanceWithoutConstructor();
        self::setOn(Connection::class, $connection, 'context', $context);
        self::assertTrue(
            self::callOn(Connection::class, $connection, 'sharesStaticHandle'),
            'a framework-managed non-SQLite connection shares the process-global handle'
        );

        // SQLite stays excluded even with a context (a :memory: database is private).
        self::setOn(Connection::class, $connection, 'engine', 'sqlite');
        self::assertFalse(
            self::callOn(Connection::class, $connection, 'sharesStaticHandle'),
            'SQLite is excluded from sharing regardless of the context'
        );

        self::assertSame(
            $foreign,
            self::staticInstances()[$key] ?? null,
            'inspecting an excluded connection must never read-through or overwrite the cache'
        );

        // A REAL ad-hoc connection opens a private backend and publishes nothing.
        self::setStaticInstances([]);
        $adHoc = $this->sqliteConnection();
        $first = $adHoc->getPDO();

        $adHoc->reconnect();

        self::assertNotSame($first, $adHoc->getPDO(), 'an excluded connection reconnects to a FRESH backend');
        self::assertSame(
            [],
            self::staticInstances(),
            'an excluded connection must never publish its handle to the shared cache'
        );
    }

    // ----------------------------------------------------------- pool discard

    #[Test]
    public function poolDiscardNeverTouchesTheDeadHandle(): void
    {
        $pool = (new \ReflectionClass(ConnectionPool::class))->newInstanceWithoutConstructor();
        self::setOn(ConnectionPool::class, $pool, 'config', ['max_connections' => 10]);

        $spy = new class ('sqlite::memory:') extends PDO {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            public function inTransaction(): bool
            {
                $this->calls[] = 'inTransaction';

                return parent::inTransaction();
            }

            public function rollBack(): bool
            {
                $this->calls[] = 'rollBack';

                return parent::rollBack();
            }

            public function commit(): bool
            {
                $this->calls[] = 'commit';

                return parent::commit();
            }

            public function exec(string $statement): int|false
            {
                $this->calls[] = 'exec';

                return parent::exec($statement);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->calls[] = 'prepare';

                return parent::prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->calls[] = 'query';

                return parent::query($query);
            }
        };

        $connection = new PooledConnection($spy, $pool, 'conn_dead');
        self::setOn(ConnectionPool::class, $pool, 'activeConnections', [$connection->getId() => $connection]);

        self::assertSame(0, $pool->getStats()['total_discards'], 'total_discards must be initialized to zero');

        $pool->discard($connection);

        self::assertSame([], $spy->calls, 'a presumed-dead handle must not be rolled back, reset, or pinged');
        self::assertNull($connection->getPDO(), 'the connection is destroyed');
        self::assertFalse($connection->isHealthy(), 'the connection is marked unhealthy before destruction');
        self::assertSame(
            [],
            self::getOn(ConnectionPool::class, $pool, 'activeConnections'),
            'the discarded connection leaves the active set'
        );
        self::assertSame(
            [],
            self::getOn(ConnectionPool::class, $pool, 'availableConnections'),
            'a discarded connection is never returned to the available set'
        );
        self::assertSame(1, $pool->getStats()['total_discards'], 'discards are counted');
        self::assertSame(1, $pool->getStats()['total_destroyed'], 'the handle is destroyed exactly once');
    }

    // --------------------------------------------------------------- fixtures

    private function newDbPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'conn-resilience-');
        self::assertIsString($path);
        $this->dbPaths[] = $path;

        return $path;
    }

    private function sqliteConnection(int $maxAttempts = 3, ?string $path = null): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $path ?? $this->newDbPath()],
            'pooling' => ['enabled' => false],
            // backoff_base_ms => 0 keeps the suite from ever sleeping.
            'retry' => ['max_attempts' => $maxAttempts, 'backoff_base_ms' => 0],
        ]);
    }

    /**
     * A Connection that never contacted a server: only the fields the identity
     * calculation and the sharing predicate read are populated.
     *
     * @param array<string, mixed> $config
     */
    private function unconnectedConnection(string $engine, array $config): Connection
    {
        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        self::setOn(Connection::class, $connection, 'config', $config);
        self::setOn(Connection::class, $connection, 'engine', $engine);
        self::setOn(Connection::class, $connection, 'context', null);
        self::setOn(Connection::class, $connection, 'resilienceLogger', new QueryLogger());

        return $connection;
    }

    private function createItemsTable(Connection $connection): void
    {
        $connection->getPDO()->exec(
            'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
    }

    private function loss(string $message): ConnectionLostException
    {
        $raw = new \PDOException($message);
        $raw->errorInfo = ['08006', 7, $message];

        return ConnectionLostException::fromPdo($raw, 'sqlite');
    }

    private function deadlock(): DeadlockException
    {
        $raw = new \PDOException('deadlock detected');
        $raw->errorInfo = ['40P01', 7, 'deadlock detected'];

        return DeadlockException::fromPdo($raw, 'pgsql');
    }

    /**
     * Source text of every method declared on $class, keyed by method name.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        $bodies = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $bodies[$method->getName()] = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        }

        return $bodies;
    }

    /** @return array<string, PDO> */
    private static function staticInstances(): array
    {
        /** @var array<string, PDO> $instances */
        $instances = (new \ReflectionProperty(Connection::class, 'instances'))->getValue();

        return $instances;
    }

    /** @param array<string, PDO> $instances */
    private static function setStaticInstances(array $instances): void
    {
        (new \ReflectionProperty(Connection::class, 'instances'))->setValue(null, $instances);
    }

    private static function setOn(string $class, object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue($target, $value);
    }

    private static function getOn(string $class, object $target, string $property): mixed
    {
        return (new \ReflectionProperty($class, $property))->getValue($target);
    }

    private static function callOn(string $class, object $target, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke($target, ...$args);
    }
}
