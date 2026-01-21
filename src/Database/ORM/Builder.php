<?php

declare(strict_types=1);

namespace Glueful\Database\ORM;

use Closure;
use Glueful\Database\ORM\Contracts\Scope;
use Glueful\Database\QueryBuilder;
use Glueful\Http\Exceptions\Domain\ModelNotFoundException;

/**
 * ORM Query Builder
 *
 * Wraps the framework's QueryBuilder to provide model-specific functionality
 * including hydration, eager loading, scopes, and relationship handling.
 *
 * @template TModel of Model
 */
class Builder
{
    /**
     * The query builder instance
     */
    protected QueryBuilder $query;

    /**
     * The model being queried
     *
     * @var TModel
     */
    protected Model $model;

    /**
     * The relationships to be eager loaded
     *
     * @var array<string, Closure|null>
     */
    protected array $eagerLoad = [];

    /**
     * The relationship counts to be loaded
     *
     * @var array<string, Closure|null>
     */
    protected array $withCount = [];

    /**
     * Global scopes that have been removed
     *
     * @var array<string, bool>
     */
    protected array $removedScopes = [];

    /**
     * The columns that should be returned
     *
     * @var array<string>
     */
    protected array $columns = ['*'];

    /**
     * All registered builder macros
     *
     * @var array<string, Closure>
     */
    protected array $macros = [];

    /**
     * Custom delete callback (used by soft deletes)
     *
     * @var Closure|null
     */
    protected ?Closure $onDelete = null;

    /**
     * Create a new model query builder instance
     *
     * @param QueryBuilder $query
     */
    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Set the model instance being queried
     *
     * @param TModel $model
     * @return static
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;
        $this->query->from($model->getTable());

        // Extend the builder with global scope methods (macros)
        foreach ($model::getGlobalScopes() as $scope) {
            if ($scope instanceof Scope && method_exists($scope, 'extend')) {
                /** @phpstan-ignore-next-line Scope::extend is optional, checked via method_exists */
                $scope->extend($this);
            }
        }

        return $this;
    }

    /**
     * Get the model instance being queried
     *
     * @return TModel
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get the underlying query builder instance
     *
     * @return QueryBuilder
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Execute the query and get all results as a collection
     *
     * @param array<string> $columns
     * @return Collection<TModel>
     */
    public function get(array $columns = ['*']): Collection
    {
        $this->applyScopes();

        // Apply column selection if not using default
        if ($columns !== ['*']) {
            $this->query->select($columns);
        }

        $results = $this->query->get();

        // Hydrate results into models
        $models = $this->hydrate($results);

        // Eager load relationships
        if (count($this->eagerLoad) > 0 && count($models) > 0) {
            $models = $this->eagerLoadRelations($models);
        }

        return new Collection($models);
    }

    /**
     * Execute the query and get the first result
     *
     * @param array<string> $columns
     * @return TModel|null
     */
    public function first(array $columns = ['*']): ?Model
    {
        return $this->take(1)->get($columns)->first();
    }

    /**
     * Execute the query and get the first result or throw an exception
     *
     * @param array<string> $columns
     * @return TModel
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array $columns = ['*']): Model
    {
        $model = $this->first($columns);

        if ($model === null) {
            throw (new ModelNotFoundException())
                ->setModel($this->model::class);
        }

        return $model;
    }

    /**
     * Find a model by its primary key
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return TModel|null
     */
    public function find(mixed $id, array $columns = ['*']): ?Model
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns)->first();
        }

        return $this->where($this->model->getKeyName(), '=', $id)->first($columns);
    }

    /**
     * Find multiple models by their primary keys
     *
     * @param array<mixed> $ids
     * @param array<string> $columns
     * @return Collection<TModel>
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        if ($ids === []) {
            return new Collection([]);
        }

        return $this->whereIn($this->model->getKeyName(), $ids)->get($columns);
    }

    /**
     * Find a model by its primary key or throw an exception
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return TModel
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id, array $columns = ['*']): Model
    {
        $model = $this->find($id, $columns);

        if ($model === null) {
            throw (new ModelNotFoundException())
                ->setModel($this->model::class, $id);
        }

        return $model;
    }

    /**
     * Find a model by its primary key or return a new instance
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return TModel
     */
    public function findOrNew(mixed $id, array $columns = ['*']): Model
    {
        $model = $this->find($id, $columns);

        return $model ?? $this->model->newInstance();
    }

    /**
     * Get the first record matching the attributes or create it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->where($attributes)->first();

        if ($instance !== null) {
            return $instance;
        }

        $instance = $this->model->newInstance(array_merge($attributes, $values));
        $instance->save();

        return $instance;
    }

    /**
     * Get the first record matching the attributes or instantiate it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function firstOrNew(array $attributes, array $values = []): Model
    {
        $instance = $this->where($attributes)->first();

        if ($instance !== null) {
            return $instance;
        }

        return $this->model->newInstance(array_merge($attributes, $values));
    }

    /**
     * Create or update a record matching the attributes
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->where($attributes)->first();

        if ($instance !== null) {
            $instance->fill($values)->save();
            return $instance;
        }

        $instance = $this->model->newInstance(array_merge($attributes, $values));
        $instance->save();

        return $instance;
    }

    /**
     * Create a new model instance with the given attributes
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes = []): Model
    {
        $instance = $this->model->newInstance($attributes);
        $instance->save();

        return $instance;
    }

    /**
     * Update records in the database
     *
     * @param array<string, mixed> $values
     * @return int
     */
    public function update(array $values): int
    {
        $this->applyScopes();

        return $this->query->update($values);
    }

    /**
     * Delete records from the database
     *
     * If a custom onDelete callback is registered (e.g., by soft deletes),
     * that callback will be invoked instead of performing a hard delete.
     *
     * @return int
     */
    public function delete(): int
    {
        $this->applyScopes();

        // If a custom delete handler is set (e.g., soft deletes), use it
        if ($this->onDelete !== null) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->query->delete();
    }

    /**
     * Get the count of records
     *
     * @return int
     */
    public function count(): int
    {
        $this->applyScopes();

        return $this->query->count();
    }

    /**
     * Determine if any rows exist for the current query
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Determine if no rows exist for the current query
     *
     * @return bool
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Hydrate an array of database results into models
     *
     * @param array<array<string, mixed>> $results
     * @return array<TModel>
     */
    public function hydrate(array $results): array
    {
        $models = [];

        foreach ($results as $result) {
            $models[] = $this->model->newFromBuilder($result);
        }

        return $models;
    }

    /**
     * Eager load the relationships for the models
     *
     * @param array<TModel> $models
     * @return array<TModel>
     */
    protected function eagerLoadRelations(array $models): array
    {
        // Sort relations by depth so base relations load first
        $sorted = $this->sortEagerLoads($this->eagerLoad);

        foreach ($sorted as $name => $constraints) {
            $models = $this->eagerLoadRelation($models, $name, $constraints);
        }

        return $models;
    }

    /**
     * Sort eager loads by depth (base relations first)
     *
     * @param array<string, Closure|null> $eagerLoad
     * @return array<string, Closure|null>
     */
    protected function sortEagerLoads(array $eagerLoad): array
    {
        $keys = array_keys($eagerLoad);

        usort($keys, function ($a, $b) {
            return substr_count($a, '.') - substr_count($b, '.');
        });

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $eagerLoad[$key];
        }

        return $sorted;
    }

    /**
     * Eagerly load a single relation for models
     *
     * @param array<TModel> $models
     * @param string $name
     * @param Closure|null $constraints
     * @return array<TModel>
     */
    protected function eagerLoadRelation(array $models, string $name, ?Closure $constraints): array
    {
        // Check if this is a nested relation
        $parts = explode('.', $name);

        if (count($parts) > 1) {
            return $this->eagerLoadNestedRelation($models, $parts, $constraints);
        }

        // Get the relation from the first model
        $relation = $this->getRelation($name);

        // Initialize the relation on all models
        $relation->initRelation($models, $name);

        // Add eager constraints
        $relation->addEagerConstraints($models);

        // Apply user-defined constraints
        if ($constraints !== null) {
            $constraints($relation);
        }

        // Get the results and match them to models
        return $relation->match($models, $relation->get(), $name);
    }

    /**
     * Eagerly load a nested relation
     *
     * @param array<TModel> $models
     * @param array<string> $parts
     * @param Closure|null $constraints
     * @return array<TModel>
     */
    protected function eagerLoadNestedRelation(array $models, array $parts, ?Closure $constraints): array
    {
        $baseName = array_shift($parts);
        $nestedName = implode('.', $parts);

        // The base relation should already be loaded
        // Now we need to load the nested relation on the base relation's results
        $nestedModels = [];

        foreach ($models as $model) {
            $related = $model->getRelation($baseName);

            if ($related instanceof Collection) {
                foreach ($related as $relatedModel) {
                    $nestedModels[] = $relatedModel;
                }
            } elseif ($related !== null) {
                $nestedModels[] = $related;
            }
        }

        if (count($nestedModels) === 0) {
            return $models;
        }

        // Get the relation from the first nested model
        $firstNested = $nestedModels[0];

        if (count($parts) === 1) {
            // Final level - load the relation
            $relationName = $parts[0];

            if (method_exists($firstNested, $relationName)) {
                $relation = $firstNested->$relationName();

                // Initialize and load the relation
                $relation->initRelation($nestedModels, $relationName);
                $relation->addEagerConstraints($nestedModels);

                if ($constraints !== null) {
                    $constraints($relation);
                }

                $relation->match($nestedModels, $relation->get(), $relationName);
            }
        } else {
            // More nesting - recurse
            $this->eagerLoadNestedRelation($nestedModels, $parts, $constraints);
        }

        return $models;
    }

    /**
     * Get the relation instance for the given relation name
     *
     * @param string $name
     * @return Relations\Relation
     */
    protected function getRelation(string $name): Relations\Relation
    {
        // Get the base name if nested
        $parts = explode('.', $name);
        $baseName = $parts[0];

        // Create a clean model instance and get the relation
        $relation = $this->model->$baseName();

        return $relation;
    }

    /**
     * Set the relationships that should be eager loaded
     *
     * @param array<string|int, string|Closure>|string $relations
     * @return static
     */
    public function with(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $parsed = $this->parseWithRelations($relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $parsed);

        return $this;
    }

    /**
     * Parse the eager loading relations
     *
     * @param array<string|int, string|Closure> $relations
     * @return array<string, Closure|null>
     */
    protected function parseWithRelations(array $relations): array
    {
        $results = [];

        foreach ($relations as $key => $value) {
            // If the key is numeric, the value is the relation name
            if (is_numeric($key)) {
                // Handle nested relations like 'posts.comments.author'
                $nested = $this->addNestedWiths($value, $results);
                $results = array_merge($results, $nested);
            } else {
                // The key is the relation name, value is a closure for constraints
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * Parse nested eager loading relations
     *
     * @param string $name
     * @param array<string, Closure|null> $results
     * @return array<string, Closure|null>
     */
    protected function addNestedWiths(string $name, array $results): array
    {
        $progress = [];
        $nested = [];

        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;
            $key = implode('.', $progress);

            if (!isset($results[$key])) {
                $nested[$key] = null;
            }
        }

        return $nested;
    }

    /**
     * Prevent the specified relations from being eager loaded
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function without(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $relation) {
            unset($this->eagerLoad[$relation]);
        }

        return $this;
    }

    /**
     * Add subselect queries to count the relations
     *
     * @param array<string|int, string|Closure>|string $relations
     * @return static
     */
    public function withCount(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $key => $value) {
            if (is_numeric($key)) {
                $this->withCount[$value] = null;
            } else {
                $this->withCount[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Add a relationship count / exists condition to the query
     *
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @param string $boolean
     * @param Closure|null $callback
     * @return static
     */
    public function has(
        string $relation,
        string $operator = '>=',
        int $count = 1,
        string $boolean = 'and',
        ?Closure $callback = null
    ): static {
        $this->addHasWhere($relation, $operator, $count, $boolean, $callback);

        return $this;
    }

    /**
     * Add a relationship count / exists condition to the query with an "or"
     *
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @return static
     */
    public function orHas(string $relation, string $operator = '>=', int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query (negated)
     *
     * @param string $relation
     * @param string $boolean
     * @param Closure|null $callback
     * @return static
     */
    public function doesntHave(string $relation, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or" (negated)
     *
     * @param string $relation
     * @return static
     */
    public function orDoesntHave(string $relation): static
    {
        return $this->doesntHave($relation, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses
     *
     * @param string $relation
     * @param Closure|null $callback
     * @param string $operator
     * @param int $count
     * @return static
     */
    public function whereHas(
        string $relation,
        ?Closure $callback = null,
        string $operator = '>=',
        int $count = 1
    ): static {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or"
     *
     * @param string $relation
     * @param Closure|null $callback
     * @param string $operator
     * @param int $count
     * @return static
     */
    public function orWhereHas(
        string $relation,
        ?Closure $callback = null,
        string $operator = '>=',
        int $count = 1
    ): static {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses (negated)
     *
     * @param string $relation
     * @param Closure|null $callback
     * @return static
     */
    public function whereDoesntHave(string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or" (negated)
     *
     * @param string $relation
     * @param Closure|null $callback
     * @return static
     */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    /**
     * Add the "has" condition where clause to the query
     *
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @param string $boolean
     * @param Closure|null $callback
     * @return void
     */
    protected function addHasWhere(
        string $relation,
        string $operator,
        int $count,
        string $boolean,
        ?Closure $callback
    ): void {
        // Get the base relation
        $parts = explode('.', $relation);
        $baseName = array_shift($parts);

        $relationInstance = $this->model->$baseName();

        // Get the relationship table and keys
        $relatedTable = $relationInstance->getRelated()->getTable();
        $parentTable = $this->model->getTable();

        // Build the subquery for existence check
        $subQuery = $relationInstance->getQuery()->getQuery();

        // Add the join condition
        if (method_exists($relationInstance, 'getQualifiedForeignKeyName')) {
            $foreignKey = $relationInstance->getQualifiedForeignKeyName();
            $localKey = $relationInstance->getQualifiedParentKeyName();
            $subQuery->whereRaw("{$foreignKey} = {$localKey}");
        }

        // Apply user constraints
        if ($callback !== null) {
            $callback($subQuery);
        }

        // Build the raw SQL for the subquery count
        $sql = "({$subQuery->toSql()})";

        // Add the where clause
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $foreignKey = $this->getRelationForeignKey($relationInstance);
        $primaryKey = $this->model->getKeyName();
        $sql = "(SELECT COUNT(*) FROM {$relatedTable} "
            . "WHERE {$relatedTable}.{$foreignKey} = {$parentTable}.{$primaryKey}) "
            . "{$operator} {$count}";
        $this->query->$method($sql);
    }

    /**
     * Get the foreign key for a relation
     *
     * @param Relations\Relation $relation
     * @return string
     */
    protected function getRelationForeignKey(Relations\Relation $relation): string
    {
        if (method_exists($relation, 'getForeignKeyName')) {
            return $relation->getForeignKeyName();
        }

        if (method_exists($relation, 'getForeignKey')) {
            return $relation->getForeignKey();
        }

        // Fallback for belongs to relations
        if (method_exists($relation, 'getOwnerKeyName')) {
            return $relation->getOwnerKeyName();
        }

        // Default fallback
        return $this->model->getForeignKey();
    }

    /**
     * Eager load relations for an array of models (called from Collection)
     *
     * @param array<TModel> $models
     * @param array<string> $relations
     * @return void
     */
    public function loadRelationsOnModels(array $models, array $relations): void
    {
        foreach ($relations as $relation) {
            $this->eagerLoad[$relation] = null;
        }

        $this->eagerLoadRelations($models);
    }

    /**
     * Apply global scopes to the query
     *
     * @return void
     */
    protected function applyScopes(): void
    {
        foreach ($this->model::getGlobalScopes() as $identifier => $scope) {
            if (isset($this->removedScopes[$identifier])) {
                continue;
            }

            if ($scope instanceof Scope) {
                $scope->apply($this, $this->model);
            } elseif ($scope instanceof Closure) {
                $scope($this);
            }
        }
    }

    /**
     * Remove a registered global scope
     *
     * @param Scope|string $scope
     * @return static
     */
    public function withoutGlobalScope(Scope|string $scope): static
    {
        $identifier = is_string($scope) ? $scope : $scope::class;
        $this->removedScopes[$identifier] = true;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes
     *
     * @param array<Scope|string>|null $scopes
     * @return static
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if ($scopes === null) {
            foreach (array_keys($this->model::getGlobalScopes()) as $scope) {
                $this->removedScopes[$scope] = true;
            }
        } else {
            foreach ($scopes as $scope) {
                $this->withoutGlobalScope($scope);
            }
        }

        return $this;
    }

    /**
     * Register a custom macro
     *
     * @param string $name
     * @param Closure $callback
     * @return void
     */
    public function macro(string $name, Closure $callback): void
    {
        $this->macros[$name] = $callback;
    }

    /**
     * Check if a macro is registered
     *
     * @param string $name
     * @return bool
     */
    public function hasMacro(string $name): bool
    {
        return isset($this->macros[$name]);
    }

    /**
     * Set a custom callback for when delete is called
     *
     * This is used by soft deletes to override the default delete behavior
     *
     * @param Closure $callback
     * @return void
     */
    public function onDelete(Closure $callback): void
    {
        $this->onDelete = $callback;
    }

    /**
     * Force a delete on the underlying database records
     *
     * This bypasses soft deletes and permanently removes records
     *
     * @return int
     */
    public function forceDelete(): int
    {
        $this->applyScopes();

        return $this->query->delete();
    }

    /**
     * Chunk the results of the query
     *
     * @param int $count
     * @param callable $callback
     * @return bool
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;

        do {
            $results = $this->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Execute a callback over each item while chunking
     *
     * @param callable $callback
     * @param int $count
     * @return bool
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Add a where clause to the query
     *
     * @param string|array<string, mixed>|Closure $column
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function where(string|array|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        $this->query->where($column, $operator, $value);

        return $this;
    }

    /**
     * Add an "or where" clause to the query
     *
     * @param string|array<string, mixed>|Closure $column
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function orWhere(string|array|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        $this->query->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * Add a "where in" clause to the query
     *
     * @param string $column
     * @param array<mixed> $values
     * @return static
     */
    public function whereIn(string $column, array $values): static
    {
        $this->query->whereIn($column, $values);

        return $this;
    }

    /**
     * Add a "where not in" clause to the query
     *
     * @param string $column
     * @param array<mixed> $values
     * @return static
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->query->whereNotIn($column, $values);

        return $this;
    }

    /**
     * Add a "where null" clause to the query
     *
     * @param string $column
     * @return static
     */
    public function whereNull(string $column): static
    {
        $this->query->whereNull($column);

        return $this;
    }

    /**
     * Add a "where not null" clause to the query
     *
     * @param string $column
     * @return static
     */
    public function whereNotNull(string $column): static
    {
        $this->query->whereNotNull($column);

        return $this;
    }

    /**
     * Add an "order by" clause to the query
     *
     * @param string $column
     * @param string $direction
     * @return static
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Add a descending "order by" clause to the query
     *
     * @param string $column
     * @return static
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Order by latest timestamp
     *
     * @param string $column
     * @return static
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderByDesc($column);
    }

    /**
     * Order by oldest timestamp
     *
     * @param string $column
     * @return static
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Set the limit on the query
     *
     * @param int $value
     * @return static
     */
    public function limit(int $value): static
    {
        $this->query->limit($value);

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query
     *
     * @param int $value
     * @return static
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Set the offset on the query
     *
     * @param int $value
     * @return static
     */
    public function offset(int $value): static
    {
        $this->query->offset($value);

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query
     *
     * @param int $value
     * @return static
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Set the limit and offset for a given page
     *
     * @param int $page
     * @param int $perPage
     * @return static
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Add a join clause to the query
     *
     * @param string $table
     * @param string $first
     * @param string|null $operator
     * @param string|null $second
     * @param string $type
     * @return static
     */
    public function join(
        string $table,
        string $first,
        ?string $operator = null,
        ?string $second = null,
        string $type = 'inner'
    ): static {
        $this->query->join($table, $first, $operator, $second, $type);

        return $this;
    }

    /**
     * Add a left join clause to the query
     *
     * @param string $table
     * @param string $first
     * @param string|null $operator
     * @param string|null $second
     * @return static
     */
    public function leftJoin(string $table, string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a right join clause to the query
     *
     * @param string $table
     * @param string $first
     * @param string|null $operator
     * @param string|null $second
     * @return static
     */
    public function rightJoin(string $table, string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a "group by" clause to the query
     *
     * @param string ...$groups
     * @return static
     */
    public function groupBy(string ...$groups): static
    {
        $this->query->groupBy(...$groups);

        return $this;
    }

    /**
     * Handle dynamic method calls into the query builder
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        // Check for registered macros first (e.g., soft delete methods)
        if ($this->hasMacro($method)) {
            return call_user_func($this->macros[$method], $this, ...$parameters);
        }

        // Check for local scopes (e.g., scopeActive -> active())
        if (method_exists($this->model, 'scope' . ucfirst($method))) {
            return $this->callScope('scope' . ucfirst($method), $parameters);
        }

        // Proxy to the underlying query builder
        $result = $this->query->$method(...$parameters);

        // Return $this for method chaining if the query builder returned itself
        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    /**
     * Call a model scope
     *
     * @param string $scope
     * @param array<mixed> $parameters
     * @return mixed
     */
    protected function callScope(string $scope, array $parameters): mixed
    {
        array_unshift($parameters, $this);

        return $this->model->$scope(...$parameters) ?? $this;
    }

    /**
     * Get the SQL representation of the query
     *
     * @return string
     */
    public function toSql(): string
    {
        $this->applyScopes();

        return $this->query->toSql();
    }
}
