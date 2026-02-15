<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Scopes;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Contracts\ExtendsBuilder;
use Glueful\Database\ORM\Contracts\Scope;
use Glueful\Database\ORM\Contracts\SoftDeletable;
use Glueful\Database\ORM\Model;

/**
 * Soft Deleting Scope
 *
 * A global scope that automatically filters out soft-deleted records
 * from query results. This scope is applied to all models using the
 * SoftDeletes trait.
 */
class SoftDeletingScope implements Scope, ExtendsBuilder
{
    /**
     * All of the extensions to be added to the builder
     *
     * @var array<string>
     */
    protected array $extensions = ['Restore', 'RestoreOrCreate', 'WithTrashed', 'WithoutTrashed', 'OnlyTrashed'];

    /**
     * Apply the scope to a given query builder
     *
     * @param Builder $builder
     * @param object $model The model instance (must use SoftDeletes trait)
     * @return void
     */
    public function apply(Builder $builder, object $model): void
    {
        /** @var SoftDeletable $model */
        $builder->whereNull($model->getQualifiedDeletedAtColumn());
    }

    /**
     * Extend the query builder with the needed functions
     *
     * @param Builder $builder
     * @return void
     */
    public function extend(Builder $builder): void
    {
        foreach ($this->extensions as $extension) {
            /** @phpstan-ignore-next-line Variable method call is intentional */
            $this->{"add{$extension}"}($builder);
        }

        // Override forceDelete on the builder
        $builder->onDelete(function (Builder $builder) {
            $column = $this->getDeletedAtColumn($builder);

            return $builder->update([
                $column => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    /**
     * Get the "deleted at" column for the builder
     *
     * @param Builder $builder
     * @return string
     */
    protected function getDeletedAtColumn(Builder $builder): string
    {
        /** @var SoftDeletable $model */
        $model = $builder->getModel();

        if (count($builder->getQuery()->joins ?? []) > 0) {
            return $model->getQualifiedDeletedAtColumn();
        }

        return $model->getDeletedAtColumn();
    }

    /**
     * Add the restore extension to the builder
     *
     * @param Builder $builder
     * @return void
     */
    protected function addRestore(Builder $builder): void
    {
        $builder->macro('restore', function (Builder $builder) {
            /** @phpstan-ignore-next-line Dynamic macro method */
            $builder->withTrashed();

            /** @var SoftDeletable $model */
            $model = $builder->getModel();

            return $builder->update([
                $model->getDeletedAtColumn() => null,
            ]);
        });
    }

    /**
     * Add the restore-or-create extension to the builder
     *
     * @param Builder $builder
     * @return void
     */
    protected function addRestoreOrCreate(Builder $builder): void
    {
        $builder->macro('restoreOrCreate', function (Builder $builder, array $attributes = [], array $values = []) {
            /** @phpstan-ignore-next-line Dynamic macro method */
            $builder->withTrashed();

            $instance = $builder->where($attributes)->first();

            if ($instance !== null) {
                /** @var SoftDeletable $instance */
                $instance->restore();
                return $instance;
            }

            return $builder->create(array_merge($attributes, $values));
        });
    }

    /**
     * Add the with-trashed extension to the builder
     *
     * @param Builder $builder
     * @return void
     */
    protected function addWithTrashed(Builder $builder): void
    {
        $builder->macro('withTrashed', function (Builder $builder, bool $withTrashed = true) {
            if (!$withTrashed) {
                /** @phpstan-ignore-next-line Dynamic macro method */
                return $builder->withoutTrashed();
            }

            return $builder->withoutGlobalScope('softDeletes');
        });
    }

    /**
     * Add the without-trashed extension to the builder
     *
     * @param Builder $builder
     * @return void
     */
    protected function addWithoutTrashed(Builder $builder): void
    {
        $builder->macro('withoutTrashed', function (Builder $builder) {
            /** @var SoftDeletable $model */
            $model = $builder->getModel();

            $builder->withoutGlobalScope('softDeletes')
                ->whereNull($model->getQualifiedDeletedAtColumn());

            return $builder;
        });
    }

    /**
     * Add the only-trashed extension to the builder
     *
     * @param Builder $builder
     * @return void
     */
    protected function addOnlyTrashed(Builder $builder): void
    {
        $builder->macro('onlyTrashed', function (Builder $builder) {
            /** @var SoftDeletable $model */
            $model = $builder->getModel();

            $builder->withoutGlobalScope('softDeletes')
                ->whereNotNull($model->getQualifiedDeletedAtColumn());

            return $builder;
        });
    }
}
