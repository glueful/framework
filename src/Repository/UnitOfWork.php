<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Http\Exceptions\Domain\DatabaseException;

/**
 * Unit of Work Implementation
 *
 * Manages database transactions and entity state changes.
 * Provides a centralized way to track changes and commit them atomically.
 *
 * @package Glueful\Repository
 */
class UnitOfWork
{
    private Connection $db;
    /** @var array<string, array<string, mixed>> */
    private array $newEntities = [];
    /** @var array<string, array<string, mixed>> */
    private array $dirtyEntities = [];
    /** @var array<string, array<string, mixed>> */
    private array $removedEntities = [];
    private bool $isCommitting = false;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Register a new entity to be inserted
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Entity data
     * @param string|null $key Optional key for tracking
     */
    public function registerNew(string $table, array $data, ?string $key = null): void
    {
        $key = $key ?? uniqid('new_', true);
        $this->newEntities[$key] = ['table' => $table, 'data' => $data];
    }

    /**
     * Register an entity to be updated
     *
     * @param string $table Table name
     * @param string $uuid Entity UUID
     * @param array<string, mixed> $data Updated data
     * @param string|null $key Optional key for tracking
     */
    public function registerDirty(string $table, string $uuid, array $data, ?string $key = null): void
    {
        $key = $key ?? $uuid;
        $this->dirtyEntities[$key] = ['table' => $table, 'uuid' => $uuid, 'data' => $data];
    }

    /**
     * Register an entity to be deleted
     *
     * @param string $table Table name
     * @param string $uuid Entity UUID
     * @param string|null $key Optional key for tracking
     */
    public function registerRemoved(string $table, string $uuid, ?string $key = null): void
    {
        $key = $key ?? $uuid;
        $this->removedEntities[$key] = ['table' => $table, 'uuid' => $uuid];
    }

    /**
     * Commit all registered changes in a transaction
     *
     * @return array<string, array<mixed>> Results of all operations
     * @throws DatabaseException If commit fails
     */
    public function commit(): array
    {
        if ($this->isCommitting) {
            throw new DatabaseException('Unit of Work is already committing');
        }

        if ($this->isEmpty()) {
            return ['new' => [], 'updated' => [], 'removed' => []];
        }

        $this->isCommitting = true;

        try {
            $pdo = $this->db->getPDO();
            $pdo->beginTransaction();

            try {
                $results = [
                    'new' => $this->commitNewEntities(),
                    'updated' => $this->commitDirtyEntities(),
                    'removed' => $this->commitRemovedEntities()
                ];

                $pdo->commit();
                $this->clear();
                return $results;
            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            throw new DatabaseException('Unit of Work commit failed: ' . $e->getMessage(), 0);
        } finally {
            $this->isCommitting = false;
        }
    }

    /**
     * Rollback all changes and clear the unit of work
     */
    public function rollback(): void
    {
        $this->clear();
        $this->isCommitting = false;
    }

    /**
     * Check if there are any registered changes
     */
    public function isEmpty(): bool
    {
        return $this->newEntities === [] && $this->dirtyEntities === [] && $this->removedEntities === [];
    }

    /**
     * Clear all registered entities
     */
    public function clear(): void
    {
        $this->newEntities = [];
        $this->dirtyEntities = [];
        $this->removedEntities = [];
    }

    /**
     * Get count of registered entities by type
     * @return array<string, int>
     */
    public function getEntityCounts(): array
    {
        return [
            'new' => count($this->newEntities),
            'dirty' => count($this->dirtyEntities),
            'removed' => count($this->removedEntities)
        ];
    }

    /**
     * Commit new entities
     * @return array<mixed>
     */
    private function commitNewEntities(): array
    {
        $results = [];

        foreach ($this->newEntities as $key => $entity) {
            $result = $this->db->table($entity['table'])->insert($entity['data']);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Commit dirty entities
     * @return array<mixed>
     */
    private function commitDirtyEntities(): array
    {
        $results = [];

        foreach ($this->dirtyEntities as $key => $entity) {
            $result = $this->db->table($entity['table'])
                ->where(['uuid' => $entity['uuid']])
                ->update($entity['data']);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Commit removed entities
     * @return array<mixed>
     */
    private function commitRemovedEntities(): array
    {
        $results = [];

        foreach ($this->removedEntities as $key => $entity) {
            $result = $this->db->table($entity['table'])
                ->where(['uuid' => $entity['uuid']])
                ->delete();
            $results[$key] = $result;
        }

        return $results;
    }
}
