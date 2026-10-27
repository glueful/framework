<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Scopes\SoftDeletingScope;

/**
 * SoftDeletes Trait
 *
 * Provides soft delete functionality for ORM models. When a model uses this trait,
 * calling delete() will set the deleted_at timestamp instead of removing the record.
 *
 * @example
 * class User extends Model
 * {
 *     use SoftDeletes;
 * }
 *
 * $user->delete();           // Sets deleted_at
 * $user->trashed();          // Returns true
 * $user->restore();          // Clears deleted_at
 * $user->forceDelete();      // Permanently deletes
 *
 * User::withTrashed()->get();   // Include soft deleted
 * User::onlyTrashed()->get();   // Only soft deleted
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting
     */
    protected bool $forceDeleting = false;

    /**
     * Boot the soft deleting trait for a model
     *
     * @return void
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope('softDeletes', new SoftDeletingScope());
    }

    /**
     * Force a hard delete on a soft deleted model
     *
     * Fires "forceDeleting" (a false return cancels), then the regular "deleting"/"deleted"
     * pair, then "forceDeleted". The row is physically removed, trashed or not.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent('forceDeleting') === false) {
            return false;
        }

        $this->forceDeleting = true;

        try {
            $deleted = $this->delete();
        } finally {
            $this->forceDeleting = false;
        }

        if ($deleted) {
            $this->exists = false;
            $this->fireModelEvent('forceDeleted', false);
        }

        return $deleted;
    }

    /**
     * Perform the actual delete query on this model instance
     *
     * @return void
     */
    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            // Bypass the soft-delete scope (the row may already be trashed) and issue a real
            // DELETE — Builder::forceDelete() skips the QueryBuilder's soft-delete default.
            $this->newModelQuery()
                ->withoutGlobalScope('softDeletes')
                ->where($this->getKeyName(), '=', $this->getKey())
                ->forceDelete();

            $this->exists = false;

            return;
        }

        $this->runSoftDelete();
    }

    /**
     * Perform the actual soft delete
     *
     * The instance keeps existing (its row is still there), so it can be restored or
     * force-deleted afterwards.
     *
     * @return void
     */
    protected function runSoftDelete(): void
    {
        $query = $this->newModelQuery()
            ->withoutGlobalScope('softDeletes')
            ->where($this->getKeyName(), '=', $this->getKey());

        $time = $this->fromDateTime($this->freshTimestamp());

        $columns = [$this->getDeletedAtColumn() => $time];

        $updatedAtColumn = $this->usesTimestamps() ? $this->getUpdatedAtColumn() : null;
        if ($updatedAtColumn !== null) {
            $columns[$updatedAtColumn] = $time;
        }

        $query->update($columns);

        // Mirror the write on the instance and mark those columns clean.
        foreach ($columns as $column => $value) {
            $this->setAttribute($column, $value);
            $this->original[$column] = $this->attributes[$column];
        }

        $this->fireModelEvent('trashed', false);
    }

    /**
     * Restore a soft-deleted model instance
     *
     * Fires "restoring" (a false return cancels) and, once saved, "restored".
     *
     * @return bool
     */
    public function restore(): bool
    {
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->setAttribute($this->getDeletedAtColumn(), null);

        $this->exists = true;

        $result = $this->save();

        if ($result) {
            $this->fireModelEvent('restored', false);
        }

        return $result;
    }

    /**
     * Determine if the model instance has been soft-deleted
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->{$this->getDeletedAtColumn()} !== null;
    }

    /**
     * Register a "restoring" model event callback
     *
     * @param callable $callback
     * @return void
     */
    public static function restoring(callable $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" model event callback
     *
     * @param callable $callback
     * @return void
     */
    public static function restored(callable $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Register a "trashed" model event callback
     *
     * @param callable $callback
     * @return void
     */
    public static function onTrashed(callable $callback): void
    {
        static::registerModelEvent('trashed', $callback);
    }

    /**
     * Register a "forceDeleting" model event callback (return false to cancel)
     *
     * @param callable $callback
     * @return void
     */
    public static function forceDeleting(callable $callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /**
     * Register a "forceDeleted" model event callback
     *
     * @param callable $callback
     * @return void
     */
    public static function forceDeleted(callable $callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /**
     * Determine if the model is currently force deleting
     *
     * @return bool
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Get the name of the "deleted at" column
     *
     * @return string
     */
    public function getDeletedAtColumn(): string
    {
        if (defined(static::class . '::DELETED_AT')) {
            /** @var string $column */
            $column = constant(static::class . '::DELETED_AT');
            return $column;
        }

        return 'deleted_at';
    }

    /**
     * Get the fully qualified "deleted at" column
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->getTable() . '.' . $this->getDeletedAtColumn();
    }
}
