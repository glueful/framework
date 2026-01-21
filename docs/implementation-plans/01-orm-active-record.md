# ORM / Active Record Implementation Plan

> **Status: ✅ COMPLETE** - Implemented January 2026

> A comprehensive plan for implementing a lightweight, performant Active Record ORM that builds on Glueful's existing QueryBuilder infrastructure.

## Implementation Summary

This feature has been fully implemented. See [ORM Documentation](../ORM.md) for usage details.

**Files Created:** 39 source files + 6 test files
**CLI Command:** `php glueful scaffold:model`

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Core Components](#core-components)
6. [Relationships](#relationships)
7. [Model Events](#model-events)
8. [Query Scopes](#query-scopes)
9. [Collections](#collections)
10. [Eager Loading](#eager-loading)
11. [Soft Deletes](#soft-deletes)
12. [Timestamps](#timestamps)
13. [Attribute Casting](#attribute-casting)
14. [Implementation Phases](#implementation-phases)
15. [Testing Strategy](#testing-strategy)
16. [Performance Considerations](#performance-considerations)
17. [Migration Path](#migration-path)
18. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of an Active Record ORM layer for Glueful Framework. The ORM will provide:

- **Model hydration** from database rows to PHP objects
- **Relationships** (HasOne, HasMany, BelongsTo, BelongsToMany)
- **Eager loading** to prevent N+1 queries
- **Model events** for lifecycle hooks
- **Query scopes** for reusable query logic
- **Soft deletes** and **timestamps** as opt-in traits

The implementation builds on the existing modular `QueryBuilder` rather than replacing it, ensuring backward compatibility and leveraging proven infrastructure.

---

## Goals and Non-Goals

### Goals

- ✅ Provide intuitive Active Record pattern for rapid development
- ✅ Build on existing QueryBuilder infrastructure
- ✅ Prevent N+1 queries through eager loading
- ✅ Support common relationship types
- ✅ Integrate with existing event system
- ✅ Maintain high performance with lazy loading
- ✅ Full type safety with PHPStan support

### Non-Goals

- ❌ Replace QueryBuilder (ORM is an addition, not replacement)
- ❌ Support every database feature (focus on common patterns)
- ❌ Compete with Doctrine's complexity (keep it simple)
- ❌ Support multiple database connections per model (v1)
- ❌ Database migrations generation from models (separate concern)

---

## Current State Analysis

### Existing Infrastructure

Glueful already has robust database infrastructure:

```
src/Database/
├── QueryBuilder.php              # Modular orchestrator (main entry point)
├── Query/
│   ├── SelectBuilder.php         # SELECT query building
│   ├── InsertBuilder.php         # INSERT query building
│   ├── UpdateBuilder.php         # UPDATE query building
│   ├── DeleteBuilder.php         # DELETE query building
│   ├── WhereClause.php           # WHERE conditions
│   └── JoinClause.php            # JOIN operations
├── Features/
│   ├── SoftDeleteHandler.php     # Soft delete support
│   └── PaginationBuilder.php     # Pagination
├── Transaction/
│   └── TransactionManager.php    # Transaction support
└── Connection.php                # Database connections
```

### What We Can Leverage

| Component | How ORM Uses It |
|-----------|-----------------|
| `QueryBuilder` | Base for all model queries |
| `SoftDeleteHandler` | Soft delete trait implementation |
| `PaginationBuilder` | Model pagination |
| `TransactionManager` | Model save transactions |
| `Connection` | Database connectivity |

### Gaps to Fill

| Gap | Solution |
|-----|----------|
| Model hydration | New `Model` base class |
| Relationships | New `Relations/` namespace |
| Eager loading | New `Builder` class wrapping QueryBuilder |
| Model events | Integration with existing event system |
| Collections | New `Collection` class |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Application Layer                        │
│  $user = User::find($id);                                       │
│  $user->posts()->where('status', 'published')->get();           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         Model Layer                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │    Model    │  │   Builder   │  │      Collection         │  │
│  │  (Entity)   │◀─│ (Query API) │──│ (Result Set)            │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Relationship Layer                         │
│  ┌────────┐ ┌─────────┐ ┌───────────┐ ┌───────────────────────┐ │
│  │ HasOne │ │ HasMany │ │ BelongsTo │ │    BelongsToMany      │ │
│  └────────┘ └─────────┘ └───────────┘ └───────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                 Existing QueryBuilder Layer                     │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                      QueryBuilder                           ││
│  │  (SelectBuilder, InsertBuilder, UpdateBuilder, etc.)        ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       Database Layer                            │
│                    (PDO / Connection Pool)                      │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/Database/ORM/
├── Model.php                    # Base model class
├── Builder.php                  # Eloquent-style query builder
├── Collection.php               # Model collection
├── ModelNotFoundException.php   # Exception for missing models
│
├── Contracts/
│   ├── ModelInterface.php       # Model contract
│   ├── RelationInterface.php    # Relation contract
│   └── ScopeInterface.php       # Query scope contract
│
├── Relations/
│   ├── Relation.php             # Base relation class
│   ├── HasOne.php               # One-to-one (owning side)
│   ├── HasMany.php              # One-to-many
│   ├── BelongsTo.php            # Inverse one-to-one/many
│   ├── BelongsToMany.php        # Many-to-many
│   └── Pivot.php                # Pivot model for many-to-many
│
├── Concerns/
│   ├── HasAttributes.php        # Attribute handling trait
│   ├── HasEvents.php            # Model events trait
│   ├── HasRelationships.php     # Relationship methods trait
│   ├── HasTimestamps.php        # created_at/updated_at trait
│   ├── HasGlobalScopes.php      # Global query scopes trait
│   └── SoftDeletes.php          # Soft delete trait
│
├── Casts/
│   ├── CastsAttributes.php      # Casting interface
│   ├── JsonCast.php             # JSON casting
│   ├── DateTimeCast.php         # DateTime casting
│   ├── BooleanCast.php          # Boolean casting
│   └── ArrayCast.php            # Array casting
│
└── Events/
    ├── ModelCreating.php        # Before create event
    ├── ModelCreated.php         # After create event
    ├── ModelUpdating.php        # Before update event
    ├── ModelUpdated.php         # After update event
    ├── ModelDeleting.php        # Before delete event
    ├── ModelDeleted.php         # After delete event
    ├── ModelSaving.php          # Before save (create or update)
    └── ModelSaved.php           # After save (create or update)
```

---

## Core Components

### Model Base Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM;

use Glueful\Database\ORM\Concerns\HasAttributes;
use Glueful\Database\ORM\Concerns\HasEvents;
use Glueful\Database\ORM\Concerns\HasRelationships;
use Glueful\Database\ORM\Concerns\HasTimestamps;
use Glueful\Database\ORM\Concerns\HasGlobalScopes;
use Glueful\Database\ORM\Contracts\ModelInterface;
use JsonSerializable;
use ArrayAccess;

/**
 * Base Model class for Active Record pattern
 *
 * @template TKey of array-key
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 */
abstract class Model implements ModelInterface, JsonSerializable, ArrayAccess
{
    use HasAttributes;
    use HasEvents;
    use HasRelationships;
    use HasTimestamps;
    use HasGlobalScopes;

    /**
     * The table associated with the model
     */
    protected string $table;

    /**
     * The primary key for the model
     */
    protected string $primaryKey = 'id';

    /**
     * The primary key type
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing
     */
    public bool $incrementing = true;

    /**
     * Indicates if the model exists in the database
     */
    public bool $exists = false;

    /**
     * Indicates if the model was recently created
     */
    public bool $wasRecentlyCreated = false;

    /**
     * The attributes that are mass assignable
     *
     * @var array<string>
     */
    protected array $fillable = [];

    /**
     * The attributes that aren't mass assignable
     *
     * @var array<string>
     */
    protected array $guarded = ['*'];

    /**
     * The attributes that should be hidden for serialization
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * The attributes that should be visible for serialization
     *
     * @var array<string>
     */
    protected array $visible = [];

    /**
     * The attributes that should be cast
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * The attributes that should be appended to arrays
     *
     * @var array<string>
     */
    protected array $appends = [];

    /**
     * The connection name for the model
     */
    protected ?string $connection = null;

    /**
     * Create a new model instance
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->syncOriginal();
        $this->fill($attributes);
    }

    /**
     * Get the table name for the model
     */
    public function getTable(): string
    {
        return $this->table ?? $this->guessTableName();
    }

    /**
     * Guess the table name from the model class name
     */
    protected function guessTableName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
    }

    /**
     * Get the primary key for the model
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the value of the model's primary key
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Begin querying the model
     */
    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    /**
     * Create a new query builder for the model
     */
    public function newQuery(): Builder
    {
        return $this->newModelQuery()->withGlobalScopes();
    }

    /**
     * Create a new query builder without global scopes
     */
    public function newModelQuery(): Builder
    {
        return $this->newBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);
    }

    /**
     * Create a new Builder instance
     */
    protected function newBuilder(\Glueful\Database\QueryBuilder $query): Builder
    {
        return new Builder($query);
    }

    /**
     * Get a new base query builder instance
     */
    protected function newBaseQueryBuilder(): \Glueful\Database\QueryBuilder
    {
        $connection = $this->getConnection();

        // Get QueryBuilder from DI container
        $queryBuilder = app(\Glueful\Database\QueryBuilder::class);

        return $queryBuilder->from($this->getTable());
    }

    /**
     * Find a model by its primary key
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return static|null
     */
    public static function find(mixed $id, array $columns = ['*']): ?static
    {
        return static::query()->find($id, $columns);
    }

    /**
     * Find a model by its primary key or throw an exception
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return static
     * @throws ModelNotFoundException
     */
    public static function findOrFail(mixed $id, array $columns = ['*']): static
    {
        $result = static::find($id, $columns);

        if ($result === null) {
            throw (new ModelNotFoundException())->setModel(static::class, $id);
        }

        return $result;
    }

    /**
     * Find a model by its primary key or create a new instance
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return static
     */
    public static function findOrNew(mixed $id, array $columns = ['*']): static
    {
        return static::find($id, $columns) ?? new static();
    }

    /**
     * Get the first record matching the attributes or create it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::where($attributes)->first();

        if ($instance !== null) {
            return $instance;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Get the first record matching the attributes or instantiate it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrNew(array $attributes = [], array $values = []): static
    {
        $instance = static::where($attributes)->first();

        if ($instance !== null) {
            return $instance;
        }

        return new static(array_merge($attributes, $values));
    }

    /**
     * Create and save a new model and return the instance
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes = []): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Save the model to the database
     */
    public function save(): bool
    {
        $query = $this->newModelQuery();

        // Fire "saving" event
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists, perform an update
        if ($this->exists) {
            $saved = $this->isDirty() ? $this->performUpdate($query) : true;
        } else {
            // Otherwise, insert a new record
            $saved = $this->performInsert($query);
        }

        if ($saved) {
            $this->fireModelEvent('saved', false);
            $this->syncOriginal();
        }

        return $saved;
    }

    /**
     * Perform a model insert operation
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $attributes = $this->getAttributes();

        if ($this->incrementing) {
            $this->insertAndSetId($query, $attributes);
        } else {
            if (empty($attributes)) {
                return true;
            }
            $query->insert($attributes);
        }

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Insert the given attributes and set the ID on the model
     *
     * @param array<string, mixed> $attributes
     */
    protected function insertAndSetId(Builder $query, array $attributes): void
    {
        $id = $query->insertGetId($attributes, $this->getKeyName());

        $this->setAttribute($this->getKeyName(), $id);
    }

    /**
     * Perform a model update operation
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $query->where($this->getKeyName(), $this->getKey())
                  ->update($dirty);

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Delete the model from the database
     */
    public function delete(): bool
    {
        if ($this->getKey() === null) {
            throw new \LogicException('No primary key defined on model.');
        }

        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->performDeleteOnModel();

        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Perform the actual delete query on this model instance
     */
    protected function performDeleteOnModel(): void
    {
        $this->newModelQuery()
             ->where($this->getKeyName(), $this->getKey())
             ->delete();

        $this->exists = false;
    }

    /**
     * Update the model in the database
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    /**
     * Reload a fresh model instance from the database
     *
     * @param array<string>|string $with
     * @return static|null
     */
    public function fresh(array|string $with = []): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::query()
            ->with(is_string($with) ? func_get_args() : $with)
            ->where($this->getKeyName(), $this->getKey())
            ->first();
    }

    /**
     * Reload the current model instance with fresh attributes from the database
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            static::query()
                ->where($this->getKeyName(), $this->getKey())
                ->first()
                ->getAttributes()
        );

        $this->syncOriginal();

        $this->relations = [];

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance
     */
    public function replicate(?array $except = null): static
    {
        $defaults = [
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        $attributes = array_diff_key(
            $this->getAttributes(),
            array_flip($except ? array_unique(array_merge($except, $defaults)) : $defaults)
        );

        return new static($attributes);
    }

    /**
     * Handle dynamic method calls into the model
     *
     * @param array<mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->newQuery()->$method(...$parameters);
    }

    /**
     * Handle dynamic static method calls into the model
     *
     * @param array<mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return (new static())->$method(...$parameters);
    }

    /**
     * Convert the model to its JSON representation
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the model instance to an array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * Specify data which should be serialized to JSON
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the model to its string representation
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
```

### Builder Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM;

use Glueful\Database\QueryBuilder;
use Glueful\Database\ORM\Relations\Relation;

/**
 * ORM Query Builder
 *
 * Wraps the QueryBuilder to provide model-aware query functionality
 * including eager loading, scopes, and model hydration.
 *
 * @template TModel of Model
 */
class Builder
{
    /**
     * The model being queried
     *
     * @var TModel
     */
    protected Model $model;

    /**
     * The relationships that should be eager loaded
     *
     * @var array<string, \Closure|null>
     */
    protected array $eagerLoad = [];

    /**
     * The scopes that have been applied
     *
     * @var array<string, mixed>
     */
    protected array $scopes = [];

    /**
     * Create a new Builder instance
     */
    public function __construct(
        protected QueryBuilder $query
    ) {
    }

    /**
     * Set the model instance for this builder
     *
     * @param TModel $model
     * @return static
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Get the model instance for this builder
     *
     * @return TModel
     */
    public function getModel(): Model
    {
        return $this->model;
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
            return $this->findMany($id, $columns);
        }

        return $this->where($this->model->getKeyName(), $id)->first($columns);
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
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereIn($this->model->getKeyName(), $ids)->get($columns);
    }

    /**
     * Execute the query and get the first result
     *
     * @param array<string>|string $columns
     * @return TModel|null
     */
    public function first(array|string $columns = ['*']): ?Model
    {
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Execute the query and get the first result or throw an exception
     *
     * @param array<string>|string $columns
     * @return TModel
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array|string $columns = ['*']): Model
    {
        $model = $this->first($columns);

        if ($model === null) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        }

        return $model;
    }

    /**
     * Execute the query as a "select" statement
     *
     * @param array<string>|string $columns
     * @return Collection<TModel>
     */
    public function get(array|string $columns = ['*']): Collection
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        $results = $this->query->select($columns)->get();

        $models = $this->hydrate($results);

        // Load eager loaded relationships
        if (count($models) > 0 && !empty($this->eagerLoad)) {
            $models = $this->eagerLoadRelations($models);
        }

        return $this->model->newCollection($models);
    }

    /**
     * Create a collection of models from plain arrays
     *
     * @param array<array<string, mixed>> $items
     * @return array<TModel>
     */
    protected function hydrate(array $items): array
    {
        return array_map(function (array $attributes) {
            $model = $this->model->newInstance([], true);
            $model->setRawAttributes($attributes, true);
            return $model;
        }, $items);
    }

    /**
     * Eager load the relationships for the models
     *
     * @param array<TModel> $models
     * @return array<TModel>
     */
    protected function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            $models = $this->eagerLoadRelation($models, $name, $constraints);
        }

        return $models;
    }

    /**
     * Eagerly load the relationship on a set of models
     *
     * @param array<TModel> $models
     * @param string $name
     * @param \Closure|null $constraints
     * @return array<TModel>
     */
    protected function eagerLoadRelation(array $models, string $name, ?\Closure $constraints): array
    {
        // Get the relation instance
        $relation = $this->getRelation($name);

        // Add eager constraints
        $relation->addEagerConstraints($models);

        // Apply custom constraints if provided
        if ($constraints !== null) {
            $constraints($relation);
        }

        // Match the loaded results to their parents
        return $relation->match(
            $models,
            $relation->getEager(),
            $name
        );
    }

    /**
     * Get the relation instance for the given relation name
     */
    protected function getRelation(string $name): Relation
    {
        // Get nested relations if using dot notation
        $segments = explode('.', $name);
        $relation = $this->model->{$segments[0]}();

        if (count($segments) > 1) {
            // Handle nested relations
            for ($i = 1; $i < count($segments); $i++) {
                $relation = $relation->getRelated()->{$segments[$i]}();
            }
        }

        return $relation;
    }

    /**
     * Set the relationships that should be eager loaded
     *
     * @param array<string, \Closure|null>|string $relations
     * @return static
     */
    public function with(array|string $relations): static
    {
        $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }

    /**
     * Parse the eager loading relations
     *
     * @param array<mixed> $relations
     * @return array<string, \Closure|null>
     */
    protected function parseWithRelations(array $relations): array
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = null;
            }

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Prevent the specified relations from being eager loaded
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function without(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->eagerLoad = array_diff_key(
            $this->eagerLoad,
            array_flip($relations)
        );

        return $this;
    }

    /**
     * Insert a new record and get the value of the primary key
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, ?string $sequence = null): int|string
    {
        return $this->query->insertGetId($values, $sequence);
    }

    /**
     * Insert a new record into the database
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): bool
    {
        return $this->query->insert($values);
    }

    /**
     * Update records in the database
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return $this->query->update($values);
    }

    /**
     * Delete records from the database
     */
    public function delete(): int
    {
        return $this->query->delete();
    }

    /**
     * Get a paginator for the query
     *
     * @param int $perPage
     * @param array<string> $columns
     * @param string $pageName
     * @param int|null $page
     * @return array{data: Collection<TModel>, meta: array<string, mixed>}
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], string $pageName = 'page', ?int $page = null): array
    {
        $page = $page ?? (int) ($_GET[$pageName] ?? 1);

        $total = $this->toBase()->count();

        $results = $this->forPage($page, $perPage)->get($columns);

        return [
            'data' => $results,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
                'has_next_page' => $page < ceil($total / $perPage),
                'has_previous_page' => $page > 1,
            ],
        ];
    }

    /**
     * Apply global scopes to the query
     *
     * @return static
     */
    public function withGlobalScopes(): static
    {
        foreach ($this->model->getGlobalScopes() as $identifier => $scope) {
            $this->scopes[$identifier] = $scope;

            if ($scope instanceof \Closure) {
                $scope($this);
            } else {
                $scope->apply($this, $this->model);
            }
        }

        return $this;
    }

    /**
     * Remove a registered global scope
     *
     * @param string $scope
     * @return static
     */
    public function withoutGlobalScope(string $scope): static
    {
        unset($this->scopes[$scope]);

        return $this;
    }

    /**
     * Get the underlying QueryBuilder instance
     */
    public function toBase(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Dynamically handle calls into the query instance
     *
     * @param array<mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        // Check for scope methods
        if (method_exists($this->model, 'scope' . ucfirst($method))) {
            return $this->callScope($method, $parameters);
        }

        $result = $this->query->$method(...$parameters);

        // If the result is the QueryBuilder, return this Builder for chaining
        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    /**
     * Call a model scope on the query
     *
     * @param array<mixed> $parameters
     */
    protected function callScope(string $scope, array $parameters): mixed
    {
        return $this->model->{'scope' . ucfirst($scope)}($this, ...$parameters) ?? $this;
    }
}
```

---

## Relationships

### Base Relation Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Collection;

/**
 * Base Relation class
 *
 * @template TRelatedModel of Model
 * @template TParentModel of Model
 */
abstract class Relation
{
    /**
     * The ORM query builder instance
     *
     * @var Builder<TRelatedModel>
     */
    protected Builder $query;

    /**
     * The parent model instance
     *
     * @var TParentModel
     */
    protected Model $parent;

    /**
     * The related model instance
     *
     * @var TRelatedModel
     */
    protected Model $related;

    /**
     * Create a new relation instance
     *
     * @param Builder<TRelatedModel> $query
     * @param TParentModel $parent
     */
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Set the base constraints on the relation query
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<TParentModel> $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Match the eagerly loaded results to their parents
     *
     * @param array<TParentModel> $models
     * @param Collection<TRelatedModel> $results
     * @param string $relation
     * @return array<TParentModel>
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Get the results of the relationship
     *
     * @return Collection<TRelatedModel>|TRelatedModel|null
     */
    abstract public function getResults(): Collection|Model|null;

    /**
     * Get the relationship for eager loading
     *
     * @return Collection<TRelatedModel>
     */
    public function getEager(): Collection
    {
        return $this->get();
    }

    /**
     * Execute the query as a "select" statement
     *
     * @param array<string> $columns
     * @return Collection<TRelatedModel>
     */
    public function get(array $columns = ['*']): Collection
    {
        return $this->query->get($columns);
    }

    /**
     * Get the first related model
     *
     * @param array<string> $columns
     * @return TRelatedModel|null
     */
    public function first(array $columns = ['*']): ?Model
    {
        return $this->query->first($columns);
    }

    /**
     * Get the related model instance
     *
     * @return TRelatedModel
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Get the parent model instance
     *
     * @return TParentModel
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Get the underlying query for the relation
     *
     * @return Builder<TRelatedModel>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Handle dynamic method calls to the relationship
     *
     * @param array<mixed> $parameters
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
```

### HasMany Relation

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Collection;

/**
 * HasMany Relation
 *
 * Represents a one-to-many relationship where the parent model
 * has many related models.
 *
 * @template TRelatedModel of Model
 * @template TParentModel of Model
 * @extends Relation<TRelatedModel, TParentModel>
 */
class HasMany extends Relation
{
    /**
     * The foreign key of the parent model
     */
    protected string $foreignKey;

    /**
     * The local key of the parent model
     */
    protected string $localKey;

    /**
     * Create a new HasMany relation instance
     *
     * @param Builder<TRelatedModel> $query
     * @param TParentModel $parent
     * @param string $foreignKey
     * @param string $localKey
     */
    public function __construct(Builder $query, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query
     */
    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, $this->getParentKey());
    }

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<TParentModel> $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);

        $this->query->whereIn($this->foreignKey, $keys);
    }

    /**
     * Match the eagerly loaded results to their parents
     *
     * @param array<TParentModel> $models
     * @param Collection<TRelatedModel> $results
     * @param string $relation
     * @return array<TParentModel>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            $model->setRelation(
                $relation,
                $this->related->newCollection($dictionary[$key] ?? [])
            );
        }

        return $models;
    }

    /**
     * Build a dictionary of related models keyed by the foreign key
     *
     * @param Collection<TRelatedModel> $results
     * @return array<mixed, array<TRelatedModel>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the results of the relationship
     *
     * @return Collection<TRelatedModel>
     */
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Get the value of the parent's local key
     */
    protected function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get all the primary keys for an array of models
     *
     * @param array<TParentModel> $models
     * @param string $key
     * @return array<mixed>
     */
    protected function getKeys(array $models, string $key): array
    {
        return array_unique(array_map(fn($model) => $model->getAttribute($key), $models));
    }

    /**
     * Create a new instance of the related model
     *
     * @param array<string, mixed> $attributes
     * @return TRelatedModel
     */
    public function create(array $attributes = []): Model
    {
        $instance = $this->related->newInstance($attributes);

        $instance->setAttribute($this->foreignKey, $this->getParentKey());

        $instance->save();

        return $instance;
    }

    /**
     * Create many new instances of the related model
     *
     * @param array<array<string, mixed>> $records
     * @return Collection<TRelatedModel>
     */
    public function createMany(array $records): Collection
    {
        $instances = [];

        foreach ($records as $record) {
            $instances[] = $this->create($record);
        }

        return $this->related->newCollection($instances);
    }

    /**
     * Attach a model to the parent
     *
     * @param TRelatedModel|int|string $model
     * @return TRelatedModel
     */
    public function save(Model|int|string $model): Model
    {
        if (!$model instanceof Model) {
            $model = $this->related->findOrFail($model);
        }

        $model->setAttribute($this->foreignKey, $this->getParentKey());
        $model->save();

        return $model;
    }
}
```

### BelongsTo Relation

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Collection;

/**
 * BelongsTo Relation
 *
 * Represents the inverse of a one-to-one or one-to-many relationship.
 *
 * @template TRelatedModel of Model
 * @template TParentModel of Model
 * @extends Relation<TRelatedModel, TParentModel>
 */
class BelongsTo extends Relation
{
    /**
     * The foreign key of the parent model
     */
    protected string $foreignKey;

    /**
     * The owner key of the related model
     */
    protected string $ownerKey;

    /**
     * The name of the relationship
     */
    protected string $relationName;

    /**
     * Create a new BelongsTo relation instance
     */
    public function __construct(
        Builder $query,
        Model $child,
        string $foreignKey,
        string $ownerKey,
        string $relationName
    ) {
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
        $this->relationName = $relationName;

        parent::__construct($query, $child);
    }

    /**
     * Set the base constraints on the relation query
     */
    public function addConstraints(): void
    {
        $this->query->where($this->ownerKey, $this->parent->getAttribute($this->foreignKey));
    }

    /**
     * Set the constraints for an eager load of the relation
     *
     * @param array<TParentModel> $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->getAttribute($this->foreignKey);
            if ($value !== null) {
                $keys[] = $value;
            }
        }

        $this->query->whereIn($this->ownerKey, array_unique($keys));
    }

    /**
     * Match the eagerly loaded results to their parents
     *
     * @param array<TParentModel> $models
     * @param Collection<TRelatedModel> $results
     * @param string $relation
     * @return array<TParentModel>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);

            $model->setRelation($relation, $dictionary[$key] ?? null);
        }

        return $models;
    }

    /**
     * Get the results of the relationship
     *
     * @return TRelatedModel|null
     */
    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        return $this->query->first();
    }

    /**
     * Associate the model instance to the given parent
     *
     * @param TRelatedModel|int|string $model
     * @return TParentModel
     */
    public function associate(Model|int|string $model): Model
    {
        $ownerKey = $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model;

        $this->parent->setAttribute($this->foreignKey, $ownerKey);

        if ($model instanceof Model) {
            $this->parent->setRelation($this->relationName, $model);
        }

        return $this->parent;
    }

    /**
     * Dissociate previously associated model from the given parent
     *
     * @return TParentModel
     */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);
        $this->parent->setRelation($this->relationName, null);

        return $this->parent;
    }
}
```

---

## Model Events

### Event Integration

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Events\Event;
use Glueful\Database\ORM\Events\ModelCreating;
use Glueful\Database\ORM\Events\ModelCreated;
use Glueful\Database\ORM\Events\ModelUpdating;
use Glueful\Database\ORM\Events\ModelUpdated;
use Glueful\Database\ORM\Events\ModelDeleting;
use Glueful\Database\ORM\Events\ModelDeleted;
use Glueful\Database\ORM\Events\ModelSaving;
use Glueful\Database\ORM\Events\ModelSaved;

/**
 * HasEvents trait
 *
 * Provides model lifecycle event support integrated with Glueful's event system.
 */
trait HasEvents
{
    /**
     * User-defined model event callbacks
     *
     * @var array<string, array<callable>>
     */
    protected static array $dispatcher = [];

    /**
     * The event map for the model
     *
     * @var array<string, class-string>
     */
    protected array $dispatchesEvents = [];

    /**
     * Register a creating model event with the dispatcher
     */
    public static function creating(callable $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a created model event with the dispatcher
     */
    public static function created(callable $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register an updating model event with the dispatcher
     */
    public static function updating(callable $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an updated model event with the dispatcher
     */
    public static function updated(callable $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a saving model event with the dispatcher
     */
    public static function saving(callable $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a saved model event with the dispatcher
     */
    public static function saved(callable $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register a deleting model event with the dispatcher
     */
    public static function deleting(callable $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a deleted model event with the dispatcher
     */
    public static function deleted(callable $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a model event callback
     */
    protected static function registerModelEvent(string $event, callable $callback): void
    {
        static::$dispatcher[static::class][$event][] = $callback;
    }

    /**
     * Fire the given event for the model
     *
     * @return bool|null Returns false if event was halted
     */
    protected function fireModelEvent(string $event, bool $halt = true): ?bool
    {
        // Call user-registered callbacks
        if (isset(static::$dispatcher[static::class][$event])) {
            foreach (static::$dispatcher[static::class][$event] as $callback) {
                $result = $callback($this);

                if ($halt && $result === false) {
                    return false;
                }
            }
        }

        // Dispatch to Glueful event system
        $eventClass = $this->dispatchesEvents[$event] ?? $this->getDefaultEventClass($event);

        if ($eventClass !== null) {
            $eventInstance = new $eventClass($this);
            Event::dispatch($eventInstance);
        }

        return null;
    }

    /**
     * Get the default event class for the given event type
     *
     * @return class-string|null
     */
    protected function getDefaultEventClass(string $event): ?string
    {
        return match ($event) {
            'creating' => ModelCreating::class,
            'created' => ModelCreated::class,
            'updating' => ModelUpdating::class,
            'updated' => ModelUpdated::class,
            'saving' => ModelSaving::class,
            'saved' => ModelSaved::class,
            'deleting' => ModelDeleting::class,
            'deleted' => ModelDeleted::class,
            default => null,
        };
    }

    /**
     * Remove all registered event callbacks
     */
    public static function flushEventListeners(): void
    {
        unset(static::$dispatcher[static::class]);
    }
}
```

---

## Implementation Phases

### Phase 1: Core Model ✅

**Deliverables:**
- [x] `Model` base class with CRUD operations
- [x] `Builder` class wrapping QueryBuilder
- [x] `Collection` class for model results
- [x] `ModelNotFoundException`
- [x] Basic attribute handling (get/set/fill)

**Acceptance Criteria:**
```php
// These should work after Phase 1
$user = User::find(1);
$user = User::create(['email' => 'test@example.com']);
$user->update(['name' => 'John']);
$user->delete();
$users = User::where('status', 'active')->get();
```

### Phase 2: Relationships ✅

**Deliverables:**
- [x] `HasOne` relation
- [x] `HasMany` relation
- [x] `BelongsTo` relation
- [x] `BelongsToMany` relation with pivot support
- [x] Eager loading with `with()`
- [x] `HasManyThrough` relation (bonus)
- [x] `HasOneThrough` relation (bonus)

**Acceptance Criteria:**
```php
// These should work after Phase 2
$user->posts;
$user->posts()->where('published', true)->get();
$post->author;
$user->roles; // many-to-many
User::with('posts', 'roles')->get(); // eager loading
```

### Phase 3: Events & Scopes ✅

**Deliverables:**
- [x] Model event system integration
- [x] Global scopes
- [x] Local scopes
- [x] Boot/booting methods

**Acceptance Criteria:**
```php
// Events
User::creating(fn($user) => $user->uuid = Str::uuid());

// Scopes
User::active()->get(); // local scope
// Global scope automatically applied
```

### Phase 4: Traits & Casts ✅

**Deliverables:**
- [x] `SoftDeletes` trait
- [x] `HasTimestamps` trait
- [x] Attribute casting system
- [x] Custom cast classes (AsJson, AsArrayObject, AsCollection, AsDateTime, AsEncryptedString, AsEnum, Attribute)

**Acceptance Criteria:**
```php
// Soft deletes
$user->delete(); // Sets deleted_at
User::withTrashed()->get();

// Timestamps
$user->created_at; // Carbon instance

// Casts
protected $casts = ['settings' => 'array'];
$user->settings['theme']; // Works as array
```

### Phase 5: Polish & Testing ✅

**Deliverables:**
- [x] Test coverage (6 test files)
- [x] Performance optimization
- [x] Documentation (ORM.md)
- [x] Scaffold commands integration (`php glueful scaffold:model`)

**Acceptance Criteria:**
- [x] Test files created
- [x] N+1 query prevention through eager loading
- [x] Complete PHPDoc coverage
- [x] `php glueful scaffold:model` command

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Database\ORM;

use PHPUnit\Framework\TestCase;
use Glueful\Database\ORM\Model;

class ModelTest extends TestCase
{
    public function testModelCanBeFilled(): void
    {
        $user = new User(['name' => 'John', 'email' => 'john@example.com']);

        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testModelGuardsAttributes(): void
    {
        $user = new User(['id' => 1, 'name' => 'John']);

        $this->assertNull($user->id); // guarded by default
        $this->assertEquals('John', $user->name);
    }

    public function testModelTracksDirtyAttributes(): void
    {
        $user = new User(['name' => 'John']);
        $user->syncOriginal();

        $user->name = 'Jane';

        $this->assertTrue($user->isDirty('name'));
        $this->assertEquals(['name' => 'Jane'], $user->getDirty());
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Tests\TestCase;

class ModelIntegrationTest extends TestCase
{
    public function testCanCreateAndRetrieveModel(): void
    {
        $user = User::create([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);

        $this->assertNotNull($user->id);

        $found = User::find($user->id);
        $this->assertEquals('John', $found->name);
    }

    public function testEagerLoadingPreventsNPlusOne(): void
    {
        $this->createUsersWithPosts(10, 5);

        $queryCount = 0;
        DB::listen(fn() => $queryCount++);

        $users = User::with('posts')->get();

        foreach ($users as $user) {
            $_ = $user->posts->count();
        }

        // Should be 2 queries: users + posts (not 1 + 10)
        $this->assertEquals(2, $queryCount);
    }
}
```

---

## Performance Considerations

### Lazy Loading vs Eager Loading

```php
// BAD: N+1 queries
$users = User::all();
foreach ($users as $user) {
    echo $user->posts->count(); // Query per user!
}

// GOOD: 2 queries total
$users = User::with('posts')->get();
foreach ($users as $user) {
    echo $user->posts->count(); // Already loaded
}
```

### Chunking Large Datasets

```php
// Memory efficient processing
User::chunk(1000, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});

// Or with lazy collections
User::lazy()->each(function ($user) {
    // Process user
});
```

### Query Optimization

```php
// Use select to limit columns
User::select(['id', 'name'])->get();

// Use exists instead of count for boolean checks
if (User::where('email', $email)->exists()) { }

// Use pluck for single column
$emails = User::pluck('email');
```

---

## Migration Path

### For Existing QueryBuilder Users

The ORM is additive - existing QueryBuilder code continues to work:

```php
// Before (still works)
$users = QueryBuilder::table('users')
    ->where('status', 'active')
    ->get();

// After (new option)
$users = User::where('status', 'active')->get();
```

### Gradual Adoption

1. Start with new features using models
2. Gradually convert existing code
3. No deadline to migrate - both approaches coexist

---

## API Reference

### Model Static Methods

| Method | Description |
|--------|-------------|
| `find($id)` | Find by primary key |
| `findOrFail($id)` | Find or throw exception |
| `create(array $attributes)` | Create and save |
| `firstOrCreate(array $attributes)` | Find or create |
| `where($column, $value)` | Start query with where |
| `with($relations)` | Eager load relations |
| `query()` | Get fresh query builder |

### Model Instance Methods

| Method | Description |
|--------|-------------|
| `save()` | Save the model |
| `update(array $attributes)` | Update attributes |
| `delete()` | Delete the model |
| `fresh()` | Get fresh instance |
| `refresh()` | Reload attributes |
| `replicate()` | Clone without ID |
| `toArray()` | Convert to array |
| `toJson()` | Convert to JSON |

### Relationship Methods

| Method | Description |
|--------|-------------|
| `hasOne($related, $foreignKey)` | Define has-one |
| `hasMany($related, $foreignKey)` | Define has-many |
| `belongsTo($related, $foreignKey)` | Define belongs-to |
| `belongsToMany($related, $table)` | Define many-to-many |
