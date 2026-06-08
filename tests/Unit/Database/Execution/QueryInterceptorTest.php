<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Execution;

use Glueful\Database\Execution\ParameterBinder;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\QueryLogger;
use PHPUnit\Framework\TestCase;

final class QueryInterceptorTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryExecutor::clearQueryInterceptors();
    }

    private function executor(\PDO $pdo): QueryExecutor
    {
        return new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger());
    }

    public function test_all_registered_interceptors_run_in_order_and_any_can_prevent(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
        $executor = $this->executor($pdo);

        $order = [];
        QueryExecutor::addQueryInterceptorCallback(function (string $sql, array $b) use (&$order) {
            $order[] = 'a';
        });
        QueryExecutor::addQueryInterceptorCallback(function (string $sql, array $b) use (&$order) {
            $order[] = 'b';
            if (str_contains($sql, 'INSERT')) {
                throw new \RuntimeException('blocked');
            }
        });

        try {
            $executor->executeStatement('INSERT INTO t (n) VALUES (?)', ['x']);
            $this->fail('expected RuntimeException to prevent the INSERT');
        } catch (\RuntimeException $e) {
            $this->assertSame('blocked', $e->getMessage());
        }

        // BOTH interceptors ran, in registration order, before the throw — no last-writer-wins.
        $this->assertSame(['a', 'b'], $order);

        // The INSERT was prevented (interceptor fires before execute()).
        $count = (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_no_interceptors_is_a_noop(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $executor = $this->executor($pdo);

        $stmt = $executor->executeStatement('SELECT * FROM t', []);
        $this->assertSame([], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
