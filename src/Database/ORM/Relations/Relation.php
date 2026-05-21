<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;

/**
 * Abstract Relation
 *
 * Base class for all ORM relationships. Provides common functionality
 * for defining and executing relationship queries between models.
 */
abstract class Relation
{
    /**
     * The query builder instance
     */
    protected Builder $query;

    /**
     * The parent model instance
     */
    protected object $parent;

    /**
     * The related model instance
     */
    protected object $related;

    /**
     * Indicates if the relation has been loaded
     */
    protected bool $loaded = false;

    /**
     * Indicates whether constraints should be skipped on construction.
     * Set to true via noConstraints() when building eager-load queries.
     */
    protected static bool $constraints = true;

    /**
     * Create a new relation instance
     *
     * @param Builder $query
     * @param object $parent
     */
    public function __construct(Builder $query, object $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        if (static::$constraints) {
            $this->addConstraints();
        }
    }

    /**
     * Run a callback with constraints disabled.
     *
     * Used by the eager loader to obtain a clean relation query without the
     * single-model WHERE clause that addConstraints() normally adds.
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public static function noConstraints(callable $callback): mixed
    {
        static::$constraints = false;
        try {
            return $callback();
        } finally {
            static::$constraints = true;
        }
    }

    /**
     * Set the base constraints on the relation query
     *
     * @return void
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<object> $models
     * @return void
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialize the relation on a set of models
     *
     * @param array<object> $models
     * @param string $relation
     * @return array<object>
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents
     *
     * @param array<object> $models
     * @param Collection $results
     * @param string $relation
     * @return array<object>
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Get the results of the relationship
     *
     * @return mixed
     */
    abstract public function getResults(): mixed;

    /**
     * Get the relationship query
     *
     * @return Builder
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the parent model
     *
     * @return object
     */
    public function getParent(): object
    {
        return $this->parent;
    }

    /**
     * Get the related model
     *
     * @return object
     */
    public function getRelated(): object
    {
        return $this->related;
    }

    /**
     * Get all of the primary keys for the relation
     *
     * @param array<object> $models
     * @param string|null $key
     * @return array<mixed>
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $key !== null ? $model->{$key} : $model->getKey();

            if ($value !== null) {
                $keys[] = $value;
            }
        }

        return array_unique($keys);
    }

    /**
     * Execute the query and get the first result
     *
     * @return object|null
     */
    public function first(): ?object
    {
        return $this->query->first();
    }

    /**
     * Execute the query and get all results
     *
     * @return Collection
     */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /**
     * Handle dynamic method calls to the relationship
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->$method(...$parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}
