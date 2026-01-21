<?php

declare(strict_types=1);

namespace Glueful\Database\ORM;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Model Collection
 *
 * A collection class for working with arrays of models. Provides
 * a fluent interface for common array operations with type safety
 * and model-specific functionality like eager loading.
 *
 * @template TModel of object
 * @implements ArrayAccess<int|string, TModel>
 * @implements IteratorAggregate<int|string, TModel>
 */
final class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The items contained in the collection
     *
     * @var array<int|string, TModel>
     */
    protected array $items = [];

    /**
     * Create a new collection instance
     *
     * @param array<int|string, TModel> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Create a new collection instance
     *
     * @param array<int|string, TModel> $items
     * @return static
     */
    public static function make(array $items = []): static
    {
        return new self($items);
    }

    /**
     * Get all items in the collection
     *
     * @return array<int|string, TModel>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the first item from the collection
     *
     * @param callable|null $callback
     * @param mixed $default
     * @return TModel|mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[array_key_first($this->items)] ?? $default;
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the last item from the collection
     *
     * @param callable|null $callback
     * @param mixed $default
     * @return TModel|mixed
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[array_key_last($this->items)] ?? $default;
        }

        $items = array_reverse($this->items, true);

        foreach ($items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get an item from the collection by key
     *
     * @param int|string $key
     * @param mixed $default
     * @return TModel|mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Determine if an item exists in the collection by key
     *
     * @param int|string $key
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Determine if the collection is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Determine if the collection is not empty
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the number of items in the collection
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Run a map over each of the items
     *
     * @param callable $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);

        return new self(array_combine($keys, $items));
    }

    /**
     * Run a filter over each of the items
     *
     * @param callable|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }

        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Filter items by the given key value pair
     *
     * @param string $key
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        // If only two arguments, assume '=' operator
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $retrieved = $this->getDataValue($item, $key);

            return match ($operator) {
                '=' => $retrieved === $value,
                '!=' => $retrieved !== $value,
                '<' => $retrieved < $value,
                '>' => $retrieved > $value,
                '<=' => $retrieved <= $value,
                '>=' => $retrieved >= $value,
                default => $retrieved === $value,
            };
        });
    }

    /**
     * Filter items where the value is null
     *
     * @param string $key
     * @return static
     */
    public function whereNull(string $key): static
    {
        return $this->where($key, '=', null);
    }

    /**
     * Filter items where the value is not null
     *
     * @param string $key
     * @return static
     */
    public function whereNotNull(string $key): static
    {
        return $this->where($key, '!=', null);
    }

    /**
     * Filter items by the given key value pair using "in" comparison
     *
     * @param string $key
     * @param array<mixed> $values
     * @return static
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(function ($item) use ($key, $values) {
            return in_array($this->getDataValue($item, $key), $values, true);
        });
    }

    /**
     * Filter items by the given key value pair using "not in" comparison
     *
     * @param string $key
     * @param array<mixed> $values
     * @return static
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(function ($item) use ($key, $values) {
            return !in_array($this->getDataValue($item, $key), $values, true);
        });
    }

    /**
     * Get the values of a given key
     *
     * @param string $value
     * @param string|null $key
     * @return static
     */
    public function pluck(string $value, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = $this->getDataValue($item, $value);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = $this->getDataValue($item, $key);
                $results[$itemKey] = $itemValue;
            }
        }

        return new self($results);
    }

    /**
     * Key the collection by the given key
     *
     * @param string|callable $keyBy
     * @return static
     */
    public function keyBy(string|callable $keyBy): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $key = is_callable($keyBy)
                ? $keyBy($item)
                : $this->getDataValue($item, $keyBy);

            $results[$key] = $item;
        }

        return new self($results);
    }

    /**
     * Group the collection by a given key
     *
     * @param string|callable $groupBy
     * @return static
     */
    public function groupBy(string|callable $groupBy): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $groupKey = is_callable($groupBy)
                ? $groupBy($item)
                : $this->getDataValue($item, $groupBy);

            if (!isset($results[$groupKey])) {
                $results[$groupKey] = [];
            }

            $results[$groupKey][] = $item;
        }

        // Convert inner arrays to collections
        foreach ($results as $key => $items) {
            $results[$key] = new self($items);
        }

        return new self($results);
    }

    /**
     * Sort the collection by the given key
     *
     * @param string|callable $key
     * @param bool $descending
     * @return static
     */
    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $results = $this->items;

        usort($results, function ($a, $b) use ($key, $descending) {
            $aValue = is_callable($key) ? $key($a) : $this->getDataValue($a, $key);
            $bValue = is_callable($key) ? $key($b) : $this->getDataValue($b, $key);

            $result = $aValue <=> $bValue;

            return $descending ? -$result : $result;
        });

        return new self($results);
    }

    /**
     * Sort the collection in descending order by the given key
     *
     * @param string|callable $key
     * @return static
     */
    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    /**
     * Reverse items order
     *
     * @return static
     */
    public function reverse(): static
    {
        return new self(array_reverse($this->items, true));
    }

    /**
     * Reset the keys on the underlying array
     *
     * @return static
     */
    public function values(): static
    {
        return new self(array_values($this->items));
    }

    /**
     * Get the keys of the collection items
     *
     * @return static
     */
    public function keys(): static
    {
        return new self(array_keys($this->items));
    }

    /**
     * Get a slice of items
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Take the first {$limit} items
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Execute a callback over each item
     *
     * @param callable $callback
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Reduce the collection to a single value
     *
     * @param callable $callback
     * @param mixed $initial
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Push an item onto the end of the collection
     *
     * @param TModel ...$values
     * @return static
     */
    public function push(mixed ...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    /**
     * Put an item in the collection by key
     *
     * @param int|string $key
     * @param TModel $value
     * @return static
     */
    public function put(int|string $key, mixed $value): static
    {
        $this->items[$key] = $value;

        return $this;
    }

    /**
     * Merge the collection with the given items
     *
     * @param array<int|string, TModel>|Collection<TModel> $items
     * @return static
     */
    public function merge(array|Collection $items): static
    {
        if ($items instanceof Collection) {
            $items = $items->all();
        }

        return new self(array_merge($this->items, $items));
    }

    /**
     * Determine if the collection contains a given item
     *
     * @param mixed $key
     * @param mixed $operator
     * @param mixed $value
     * @return bool
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key) && !is_string($key)) {
                return $this->first($key) !== null;
            }

            return in_array($key, $this->items, true);
        }

        return $this->where($key, $operator, $value)->isNotEmpty();
    }

    /**
     * Get the sum of the given key
     *
     * @param string|callable|null $key
     * @return int|float
     */
    public function sum(string|callable|null $key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }

        return $this->reduce(function ($result, $item) use ($key) {
            $value = is_callable($key) ? $key($item) : $this->getDataValue($item, $key);

            return $result + ($value ?? 0);
        }, 0);
    }

    /**
     * Get the average value of a given key
     *
     * @param string|callable|null $key
     * @return int|float|null
     */
    public function avg(string|callable|null $key = null): int|float|null
    {
        $count = $this->count();

        if ($count === 0) {
            return null;
        }

        return $this->sum($key) / $count;
    }

    /**
     * Get the max value of a given key
     *
     * @param string|callable|null $key
     * @return mixed
     */
    public function max(string|callable|null $key = null): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($key === null) {
            return max($this->items);
        }

        return $this->pluck(is_string($key) ? $key : '')->max();
    }

    /**
     * Get the min value of a given key
     *
     * @param string|callable|null $key
     * @return mixed
     */
    public function min(string|callable|null $key = null): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($key === null) {
            return min($this->items);
        }

        return $this->pluck(is_string($key) ? $key : '')->min();
    }

    /**
     * Get unique items from the collection
     *
     * @param string|callable|null $key
     * @return static
     */
    public function unique(string|callable|null $key = null): static
    {
        if ($key === null) {
            return new self(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        $results = [];

        foreach ($this->items as $item) {
            $value = is_callable($key) ? $key($item) : $this->getDataValue($item, $key);

            if (!in_array($value, $exists, true)) {
                $exists[] = $value;
                $results[] = $item;
            }
        }

        return new self($results);
    }

    /**
     * Load a set of relationships onto the models
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function load(array|string $relations): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $first = $this->first();

        if ($first === null || !is_object($first)) {
            return $this;
        }

        // Check if the first item is a Model with a query method
        if (!method_exists($first, 'newQuery')) {
            return $this;
        }

        // Use the first model's class to create a query and load relations
        /** @var Model $first */
        $builder = $first->newQuery();

        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $builder->loadRelationsOnModels($this->items, $relations);

        return $this;
    }

    /**
     * Get the array representation of the collection
     *
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return array_map(function ($item) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }

            return $item;
        }, $this->items);
    }

    /**
     * Get the JSON representation of the collection
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Get the JSON-serializable representation
     *
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the data value from an item
     *
     * @param mixed $item
     * @param string $key
     * @return mixed
     */
    protected function getDataValue(mixed $item, string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if (is_object($item)) {
            return $item->{$key} ?? null;
        }

        return null;
    }

    /**
     * Eager load relations on all models if not already loaded
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function loadMissing(array|string $relations): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        if (is_string($relations)) {
            $relations = func_get_args();
        }

        // Filter out relations that are already loaded on all models
        $first = $this->first();

        if ($first === null || !method_exists($first, 'relationLoaded')) {
            return $this;
        }

        $missing = [];
        foreach ($relations as $key => $value) {
            $name = is_numeric($key) ? $value : $key;
            $baseName = explode('.', $name)[0];

            // Check if any model is missing this relation
            $anyMissing = false;
            foreach ($this->items as $model) {
                if (method_exists($model, 'relationLoaded') && !$model->relationLoaded($baseName)) {
                    $anyMissing = true;
                    break;
                }
            }

            if ($anyMissing) {
                $missing[$key] = $value;
            }
        }

        if ($missing !== []) {
            $this->load($missing);
        }

        return $this;
    }

    /**
     * Get an iterator for the items
     *
     * @return Traversable<int|string, TModel>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Determine if an item exists at an offset
     *
     * @param int|string $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Get an item at a given offset
     *
     * @param int|string $offset
     * @return TModel
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * Set the item at a given offset
     *
     * @param int|string|null $offset
     * @param TModel $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset
     *
     * @param int|string $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}
