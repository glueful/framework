<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Transaction;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Glueful\Database\Transaction\TransactionManager;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\QueryLogger;
use PDO;

#[CoversClass(TransactionManager::class)]
class TransactionManagerTest extends TestCase
{
    private PDO $pdo;
    private SavepointManagerInterface $savepointManager;
    private QueryLogger $logger;
    private TransactionManager $manager;

    protected function setUp(): void
    {
        // Create an in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create a mock SavepointManager
        $this->savepointManager = $this->createMock(SavepointManagerInterface::class);
        $this->logger = new QueryLogger();

        $this->manager = new TransactionManager(
            $this->pdo,
            $this->savepointManager,
            $this->logger
        );
    }

    #[Test]
    public function afterCommitCallbackExecutesOnCommit(): void
    {
        $executed = false;

        $this->manager->begin();
        $this->manager->afterCommit(function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed, 'Callback should not execute before commit');

        $this->manager->commit();

        $this->assertTrue($executed, 'Callback should execute after commit');
    }

    #[Test]
    public function afterCommitCallbackDoesNotExecuteOnRollback(): void
    {
        $executed = false;

        $this->manager->begin();
        $this->manager->afterCommit(function () use (&$executed) {
            $executed = true;
        });

        $this->manager->rollback();

        $this->assertFalse($executed, 'Callback should not execute after rollback');
    }

    #[Test]
    public function afterRollbackCallbackExecutesOnRollback(): void
    {
        $executed = false;

        $this->manager->begin();
        $this->manager->afterRollback(function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed, 'Callback should not execute before rollback');

        $this->manager->rollback();

        $this->assertTrue($executed, 'Callback should execute after rollback');
    }

    #[Test]
    public function afterRollbackCallbackDoesNotExecuteOnCommit(): void
    {
        $executed = false;

        $this->manager->begin();
        $this->manager->afterRollback(function () use (&$executed) {
            $executed = true;
        });

        $this->manager->commit();

        $this->assertFalse($executed, 'Rollback callback should not execute after commit');
    }

    #[Test]
    public function afterCommitExecutesImmediatelyWhenNotInTransaction(): void
    {
        $executed = false;

        $this->assertFalse($this->manager->isActive(), 'Should not be in transaction');

        $this->manager->afterCommit(function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed, 'Callback should execute immediately when not in transaction');
    }

    #[Test]
    public function afterRollbackIsIgnoredWhenNotInTransaction(): void
    {
        $executed = false;

        $this->assertFalse($this->manager->isActive(), 'Should not be in transaction');

        $this->manager->afterRollback(function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed, 'Rollback callback should be ignored when not in transaction');
    }

    #[Test]
    public function multipleCallbacksExecuteInOrder(): void
    {
        $order = [];

        $this->manager->begin();
        $this->manager->afterCommit(function () use (&$order) {
            $order[] = 'first';
        });
        $this->manager->afterCommit(function () use (&$order) {
            $order[] = 'second';
        });
        $this->manager->afterCommit(function () use (&$order) {
            $order[] = 'third';
        });

        $this->manager->commit();

        $this->assertEquals(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function nestedTransactionCallbacksPromotedToParent(): void
    {
        $executed = false;

        // Configure savepoint manager for nested transactions
        $this->savepointManager
            ->expects($this->once())
            ->method('create')
            ->with(1);

        $this->manager->begin(); // Level 1
        $this->manager->begin(); // Level 2 (savepoint)

        $this->manager->afterCommit(function () use (&$executed) {
            $executed = true;
        });

        // Commit inner savepoint - callback should NOT execute yet
        $this->manager->commit();
        $this->assertFalse($executed, 'Callback should not execute on savepoint commit');

        // Commit outer transaction - callback SHOULD execute now
        $this->manager->commit();
        $this->assertTrue($executed, 'Callback should execute when outermost transaction commits');
    }

    #[Test]
    public function nestedTransactionCallbacksDiscardedOnSavepointRollback(): void
    {
        $executed = false;

        // Configure savepoint manager for nested transactions
        $this->savepointManager
            ->expects($this->once())
            ->method('create')
            ->with(1);
        $this->savepointManager
            ->expects($this->once())
            ->method('rollbackTo')
            ->with(1);

        $this->manager->begin(); // Level 1
        $this->manager->begin(); // Level 2 (savepoint)

        $this->manager->afterCommit(function () use (&$executed) {
            $executed = true;
        });

        // Rollback savepoint - callback should be discarded
        $this->manager->rollback();

        // Commit outer transaction - callback should NOT execute (was discarded)
        $this->manager->commit();
        $this->assertFalse($executed, 'Callback should be discarded on savepoint rollback');
    }

    #[Test]
    public function callbackExceptionDoesNotPreventOtherCallbacks(): void
    {
        $firstExecuted = false;
        $secondExecuted = false;
        $thirdExecuted = false;

        $this->manager->begin();

        $this->manager->afterCommit(function () use (&$firstExecuted) {
            $firstExecuted = true;
        });
        $this->manager->afterCommit(function () {
            throw new \RuntimeException('Callback error');
        });
        $this->manager->afterCommit(function () use (&$thirdExecuted) {
            $thirdExecuted = true;
        });

        // Should not throw - exceptions are caught and logged
        $this->manager->commit();

        $this->assertTrue($firstExecuted, 'First callback should execute');
        $this->assertTrue($thirdExecuted, 'Third callback should execute despite second throwing');
    }

    #[Test]
    public function getPendingCallbackCountReturnsCorrectValue(): void
    {
        $this->assertEquals(0, $this->manager->getPendingCommitCallbackCount());
        $this->assertEquals(0, $this->manager->getPendingRollbackCallbackCount());

        $this->manager->begin();

        $this->manager->afterCommit(fn() => null);
        $this->manager->afterCommit(fn() => null);
        $this->manager->afterRollback(fn() => null);

        $this->assertEquals(2, $this->manager->getPendingCommitCallbackCount());
        $this->assertEquals(1, $this->manager->getPendingRollbackCallbackCount());

        $this->manager->commit();

        $this->assertEquals(0, $this->manager->getPendingCommitCallbackCount());
        $this->assertEquals(0, $this->manager->getPendingRollbackCallbackCount());
    }

    #[Test]
    public function transactionMethodExecutesCallbacksOnSuccess(): void
    {
        $transactionExecuted = false;
        $afterCommitExecuted = false;

        $result = $this->manager->transaction(function () use (&$transactionExecuted, &$afterCommitExecuted) {
            $transactionExecuted = true;

            $this->manager->afterCommit(function () use (&$afterCommitExecuted) {
                $afterCommitExecuted = true;
            });

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertTrue($transactionExecuted);
        $this->assertTrue($afterCommitExecuted, 'afterCommit callback should execute after transaction() succeeds');
    }

    #[Test]
    public function transactionMethodExecutesRollbackCallbacksOnFailure(): void
    {
        $afterCommitExecuted = false;
        $afterRollbackExecuted = false;

        try {
            $this->manager->transaction(function () use (&$afterCommitExecuted, &$afterRollbackExecuted) {
                $this->manager->afterCommit(function () use (&$afterCommitExecuted) {
                    $afterCommitExecuted = true;
                });
                $this->manager->afterRollback(function () use (&$afterRollbackExecuted) {
                    $afterRollbackExecuted = true;
                });

                throw new \RuntimeException('Transaction failed');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertFalse($afterCommitExecuted, 'afterCommit should not execute on failure');
        $this->assertTrue($afterRollbackExecuted, 'afterRollback should execute on failure');
    }

    #[Test]
    public function callbacksAreClearedAfterExecution(): void
    {
        $executionCount = 0;

        $this->manager->begin();
        $this->manager->afterCommit(function () use (&$executionCount) {
            $executionCount++;
        });
        $this->manager->commit();

        $this->assertEquals(1, $executionCount);

        // Start another transaction - the callback should NOT run again
        $this->manager->begin();
        $this->manager->commit();

        $this->assertEquals(1, $executionCount, 'Callback should only execute once');
    }
}
