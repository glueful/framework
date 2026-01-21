<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Concerns;

use Closure;
use Glueful\Http\Resources\Support\MissingValue;

/**
 * Conditional Attribute Loading
 *
 * Provides methods for conditionally including attributes in resources.
 * When conditions are not met, a MissingValue sentinel is returned,
 * which is filtered out during resource resolution.
 *
 * @package Glueful\Http\Resources\Concerns
 */
trait ConditionallyLoadsAttributes
{
    /**
     * Include a value only when a condition is true
     *
     * @param bool|Closure $condition The condition to evaluate
     * @param mixed|Closure $value Value to include (or closure that returns value)
     * @param mixed $default Default value when condition is false (optional)
     * @return mixed The value, default, or MissingValue sentinel
     */
    protected function when(bool|Closure $condition, mixed $value, mixed $default = null): mixed
    {
        $conditionResult = $condition instanceof Closure ? $condition() : $condition;

        if ($conditionResult) {
            return $value instanceof Closure ? $value() : $value;
        }

        if (func_num_args() === 3) {
            return $default instanceof Closure ? $default() : $default;
        }

        return new MissingValue();
    }

    /**
     * Include a value only when it's not null
     *
     * @param mixed $value The value to check
     * @param Closure|null $callback Optional transformation callback
     * @return mixed The value (optionally transformed) or MissingValue
     */
    protected function whenNotNull(mixed $value, ?Closure $callback = null): mixed
    {
        return $this->when(
            $value !== null,
            fn() => $callback !== null ? $callback($value) : $value
        );
    }

    /**
     * Include a value only when it's not empty
     *
     * @param mixed $value The value to check
     * @param Closure|null $callback Optional transformation callback
     * @return mixed The value (optionally transformed) or MissingValue
     */
    protected function whenNotEmpty(mixed $value, ?Closure $callback = null): mixed
    {
        $isEmpty = $value === null
            || $value === ''
            || $value === []
            || $value === 0
            || $value === '0'
            || $value === false;

        return $this->when(
            !$isEmpty,
            fn() => $callback !== null ? $callback($value) : $value
        );
    }

    /**
     * Include a relationship only when it's been loaded
     *
     * Works with ORM models that have relationship loading tracking,
     * arrays with the key present, or objects with the property set.
     *
     * @param string $relationship The relationship name
     * @param mixed $default Default value when not loaded (optional)
     * @return mixed The relationship data or MissingValue
     */
    protected function whenLoaded(string $relationship, mixed $default = null): mixed
    {
        // Check if resource has relationLoaded method (ORM model)
        if (is_object($this->resource) && method_exists($this->resource, 'relationLoaded')) {
            if ($this->resource->relationLoaded($relationship)) {
                // Use getRelation for ORM models to get the cached relation
                if (method_exists($this->resource, 'getRelation')) {
                    $value = $this->resource->getRelation($relationship);
                } else {
                    $value = $this->resource->$relationship;
                }

                // Convert ORM Collection to array for consistency
                if (is_object($value) && method_exists($value, 'all')) {
                    return $value->all();
                }

                return $value;
            }

            // Relation exists but not loaded
            if (func_num_args() === 2) {
                return $default instanceof Closure ? $default() : $default;
            }

            return new MissingValue();
        }

        // Check if it's an array with the key present
        if (is_array($this->resource) && array_key_exists($relationship, $this->resource)) {
            return $this->resource[$relationship];
        }

        // Check if it's an object with the property
        if (is_object($this->resource) && isset($this->resource->$relationship)) {
            return $this->resource->$relationship;
        }

        if (func_num_args() === 2) {
            return $default instanceof Closure ? $default() : $default;
        }

        return new MissingValue();
    }

    /**
     * Include a count only when it's been loaded
     *
     * @param string $relationship The relationship name (will check for {relationship}_count)
     * @param mixed $default Default value when not loaded
     * @return mixed The count or MissingValue
     */
    protected function whenCounted(string $relationship, mixed $default = null): mixed
    {
        $countKey = $relationship . '_count';

        // Check if resource is an ORM model with getAttribute method
        if (is_object($this->resource) && method_exists($this->resource, 'getAttribute')) {
            $count = $this->resource->getAttribute($countKey);
            if ($count !== null) {
                return $count;
            }
        } elseif (is_object($this->resource) && isset($this->resource->$countKey)) {
            return $this->resource->$countKey;
        }

        if (is_array($this->resource) && array_key_exists($countKey, $this->resource)) {
            return $this->resource[$countKey];
        }

        if (func_num_args() === 2) {
            return $default instanceof Closure ? $default() : $default;
        }

        return new MissingValue();
    }

    /**
     * Include pivot attributes when available
     *
     * @param string $table The pivot table name
     * @param string $attribute Pivot attribute name
     * @param mixed $default Default value
     * @return mixed The pivot attribute or MissingValue
     */
    protected function whenPivotLoaded(string $table, string $attribute, mixed $default = null): mixed
    {
        if (!is_object($this->resource)) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        // Check for pivot property (ORM model)
        $pivot = $this->resource->pivot ?? null;

        if ($pivot === null) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        // Verify it's the right pivot table
        if (method_exists($pivot, 'getTable') && $pivot->getTable() !== $table) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        return $pivot->$attribute ?? $default;
    }

    /**
     * Include pivot attributes using the pivot accessor
     *
     * @param string $attribute Pivot attribute name
     * @param mixed $default Default value
     * @return mixed The pivot attribute or MissingValue
     */
    protected function whenPivotLoadedAs(string $accessor, string $attribute, mixed $default = null): mixed
    {
        if (!is_object($this->resource)) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        $pivot = $this->resource->$accessor ?? null;

        if ($pivot === null) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        return $pivot->$attribute ?? $default;
    }

    /**
     * Merge values conditionally into the response
     *
     * @param bool|Closure $condition The condition to evaluate
     * @param array<string, mixed>|Closure $values Values to merge
     * @return array<string, mixed>|MissingValue The values or MissingValue
     */
    protected function mergeWhen(bool|Closure $condition, array|Closure $values): array|MissingValue
    {
        $conditionResult = $condition instanceof Closure ? $condition() : $condition;

        if (!$conditionResult) {
            return new MissingValue();
        }

        return $values instanceof Closure ? $values() : $values;
    }

    /**
     * Merge values unconditionally
     *
     * Helper for including computed arrays in the response.
     *
     * @param array<string, mixed>|Closure $values Values to merge
     * @return array<string, mixed>
     */
    protected function merge(array|Closure $values): array
    {
        return $values instanceof Closure ? $values() : $values;
    }

    /**
     * Include attributes based on the request's field selection
     *
     * Integrates with FieldSelector for GraphQL-style field selection.
     *
     * @param string $field The field name
     * @param mixed $value The value to include
     * @return mixed The value or MissingValue
     */
    protected function whenRequested(string $field, mixed $value): mixed
    {
        $request = $this->getRequest();

        if ($request === null) {
            return $value instanceof Closure ? $value() : $value;
        }

        /** @var mixed $fields */
        $fields = $request->query->all()['fields'] ?? '*';

        if ($fields === '*') {
            return $value instanceof Closure ? $value() : $value;
        }

        // Handle both array and string formats
        if (is_array($fields)) {
            $requestedFields = array_map('trim', $fields);
        } else {
            $requestedFields = array_map('trim', explode(',', (string) $fields));
        }

        return $this->when(
            in_array($field, $requestedFields, true),
            $value
        );
    }

    /**
     * Get the current request
     *
     * @return \Symfony\Component\HttpFoundation\Request|null
     */
    protected function getRequest(): ?\Symfony\Component\HttpFoundation\Request
    {
        // Create request from PHP globals
        // This follows the same pattern as Glueful\Helpers\RequestHelper
        return \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    }
}
