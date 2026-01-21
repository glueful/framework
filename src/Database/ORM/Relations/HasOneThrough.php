<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Model;

/**
 * Has One Through Relation
 *
 * Provides access to a distant relation through an intermediate model.
 * For example, a Country hasOneThrough User through a Supplier.
 *
 * @example
 * // countries -> suppliers -> users
 * // A country has one user through a supplier
 * public function userThroughSupplier(): HasOneThrough
 * {
 *     return $this->hasOneThrough(User::class, Supplier::class);
 * }
 */
class HasOneThrough extends Relation
{
    /**
     * The "through" model instance
     */
    protected Model $throughParent;

    /**
     * The far parent model instance
     */
    protected Model $farParent;

    /**
     * The first key on the intermediate model
     */
    protected string $firstKey;

    /**
     * The second key on the final model
     */
    protected string $secondKey;

    /**
     * The local key on the far parent model
     */
    protected string $localKey;

    /**
     * The local key on the intermediate model
     */
    protected string $secondLocalKey;

    /**
     * Create a new has one through relationship instance
     *
     * @param Builder $query
     * @param Model $farParent
     * @param Model $throughParent
     * @param string $firstKey
     * @param string $secondKey
     * @param string $localKey
     * @param string $secondLocalKey
     */
    public function __construct(
        Builder $query,
        Model $farParent,
        Model $throughParent,
        string $firstKey,
        string $secondKey,
        string $localKey,
        string $secondLocalKey
    ) {
        $this->throughParent = $throughParent;
        $this->farParent = $farParent;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->localKey = $localKey;
        $this->secondLocalKey = $secondLocalKey;

        parent::__construct($query, $farParent);
    }

    /**
     * Set the base constraints on the relation query
     *
     * @return void
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        $localValue = $this->farParent->{$this->localKey};

        $this->query->where(
            $this->getQualifiedFirstKeyName(),
            '=',
            $localValue
        );
    }

    /**
     * Set the join clause on the query
     *
     * @return void
     */
    protected function performJoin(): void
    {
        $throughTable = $this->throughParent->getTable();
        $farTable = $this->related->getTable();

        $this->query->join(
            $throughTable,
            "{$throughTable}.{$this->secondLocalKey}",
            '=',
            "{$farTable}.{$this->secondKey}"
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
        $keys = $this->getKeys($models, $this->localKey);

        $this->query->whereIn($this->getQualifiedFirstKeyName(), $keys);
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
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->localKey};

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    /**
     * Build a dictionary from the results
     *
     * @param Collection $results
     * @return array<mixed, Model>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];
        $throughTable = $this->throughParent->getTable();

        foreach ($results as $result) {
            $key = $result->{"laravel_through_key"} ?? null;

            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Get the results of the relationship
     *
     * @return Model|null
     */
    public function getResults(): ?Model
    {
        $parentKey = $this->farParent->{$this->localKey};

        if ($parentKey === null) {
            return null;
        }

        return $this->first();
    }

    /**
     * Execute the query and get the first result
     *
     * @return Model|null
     */
    public function first(): ?Model
    {
        $results = $this->get();

        return $results->first();
    }

    /**
     * Execute the query and get all results
     *
     * @return Collection
     */
    public function get(): Collection
    {
        $columns = $this->shouldSelect(['*']);

        $results = $this->query->getQuery()->select($columns)->get();

        $models = [];
        foreach ($results as $result) {
            $models[] = $this->related->newFromBuilder((array) $result);
        }

        return new Collection($models);
    }

    /**
     * Set the columns to be selected
     *
     * @param array<string> $columns
     * @return array<string>
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        $farTable = $this->related->getTable();
        $throughTable = $this->throughParent->getTable();

        if ($columns === ['*']) {
            $columns = ["{$farTable}.*"];
        }

        // Add the through key for matching
        $columns[] = "{$throughTable}.{$this->firstKey} as laravel_through_key";

        return $columns;
    }

    /**
     * Get the qualified first key name
     *
     * @return string
     */
    public function getQualifiedFirstKeyName(): string
    {
        return "{$this->throughParent->getTable()}.{$this->firstKey}";
    }

    /**
     * Get the first key name
     *
     * @return string
     */
    public function getFirstKeyName(): string
    {
        return $this->firstKey;
    }

    /**
     * Get the second key name
     *
     * @return string
     */
    public function getSecondKeyName(): string
    {
        return $this->secondKey;
    }

    /**
     * Get the local key name
     *
     * @return string
     */
    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    /**
     * Get the second local key name
     *
     * @return string
     */
    public function getSecondLocalKeyName(): string
    {
        return $this->secondLocalKey;
    }
}
