<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

/**
 * Pivot Model
 *
 * Represents a row in a pivot/junction table for many-to-many relationships.
 * Provides access to pivot table attributes when retrieving related models.
 *
 * @example
 * // Access pivot data on a related model
 * $user->roles->each(function ($role) {
 *     echo $role->pivot->assigned_at;
 * });
 */
class Pivot
{
    /**
     * The pivot attributes
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The pivot table name
     */
    protected string $table;

    /**
     * Create a new pivot instance
     *
     * @param array<string, mixed> $attributes
     * @param string $table
     */
    public function __construct(array $attributes, string $table)
    {
        $this->attributes = $attributes;
        $this->table = $table;
    }

    /**
     * Get an attribute from the pivot
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set an attribute on the pivot
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Check if an attribute exists
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Get all pivot attributes
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get the pivot table name
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Convert pivot to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
