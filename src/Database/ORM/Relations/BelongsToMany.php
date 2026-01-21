<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Relations;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Model;

/**
 * Belongs To Many Relation
 *
 * Represents a many-to-many relationship using a pivot/junction table.
 * For example, a User belongsToMany Roles through the role_user pivot table.
 *
 * @example
 * // In User model:
 * public function roles(): BelongsToMany
 * {
 *     return $this->belongsToMany(Role::class);
 * }
 *
 * // With custom pivot table and keys:
 * public function roles(): BelongsToMany
 * {
 *     return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
 *         ->withPivot('assigned_at', 'assigned_by')
 *         ->withTimestamps();
 * }
 */
class BelongsToMany extends Relation
{
    /**
     * The pivot table name
     */
    protected string $table;

    /**
     * The foreign pivot key name
     */
    protected string $foreignPivotKey;

    /**
     * The related pivot key name
     */
    protected string $relatedPivotKey;

    /**
     * The parent key name
     */
    protected string $parentKey;

    /**
     * The related key name
     */
    protected string $relatedKey;

    /**
     * The name of the relationship
     */
    protected string $relationName;

    /**
     * Extra columns to retrieve from the pivot table
     *
     * @var array<string>
     */
    protected array $pivotColumns = [];

    /**
     * Whether the pivot table has timestamps
     */
    protected bool $withTimestamps = false;

    /**
     * The custom pivot table created_at column
     */
    protected string $pivotCreatedAt = 'created_at';

    /**
     * The custom pivot table updated_at column
     */
    protected string $pivotUpdatedAt = 'updated_at';

    /**
     * Constraints to apply when selecting pivot data
     *
     * @var array<array{column: string, operator: string, value: mixed}>
     */
    protected array $pivotWheres = [];

    /**
     * Create a new belongs to many relationship instance
     *
     * @param Builder $query
     * @param Model $parent
     * @param string $table
     * @param string $foreignPivotKey
     * @param string $relatedPivotKey
     * @param string $parentKey
     * @param string $relatedKey
     * @param string $relationName
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        string $relationName
    ) {
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
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
        $this->performJoin();

        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(),
            '=',
            $this->parent->{$this->parentKey}
        );
    }

    /**
     * Set the join clause for the relation query
     *
     * @return void
     */
    protected function performJoin(): void
    {
        $baseTable = $this->related->getTable();

        $this->query->join(
            $this->table,
            "{$baseTable}.{$this->relatedKey}",
            '=',
            $this->getQualifiedRelatedPivotKeyName()
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
        $keys = $this->getKeys($models, $this->parentKey);

        $this->query->whereIn($this->getQualifiedForeignPivotKeyName(), $keys);
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
        // Build a dictionary of results keyed by foreign pivot key
        $dictionary = $this->buildDictionary($results);

        // Match results to their parents
        foreach ($models as $model) {
            $key = $model->{$this->parentKey};

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, new Collection($dictionary[$key]));
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key
     *
     * @param Collection $results
     * @return array<mixed, array<Model>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $pivotKey = $result->pivot->{$this->foreignPivotKey} ?? null;

            if ($pivotKey !== null) {
                $dictionary[$pivotKey][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Get the results of the relationship
     *
     * @return Collection
     */
    public function getResults(): Collection
    {
        $parentKey = $this->parent->{$this->parentKey};

        if ($parentKey === null) {
            return new Collection([]);
        }

        return $this->get();
    }

    /**
     * Execute the query and get results
     *
     * @param array<string> $columns
     * @return Collection
     */
    public function get(array $columns = ['*']): Collection
    {
        // Get the base table columns
        $baseTable = $this->related->getTable();
        $columns = $this->shouldSelect($columns);

        // Get results from query builder
        $results = $this->query->getQuery()->select($columns)->get();

        // Hydrate models with pivot data
        $models = [];
        foreach ($results as $result) {
            $model = $this->related->newFromBuilder((array) $result);
            $this->hydratePivotRelation($model, (array) $result);
            $models[] = $model;
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
        $baseTable = $this->related->getTable();

        if ($columns === ['*']) {
            $columns = ["{$baseTable}.*"];
        }

        // Add pivot columns
        return array_merge(
            $columns,
            $this->aliasedPivotColumns()
        );
    }

    /**
     * Get the pivot columns with aliases
     *
     * @return array<string>
     */
    protected function aliasedPivotColumns(): array
    {
        $columns = [];

        // Always include the pivot keys
        $columns[] = "{$this->table}.{$this->foreignPivotKey} as pivot_{$this->foreignPivotKey}";
        $columns[] = "{$this->table}.{$this->relatedPivotKey} as pivot_{$this->relatedPivotKey}";

        // Add extra pivot columns
        foreach ($this->pivotColumns as $column) {
            $columns[] = "{$this->table}.{$column} as pivot_{$column}";
        }

        // Add timestamp columns if enabled
        if ($this->withTimestamps) {
            $columns[] = "{$this->table}.{$this->pivotCreatedAt} as pivot_{$this->pivotCreatedAt}";
            $columns[] = "{$this->table}.{$this->pivotUpdatedAt} as pivot_{$this->pivotUpdatedAt}";
        }

        return $columns;
    }

    /**
     * Hydrate the pivot table relationship on the models
     *
     * @param Model $model
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function hydratePivotRelation(Model $model, array $attributes): void
    {
        $pivotAttributes = [];

        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, 'pivot_')) {
                $pivotAttributes[substr($key, 6)] = $value;
            }
        }

        $model->setRelation('pivot', new Pivot($pivotAttributes, $this->table));
    }

    /**
     * Specify additional columns to retrieve from the pivot table
     *
     * @param array<string>|string $columns
     * @return static
     */
    public function withPivot(array|string $columns): static
    {
        $this->pivotColumns = array_merge(
            $this->pivotColumns,
            is_array($columns) ? $columns : func_get_args()
        );

        return $this;
    }

    /**
     * Specify that the pivot table has timestamps
     *
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @return static
     */
    public function withTimestamps(?string $createdAt = null, ?string $updatedAt = null): static
    {
        $this->withTimestamps = true;

        if ($createdAt !== null) {
            $this->pivotCreatedAt = $createdAt;
        }

        if ($updatedAt !== null) {
            $this->pivotUpdatedAt = $updatedAt;
        }

        return $this;
    }

    /**
     * Add a constraint to the pivot query
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function wherePivot(string $column, mixed $operator = null, mixed $value = null): static
    {
        // Handle 2-argument form
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->pivotWheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        $this->query->where("{$this->table}.{$column}", $operator, $value);

        return $this;
    }

    /**
     * Add a constraint for null values on the pivot
     *
     * @param string $column
     * @return static
     */
    public function wherePivotNull(string $column): static
    {
        $this->query->whereNull("{$this->table}.{$column}");

        return $this;
    }

    /**
     * Add a constraint for not null values on the pivot
     *
     * @param string $column
     * @return static
     */
    public function wherePivotNotNull(string $column): static
    {
        $this->query->whereNotNull("{$this->table}.{$column}");

        return $this;
    }

    /**
     * Add a where in constraint on the pivot
     *
     * @param string $column
     * @param array<mixed> $values
     * @return static
     */
    public function wherePivotIn(string $column, array $values): static
    {
        $this->query->whereIn("{$this->table}.{$column}", $values);

        return $this;
    }

    /**
     * Order by a pivot column
     *
     * @param string $column
     * @param string $direction
     * @return static
     */
    public function orderByPivot(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy("{$this->table}.{$column}", $direction);

        return $this;
    }

    /**
     * Attach models to the parent
     *
     * @param mixed $id
     * @param array<string, mixed> $attributes
     * @return void
     */
    public function attach(mixed $id, array $attributes = []): void
    {
        $ids = $this->parseIds($id);

        $records = [];
        $now = date('Y-m-d H:i:s');

        foreach ($ids as $relatedId => $pivotAttributes) {
            $record = [
                $this->foreignPivotKey => $this->parent->{$this->parentKey},
                $this->relatedPivotKey => is_array($pivotAttributes) ? $relatedId : $pivotAttributes,
            ];

            // Merge pivot attributes
            if (is_array($pivotAttributes)) {
                $record = array_merge($record, $pivotAttributes);
            }

            // Merge passed attributes
            $record = array_merge($record, $attributes);

            // Add timestamps if enabled
            if ($this->withTimestamps) {
                $record[$this->pivotCreatedAt] = $now;
                $record[$this->pivotUpdatedAt] = $now;
            }

            $records[] = $record;
        }

        // Insert all records
        foreach ($records as $record) {
            $this->parent->getConnection()->table($this->table)->insert($record);
        }
    }

    /**
     * Detach models from the parent
     *
     * @param mixed $ids
     * @return int
     */
    public function detach(mixed $ids = null): int
    {
        $query = $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, '=', $this->parent->{$this->parentKey});

        if ($ids !== null) {
            $ids = $this->parseIds($ids);
            $relatedIds = array_values(array_map(
                fn ($v) => is_array($v) ? key($v) : $v,
                $ids
            ));
            $query->whereIn($this->relatedPivotKey, $relatedIds);
        }

        return $query->delete();
    }

    /**
     * Sync the related models
     *
     * @param mixed $ids
     * @param bool $detaching
     * @return array{attached: array<mixed>, detached: array<mixed>, updated: array<mixed>}
     */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        // Get current related IDs
        $current = $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, '=', $this->parent->{$this->parentKey})
            ->get();

        $currentIds = array_column($current, $this->relatedPivotKey);

        // Parse the ids to sync
        $parsed = $this->parseIds($ids);
        $syncIds = array_keys($parsed);

        // Detach
        if ($detaching) {
            $detach = array_diff($currentIds, $syncIds);
            if (count($detach) > 0) {
                $this->detach($detach);
                $changes['detached'] = array_values($detach);
            }
        }

        // Attach new
        $attach = array_diff($syncIds, $currentIds);
        foreach ($attach as $id) {
            $attributes = $parsed[$id] ?? [];
            $this->attach([$id => $attributes]);
            $changes['attached'][] = $id;
        }

        return $changes;
    }

    /**
     * Toggle the attachment status of the given IDs
     *
     * @param mixed $ids
     * @return array{attached: array<mixed>, detached: array<mixed>}
     */
    public function toggle(mixed $ids): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
        ];

        // Get current related IDs
        $current = $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, '=', $this->parent->{$this->parentKey})
            ->get();

        $currentIds = array_column($current, $this->relatedPivotKey);

        // Parse the ids to toggle
        $parsed = $this->parseIds($ids);

        foreach ($parsed as $id => $attributes) {
            if (in_array($id, $currentIds, true)) {
                $this->detach($id);
                $changes['detached'][] = $id;
            } else {
                $this->attach([$id => $attributes]);
                $changes['attached'][] = $id;
            }
        }

        return $changes;
    }

    /**
     * Update an existing pivot record
     *
     * @param mixed $id
     * @param array<string, mixed> $attributes
     * @return int
     */
    public function updateExistingPivot(mixed $id, array $attributes): int
    {
        if ($this->withTimestamps) {
            $attributes[$this->pivotUpdatedAt] = date('Y-m-d H:i:s');
        }

        return $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, '=', $this->parent->{$this->parentKey})
            ->where($this->relatedPivotKey, '=', $id)
            ->update($attributes);
    }

    /**
     * Parse a list of IDs into a normalized array
     *
     * @param mixed $ids
     * @return array<int|string, array<string, mixed>|int|string>
     */
    protected function parseIds(mixed $ids): array
    {
        if ($ids instanceof Collection) {
            $ids = $ids->pluck($this->relatedKey)->all();
        }

        if ($ids instanceof Model) {
            $ids = [$ids->{$this->relatedKey}];
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $parsed = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $parsed[$key] = $value;
            } else {
                $parsed[$value] = [];
            }
        }

        return $parsed;
    }

    /**
     * Get the qualified foreign pivot key name
     *
     * @return string
     */
    public function getQualifiedForeignPivotKeyName(): string
    {
        return "{$this->table}.{$this->foreignPivotKey}";
    }

    /**
     * Get the qualified related pivot key name
     *
     * @return string
     */
    public function getQualifiedRelatedPivotKeyName(): string
    {
        return "{$this->table}.{$this->relatedPivotKey}";
    }

    /**
     * Get the pivot table name
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the foreign pivot key name
     *
     * @return string
     */
    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Get the related pivot key name
     *
     * @return string
     */
    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the parent key name
     *
     * @return string
     */
    public function getParentKeyName(): string
    {
        return $this->parentKey;
    }

    /**
     * Get the related key name
     *
     * @return string
     */
    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
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
}
