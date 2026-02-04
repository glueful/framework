<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction;

use PDO;
use Exception;
use Throwable;
use Glueful\Database\Transaction\Interfaces\TransactionManagerInterface;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\QueryLogger;

/**
 * TransactionManager
 *
 * Handles database transaction management with deadlock retry, nested transaction support,
 * and after-commit/after-rollback callbacks.
 *
 * Extracted from the monolithic QueryBuilder to follow Single Responsibility Principle.
 */
class TransactionManager implements TransactionManagerInterface
{
    protected PDO $pdo;
    protected SavepointManagerInterface $savepointManager;
    protected QueryLogger $logger;
    protected int $transactionLevel = 0;
    protected int $maxRetries = 3;

    /**
     * Callbacks to execute after transaction commits, indexed by transaction level.
     *
     * @var array<int, callable[]>
     */
    protected array $commitCallbacks = [];

    /**
     * Callbacks to execute after transaction rolls back, indexed by transaction level.
     *
     * @var array<int, callable[]>
     */
    protected array $rollbackCallbacks = [];

    public function __construct(
        PDO $pdo,
        SavepointManagerInterface $savepointManager,
        QueryLogger $logger
    ) {
        $this->pdo = $pdo;
        $this->savepointManager = $savepointManager;
        $this->logger = $logger;
    }

    /**
     * Execute callback within a transaction
     */
    public function transaction(callable $callback): mixed
    {
        $retryCount = 0;

        $this->logger->logEvent("Starting transaction", ['retries_allowed' => $this->maxRetries]);

        while ($retryCount < $this->maxRetries) {
            $this->begin();
            try {
                $result = $callback($this);
                $this->commit();

                // Log successful transaction
                $this->logger->logEvent(
                    "Transaction completed successfully",
                    [
                    'retries' => $retryCount,
                    'level' => $this->transactionLevel
                    ],
                    'info'
                );

                return $result;
            } catch (Exception $e) {
                if ($this->isDeadlock($e)) {
                    $this->rollback();
                    $retryCount++;

                    // Log deadlock and retry
                    $this->logger->logEvent(
                        "Transaction deadlock detected, retrying",
                        [
                        'retry' => $retryCount,
                        'max_retries' => $this->maxRetries,
                        'error' => $e->getMessage()
                        ],
                        'warning'
                    );

                    // Progressive backoff
                    usleep(500000 * $retryCount);
                } else {
                    $this->rollback();

                    // Log transaction failure
                    $this->logger->logEvent(
                        "Transaction failed",
                        [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'level' => $this->transactionLevel
                        ],
                        'error'
                    );

                    throw $e;
                }
            }
        }

        $this->logger->logEvent(
            "Transaction failed after maximum retries",
            [
            'max_retries' => $this->maxRetries
            ],
            'error'
        );

        throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
    }

    /**
     * Begin a new transaction or create savepoint
     */
    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
            $this->logger->logEvent("Transaction started", ['level' => 1], 'debug');
        } else {
            $this->savepointManager->create($this->transactionLevel);
            $this->logger->logEvent("Savepoint created", ['level' => $this->transactionLevel + 1], 'debug');
        }
        $this->transactionLevel++;
    }

    /**
     * Commit current transaction level
     */
    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->logger->logEvent("Attempted to commit with no active transaction", [], 'warning');
            return;
        }

        $level = $this->transactionLevel;

        if ($level === 1) {
            // Outermost transaction - actually commit to database
            $this->pdo->commit();
            $this->logger->logEvent("Transaction committed", ['level' => 1], 'debug');
            $this->transactionLevel = 0;

            // Execute after-commit callbacks
            $this->executeCallbacks($this->commitCallbacks[$level] ?? []);
            $this->clearCallbacks($level);
        } else {
            // Nested transaction (savepoint) - promote callbacks to parent level
            // They are automatically released when the parent transaction commits
            $this->logger->logEvent("Savepoint committed", ['level' => $level], 'debug');
            $this->promoteCallbacks($level);
            $this->transactionLevel--;
        }
    }

    /**
     * Rollback current transaction level
     */
    public function rollback(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->logger->logEvent("Attempted to rollback with no active transaction", [], 'warning');
            return;
        }

        $level = $this->transactionLevel;

        if ($level === 1) {
            // Outermost transaction - actually rollback
            $this->pdo->rollBack();
            $this->logger->logEvent("Transaction rolled back", ['level' => 1], 'debug');
            $this->transactionLevel = 0;

            // Execute after-rollback callbacks
            $this->executeCallbacks($this->rollbackCallbacks[$level] ?? []);
            $this->clearCallbacks($level);
        } else {
            // Nested transaction (savepoint) - rollback to previous savepoint
            $this->savepointManager->rollbackTo($level - 1);
            $this->logger->logEvent("Rolled back to savepoint", ['level' => $level - 1], 'debug');
            $this->transactionLevel--;

            // Discard callbacks for this level (not promoted on rollback)
            $this->clearCallbacks($level);
        }
    }

    /**
     * Check if a transaction is currently active
     */
    public function isActive(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Get current transaction nesting level
     */
    public function getLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Set maximum retry attempts for deadlocked transactions
     */
    public function setMaxRetries(int $retries): void
    {
        $this->maxRetries = max(0, $retries);
    }

    /**
     * Get current max retry attempts
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Check if exception is a deadlock
     */
    protected function isDeadlock(Exception $e): bool
    {
        // MySQL deadlock error codes: 1213, 1205
        // PostgreSQL deadlock error code: 40001
        $deadlockCodes = ['1213', '1205', '40001'];

        return in_array((string) $e->getCode(), $deadlockCodes, true);
    }

    /**
     * Register a callback to execute after the transaction commits.
     *
     * If not currently in a transaction, the callback is executed immediately.
     * For nested transactions, callbacks are promoted to the parent level on
     * commit and only fire when the outermost transaction commits.
     *
     * @param callable $callback The callback to execute after commit
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionLevel === 0) {
            // Not in a transaction - execute immediately
            try {
                $callback();
            } catch (Throwable $e) {
                $this->logger->logEvent(
                    "Immediate after-commit callback failed",
                    ['error' => $e->getMessage()],
                    'error'
                );
            }
            return;
        }

        // Store callback at current transaction level
        $this->commitCallbacks[$this->transactionLevel][] = $callback;
    }

    /**
     * Register a callback to execute after the transaction rolls back.
     *
     * If not currently in a transaction, the callback is ignored.
     * For nested transactions, callbacks are discarded if the nested
     * transaction is rolled back (not promoted to parent).
     *
     * @param callable $callback The callback to execute after rollback
     */
    public function afterRollback(callable $callback): void
    {
        if ($this->transactionLevel === 0) {
            // Not in a transaction - ignore
            return;
        }

        // Store callback at current transaction level
        $this->rollbackCallbacks[$this->transactionLevel][] = $callback;
    }

    /**
     * Execute an array of callbacks, catching and logging any exceptions.
     *
     * @param callable[] $callbacks The callbacks to execute
     */
    protected function executeCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $this->logger->logEvent(
                    "Transaction callback failed",
                    ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                    'error'
                );
                // Continue executing remaining callbacks
            }
        }
    }

    /**
     * Promote callbacks from a nested transaction level to the parent level.
     *
     * When a savepoint commits, its callbacks should fire when the parent
     * transaction commits, so we move them up one level.
     *
     * @param int $level The level to promote callbacks from
     */
    protected function promoteCallbacks(int $level): void
    {
        $parentLevel = $level - 1;

        // Promote commit callbacks
        foreach ($this->commitCallbacks[$level] ?? [] as $callback) {
            $this->commitCallbacks[$parentLevel][] = $callback;
        }
        unset($this->commitCallbacks[$level]);

        // Promote rollback callbacks
        foreach ($this->rollbackCallbacks[$level] ?? [] as $callback) {
            $this->rollbackCallbacks[$parentLevel][] = $callback;
        }
        unset($this->rollbackCallbacks[$level]);
    }

    /**
     * Clear all callbacks for a given transaction level.
     *
     * @param int $level The level to clear callbacks for
     */
    protected function clearCallbacks(int $level): void
    {
        unset($this->commitCallbacks[$level], $this->rollbackCallbacks[$level]);
    }

    /**
     * Get the count of pending commit callbacks (for testing/debugging).
     *
     * @return int Total number of pending commit callbacks across all levels
     */
    public function getPendingCommitCallbackCount(): int
    {
        $count = 0;
        foreach ($this->commitCallbacks as $callbacks) {
            $count += count($callbacks);
        }
        return $count;
    }

    /**
     * Get the count of pending rollback callbacks (for testing/debugging).
     *
     * @return int Total number of pending rollback callbacks across all levels
     */
    public function getPendingRollbackCallbackCount(): int
    {
        $count = 0;
        foreach ($this->rollbackCallbacks as $callbacks) {
            $count += count($callbacks);
        }
        return $count;
    }
}
