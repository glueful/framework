<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Contracts;

use Glueful\Database\ORM\Builder;

/**
 * Scope Interface
 *
 * Defines the contract for global query scopes that can be applied
 * to all queries for a model. Global scopes are automatically applied
 * when querying the model, unless explicitly removed.
 *
 * @example
 * class ActiveScope implements Scope
 * {
 *     public function apply(Builder $builder, Model $model): void
 *     {
 *         $builder->where('is_active', true);
 *     }
 * }
 */
interface Scope
{
    /**
     * Apply the scope to a given query builder
     *
     * @param Builder $builder The query builder instance
     * @param object $model The model instance
     * @return void
     */
    public function apply(Builder $builder, object $model): void;
}
