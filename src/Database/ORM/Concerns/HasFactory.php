<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Factory\Factory;

/**
 * HasFactory Trait
 *
 * Provides the static factory() method for models to generate test data.
 * This trait should be used in models that need factory support for testing.
 *
 * @example
 * class User extends Model
 * {
 *     use HasFactory;
 *
 *     protected string $table = 'users';
 * }
 *
 * // Usage
 * $user = User::factory()->create();
 * $users = User::factory()->count(10)->create();
 * $admin = User::factory()->admin()->create();
 *
 * @package Glueful\Database\ORM\Concerns
 */
trait HasFactory
{
    /**
     * Get a new factory instance for the model
     *
     * @param int $count Number of models to create (default 1)
     * @param ApplicationContext|null $context Context to create models with (default: the app's)
     * @return Factory<static>
     */
    public static function factory(int $count = 1, ?ApplicationContext $context = null): Factory
    {
        $factoryClass = static::resolveFactoryClass();

        return $factoryClass::new(static::class, $context)->count($count);
    }

    /**
     * Resolve the factory class for this model
     *
     * Convention: App\Models\User -> App\Database\Factories\UserFactory (then Database\Factories)
     *
     * @return class-string<Factory<static>>
     * @throws \RuntimeException If factory class not found
     */
    protected static function resolveFactoryClass(): string
    {
        // Get model name without namespace
        $modelClass = static::class;
        $parts = explode('\\', $modelClass);
        $modelName = end($parts);

        // Try multiple factory locations
        $candidates = [
            "App\\Database\\Factories\\{$modelName}Factory",
            "Database\\Factories\\{$modelName}Factory",
            "Tests\\Factories\\{$modelName}Factory",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                /** @var class-string<Factory<static>> */
                return $candidate;
            }
        }

        throw new \RuntimeException(
            "Factory [App\\Database\\Factories\\{$modelName}Factory] not found for model [{$modelClass}]. " .
            "Create one with: php glueful scaffold:factory {$modelName}Factory"
        );
    }

    /**
     * Create a new factory instance with a custom factory class
     *
     * Use this method when the factory doesn't follow naming conventions.
     *
     * @param class-string<Factory<static>> $factoryClass
     * @param int $count Number of models to create
     * @param ApplicationContext|null $context Context to create models with (default: the app's)
     * @return Factory<static>
     */
    public static function factoryUsing(
        string $factoryClass,
        int $count = 1,
        ?ApplicationContext $context = null
    ): Factory {
        return $factoryClass::new(static::class, $context)->count($count);
    }
}
