<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Casts\Attribute;
use Glueful\Database\ORM\Contracts\CastsAttributes;
use InvalidArgumentException;

/**
 * Has Attributes Trait
 *
 * Provides attribute handling functionality for ORM models including:
 * - Attribute storage and retrieval
 * - Dirty tracking (changed attributes)
 * - Mass assignment protection (fillable/guarded)
 * - Attribute casting
 * - Accessors and mutators
 */
trait HasAttributes
{
    /**
     * The model's attributes
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The model's original attributes (as loaded from database)
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * The attributes that should be cast to native types
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * The attributes that are mass assignable
     *
     * @var array<string>
     */
    protected array $fillable = [];

    /**
     * The attributes that are not mass assignable
     *
     * @var array<string>
     */
    protected array $guarded = ['*'];

    /**
     * Indicates if all mass assignment is enabled
     */
    protected static bool $unguarded = false;

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
     * The accessors to append to the model's array form
     *
     * @var array<string>
     */
    protected array $appends = [];

    /**
     * Cache of instantiated cast objects
     *
     * @var array<string, CastsAttributes<mixed, mixed>>
     */
    protected array $castCache = [];

    /**
     * Cache of Attribute accessor/mutator instances
     *
     * @var array<string, Attribute>
     */
    protected array $attributeCache = [];

    /**
     * Get an attribute from the model
     *
     * @param string $key The attribute name
     * @return mixed The attribute value
     */
    public function getAttribute(string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        // Check for Attribute-based accessor (modern pattern)
        if ($this->hasAttributeAccessor($key)) {
            return $this->callAttributeAccessor($key);
        }

        // Check for traditional accessor method
        if ($this->hasGetAccessor($key)) {
            return $this->callGetAccessor($key);
        }

        // Get from attributes array
        $value = $this->attributes[$key] ?? null;

        // Apply casting if defined
        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Check if the model has an Attribute-based accessor
     *
     * @param string $key
     * @return bool
     */
    protected function hasAttributeAccessor(string $key): bool
    {
        $method = $this->camelCase($key);

        if (!method_exists($this, $method)) {
            return false;
        }

        $attribute = $this->getAttributeDefinition($key);

        return $attribute !== null && $attribute->get !== null;
    }

    /**
     * Get the Attribute definition for a key
     *
     * @param string $key
     * @return Attribute|null
     */
    protected function getAttributeDefinition(string $key): ?Attribute
    {
        // Return from cache if available
        if (isset($this->attributeCache[$key])) {
            return $this->attributeCache[$key];
        }

        $method = $this->camelCase($key);

        if (!method_exists($this, $method)) {
            return null;
        }

        $result = $this->$method();

        if ($result instanceof Attribute) {
            return $this->attributeCache[$key] = $result;
        }

        return null;
    }

    /**
     * Call the Attribute-based accessor
     *
     * @param string $key
     * @return mixed
     */
    protected function callAttributeAccessor(string $key): mixed
    {
        $attribute = $this->getAttributeDefinition($key);

        if ($attribute === null || $attribute->get === null) {
            return null;
        }

        $value = $this->attributes[$key] ?? null;

        return ($attribute->get)($value, $this->attributes);
    }

    /**
     * Convert a string to camelCase
     *
     * @param string $value
     * @return string
     */
    protected function camelCase(string $value): string
    {
        $studly = $this->studlyCase($value);

        return lcfirst($studly);
    }

    /**
     * Set an attribute on the model
     *
     * @param string $key The attribute name
     * @param mixed $value The attribute value
     * @return static
     */
    public function setAttribute(string $key, mixed $value): static
    {
        // Check for Attribute-based mutator (modern pattern)
        if ($this->hasAttributeMutator($key)) {
            return $this->callAttributeMutator($key, $value);
        }

        // Check for traditional mutator method
        if ($this->hasSetMutator($key)) {
            return $this->callSetMutator($key, $value);
        }

        // Handle casting for setting
        if ($this->hasCast($key)) {
            $value = $this->castAttributeForSet($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Check if the model has an Attribute-based mutator
     *
     * @param string $key
     * @return bool
     */
    protected function hasAttributeMutator(string $key): bool
    {
        $method = $this->camelCase($key);

        if (!method_exists($this, $method)) {
            return false;
        }

        $attribute = $this->getAttributeDefinition($key);

        return $attribute !== null && $attribute->set !== null;
    }

    /**
     * Call the Attribute-based mutator
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    protected function callAttributeMutator(string $key, mixed $value): static
    {
        $attribute = $this->getAttributeDefinition($key);

        if ($attribute === null || $attribute->set === null) {
            $this->attributes[$key] = $value;
            return $this;
        }

        $result = ($attribute->set)($value, $this->attributes);

        // The setter can return an array of attributes to set
        if (is_array($result)) {
            foreach ($result as $attrKey => $attrValue) {
                $this->attributes[$attrKey] = $attrValue;
            }
        } else {
            $this->attributes[$key] = $result;
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes
     *
     * @param array<string, mixed> $attributes
     * @return static
     * @throws InvalidArgumentException
     */
    public function fill(array $attributes): static
    {
        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif (!static::$unguarded) {
                throw new InvalidArgumentException(
                    sprintf('Add [%s] to fillable property to allow mass assignment on [%s].', $key, static::class)
                );
            }
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes, bypassing mass assignment protection
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function forceFill(array $attributes): static
    {
        return static::unguarded(fn () => $this->fill($attributes));
    }

    /**
     * Get all of the current attributes on the model
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set the array of model attributes without any mass assignment protection
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function setRawAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Get the model's original attribute values
     *
     * @param string|null $key
     * @return mixed
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }

        return $this->original[$key] ?? null;
    }

    /**
     * Sync the original attributes with the current
     *
     * @return static
     */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * Determine if the model or a given attribute has been modified
     *
     * @param string|array<string>|null $attributes
     * @return bool
     */
    public function isDirty(string|array|null $attributes = null): bool
    {
        return $this->hasChanges(
            $this->getDirty(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine if the model or a given attribute has remained unchanged
     *
     * @param string|array<string>|null $attributes
     * @return bool
     */
    public function isClean(string|array|null $attributes = null): bool
    {
        return !$this->isDirty(...func_get_args());
    }

    /**
     * Get the attributes that have been changed since the model was loaded
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get the attributes that were changed since the model was last saved
     *
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->getDirty();
    }

    /**
     * Determine if any of the given attributes were changed
     *
     * @param array<string, mixed> $changes
     * @param array<string> $attributes
     * @return bool
     */
    protected function hasChanges(array $changes, array $attributes = []): bool
    {
        if ($attributes === []) {
            return count($changes) > 0;
        }

        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an attribute is fillable
     *
     * @param string $key
     * @return bool
     */
    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        // If the key is in the fillable array, we can fill it
        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        // If the attribute is guarded, we cannot fill it
        if ($this->isGuarded($key)) {
            return false;
        }

        // If fillable is empty and guarded is not *, allow all
        return $this->fillable === [] && !in_array('*', $this->guarded, true);
    }

    /**
     * Determine if an attribute is guarded
     *
     * @param string $key
     * @return bool
     */
    public function isGuarded(string $key): bool
    {
        if ($this->guarded === []) {
            return false;
        }

        return in_array($key, $this->guarded, true) ||
               in_array('*', $this->guarded, true);
    }

    /**
     * Get the fillable attributes from the given array
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function fillableFromArray(array $attributes): array
    {
        if ($this->fillable !== [] && !static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

        return $attributes;
    }

    /**
     * Run the given callable while being unguarded
     *
     * @param callable $callback
     * @return mixed
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::$unguarded) {
            return $callback();
        }

        static::$unguarded = true;

        try {
            return $callback();
        } finally {
            static::$unguarded = false;
        }
    }

    /**
     * Disable all mass assignment restrictions
     */
    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    /**
     * Determine if an attribute has a cast
     *
     * @param string $key
     * @return bool
     */
    protected function hasCast(string $key): bool
    {
        return array_key_exists($key, $this->casts);
    }

    /**
     * Cast an attribute to a native PHP type
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->casts[$key];

        // Check for class-based caster
        if ($this->isCustomCast($castType)) {
            $caster = $this->resolveCaster($castType);

            /** @var CastsAttributes<mixed, mixed> $caster */
            return $caster->get($this, $key, $value, $this->attributes);
        }

        if ($value === null) {
            return null;
        }

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'object' => json_decode((string) $value, false),
            'array', 'json' => json_decode((string) $value, true),
            'datetime', 'date' => $this->asDateTime($value),
            'timestamp' => $this->asTimestamp($value),
            default => $value,
        };
    }

    /**
     * Determine if the cast type is a custom class-based cast
     *
     * @param string $castType
     * @return bool
     */
    protected function isCustomCast(string $castType): bool
    {
        // Check if it's a class that implements CastsAttributes
        if (class_exists($castType)) {
            return is_subclass_of($castType, CastsAttributes::class);
        }

        // Check if it's a class with constructor args (e.g., AsEnum::class.':'.Status::class)
        if (str_contains($castType, ':')) {
            $class = explode(':', $castType)[0];
            return class_exists($class) && is_subclass_of($class, CastsAttributes::class);
        }

        return false;
    }

    /**
     * Resolve a caster instance for the given cast type
     *
     * @param string $castType
     * @return CastsAttributes<mixed, mixed>
     */
    protected function resolveCaster(string $castType): CastsAttributes
    {
        // Return cached instance if available
        if (isset($this->castCache[$castType])) {
            return $this->castCache[$castType];
        }

        // Parse the cast type for constructor arguments
        if (str_contains($castType, ':')) {
            $parts = explode(':', $castType, 2);
            $class = $parts[0];
            $args = explode(',', $parts[1]);

            $caster = new $class(...$args);
        } else {
            $caster = new $castType();
        }

        // Cache and return the caster
        return $this->castCache[$castType] = $caster;
    }

    /**
     * Cast an attribute for setting
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function castAttributeForSet(string $key, mixed $value): mixed
    {
        $castType = $this->casts[$key];

        // Check for class-based caster
        if ($this->isCustomCast($castType)) {
            $caster = $this->resolveCaster($castType);

            /** @var CastsAttributes<mixed, mixed> $caster */
            return $caster->set($this, $key, $value, $this->attributes);
        }

        if ($value === null) {
            return null;
        }

        return match ($castType) {
            'array', 'json', 'object' => json_encode($value),
            'datetime', 'date' => $this->fromDateTime($value),
            default => $value,
        };
    }

    /**
     * Convert a value to a DateTime instance
     *
     * @param mixed $value
     * @return \DateTimeInterface
     */
    protected function asDateTime(mixed $value): \DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (is_numeric($value)) {
            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Convert a value to a Unix timestamp
     *
     * @param mixed $value
     * @return int
     */
    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Convert a DateTime to a storable string
     *
     * @param mixed $value
     * @return string|null
     */
    protected function fromDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * Determine if a get accessor exists for an attribute
     *
     * @param string $key
     * @return bool
     */
    protected function hasGetAccessor(string $key): bool
    {
        return method_exists($this, 'get' . $this->studlyCase($key) . 'Attribute');
    }

    /**
     * Determine if a set mutator exists for an attribute
     *
     * @param string $key
     * @return bool
     */
    protected function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . $this->studlyCase($key) . 'Attribute');
    }

    /**
     * Call the get accessor for an attribute
     *
     * @param string $key
     * @return mixed
     */
    protected function callGetAccessor(string $key): mixed
    {
        $method = 'get' . $this->studlyCase($key) . 'Attribute';

        return $this->$method($this->attributes[$key] ?? null);
    }

    /**
     * Call the set mutator for an attribute
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    protected function callSetMutator(string $key, mixed $value): static
    {
        $method = 'set' . $this->studlyCase($key) . 'Attribute';

        $this->$method($value);

        return $this;
    }

    /**
     * Convert a string to StudlyCase
     *
     * @param string $value
     * @return string
     */
    protected function studlyCase(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));

        $studly = array_map(fn ($word) => ucfirst($word), $words);

        return implode('', $studly);
    }

    /**
     * Convert the model's attributes to an array
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = $this->attributes;

        // Apply casts
        foreach ($this->casts as $key => $type) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
            }
        }

        // Add appended attributes
        foreach ($this->appends as $key) {
            if ($this->hasGetAccessor($key)) {
                $attributes[$key] = $this->callGetAccessor($key);
            }
        }

        // Filter visible/hidden
        if ($this->visible !== []) {
            $attributes = array_intersect_key($attributes, array_flip($this->visible));
        }

        if ($this->hidden !== []) {
            $attributes = array_diff_key($attributes, array_flip($this->hidden));
        }

        return $attributes;
    }

    /**
     * Make the given attribute visible
     *
     * @param array<string>|string $attributes
     * @return static
     */
    public function makeVisible(array|string $attributes): static
    {
        $this->hidden = array_diff($this->hidden, (array) $attributes);

        if ($this->visible !== []) {
            $this->visible = array_merge($this->visible, (array) $attributes);
        }

        return $this;
    }

    /**
     * Make the given attribute hidden
     *
     * @param array<string>|string $attributes
     * @return static
     */
    public function makeHidden(array|string $attributes): static
    {
        $this->hidden = array_merge($this->hidden, (array) $attributes);

        return $this;
    }

    /**
     * Set the visible attributes for the model
     *
     * @param array<string> $visible
     * @return static
     */
    public function setVisible(array $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Set the hidden attributes for the model
     *
     * @param array<string> $hidden
     * @return static
     */
    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Set the accessors to append to model arrays
     *
     * @param array<string> $appends
     * @return static
     */
    public function setAppends(array $appends): static
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Dynamically retrieve attributes on the model
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute exists on the model
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || $this->hasGetAccessor($key);
    }

    /**
     * Unset an attribute on the model
     *
     * @param string $key
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}
