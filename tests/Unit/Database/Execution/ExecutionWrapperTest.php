<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Execution;

use Glueful\Database\Execution\ExecutionWrapperInterface;
use Glueful\Database\Execution\ParameterBinder;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\QueryLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecutionWrapperTest extends TestCase
{
    protected function setUp(): void
    {
        QueryExecutor::clearExecutionWrappers();
    }

    protected function tearDown(): void
    {
        QueryExecutor::clearExecutionWrappers();
    }

    public function testWrappersComposeInRegistrationOrderAroundExecution(): void
    {
        $events = [];
        QueryExecutor::addExecutionWrapper($this->recordingWrapper('a', $events));
        QueryExecutor::addExecutionWrapper($this->recordingWrapper('b', $events));

        $statement = $this->executor()->executeStatement('SELECT 1 AS n');

        self::assertSame(1, (int) $statement->fetchColumn());
        self::assertSame(['a:before', 'b:before', 'b:after', 'a:after'], $events);
    }

    public function testWrapperCanPreventExecution(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        QueryExecutor::addExecutionWrapper(new class implements ExecutionWrapperInterface {
            public function around(string $sql, array $bindings, callable $proceed): PDOStatement
            {
                throw new RuntimeException('blocked');
            }
        });

        try {
            (new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger()))
                ->executeStatement('INSERT INTO t (id) VALUES (1)');
            self::fail('Expected the wrapper to reject execution.');
        } catch (RuntimeException $exception) {
            self::assertSame('blocked', $exception->getMessage());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testClearingWrappersRestoresDirectExecution(): void
    {
        QueryExecutor::addExecutionWrapper(new class implements ExecutionWrapperInterface {
            public function around(string $sql, array $bindings, callable $proceed): PDOStatement
            {
                throw new RuntimeException('blocked');
            }
        });
        QueryExecutor::clearExecutionWrappers();

        self::assertSame(1, (int) $this->executor()->executeStatement('SELECT 1')->fetchColumn());
    }

    private function executor(): QueryExecutor
    {
        return new QueryExecutor(new PDO('sqlite::memory:'), new ParameterBinder(), new QueryLogger());
    }

    /** @param list<string> $events */
    private function recordingWrapper(string $name, array &$events): ExecutionWrapperInterface
    {
        return new class ($name, $events) implements ExecutionWrapperInterface {
            /** @param list<string> $events */
            public function __construct(private readonly string $name, private array &$events)
            {
            }

            public function around(string $sql, array $bindings, callable $proceed): PDOStatement
            {
                $this->events[] = $this->name . ':before';
                try {
                    return $proceed();
                } finally {
                    $this->events[] = $this->name . ':after';
                }
            }
        };
    }
}
