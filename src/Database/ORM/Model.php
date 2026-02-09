<?php

declare(strict_types=1);

namespace Glueful\Database\ORM;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\ORM\Concerns\HasAttributes;
use Glueful\Database\ORM\Concerns\HasEvents;
use Glueful\Database\ORM\Concerns\HasGlobalScopes;
use Glueful\Database\ORM\Concerns\HasRelationships;
use Glueful\Database\ORM\Concerns\HasTimestamps;
use Glueful\Database\ORM\Contracts\ModelInterface;
use Glueful\Database\QueryBuilder;
use JsonSerializable;

/**
 * Base Model Class
 *
 * Active Record implementation for the ORM. Provides a rich set of
 * features for working with database records as objects, including:
 *
 * - Automatic attribute management with dirty tracking
 * - Timestamp handling (created_at, updated_at)
 * - Relationships (hasOne, hasMany, belongsTo)
 * - Global query scopes
 * - Model lifecycle events
 * - Mass assignment protection
 *
 * @example
 * // Define a model
 * class User extends Model
 * {
 *     protected string $table = 'users';
 *     protected array $fillable = ['name', 'email'];
 *
 *     public function posts(): HasMany
 *     {
 *         return $this->hasMany(Post::class);
 *     }
 * }
 *
 * // Create a new user
 * $user = User::create($context, ['name' => 'John', 'email' => 'john@example.com']);
 *
 * // Find and update
 * $user = User::find($context, 1);
 * $user->name = 'Jane';
 * $user->save();
 *
 * // Query with relationships
 * $users = User::with($context, 'posts')->where('active', true)->get();
 */
abstract class Model implements ModelInterface, JsonSerializable
{
    use HasAttributes;
    use HasTimestamps {
        HasTimestamps::fromDateTime insteadof HasAttributes;
    }
    use HasEvents;
    use HasGlobalScopes;
    use HasRelationships;

    /**
     * The database connection that should be used
     */
    protected ?Connection $connection = null;

    /**
     * Application context (optional, required for container-backed services)
     */
    protected ?ApplicationContext $context = null;

    /**
     * Default application context for static calls
     */
    private static ?ApplicationContext $defaultContext = null;

    /**
     * The table associated with the model
     */
    protected string $table = '';

    /**
     * The primary key for the model
     */
    protected string $primaryKey = 'id';

    /**
     * The "type" of the primary key ID
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
     * Indicates if the model was inserted during the current request lifecycle
     */
    public bool $wasRecentlyCreated = false;

    /**
     * The number of models to return for pagination
     */
    protected int $perPage = 15;

    /**
     * Create a new model instance
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [], ?ApplicationContext $context = null)
    {
        $this->context = $context;
        $this->bootIfNotBooted();
        $this->fill($attributes);
    }

    /**
     * Boot the model if it hasn't been booted
     *
     * @return void
     */
    protected function bootIfNotBooted(): void
    {
        $class = static::class;

        if (!isset(static::$booted[$class])) {
            static::$booted[$class] = true;

            static::bootTraits();
            static::boot();
        }
    }

    /**
     * The array of booted models
     *
     * @var array<class-string, bool>
     */
    protected static array $booted = [];

    /**
     * Boot the model
     *
     * Override this method in your model to perform any initialization.
     *
     * @return void
     */
    protected static function boot(): void
    {
        // Override in subclasses for custom boot logic
    }

    /**
     * Boot all of the bootable traits on the model
     *
     * @return void
     */
    protected static function bootTraits(): void
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {
            $method = 'boot' . class_basename($trait);

            if (method_exists($class, $method)) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Set the default application context for static model calls
     */
    public static function setDefaultContext(ApplicationContext $context): void
    {
        self::$defaultContext = $context;
    }

    public function setContext(ApplicationContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): ?ApplicationContext
    {
        return $this->context;
    }

    /**
     * Get the database connection for the model
     *
     * @return Connection
     */
    public function getConnection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if ($this->context !== null && $this->context->hasContainer()) {
            return $this->context->getContainer()->get('database');
        }

        throw new \RuntimeException(
            'No database connection available. Provide ApplicationContext or set a connection.'
        );
    }

    /**
     * Set the database connection for the model
     *
     * @param Connection $connection
     * @return static
     */
    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Get the table associated with the model
     *
     * @return string
     */
    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        // Auto-generate table name from class name
        return $this->snakeCase($this->pluralize(class_basename(static::class)));
    }

    /**
     * Set the table associated with the model
     *
     * @param string $table
     * @return static
     */
    public function setTable(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Get the primary key for the model
     *
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Set the primary key for the model
     *
     * @param string $key
     * @return static
     */
    public function setKeyName(string $key): static
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Get the value of the model's primary key
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the auto-incrementing key type
     *
     * @return string
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Set the data type for the primary key
     *
     * @param string $type
     * @return static
     */
    public function setKeyType(string $type): static
    {
        $this->keyType = $type;

        return $this;
    }

    /**
     * Get the number of models to return per page
     *
     * @return int
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Set the number of models to return per page
     *
     * @param int $perPage
     * @return static
     */
    public function setPerPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Create a new instance of the given model
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function newInstance(array $attributes = []): static
    {
        $model = new static($attributes, $this->context);

        $model->connection = $this->connection;

        return $model;
    }

    /**
     * Create a new model instance from a database result
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function newFromBuilder(array $attributes = []): static
    {
        $model = $this->newInstance();

        $model->setRawAttributes($attributes);
        $model->syncOriginal();
        $model->exists = true;

        $model->fireRetrievedEvent();

        return $model;
    }

    /**
     * Begin querying the model
     *
     * @return Builder
     */
    public static function query(ApplicationContext $context): Builder
    {
        return (new static([], $context))->newQuery();
    }

    /**
     * Get a new query builder for the model's table
     *
     * @return Builder
     */
    public function newQuery(): Builder
    {
        return $this->newModelQuery();
    }

    /**
     * Get a new query builder for the model
     *
     * @return Builder
     */
    public function newModelQuery(): Builder
    {
        return (new Builder($this->newBaseQueryBuilder()))
            ->setModel($this);
    }

    /**
     * Get a new query builder instance for the connection
     *
     * @return QueryBuilder
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        return $this->getConnection()->table($this->getTable());
    }

    /**
     * Save the model to the database
     *
     * @return bool
     */
    public function save(): bool
    {
        // Fire saving event
        if (!$this->fireSavingEvent()) {
            return false;
        }

        // Determine if we're creating or updating
        if ($this->exists) {
            $saved = $this->performUpdate();
        } else {
            $saved = $this->performInsert();
        }

        if ($saved) {
            // Fire saved event
            $this->fireSavedEvent();

            // Sync original attributes
            $this->syncOriginal();
        }

        return $saved;
    }

    /**
     * Perform a model insert operation
     *
     * @return bool
     */
    protected function performInsert(): bool
    {
        // Fire creating event
        if (!$this->fireCreatingEvent()) {
            return false;
        }

        // Update timestamps
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $attributes = $this->getAttributes();

        // Remove null primary key for auto-increment
        if ($this->incrementing && !isset($attributes[$this->getKeyName()])) {
            unset($attributes[$this->getKeyName()]);
        }

        // Insert the record
        $query = $this->newModelQuery();
        $id = $query->getQuery()->insert($attributes);

        // Set the primary key
        if ($this->incrementing && $id > 0) {
            $this->setAttribute($this->getKeyName(), $id);
        }

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        // Fire created event
        $this->fireCreatedEvent();

        return true;
    }

    /**
     * Perform a model update operation
     *
     * @return bool
     */
    protected function performUpdate(): bool
    {
        // Fire updating event
        if (!$this->fireUpdatingEvent()) {
            return false;
        }

        // Check if there are dirty attributes
        $dirty = $this->getDirty();

        if ($dirty === []) {
            return true;
        }

        // Update timestamps
        if ($this->usesTimestamps()) {
            $this->touch();
            $dirty = $this->getDirty();
        }

        // Update the record
        $query = $this->newModelQuery();
        $query->where($this->getKeyName(), '=', $this->getKey());
        $query->update($dirty);

        // Fire updated event
        $this->fireUpdatedEvent();

        return true;
    }

    /**
     * Delete the model from the database
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        // Fire deleting event
        if (!$this->fireDeletingEvent()) {
            return false;
        }

        // Delete the record
        $query = $this->newModelQuery();
        $query->where($this->getKeyName(), '=', $this->getKey());
        $query->delete();

        $this->exists = false;

        // Fire deleted event
        $this->fireDeletedEvent();

        return true;
    }

    /**
     * Update the model in the database
     *
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function update(array $attributes = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    /**
     * Refresh the model from the database
     *
     * @return static|null
     */
    public function refresh(): ?static
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = $this->newModelQuery()->find($this->getKey());

        if ($fresh === null) {
            return null;
        }

        $this->setRawAttributes($fresh->getAttributes());
        $this->syncOriginal();

        // Clear cached relations
        $this->relations = [];

        return $this;
    }

    /**
     * Get a fresh model instance from the database
     *
     * @return static|null
     */
    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return $this->newModelQuery()->find($this->getKey());
    }

    /**
     * Reload the model instance with fresh attributes
     *
     * @return static
     */
    public function replicate(): static
    {
        $instance = $this->newInstance();

        $instance->setRawAttributes($this->getAttributes());

        // Remove the primary key
        unset($instance->attributes[$this->getKeyName()]);

        return $instance;
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
     * Convert the model instance to JSON
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get all the models from the database
     *
     * @param array<string> $columns
     * @return Collection<static>
     */
    public static function all(ApplicationContext $context, array $columns = ['*']): Collection
    {
        return static::query($context)->get($columns);
    }

    /**
     * Find a model by its primary key
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return static|null
     */
    public static function find(ApplicationContext $context, mixed $id, array $columns = ['*']): ?static
    {
        return static::query($context)->find($id, $columns);
    }

    /**
     * Find a model by its primary key or throw an exception
     *
     * @param mixed $id
     * @param array<string> $columns
     * @return static
     * @throws \Glueful\Http\Exceptions\Domain\ModelNotFoundException
     */
    public static function findOrFail(ApplicationContext $context, mixed $id, array $columns = ['*']): static
    {
        return static::query($context)->findOrFail($id, $columns);
    }

    /**
     * Create a new model and save it to the database
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(ApplicationContext $context, array $attributes = []): static
    {
        return static::query($context)->create($attributes);
    }

    /**
     * Destroy the models for the given IDs
     *
     * @param mixed $ids
     * @return int
     */
    public static function destroy(ApplicationContext $context, mixed $ids): int
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $count = 0;

        foreach ($ids as $id) {
            $model = static::find($context, $id);

            if ($model !== null && $model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the first record matching the attributes or create it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrCreate(ApplicationContext $context, array $attributes, array $values = []): static
    {
        return static::query($context)->firstOrCreate($attributes, $values);
    }

    /**
     * Get the first record matching the attributes or instantiate it
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrNew(ApplicationContext $context, array $attributes, array $values = []): static
    {
        return static::query($context)->firstOrNew($attributes, $values);
    }

    /**
     * Create or update a record matching the attributes
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function updateOrCreate(ApplicationContext $context, array $attributes, array $values = []): static
    {
        return static::query($context)->updateOrCreate($attributes, $values);
    }

    /**
     * Begin querying the model with eager loads
     *
     * @param array<string>|string $relations
     * @return Builder
     */
    public static function with(ApplicationContext $context, array|string $relations): Builder
    {
        return static::query($context)->with($relations);
    }

    /**
     * Eager load relations on the model (lazy eager loading)
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function load(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $query = $this->newQuery()->with($relations);

        // Eager load the relations on this model
        $query->loadRelationsOnModels([$this], $relations);

        return $this;
    }

    /**
     * Eager load relations on the model if they are not already loaded
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function loadMissing(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        // Filter out already loaded relations
        $missing = [];
        foreach ($relations as $key => $value) {
            $name = is_numeric($key) ? $value : $key;

            // Get the base relation name for nested relations
            $baseName = explode('.', $name)[0];

            if (!$this->relationLoaded($baseName)) {
                $missing[$key] = $value;
            }
        }

        if ($missing !== []) {
            $this->load($missing);
        }

        return $this;
    }

    /**
     * Load a relationship count if it is not already loaded
     *
     * @param array<string>|string $relations
     * @return static
     */
    public function loadCount(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        // Perform count queries for each relation
        foreach ($relations as $key => $value) {
            $relation = is_numeric($key) ? $value : $key;
            $callback = is_numeric($key) ? null : $value;

            // Get the relation
            if (method_exists($this, $relation)) {
                $relInstance = $this->$relation();

                if ($relInstance instanceof Relations\Relation) {
                    $query = $relInstance->getQuery();

                    if ($callback instanceof \Closure) {
                        $callback($query);
                    }

                    $count = $query->count();
                    $this->setAttribute("{$relation}_count", $count);
                }
            }
        }

        return $this;
    }

    /**
     * Simple pluralization for table names
     *
     * @param string $value
     * @return string
     */
    protected function pluralize(string $value): string
    {
        if (str_ends_with($value, 's')) {
            return $value . 'es';
        }

        if (str_ends_with($value, 'y')) {
            return substr($value, 0, -1) . 'ies';
        }

        return $value . 's';
    }

    /**
     * Handle dynamic method calls into the model
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->newQuery()->$method(...$parameters);
    }

    /**
     * Handle dynamic static method calls into the model
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (($parameters[0] ?? null) instanceof ApplicationContext) {
            $context = array_shift($parameters);
        } elseif (self::$defaultContext !== null) {
            $context = self::$defaultContext;
        } else {
            throw new \RuntimeException('ApplicationContext is required for static model calls.');
        }

        return (new static([], $context))->$method(...$parameters);
    }

    /**
     * Convert the model to its string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}

/**
 * Recursively get all traits used by a class
 *
 * @param object|class-string $class
 * @return array<class-string>
 */
function class_uses_recursive(object|string $class): array
{
    if (is_object($class)) {
        $class = $class::class;
    }

    $results = [];

    foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $class) {
        $results = array_merge(class_uses($class) ?: [], $results);
    }

    return array_unique($results);
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
