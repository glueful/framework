<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction;

use PDO;
use Exception;
use Throwable;
use Glueful\Database\Transaction\Interfaces\TransactionManagerInterface;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\QueryLogger;
use Glueful\Database\Exceptions\CommitOutcomeUnknownException;
use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\ExceptionClassifier;
use Glueful\Database\Exceptions\RetryableTransactionFailureInterface;
use Glueful\Database\Resilience\RetryBudget;
use Glueful\Database\Resilience\SleeperInterface;
use Glueful\Database\Resilience\UsleepSleeper;

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
    protected string $driver;
    protected ExceptionClassifier $classifier;
    private SleeperInterface $sleeper;

    /**
     * Set when a rollback or commit failure is classified as a connection
     * loss: the handle is presumed dead and callers should invalidate/
     * reconnect rather than reuse it.
     */
    private bool $connectionPresumedDead = false;

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
        QueryLogger $logger,
        ?string $driver = null,
        ?SleeperInterface $sleeper = null
    ) {
        $this->pdo = $pdo;
        $this->savepointManager = $savepointManager;
        $this->logger = $logger;
        $driverName = $driver ?? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driverName) ? $driverName : '';
        $this->classifier = new ExceptionClassifier();
        $this->sleeper = $sleeper ?? new UsleepSleeper();
    }

    /**
     * Whether the underlying connection is presumed dead after a rollback
     * or commit failure classified as a connection loss. Callers (e.g.
     * Connection) should invalidate/reconnect rather than reuse this handle.
     */
    public function connectionPresumedDead(): bool
    {
        return $this->connectionPresumedDead;
    }

    /**
     * Execute callback within a transaction, retrying on classified
     * deadlock/lock-contention failures.
     *
     * @param callable $callback The transactional work to run
     * @param RetryBudget|null $budget Shared retry budget (e.g. supplied by
     *        Connection); when null, a local budget honoring setMaxRetries()
     *        and the historical 500ms backoff is constructed for this call.
     */
    public function transaction(callable $callback, ?RetryBudget $budget = null): mixed
    {
        $this->logger->logEvent("Starting transaction", ['retries_allowed' => $this->maxRetries]);

        if ($budget === null && $this->maxRetries === 0) {
            // setMaxRetries(0) is valid and makes zero attempts, so no typed
            // failure exists; retain the historical generic exception for
            // that compatibility edge only.
            throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
        }

        $budget ??= RetryBudget::forAttempts($this->maxRetries, 500, $this->sleeper);
        $lastFailure = null;

        do {
            $began = false;
            try {
                $this->begin();
                $began = true;
                $result = $callback($this);
                $this->commit();

                // Log successful transaction
                $this->logger->logEvent(
                    "Transaction completed successfully",
                    [
                    'retries' => $budget->attemptsUsed() - 1,
                    'level' => $this->transactionLevel
                    ],
                    'info'
                );

                return $result;
            } catch (Throwable $e) {
                // begin()/commit()/rollback() classify their own failures; a raw
                // PDOException here came from the callback's direct PDO use.
                if ($e instanceof \PDOException && !$e instanceof DatabaseException) {
                    $e = $this->classifier->classify($e, $this->driver);
                }

                if ($e instanceof RetryableTransactionFailureInterface) {
                    if ($began) {
                        $superseding = $this->rollbackForFailure($e);
                        if ($superseding !== null) {
                            throw $superseding;
                        }
                    }
                    $lastFailure = $e;

                    if (!$budget->tryConsume()) {
                        break;
                    }

                    // Log deadlock and retry
                    $this->logger->logEvent(
                        "Transaction deadlock detected, retrying",
                        [
                        'attempt' => $budget->attemptsUsed(),
                        'max_retries' => $this->maxRetries,
                        'delay_ms' => $budget->lastDelayMilliseconds(),
                        'error' => $e->getMessage()
                        ],
                        'warning'
                    );
                } else {
                    if ($began) {
                        $this->rollbackForFailure($e);
                    }

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
        } while (true);

        $this->logger->logEvent(
            "Transaction failed after maximum retries",
            [
            'max_retries' => $this->maxRetries
            ],
            'error'
        );

        // $lastFailure is always set here: the only way to reach this point is
        // via `break`, which is always preceded by `$lastFailure = $e;` in the
        // same iteration.
        throw $lastFailure;
    }

    /**
     * Roll back after a transaction failure, resolving precedence between
     * the primary failure and any failure the rollback itself produces.
     *
     * Returns null when the primary should be (re)thrown by the caller, or
     * a superseding \Throwable that must be thrown instead (rule 3 below).
     */
    private function rollbackForFailure(Throwable $primary): ?Throwable
    {
        try {
            $this->rollback();

            return null;
        } catch (\PDOException $rollbackFailure) {
            $classified = $rollbackFailure instanceof DatabaseException
                ? $rollbackFailure
                : $this->classifier->classify($rollbackFailure, $this->driver);

            if (!$classified instanceof ConnectionLostException) {
                // Non-loss rollback failure: rollback() threw BEFORE reaching
                // its own `transactionLevel = 0`, so this manager's transaction
                // state is unknown. Reset bookkeeping anyway — otherwise the
                // retryable branch would retry at level 1 (begin() takes the
                // savepoint path against a possibly-nonexistent transaction)
                // and a later loss primary would trip Connection's reconnect
                // guard and be masked by a LogicException. The connection may
                // well be alive, so do NOT set connectionPresumedDead — only
                // loss-classified rollback failures flag the handle.
                $this->transactionLevel = 0;
                $this->commitCallbacks = [];
                $this->rollbackCallbacks = [];
                $this->logger->logEvent(
                    'Rollback failed while handling a transaction failure',
                    ['primary' => $primary->getMessage(), 'secondary' => $classified->getMessage()],
                    'error'
                );

                return null; // preserve the primary
            }

            // The connection died during rollback. The server rolls back on
            // disconnect; reset bookkeeping and flag the dead handle.
            $this->transactionLevel = 0;
            $this->commitCallbacks = [];
            $this->rollbackCallbacks = [];
            $this->connectionPresumedDead = true;

            if ($primary instanceof ConnectionLostException) {
                $this->logger->logEvent(
                    'Connection also lost during rollback; preserving primary loss',
                    ['secondary' => $classified->getMessage()],
                    'warning'
                );

                return null; // rule 1: preserve primary loss
            }
            if ($primary instanceof RetryableTransactionFailureInterface) {
                $this->logger->logEvent(
                    'Retryable failure superseded by connection loss during rollback',
                    ['primary' => $primary->getMessage()],
                    'warning'
                );

                return $classified; // rule 3: surface the loss to Connection
            }
            $this->logger->logEvent(
                'Connection lost during rollback; preserving non-retryable primary',
                ['primary' => $primary->getMessage(), 'secondary' => $classified->getMessage()],
                'error'
            );

            return null; // rule 2: preserve primary, dead handle flagged
        }
    }

    /**
     * Begin a new transaction or create savepoint
     */
    public function begin(): void
    {
        try {
            if ($this->transactionLevel === 0) {
                $this->pdo->beginTransaction();
                $this->logger->logEvent("Transaction started", ['level' => 1], 'debug');
            } else {
                $this->savepointManager->create($this->transactionLevel);
                $this->logger->logEvent("Savepoint created", ['level' => $this->transactionLevel + 1], 'debug');
            }
        } catch (\PDOException $e) {
            throw $this->classifier->classify($e, $this->driver);
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
            try {
                $this->pdo->commit();
            } catch (\PDOException $e) {
                $classified = $e instanceof DatabaseException ? $e : $this->classifier->classify($e, $this->driver);
                if ($classified instanceof ConnectionLostException) {
                    // Ambiguous outcome: the server may have committed before
                    // the acknowledgement was lost. Never replayable; nothing
                    // about either callback set can be inferred.
                    $this->transactionLevel = 0;
                    $this->commitCallbacks = [];
                    $this->rollbackCallbacks = [];
                    $this->connectionPresumedDead = true;
                    throw CommitOutcomeUnknownException::fromLoss($classified);
                }
                throw $classified;
            }
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
            try {
                $this->pdo->rollBack();
            } catch (\PDOException $e) {
                throw $this->classifier->classify($e, $this->driver);
            }
            $this->logger->logEvent("Transaction rolled back", ['level' => 1], 'debug');
            $this->transactionLevel = 0;

            // Execute after-rollback callbacks
            $this->executeCallbacks($this->rollbackCallbacks[$level] ?? []);
            $this->clearCallbacks($level);
        } else {
            // Nested transaction (savepoint) - rollback to previous savepoint
            try {
                $this->savepointManager->rollbackTo($level - 1);
            } catch (\PDOException $e) {
                throw $this->classifier->classify($e, $this->driver);
            }
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
