<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Contracts;

/**
 * SoftDeletable Interface
 *
 * Defines the contract for models that support soft deletes.
 * This interface is implemented by models using the SoftDeletes trait.
 */
interface SoftDeletable
{
    /**
     * Get the name of the "deleted at" column
     *
     * @return string
     */
    public function getDeletedAtColumn(): string;

    /**
     * Get the fully qualified "deleted at" column
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn(): string;

    /**
     * Determine if the model instance has been soft-deleted
     *
     * @return bool
     */
    public function trashed(): bool;

    /**
     * Restore a soft-deleted model instance
     *
     * @return bool
     */
    public function restore(): bool;

    /**
     * Force a hard delete on a soft deleted model
     *
     * @return bool
     */
    public function forceDelete(): bool;

    /**
     * Determine if the model is currently force deleting
     *
     * @return bool
     */
    public function isForceDeleting(): bool;
}
