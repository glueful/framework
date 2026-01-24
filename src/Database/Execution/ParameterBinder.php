<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

use PDOStatement;
use Glueful\Database\Execution\Interfaces\ParameterBinderInterface;

/**
 * ParameterBinder
 *
 * Handles parameter binding and sanitization for database queries.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class ParameterBinder implements ParameterBinderInterface
{
    /**
     * @var array<int, string>
     */
    protected array $sensitiveFields = [
        'password',
        'password_hash',
        'api_key',
        'secret',
        'token',
        'private_key',
        'access_token',
        'refresh_token'
    ];

    /**
     * Flatten bindings to prevent nested arrays and normalize types for database
     */
    public function flattenBindings(array $bindings): array
    {
        $flattened = [];
        foreach ($bindings as $key => $binding) {
            if (is_array($binding)) {
                // Convert array to JSON string to prevent array to string conversion
                $flattened[$key] = json_encode($binding);
            } elseif (is_bool($binding)) {
                // Convert PHP booleans to integers for cross-database compatibility
                // Integer representation (0/1) is universally accepted by all SQL databases
                $flattened[$key] = $binding ? 1 : 0;
            } else {
                $flattened[$key] = $binding;
            }
        }
        return $flattened;
    }

    /**
     * Bind parameters to a prepared statement
     */
    public function bindParameters(PDOStatement $statement, array $bindings): void
    {
        $flattenedBindings = $this->flattenBindings($bindings);

        foreach ($flattenedBindings as $index => $value) {
            $parameterIndex = is_string($index) ? $index : $index + 1; // PDO parameters are 1-indexed for numeric keys

            if (!$this->validateParameter($value)) {
                throw new \InvalidArgumentException("Invalid parameter type at index {$index}");
            }

            $statement->bindValue($parameterIndex, $value);
        }
    }

    /**
     * Sanitize parameter for logging (remove sensitive data)
     */
    public function sanitizeForLog(mixed $parameter): mixed
    {
        if (is_string($parameter) && $this->isSensitiveValue($parameter)) {
            return '[REDACTED]';
        }

        if (is_array($parameter)) {
            return array_map([$this, 'sanitizeForLog'], $parameter);
        }

        return $parameter;
    }

    /**
     * Sanitize array of parameters for logging
     */
    public function sanitizeBindingsForLog(array $bindings): array
    {
        return array_map([$this, 'sanitizeForLog'], $bindings);
    }

    /**
     * Validate parameter type
     */
    public function validateParameter(mixed $parameter): bool
    {
        // Allow common parameter types
        return is_null($parameter) ||
               is_scalar($parameter) ||
               is_array($parameter);
    }

    /**
     * Check if a value appears to be sensitive
     */
    protected function isSensitiveValue(string $value): bool
    {
        // Check if value looks like a hash, token, or key
        if (strlen($value) > 20 && ctype_alnum(str_replace(['_', '-'], '', $value))) {
            return true;
        }

        // Check for common sensitive patterns
        $sensitivePatterns = [
            '/^[a-f0-9]{32,}$/i',  // MD5, SHA hashes
            '/^[A-Za-z0-9+\/]{20,}={0,2}$/', // Base64 encoded
            '/^sk_[a-zA-Z0-9_]+$/', // Stripe-style secret keys
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add sensitive field patterns
     */
    public function addSensitiveField(string $field): void
    {
        if (!in_array($field, $this->sensitiveFields, true)) {
            $this->sensitiveFields[] = $field;
        }
    }

    /**
     * Get list of sensitive fields
     */
    /**
     * @return array<int, string>
     */
    public function getSensitiveFields(): array
    {
        return $this->sensitiveFields;
    }
}
