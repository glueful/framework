<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Closure;
use Glueful\Database\ORM\Contracts\Scope;
use InvalidArgumentException;

/**
 * Has Global Scopes Trait
 *
 * Provides global scope functionality for ORM models. Global scopes
 * are automatically applied to all queries for a model unless explicitly
 * removed. This is useful for features like soft deletes, tenant isolation,
 * or any query constraint that should apply by default.
 */
trait HasGlobalScopes
{
    /**
     * The registered global scopes for each model class
     *
     * @var array<class-string, array<string, Scope|Closure>>
     */
    protected static array $globalScopes = [];

    /**
     * Register a new global scope on the model
     *
     * @param Scope|Closure|string $scope The scope instance, closure, or class name
     * @param Scope|Closure|null $implementation The implementation when $scope is a string
     * @return void
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope(Scope|Closure|string $scope, Scope|Closure|null $implementation = null): void
    {
        if (is_string($scope) && $implementation !== null) {
            static::$globalScopes[static::class][$scope] = $implementation;
            return;
        }

        if ($scope instanceof Closure) {
            static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
            return;
        }

        if ($scope instanceof Scope) {
            static::$globalScopes[static::class][$scope::class] = $scope;
            return;
        }

        if (is_string($scope) && class_exists($scope)) {
            $instance = new $scope();
            if ($instance instanceof Scope) {
                static::$globalScopes[static::class][$scope] = $instance;
                return;
            }
        }

        throw new InvalidArgumentException(
            'Global scope must be a Scope instance, Closure, or valid Scope class name.'
        );
    }

    /**
     * Determine if a model has a global scope
     *
     * @param Scope|string $scope
     * @return bool
     */
    public static function hasGlobalScope(Scope|string $scope): bool
    {
        $scopeKey = is_string($scope) ? $scope : $scope::class;

        return isset(static::$globalScopes[static::class][$scopeKey]);
    }

    /**
     * Get a global scope registered with the model
     *
     * @param Scope|string $scope
     * @return Scope|Closure|null
     */
    public static function getGlobalScope(Scope|string $scope): Scope|Closure|null
    {
        $scopeKey = is_string($scope) ? $scope : $scope::class;

        return static::$globalScopes[static::class][$scopeKey] ?? null;
    }

    /**
     * Get all of the global scopes for this model
     *
     * @return array<string, Scope|Closure>
     */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    /**
     * Clear all global scopes for this model
     *
     * @return void
     */
    public static function clearGlobalScopes(): void
    {
        static::$globalScopes[static::class] = [];
    }

    /**
     * Boot the has global scopes trait for a model
     *
     * Override this method in your model to register scopes during boot.
     *
     * @return void
     */
    protected static function bootHasGlobalScopes(): void
    {
        // Override in model to register scopes
        // Example:
        // static::addGlobalScope(new ActiveScope());
    }
}
