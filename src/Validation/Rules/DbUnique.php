<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Validation\Contracts\Rule;
use PDO;

/**
 * DbUnique rule - validates that a value is unique in a database table
 *
 * Supports both direct PDO injection and container-resolved PDO.
 *
 * @example
 * // Direct PDO injection
 * new DbUnique($pdo, 'users', 'email')
 *
 * // String syntax (PDO resolved from container)
 * 'unique:users,email'
 * 'unique:users,email,123'  // Exclude ID 123
 */
final class DbUnique implements Rule
{
    private ?PDO $pdo;
    private string $table;
    private ?string $column;
    private string|int|null $exceptId;

    /**
     * Create a new DbUnique rule
     *
     * @param PDO|string $pdoOrTable PDO instance or table name
     * @param string|null $tableOrColumn Table name (if PDO given) or column name
     * @param string|null $columnOrExceptId Column name (if PDO given) or except ID
     * @param string|int|null $exceptId ID to exclude from uniqueness check
     */
    public function __construct(
        PDO|string $pdoOrTable,
        ?string $tableOrColumn = null,
        string|int|null $columnOrExceptId = null,
        string|int|null $exceptId = null
    ) {
        // Support both constructor signatures:
        // 1. new DbUnique($pdo, 'table', 'column')  - Original signature
        // 2. new DbUnique('table', 'column', $exceptId)  - String syntax

        if ($pdoOrTable instanceof PDO) {
            // Original signature with PDO
            $this->pdo = $pdoOrTable;
            $this->table = $tableOrColumn ?? '';
            $this->column = is_string($columnOrExceptId) ? $columnOrExceptId : null;
            $this->exceptId = $exceptId;
        } else {
            // String syntax without PDO
            $this->pdo = null;
            $this->table = $pdoOrTable;
            $this->column = $tableOrColumn;
            $this->exceptId = $columnOrExceptId;
        }
    }

    /**
     * Set PDO instance for database connection
     */
    public function setPdo(PDO $pdo): self
    {
        $this->pdo = $pdo;
        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = $context['field'] ?? 'field';
        $column = $this->column ?? $field;

        // Get PDO from context or container
        $pdo = $this->pdo;

        if ($pdo === null && isset($context['pdo'])) {
            $pdo = $context['pdo'];
        }

        if ($pdo === null && ($context['context'] ?? null) instanceof ApplicationContext) {
            try {
                $pdo = container($context['context'])->get(PDO::class);
            } catch (\Throwable) {
                // PDO not available in container
            }
        }

        if ($pdo === null) {
            // If we can't access the database, skip the check
            return null;
        }

        // Validate table and column names to prevent SQL injection
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException('Invalid column name.');
        }

        // Build query
        $sql = "SELECT 1 FROM {$this->table} WHERE {$column} = :value";
        $params = ['value' => $value];

        // Exclude specific ID if provided
        if ($this->exceptId !== null && $this->exceptId !== '') {
            $sql .= " AND id != :except_id";
            $params['except_id'] = $this->exceptId;
        }

        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            return "The {$field} has already been taken.";
        }

        return null;
    }
}
