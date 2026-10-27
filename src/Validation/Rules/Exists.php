<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\DatabaseAwareRule;
use Glueful\Validation\Support\ResolvesRuleConnection;

/**
 * Exists rule - validates that a value exists in a database table
 *
 * The Validator supplies its connection; fails closed (LogicException) when no
 * connection is available.
 *
 * @example
 * new Exists('users', 'id')           // Check if ID exists in users table
 * new Exists('categories', 'slug')    // Check if slug exists in categories
 */
final class Exists implements DatabaseAwareRule
{
    use ResolvesRuleConnection;

    public function __construct(
        private string $table,
        private string $column = 'id'
    ) {
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

        $pdo = $this->resolvePdo($context, 'exists', (string) $field);

        // Validate table and column names to prevent SQL injection
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table) !== 1) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->column) !== 1) {
            throw new \InvalidArgumentException('Invalid column name.');
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM {$this->table} WHERE {$this->column} = :value LIMIT 1"
        );
        $stmt->execute(['value' => $value]);

        if ($stmt->fetchColumn() === false) {
            return "The selected {$field} is invalid.";
        }

        return null;
    }
}
