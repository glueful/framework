<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\ConnectionPool;
use Glueful\Database\PooledConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression: releasing a pooled connection that was left mid-transaction must roll
 * back before the connection can be reused.
 *
 * A borrower that leaves a transaction open (an exception bypassing TransactionManager,
 * or code using the raw PDO directly) would otherwise return a live transaction to the
 * pool: the next borrower's commit() commits the prior borrower's uncommitted write, or
 * its reads observe uncommitted cross-tenant rows. The wrapper's own inTransaction flag
 * is only set when transaction calls go through it, so the check must consult the RAW PDO.
 *
 * The pool is built via newInstanceWithoutConstructor() to avoid its constructor's
 * background maintenance worker (which may fork under CLI + pcntl).
 */
final class ConnectionPoolReleaseTest extends TestCase
{
    public function test_release_rolls_back_an_open_transaction_left_on_the_raw_pdo(): void
    {
        $pool = (new \ReflectionClass(ConnectionPool::class))->newInstanceWithoutConstructor();
        // max_lifetime = -1 forces the recycle path, so release() rolls back then destroys
        // without invoking the connectivity ping -- keeps the test deterministic.
        $this->setPrivate($pool, 'config', ['max_lifetime' => -1, 'idle_timeout' => -1]);
        $this->setPrivate($pool, 'availableConnections', []);
        $this->setPrivate($pool, 'stats', [
            'total_releases' => 0,
            'total_destroyed' => 0,
            'total_health_checks' => 0,
            'failed_health_checks' => 0,
        ]);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER)');

        // Begin DIRECTLY on the raw PDO -- the wrapper's inTransaction flag stays false,
        // exactly the bypass that makes a leaked transaction invisible to the wrapper.
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO t VALUES (1)');
        self::assertTrue($pdo->inTransaction(), 'precondition: raw PDO is mid-transaction');

        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        self::assertFalse(
            $pdo->inTransaction(),
            'release() must roll back a transaction left open on the raw PDO'
        );
    }

    public function test_release_issues_pg_discard_all_session_reset(): void
    {
        [$pool, $pdo] = $this->poolWithFakePdo('pgsql');
        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        self::assertContains('DISCARD ALL', $pdo->execLog, 'PostgreSQL release should DISCARD ALL session state');
    }

    public function test_release_issues_mysql_reset_connection_session_reset(): void
    {
        [$pool, $pdo] = $this->poolWithFakePdo('mysql');
        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        self::assertContains('RESET CONNECTION', $pdo->execLog, 'MySQL release should RESET CONNECTION');
    }

    public function test_release_issues_no_session_reset_for_sqlite(): void
    {
        [$pool, $pdo] = $this->poolWithFakePdo('sqlite');
        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        self::assertNotContains('DISCARD ALL', $pdo->execLog);
        self::assertNotContains('RESET CONNECTION', $pdo->execLog);
    }

    public function test_pg_release_reapplies_search_path_after_discard_all(): void
    {
        [$pool, $pdo] = $this->poolWithFakePdo('pgsql', dbConfig: ['schema' => 'tenant_a']);
        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        $discardAt = array_search('DISCARD ALL', $pdo->execLog, true);
        $searchPathAt = array_search("SET search_path TO 'tenant_a'", $pdo->execLog, true);
        self::assertNotFalse($discardAt, 'precondition: DISCARD ALL must run on release');
        self::assertNotFalse(
            $searchPathAt,
            'DISCARD ALL wipes search_path; release must re-SET it or a reused connection queries the wrong schema'
        );
        self::assertGreaterThan($discardAt, $searchPathAt, 'search_path must be reapplied AFTER the discard');
    }

    public function test_mysql_release_reapplies_init_command_after_reset_connection(): void
    {
        $initCommand = "SET sql_mode='STRICT_ALL_TABLES'";
        [$pool, $pdo] = $this->poolWithFakePdo(
            'mysql',
            options: [\PDO::MYSQL_ATTR_INIT_COMMAND => $initCommand]
        );
        $connection = new PooledConnection($pdo, $pool);
        $this->setPrivate($pool, 'activeConnections', [$connection->getId() => $connection]);

        $pool->release($connection);

        $resetAt = array_search('RESET CONNECTION', $pdo->execLog, true);
        $initAt = array_search($initCommand, $pdo->execLog, true);
        self::assertNotFalse($resetAt, 'precondition: RESET CONNECTION must run on release');
        self::assertNotFalse(
            $initAt,
            'RESET CONNECTION wipes sql_mode; release must re-run the init command or strict mode is silently lost'
        );
        self::assertGreaterThan($resetAt, $initAt, 'init command must be reapplied AFTER the reset');
    }

    /**
     * Build a constructor-less pool plus a fake PDO that reports the given driver name and
     * records every exec() (so the test can assert which session-reset statement ran).
     *
     * @param array<string, mixed> $dbConfig
     * @param array<int, mixed> $options
     * @return array{0: ConnectionPool, 1: \PDO}
     */
    private function poolWithFakePdo(string $driverName, array $dbConfig = [], array $options = []): array
    {
        $pool = (new \ReflectionClass(ConnectionPool::class))->newInstanceWithoutConstructor();
        $this->setPrivate($pool, 'config', ['max_lifetime' => -1, 'idle_timeout' => -1]);
        $this->setPrivate($pool, 'availableConnections', []);
        $this->setPrivate($pool, 'dsn', $driverName . ':');
        $this->setPrivate($pool, 'dbConfig', $dbConfig);
        $this->setPrivate($pool, 'options', $options);
        $this->setPrivate($pool, 'stats', [
            'total_releases' => 0,
            'total_destroyed' => 0,
            'total_health_checks' => 0,
            'failed_health_checks' => 0,
        ]);

        $pdo = new class ('sqlite::memory:') extends \PDO {
            /** @var array<int, string> */
            public array $execLog = [];
            private string $driverName = 'sqlite';
            public function setDriverName(string $name): void
            {
                $this->driverName = $name;
            }
            #[\ReturnTypeWillChange]
            public function getAttribute(int $attribute): mixed
            {
                if ($attribute === \PDO::ATTR_DRIVER_NAME) {
                    return $this->driverName;
                }
                return parent::getAttribute($attribute);
            }
            #[\ReturnTypeWillChange]
            public function exec(string $statement): int|false
            {
                $this->execLog[] = $statement;
                return 0;
            }
        };
        $pdo->setDriverName($driverName);

        return [$pool, $pdo];
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
