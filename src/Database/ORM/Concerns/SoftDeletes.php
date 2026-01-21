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
     * Initialize the soft deleting trait for an instance
     *
     * @return void
     */
    public function initializeSoftDeletes(): void
    {
        // Ensure deleted_at is in the dates array for proper casting
        if (!in_array($this->getDeletedAtColumn(), $this->getDates(), true)) {
            $this->dates[] = $this->getDeletedAtColumn();
        }
    }

    /**
     * Force a hard delete on a soft deleted model
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        $this->forceDeleting = true;

        return tap($this->delete(), function () {
            $this->forceDeleting = false;
            $this->exists = false;
        });
    }

    /**
     * Perform the actual delete query on this model instance
     *
     * @return void
     */
    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            $this->newModelQuery()
                ->where($this->getKeyName(), $this->getKey())
                ->forceDelete();

            $this->exists = false;

            return;
        }

        $this->runSoftDelete();
    }

    /**
     * Perform the actual soft delete
     *
     * @return void
     */
    protected function runSoftDelete(): void
    {
        $query = $this->newModelQuery()
            ->where($this->getKeyName(), $this->getKey());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && $this->getUpdatedAtColumn() !== null) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttribute($this->getDeletedAtColumn());

        // Fire the trashed event
        $this->fireModelEvent('trashed', false);
    }

    /**
     * Restore a soft-deleted model instance
     *
     * @return bool
     */
    public function restore(): bool
    {
        // Fire restoring event
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->{$this->getDeletedAtColumn()} = null;

        $this->exists = true;

        $result = $this->save();

        // Fire restored event
        $this->fireModelEvent('restored', false);

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

/**
 * Helper function for tap pattern
 *
 * @template T
 * @param T $value
 * @param callable(T): void $callback
 * @return T
 */
function tap(mixed $value, callable $callback): mixed
{
    $callback($value);

    return $value;
}
