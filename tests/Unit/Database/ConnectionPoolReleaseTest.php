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

    /**
     * Build a constructor-less pool plus a fake PDO that reports the given driver name and
     * records every exec() (so the test can assert which session-reset statement ran).
     *
     * @return array{0: ConnectionPool, 1: \PDO}
     */
    private function poolWithFakePdo(string $driverName): array
    {
        $pool = (new \ReflectionClass(ConnectionPool::class))->newInstanceWithoutConstructor();
        $this->setPrivate($pool, 'config', ['max_lifetime' => -1, 'idle_timeout' => -1]);
        $this->setPrivate($pool, 'availableConnections', []);
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
