<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use Glueful\Database\ORM\Collection as OrmCollection;
use Glueful\Database\ORM\Model;

/**
 * Model Resource - ORM-aware API Response Transformer
 *
 * Extends JsonResource with ORM-specific functionality for
 * working with Glueful ORM models. Provides:
 *
 * - Automatic relationship detection
 * - Pivot data access
 * - Relationship count handling
 * - Model-specific helpers
 *
 * @example
 * ```php
 * class UserResource extends ModelResource
 * {
 *     public function toArray(): array
 *     {
 *         return [
 *             'id' => $this->uuid,
 *             'name' => $this->name,
 *             'email' => $this->email,
 *
 *             // Only included if relationship is loaded
 *             'posts' => PostResource::collection($this->whenLoaded('posts')),
 *             'posts_count' => $this->whenCounted('posts'),
 *
 *             // Pivot data for many-to-many
 *             'role' => $this->whenPivotLoaded('role_user', 'role_name'),
 *         ];
 *     }
 * }
 * ```
 *
 * @template TModel of Model
 * @extends JsonResource<TModel>
 * @package Glueful\Http\Resources
 */
class ModelResource extends JsonResource
{
    /**
     * Get the model's primary key value
     *
     * @return mixed
     */
    protected function getKey(): mixed
    {
        if ($this->resource instanceof Model) {
            return $this->resource->getKey();
        }

        return $this->resource['id'] ?? null;
    }

    /**
     * Get a model attribute with optional default
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function attribute(string $key, mixed $default = null): mixed
    {
        if ($this->resource instanceof Model) {
            return $this->resource->getAttribute($key) ?? $default;
        }

        if (is_array($this->resource)) {
            return $this->resource[$key] ?? $default;
        }

        return $this->resource->$key ?? $default;
    }

    /**
     * Check if the model has a specific attribute
     *
     * @param string $key
     * @return bool
     */
    protected function hasAttribute(string $key): bool
    {
        if ($this->resource instanceof Model) {
            $attributes = $this->resource->getAttributes();
            return array_key_exists($key, $attributes);
        }

        if (is_array($this->resource)) {
            return array_key_exists($key, $this->resource);
        }

        return isset($this->resource->$key);
    }

    /**
     * Get all of the model's loaded relationships
     *
     * @return array<string, mixed>
     */
    protected function getLoadedRelations(): array
    {
        if ($this->resource instanceof Model) {
            return $this->resource->getRelations();
        }

        return [];
    }

    /**
     * Check if any of the given relationships are loaded
     *
     * @param string ...$relations
     * @return bool
     */
    protected function hasAnyRelationLoaded(string ...$relations): bool
    {
        foreach ($relations as $relation) {
            if ($this->isRelationLoaded($relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all of the given relationships are loaded
     *
     * @param string ...$relations
     * @return bool
     */
    protected function hasAllRelationsLoaded(string ...$relations): bool
    {
        foreach ($relations as $relation) {
            if (!$this->isRelationLoaded($relation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a relationship is loaded on the model
     *
     * @param string $relation
     * @return bool
     */
    protected function isRelationLoaded(string $relation): bool
    {
        if ($this->resource instanceof Model) {
            return $this->resource->relationLoaded($relation);
        }

        if (is_array($this->resource)) {
            return array_key_exists($relation, $this->resource);
        }

        return isset($this->resource->$relation);
    }

    /**
     * Get a loaded relationship or null
     *
     * @param string $relation
     * @return mixed
     */
    protected function getRelation(string $relation): mixed
    {
        if ($this->resource instanceof Model) {
            return $this->resource->getRelation($relation);
        }

        if (is_array($this->resource)) {
            return $this->resource[$relation] ?? null;
        }

        return $this->resource->$relation ?? null;
    }

    /**
     * Transform a loaded collection relationship to resources
     *
     * @param string $relation The relationship name
     * @param string|null $resourceClass Resource class to use
     * @return ResourceCollection|AnonymousResourceCollection|Support\MissingValue
     *
     * @phpstan-ignore-next-line
     */
    protected function relationshipCollection(
        string $relation,
        ?string $resourceClass = null
    ): ResourceCollection|AnonymousResourceCollection|Support\MissingValue {
        if (!$this->isRelationLoaded($relation)) {
            return new Support\MissingValue();
        }

        $related = $this->getRelation($relation);

        if ($related === null) {
            return new Support\MissingValue();
        }

        // Handle ORM Collection
        if ($related instanceof OrmCollection) {
            $items = $related->all();
        } elseif (is_iterable($related)) {
            $items = $related;
        } else {
            return new Support\MissingValue();
        }

        if ($resourceClass !== null) {
            return new AnonymousResourceCollection($items, $resourceClass);
        }

        return ResourceCollection::make($items);
    }

    /**
     * Transform a loaded single relationship to a resource
     *
     * @param string $relation The relationship name
     * @param string|null $resourceClass Resource class to use
     * @return JsonResource<mixed>|Support\MissingValue
     */
    protected function relationshipResource(
        string $relation,
        ?string $resourceClass = null
    ): JsonResource|Support\MissingValue {
        if (!$this->isRelationLoaded($relation)) {
            return new Support\MissingValue();
        }

        $related = $this->getRelation($relation);

        if ($related === null) {
            return new Support\MissingValue();
        }

        if ($resourceClass !== null) {
            return new $resourceClass($related);
        }

        return JsonResource::make($related);
    }

    /**
     * Get a date attribute formatted as ISO 8601
     *
     * @param string $key
     * @return string|null
     */
    protected function dateAttribute(string $key): ?string
    {
        $value = $this->attribute($key);

        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value)) {
            try {
                return (new \DateTime($value))->format(\DateTimeInterface::ATOM);
            } catch (\Exception) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get a date attribute formatted as ISO 8601, only if not null
     *
     * @param string $key
     * @return string|Support\MissingValue
     */
    protected function whenDateNotNull(string $key): string|Support\MissingValue
    {
        $formatted = $this->dateAttribute($key);

        if ($formatted === null) {
            return new Support\MissingValue();
        }

        return $formatted;
    }

    /**
     * Create a collection of resources from the current resource class
     *
     * @param iterable<TModel> $resources
     * @return AnonymousResourceCollection
     *
     * @phpstan-ignore-next-line
     */
    public static function collection(iterable $resources): AnonymousResourceCollection
    {
        // Handle ORM Collection
        if ($resources instanceof OrmCollection) {
            $resources = $resources->all();
        }

        return new AnonymousResourceCollection($resources, static::class);
    }
}
