<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction\Interfaces;

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
     * Execute callback within a transaction
     */
    public function transaction(callable $callback): mixed;

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
