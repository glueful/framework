<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Relations\BelongsTo;
use Glueful\Database\ORM\Relations\BelongsToMany;
use Glueful\Database\ORM\Relations\HasMany;
use Glueful\Database\ORM\Relations\HasManyThrough;
use Glueful\Database\ORM\Relations\HasOne;
use Glueful\Database\ORM\Relations\HasOneThrough;
use Glueful\Database\ORM\Relations\Relation;

/**
 * Has Relationships Trait
 *
 * Provides relationship functionality for ORM models. Supports
 * one-to-one (hasOne), one-to-many (hasMany), and inverse (belongsTo)
 * relationships with eager loading support.
 */
trait HasRelationships
{
    /**
     * The loaded relationships for the model
     *
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * The relationships that should be touched on save
     *
     * @var array<string>
     */
    protected array $touches = [];

    /**
     * Define a one-to-one relationship
     *
     * @param class-string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @return HasOne
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new HasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship
     *
     * @param class-string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @return HasMany
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new HasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a has-one-through relationship
     *
     * @param class-string $related The final related model
     * @param class-string $through The intermediate model
     * @param string|null $firstKey Foreign key on the intermediate model
     * @param string|null $secondKey Foreign key on the final model
     * @param string|null $localKey Local key on this model
     * @param string|null $secondLocalKey Local key on the intermediate model
     * @return HasOneThrough
     */
    public function hasOneThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): HasOneThrough {
        $throughInstance = $this->newRelatedInstance($through);
        $relatedInstance = $this->newRelatedInstance($related);

        $firstKey = $firstKey ?? $this->getForeignKey();
        $secondKey = $secondKey ?? $throughInstance->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();
        $secondLocalKey = $secondLocalKey ?? $throughInstance->getKeyName();

        return new HasOneThrough(
            $relatedInstance->newQuery(),
            $this,
            $throughInstance,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    /**
     * Define a has-many-through relationship
     *
     * @param class-string $related The final related model
     * @param class-string $through The intermediate model
     * @param string|null $firstKey Foreign key on the intermediate model
     * @param string|null $secondKey Foreign key on the final model
     * @param string|null $localKey Local key on this model
     * @param string|null $secondLocalKey Local key on the intermediate model
     * @return HasManyThrough
     */
    public function hasManyThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): HasManyThrough {
        $throughInstance = $this->newRelatedInstance($through);
        $relatedInstance = $this->newRelatedInstance($related);

        $firstKey = $firstKey ?? $this->getForeignKey();
        $secondKey = $secondKey ?? $throughInstance->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();
        $secondLocalKey = $secondLocalKey ?? $throughInstance->getKeyName();

        return new HasManyThrough(
            $relatedInstance->newQuery(),
            $this,
            $throughInstance,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey
        );
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship
     *
     * @param class-string $related
     * @param string|null $foreignKey
     * @param string|null $ownerKey
     * @param string|null $relation
     * @return BelongsTo
     */
    public function belongsTo(
        string $related,
        ?string $foreignKey = null,
        ?string $ownerKey = null,
        ?string $relation = null
    ): BelongsTo {
        // If no relation name was given, use the calling method name
        if ($relation === null) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // If no foreign key was supplied, we can guess it from the relation name
        if ($foreignKey === null) {
            $foreignKey = $this->snakeCase($relation) . '_' . $instance->getKeyName();
        }

        $ownerKey = $ownerKey ?? $instance->getKeyName();

        return new BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Define a many-to-many relationship
     *
     * @param class-string $related The related model class
     * @param string|null $table The pivot table name
     * @param string|null $foreignPivotKey The foreign key on the pivot table for this model
     * @param string|null $relatedPivotKey The foreign key on the pivot table for the related model
     * @param string|null $parentKey The local key on this model
     * @param string|null $relatedKey The local key on the related model
     * @param string|null $relation The relation name
     * @return BelongsToMany
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null
    ): BelongsToMany {
        // If no relation name was given, use the calling method name
        if ($relation === null) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // Guess the pivot table name if not provided
        if ($table === null) {
            $table = $this->joiningTable($related);
        }

        // Guess the pivot keys
        $foreignPivotKey = $foreignPivotKey ?? $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?? $instance->getForeignKey();

        // Default to primary keys
        $parentKey = $parentKey ?? $this->getKeyName();
        $relatedKey = $relatedKey ?? $instance->getKeyName();

        return new BelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relation
        );
    }

    /**
     * Get the joining/pivot table name for a many-to-many relationship
     *
     * The table name is derived by sorting the two model table names
     * alphabetically and joining them with an underscore.
     *
     * @param class-string $related
     * @return string
     */
    protected function joiningTable(string $related): string
    {
        $instance = $this->newRelatedInstance($related);

        $tables = [
            $this->snakeCase(class_basename(static::class)),
            $this->snakeCase(class_basename($related)),
        ];

        // Sort alphabetically
        sort($tables);

        return strtolower(implode('_', $tables));
    }

    /**
     * Create a new model instance for a related model
     *
     * @param class-string $class
     * @return object
     */
    protected function newRelatedInstance(string $class): object
    {
        return new $class();
    }

    /**
     * Get the default foreign key name for the model
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->snakeCase(class_basename(static::class)) . '_' . $this->getKeyName();
    }

    /**
     * Guess the "belongs to" relationship name
     *
     * @return string
     */
    protected function guessBelongsToRelation(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $trace[2]['function'] ?? 'unknown';
    }

    /**
     * Get a relationship value from the model
     *
     * @param string $key
     * @return mixed
     */
    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    /**
     * Set a relationship value on the model
     *
     * @param string $relation
     * @param mixed $value
     * @return static
     */
    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Unset a loaded relationship
     *
     * @param string $relation
     * @return static
     */
    public function unsetRelation(string $relation): static
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Set the given relationships on the model
     *
     * @param array<string, mixed> $relations
     * @return static
     */
    public function setRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Get all the loaded relations for the instance
     *
     * @return array<string, mixed>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Determine if the given relation is loaded
     *
     * @param string $key
     * @return bool
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Get a relationship value dynamically
     *
     * @param string $key
     * @return mixed
     */
    public function getRelationValue(string $key): mixed
    {
        // If the relation is already loaded, return it
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        // If a relation method exists, load and cache it
        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    /**
     * Get a relationship value from a method
     *
     * @param string $method
     * @return mixed
     */
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->$method();

        if (!$relation instanceof Relation) {
            return null;
        }

        $results = $relation->getResults();

        $this->setRelation($method, $results);

        return $results;
    }

    /**
     * Touch the owning relations of the model
     *
     * @return void
     */
    public function touchOwners(): void
    {
        foreach ($this->touches as $relation) {
            if (method_exists($this, $relation)) {
                $relationObj = $this->$relation();

                if ($relationObj instanceof BelongsTo) {
                    $parent = $relationObj->getResults();
                    if ($parent !== null && method_exists($parent, 'touch')) {
                        $parent->touch();
                    }
                }
            }
        }
    }

    /**
     * Convert the model's relationships to an array
     *
     * @return array<string, mixed>
     */
    public function relationsToArray(): array
    {
        $attributes = [];

        foreach ($this->relations as $key => $value) {
            if ($value instanceof Collection) {
                $attributes[$key] = $value->toArray();
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $attributes[$key] = $value->toArray();
            } elseif ($value === null) {
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }

    /**
     * Convert a string to snake_case
     *
     * @param string $value
     * @return string
     */
    protected function snakeCase(string $value): string
    {
        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));
            $value = mb_strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $value), 'UTF-8');
        }

        return $value;
    }
}

/**
 * Get the class "basename" of a class string
 *
 * @param string|object $class
 * @return string
 */
function class_basename(string|object $class): string
{
    $class = is_object($class) ? $class::class : $class;
    $pos = strrpos($class, '\\');

    return $pos === false ? $class : substr($class, $pos + 1);
}
