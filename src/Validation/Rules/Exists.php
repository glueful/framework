<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use PDO;

/**
 * Exists rule - validates that a value exists in a database table
 *
 * @example
 * new Exists('users', 'id')           // Check if ID exists in users table
 * new Exists('categories', 'slug')    // Check if slug exists in categories
 */
final class Exists implements Rule
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $table,
        private string $column = 'id'
    ) {
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

        // Get PDO from context or container
        $pdo = $this->pdo;

        if ($pdo === null && isset($context['pdo'])) {
            $pdo = $context['pdo'];
        }

        if ($pdo === null && function_exists('app')) {
            try {
                $pdo = app()->get(PDO::class);
            } catch (\Throwable) {
                // PDO not available in container
            }
        }

        if ($pdo === null) {
            // If we can't access the database, skip the check
            // This allows the rule to be used without a database connection in tests
            return null;
        }

        // Validate table and column names to prevent SQL injection
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->column)) {
            throw new \InvalidArgumentException('Invalid column name.');
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM {$this->table} WHERE {$this->column} = :value LIMIT 1"
        );
        $stmt->execute(['value' => $value]);

        if (!$stmt->fetchColumn()) {
            return "The selected {$field} is invalid.";
        }

        return null;
    }
}
