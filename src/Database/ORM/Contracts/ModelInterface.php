<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Contracts;

/**
 * Model Interface
 *
 * Defines the contract for Active Record models in the ORM.
 */
interface ModelInterface
{
    /**
     * Get the table name for the model
     */
    public function getTable(): string;

    /**
     * Get the primary key for the model
     */
    public function getKeyName(): string;

    /**
     * Get the value of the model's primary key
     */
    public function getKey(): mixed;

    /**
     * Save the model to the database
     */
    public function save(): bool;

    /**
     * Delete the model from the database
     */
    public function delete(): bool;

    /**
     * Fill the model with an array of attributes
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static;

    /**
     * Get all of the current attributes on the model
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array;

    /**
     * Convert the model instance to an array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
