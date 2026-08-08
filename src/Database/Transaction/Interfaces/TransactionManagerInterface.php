<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction\Interfaces;

use Glueful\Database\Resilience\RetryBudget;

/**
 * TransactionManager Interface
 *
 * Defines the contract for database transaction management.
 * This interface ensures consistent transaction handling across
 * different implementations.
 */
interface TransactionManagerInterface
{
    /**
     * Execute callback within a transaction, retrying on classified
     * deadlock/lock-contention failures.
     *
     * @param callable $callback The transactional work to run
     * @param RetryBudget|null $budget Shared retry budget (e.g. supplied by
     *        Connection so a connection-loss reconnect attempt and a
     *        transaction-level deadlock retry draw from the same allowance).
     *        When null, a local budget honoring setMaxRetries() and the
     *        historical 500ms backoff is constructed for this call only.
     */
    public function transaction(callable $callback, ?RetryBudget $budget = null): mixed;

    /**
     * Begin a new transaction or create savepoint
     */
    public function begin(): void;

    /**
     * Commit current transaction level
     */
    public function commit(): void;

    /**
     * Rollback current transaction level
     */
    public function rollback(): void;

    /**
     * Check if a transaction is currently active
     */
    public function isActive(): bool;

    /**
     * Get current transaction nesting level
     */
    public function getLevel(): int;

    /**
     * Set maximum retry attempts for deadlocked transactions
     */
    public function setMaxRetries(int $retries): void;

    /**
     * Get current max retry attempts
     */
    public function getMaxRetries(): int;

    /**
     * Register a callback to execute after the transaction commits.
     *
     * Callbacks are executed only when the outermost transaction commits.
     * For nested transactions (savepoints), callbacks are promoted to the
     * parent level on commit and discarded on rollback.
     *
     * If not in a transaction, the callback is executed immediately.
     *
     * @param callable $callback The callback to execute after commit
     */
    public function afterCommit(callable $callback): void;

    /**
     * Register a callback to execute after the transaction rolls back.
     *
     * Callbacks are executed when the outermost transaction rolls back.
     * For nested transactions, callbacks at that level are discarded
     * (not promoted to parent).
     *
     * If not in a transaction, the callback is ignored.
     *
     * @param callable $callback The callback to execute after rollback
     */
    public function afterRollback(callable $callback): void;
}
