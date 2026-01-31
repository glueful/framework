<?php

declare(strict_types=1);

namespace Glueful\Database\Factory;

use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Collection;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Base Factory Class
 *
 * Abstract base class for model factories. Provides a fluent interface
 * for generating test data with Faker integration.
 *
 * Note: Requires fakerphp/faker as a dev dependency.
 * Install with: composer require --dev fakerphp/faker
 *
 * @example
 * // Create a single user
 * $user = User::factory()->create();
 *
 * // Create multiple users
 * $users = User::factory()->count(10)->create();
 *
 * // Create with state
 * $admin = User::factory()->admin()->create();
 *
 * // Create with relationships
 * $user = User::factory()->has('posts', 3)->create();
 *
 * @template TModel of Model
 * @package Glueful\Database\Factory
 */
abstract class Factory
{
    /**
     * The Faker instance (lazy-loaded via FakerBridge)
     *
     * @var object Faker\Generator instance when available
     */
    protected object $faker;

    /**
     * The model class this factory creates
     *
     * @var class-string<TModel>
     */
    protected string $model;

    /**
     * Number of models to create
     */
    protected int $count = 1;

    /**
     * States to apply
     *
     * @var array<string>
     */
    protected array $states = [];

    /**
     * Attribute overrides
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Callbacks to run after creating models
     *
     * @var array<callable>
     */
    protected array $afterCreating = [];

    /**
     * Callbacks to run after making models (before persisting)
     *
     * @var array<callable>
     */
    protected array $afterMaking = [];

    protected ?ApplicationContext $context = null;

    /**
     * Models to recycle for relationships
     *
     * @var array<class-string, Collection<Model>>
     */
    protected array $recycle = [];

    /**
     * Sequence of attribute values
     *
     * @var array<array<string, mixed>>
     */
    protected array $sequence = [];

    /**
     * Current sequence index
     */
    protected int $sequenceIndex = 0;

    /**
     * Create a new factory instance
     *
     * @param object|null $faker Faker\Generator instance or null to auto-load
     * @throws \RuntimeException if Faker is not installed
     */
    public function __construct(?object $faker = null, ?ApplicationContext $context = null)
    {
        $this->faker = $faker ?? FakerBridge::getInstance();
        $this->context = $context;
    }

    /**
     * Define the model's default state
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Create a new factory instance for the given model
     *
     * @param class-string<TModel>|null $model
     * @return static
     */
    public static function new(?string $model = null, ?ApplicationContext $context = null): static
    {
        /** @phpstan-ignore-next-line Static factory with generics */
        $factory = new static(context: $context);

        if ($model !== null) {
            $factory->model = $model;
        }

        /** @phpstan-ignore-next-line */
        return $factory;
    }

    /**
     * Set the number of models to create
     *
     * @param int $count Number of models
     * @return static
     */
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = $count;
        return $clone;
    }

    /**
     * Apply a state transformation
     *
     * @param string|array<string, mixed>|callable $state State name, attribute overrides, or closure
     * @return static
     */
    public function state(string|array|callable $state): static
    {
        $clone = clone $this;

        if (is_callable($state)) {
            // Callable state - will be evaluated during attribute resolution
            $result = $state();
            if (is_array($result)) {
                $clone->attributes = array_merge($clone->attributes, $result);
            }
        } elseif (is_array($state)) {
            $clone->attributes = array_merge($clone->attributes, $state);
        } else {
            $clone->states[] = $state;
        }

        return $clone;
    }

    /**
     * Create a single model without persisting
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return TModel
     */
    public function make(array $attributes = []): Model
    {
        $models = $this->makeMany($attributes);
        $first = $models->first();

        if ($first === null) {
            throw new \RuntimeException('Failed to create model instance');
        }

        return $first;
    }

    /**
     * Create multiple models without persisting
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return Collection<TModel>
     */
    public function makeMany(array $attributes = []): Collection
    {
        /** @var Collection<TModel> $models */
        $models = new Collection();

        for ($i = 0; $i < $this->count; $i++) {
            $this->sequenceIndex = $i;
            $modelAttributes = $this->resolveAttributes($attributes);

            /** @var TModel $model */
            $model = new $this->model($modelAttributes);

            // Run afterMaking callbacks
            foreach ($this->afterMaking as $callback) {
                $callback($model);
            }

            $models->push($model);
        }

        return $models;
    }

    /**
     * Create a single model and persist to database
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return TModel
     */
    public function create(array $attributes = []): Model
    {
        $models = $this->createMany($attributes);
        $first = $models->first();

        if ($first === null) {
            throw new \RuntimeException('Failed to create model instance');
        }

        return $first;
    }

    /**
     * Create multiple models and persist to database
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return Collection<TModel>
     */
    public function createMany(array $attributes = []): Collection
    {
        /** @var Collection<TModel> $models */
        $models = new Collection();

        for ($i = 0; $i < $this->count; $i++) {
            $this->sequenceIndex = $i;
            $modelAttributes = $this->resolveAttributes($attributes);

            /** @var TModel $model */
            $model = $this->model::create($this->requireContext(), $modelAttributes);

            // Run afterCreating callbacks
            foreach ($this->afterCreating as $callback) {
                $callback($model);
            }

            $models->push($model);
        }

        return $models;
    }

    private function requireContext(): ApplicationContext
    {
        if ($this->context === null) {
            throw new \RuntimeException('ApplicationContext is required to create models.');
        }

        return $this->context;
    }

    /**
     * Resolve all attributes including states and overrides
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return array<string, mixed>
     */
    protected function resolveAttributes(array $attributes): array
    {
        // Start with definition
        $resolved = $this->definition();

        // Apply states
        foreach ($this->states as $state) {
            $method = $state;
            if (method_exists($this, $method)) {
                $stateResult = $this->$method();
                if ($stateResult instanceof static) {
                    // State returned a factory, merge its attributes
                    $resolved = array_merge($resolved, $stateResult->attributes);
                } else {
                    // State returned attributes directly
                    $resolved = array_merge($resolved, $stateResult);
                }
            }
        }

        // Apply sequence
        if ($this->sequence !== []) {
            $sequenceAttrs = $this->sequence[$this->sequenceIndex % count($this->sequence)];
            $resolved = array_merge($resolved, $sequenceAttrs);
        }

        // Apply attribute overrides
        $resolved = array_merge($resolved, $this->attributes, $attributes);

        // Resolve closures and relationships
        return $this->evaluateClosures($resolved);
    }

    /**
     * Evaluate closure values in attributes
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function evaluateClosures(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof \Closure) {
                $attributes[$key] = $value($this->faker);
            } elseif ($value instanceof self) {
                // Nested factory - create the related model
                $created = $value->create();
                $attributes[$key] = $created->getKey();
            }
        }

        return $attributes;
    }

    /**
     * Set a sequence of attribute values
     *
     * Values rotate through the sequence as models are created.
     *
     * @param array<string, mixed> ...$sequence Sequence of attribute arrays
     * @return static
     */
    public function sequence(array ...$sequence): static
    {
        $clone = clone $this;
        $clone->sequence = $sequence;
        return $clone;
    }

    /**
     * Recycle existing models for relationships
     *
     * Instead of creating new related models, use existing ones.
     *
     * @param Collection<Model>|Model $models Models to recycle
     * @return static
     */
    public function recycle(Collection|Model $models): static
    {
        $clone = clone $this;

        if ($models instanceof Model) {
            $models = new Collection([$models]);
        }

        $first = $models->first();
        if ($first !== null) {
            $modelClass = get_class($first);
            $clone->recycle[$modelClass] = $models;
        }

        return $clone;
    }

    /**
     * Get a recycled model or create a new one
     *
     * @param class-string<Model> $model Model class
     * @return Model
     */
    protected function getRecycled(string $model): Model
    {
        if (isset($this->recycle[$model]) && !$this->recycle[$model]->isEmpty()) {
            $items = $this->recycle[$model]->all();
            /** @var Model */
            return $items[array_rand($items)];
        }

        // Note: model::factory() requires HasFactory trait on the model
        throw new \RuntimeException(
            "No recycled models available for {$model}. " .
            "Either provide models via recycle() or ensure the model has a factory."
        );
    }

    /**
     * Add a callback to run after creating
     *
     * @param callable(TModel): void $callback
     * @return static
     */
    public function afterCreating(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;
        return $clone;
    }

    /**
     * Add a callback to run after making (before persisting)
     *
     * @param callable(TModel): void $callback
     * @return static
     */
    public function afterMaking(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterMaking[] = $callback;
        return $clone;
    }

    /**
     * Create models with a has-many relationship
     *
     * @param string $relationship Relationship method name
     * @param self<Model>|int $factory Factory or count
     * @return static
     */
    public function has(string $relationship, self|int $factory = 1): static
    {
        $count = is_int($factory) ? $factory : $factory->count;
        $relatedFactory = is_int($factory) ? null : $factory;

        return $this->afterCreating(
            function (Model $model) use ($relationship, $count, $relatedFactory): void {
                if (!method_exists($model, $relationship)) {
                    $class = get_class($model);
                    throw new \RuntimeException(
                        "Relationship method '{$relationship}' does not exist on {$class}"
                    );
                }

                $relationMethod = $model->$relationship();
                $relatedModel = $relationMethod->getRelated();
                $foreignKey = $relationMethod->getForeignKeyName();

                $factoryInstance = $relatedFactory ?? $relatedModel::factory();

                $factoryInstance->count($count)
                    ->state([$foreignKey => $model->getKey()])
                    ->create();
            }
        );
    }

    /**
     * Create model with a belongs-to relationship
     *
     * @param string $relationship Relationship method name
     * @param self<Model>|Model|null $factory Factory, model instance, or null to auto-create
     * @return static
     */
    public function for(string $relationship, self|Model|null $factory = null): static
    {
        return $this->state(function () use ($relationship, $factory): array {
            if ($factory instanceof Model) {
                $model = $factory;
            } elseif ($factory instanceof self) {
                $model = $factory->create();
            } else {
                // Will be resolved by the relationship
                return [];
            }

            // Get the foreign key from the relationship
            $tempModel = new $this->model();
            if (!method_exists($tempModel, $relationship)) {
                throw new \RuntimeException(
                    "Relationship method '{$relationship}' does not exist on " . $this->model
                );
            }

            $relationMethod = $tempModel->$relationship();

            return [
                $relationMethod->getForeignKeyName() => $model->getKey(),
            ];
        });
    }

    /**
     * Get the model class for this factory
     *
     * @return class-string<TModel>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the current count
     */
    public function getCount(): int
    {
        return $this->count;
    }
}
