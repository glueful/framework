<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;

/**
 * Has Many Relation
 *
 * Represents a one-to-many relationship where the related models
 * contain the foreign key. For example, a User hasMany Posts.
 */
class HasMany extends Relation
{
    /**
     * The foreign key of the related model
     */
    protected string $foreignKey;

    /**
     * The local key on the parent model
     */
    protected string $localKey;

    /**
     * Create a new has many relationship instance
     *
     * @param Builder $query
     * @param object $parent
     * @param string $foreignKey
     * @param string $localKey
     */
    public function __construct(Builder $query, object $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query
     *
     * @return void
     */
    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, '=', $this->parent->{$this->localKey});
    }

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<object> $models
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);

        $this->query->whereIn($this->foreignKey, $keys);
    }

    /**
     * Initialize the relation on a set of models
     *
     * @param array<object> $models
     * @param string $relation
     * @return array<object>
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection([]));
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents
     *
     * @param array<object> $models
     * @param Collection $results
     * @param string $relation
     * @return array<object>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        // Build a dictionary of results keyed by foreign key
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            if (!isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }
            $dictionary[$key][] = $result;
        }

        // Match results to their parents
        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }

        return $models;
    }

    /**
     * Get the results of the relationship
     *
     * @return Collection
     */
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Get the foreign key for the relationship
     *
     * @return string
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key for the relationship
     *
     * @return string
     */
    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }
}
