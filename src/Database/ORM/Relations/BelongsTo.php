<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;

/**
 * Belongs To Relation
 *
 * Represents the inverse of a one-to-one or one-to-many relationship
 * where the current model contains the foreign key. For example,
 * a Post belongsTo a User.
 */
class BelongsTo extends Relation
{
    /**
     * The foreign key of the parent model
     */
    protected string $foreignKey;

    /**
     * The owner key on the related model
     */
    protected string $ownerKey;

    /**
     * The name of the relationship
     */
    protected string $relationName;

    /**
     * Create a new belongs to relationship instance
     *
     * @param Builder $query
     * @param object $parent
     * @param string $foreignKey
     * @param string $ownerKey
     * @param string $relationName
     */
    public function __construct(
        Builder $query,
        object $parent,
        string $foreignKey,
        string $ownerKey,
        string $relationName
    ) {
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
        $this->relationName = $relationName;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query
     *
     * @return void
     */
    public function addConstraints(): void
    {
        $table = $this->related->getTable();

        $this->query->where(
            "{$table}.{$this->ownerKey}",
            '=',
            $this->parent->{$this->foreignKey}
        );
    }

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<object> $models
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->foreignKey);

        $table = $this->related->getTable();

        $this->query->whereIn("{$table}.{$this->ownerKey}", $keys);
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
            $model->setRelation($relation, null);
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
        // Build a dictionary of results keyed by owner key
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->{$this->ownerKey}] = $result;
        }

        // Match results to their children
        foreach ($models as $model) {
            $key = $model->{$this->foreignKey};
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }

        return $models;
    }

    /**
     * Get the results of the relationship
     *
     * @return object|null
     */
    public function getResults(): ?object
    {
        if ($this->parent->{$this->foreignKey} === null) {
            return null;
        }

        return $this->query->first();
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
     * Get the owner key for the relationship
     *
     * @return string
     */
    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }

    /**
     * Get the name of the relationship
     *
     * @return string
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }

    /**
     * Associate the model with the given parent
     *
     * @param object|mixed $model
     * @return object
     */
    public function associate(mixed $model): object
    {
        $ownerKey = $model instanceof \Glueful\Database\ORM\Model
            ? $model->{$this->ownerKey}
            : $model;

        $this->parent->{$this->foreignKey} = $ownerKey;

        if ($model instanceof \Glueful\Database\ORM\Model) {
            $this->parent->setRelation($this->relationName, $model);
        }

        return $this->parent;
    }

    /**
     * Dissociate previously associated model from the given parent
     *
     * @return object
     */
    public function dissociate(): object
    {
        $this->parent->{$this->foreignKey} = null;
        $this->parent->setRelation($this->relationName, null);

        return $this->parent;
    }
}
