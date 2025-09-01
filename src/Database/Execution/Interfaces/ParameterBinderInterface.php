<?php

declare(strict_types=1);

namespace Glueful\Database\Execution\Interfaces;

/**
 * ParameterBinder Interface
 *
 * Defines the contract for parameter binding functionality.
 * This interface ensures consistent parameter binding across
 * different implementations.
 */
interface ParameterBinderInterface
{
    /**
     * Flatten bindings to prevent nested arrays
     * 
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>
     */
    public function flattenBindings(array $bindings): array;

    /**
     * Bind parameters to a prepared statement
     * 
     * @param array<string, mixed> $bindings
     */
    public function bindParameters(\PDOStatement $statement, array $bindings): void;

    /**
     * Sanitize parameter for logging (remove sensitive data)
     */
    public function sanitizeForLog(mixed $parameter): mixed;

    /**
     * Sanitize array of parameters for logging
     * 
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>
     */
    public function sanitizeBindingsForLog(array $bindings): array;

    /**
     * Validate parameter type
     */
    public function validateParameter(mixed $parameter): bool;
}
