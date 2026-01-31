# Database Factories & Seeders Implementation Plan

> A comprehensive plan for implementing the factory pattern for test data generation and database seeding in Glueful Framework.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Architecture Decision](#architecture-decision)
4. [Current State Analysis](#current-state-analysis)
5. [Architecture Design](#architecture-design)
6. [Factory Implementation](#factory-implementation)
7. [Seeder Implementation](#seeder-implementation)
8. [Console Commands](#console-commands)
9. [ORM Integration](#orm-integration)
10. [Implementation Phases](#implementation-phases)
11. [Testing Strategy](#testing-strategy)
12. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of a database factory and seeder system for Glueful Framework. The system will provide:

- **Factory classes** for generating fake model data with Faker integration
- **Factory states** for creating variations of models
- **Relationship handling** in factories
- **Seeder classes** for populating databases
- **Console commands** for running seeders and generating factory/seeder files

The implementation builds on the ORM system and integrates with the existing scaffold commands.

---

## Goals and Non-Goals

### Goals

- ✅ Factory pattern for generating test data
- ✅ Faker integration for realistic fake data
- ✅ Factory states for model variations (e.g., admin, verified)
- ✅ Relationship support (belongsTo, hasMany)
- ✅ Seeder classes with dependency ordering
- ✅ `db:seed` command with environment protection
- ✅ `scaffold:factory` and `scaffold:seeder` commands
- ✅ Integration with ORM models
- ✅ Production-safe: Faker as `require-dev` only

### Non-Goals

- ❌ Database migration generation (existing feature)
- ❌ Production data import/export
- ❌ Data anonymization tools
- ❌ External data source integration

---

## Architecture Decision

### Core vs Extension Analysis

Following Glueful's "lean core, rich ecosystem" philosophy, we evaluated whether factories and seeders should be in core or a separate extension.

**Decision: Split Approach**

| Component | Location | Rationale |
|-----------|----------|-----------|
| **Seeders** | Core Framework | Used in production (initial data, admin users, demo data) |
| **Factories** | Core Framework | Tight ORM integration required (`User::factory()`) |
| **Faker** | `require-dev` | Only needed in development/testing, ~2MB dependency |

### Why This Approach

1. **Production-safe**: Running `composer install --no-dev` excludes Faker, keeping production deployments lean

2. **No extra package**: Developers don't need `composer require glueful/testing` - it works out of the box

3. **Industry standard**: Matches Laravel's approach - proven pattern

4. **Good DX**: `User::factory()` syntax works immediately in development

5. **Graceful degradation**: Helpful error if Faker missing in production context

### Composer Configuration

```json
{
    "require": {
        "php": "^8.3"
        // ... core dependencies (no Faker)
    },
    "require-dev": {
        "fakerphp/faker": "^1.23",
        "phpunit/phpunit": "^11.0"
    }
}
```

### Faker Availability Check

```php
// src/Database/Factory/FakerBridge.php
public static function getInstance(): Generator
{
    if (!class_exists(\Faker\Factory::class)) {
        throw new \RuntimeException(
            'Faker is required for model factories. ' .
            'Run: composer require --dev fakerphp/faker'
        );
    }

    return self::$instance ??= \Faker\Factory::create();
}
```

---

## Current State Analysis

### Existing Infrastructure

Glueful has a complete ORM system:

```
src/Database/ORM/
├── Model.php                    # Base model class
├── Builder.php                  # Query builder
├── Collection.php               # Model collection
├── Relations/                   # Relationship types
│   ├── HasOne.php
│   ├── HasMany.php
│   ├── BelongsTo.php
│   └── BelongsToMany.php
└── Concerns/
    ├── HasAttributes.php
    ├── HasRelationships.php
    └── HasTimestamps.php
```

### Gap Analysis

| Gap | Solution |
|-----|----------|
| No test data generation | Factory classes with Faker |
| Manual test setup | `Model::factory()` integration |
| No database seeding | Seeder classes with ordering |
| No seeding commands | `db:seed`, `db:seed:status` |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                       Factory System                             │
│                                                                 │
│  Model::factory()                                               │
│        │                                                        │
│        ▼                                                        │
│  ┌─────────────────┐                                           │
│  │  Factory Class  │                                           │
│  │  (UserFactory)  │                                           │
│  └─────────────────┘                                           │
│        │                                                        │
│   ┌────┴────┬──────────┐                                       │
│   ▼         ▼          ▼                                        │
│ ┌───────┐ ┌───────┐ ┌─────────────┐                           │
│ │ Faker │ │States │ │Relationships│                           │
│ └───────┘ └───────┘ └─────────────┘                           │
│        │                                                        │
│        ▼                                                        │
│  ┌─────────────────┐                                           │
│  │  Model::create($context) │ ──► Database                      │
│  └─────────────────┘                                           │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                       Seeder System                              │
│                                                                 │
│  php glueful db:seed                                            │
│        │                                                        │
│        ▼                                                        │
│  ┌──────────────────┐                                          │
│  │ DatabaseSeeder   │                                          │
│  └──────────────────┘                                          │
│        │                                                        │
│   ┌────┴────┬──────────┐                                       │
│   ▼         ▼          ▼                                        │
│ ┌───────┐ ┌───────┐ ┌───────┐                                  │
│ │UserS. │ │PostS. │ │RoleS. │                                  │
│ └───────┘ └───────┘ └───────┘                                  │
│        │                                                        │
│        ▼                                                        │
│  Database populated                                             │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/
├── Database/
│   ├── ORM/
│   │   └── ...existing...
│   │
│   ├── Factory/                           # 📋 NEW (Core, but requires Faker dev-dep)
│   │   ├── Factory.php                    # Base factory class
│   │   ├── FactoryBuilder.php             # Fluent builder
│   │   ├── FakerBridge.php                # Faker integration (checks availability)
│   │   ├── Concerns/
│   │   │   ├── HasStates.php              # State management
│   │   │   ├── HasSequences.php           # Sequence support
│   │   │   └── ManagesRelationships.php   # Relationship factories
│   │   └── Contracts/
│   │       └── FactoryInterface.php
│   │
│   └── Seeders/                           # 📋 NEW (Core, production-ready)
│       ├── Seeder.php                     # Base seeder class
│       └── Contracts/
│           └── SeederInterface.php
│
├── Console/
│   └── Commands/
│       ├── Scaffold/
│       │   ├── FactoryCommand.php         # 📋 NEW
│       │   └── SeederCommand.php          # 📋 NEW
│       │
│       └── Database/
│           ├── SeedCommand.php            # 📋 NEW
│           └── SeedStatusCommand.php      # 📋 NEW
│
└── ...existing...

# Application structure (generated files)
database/
├── factories/
│   ├── UserFactory.php
│   └── PostFactory.php
│
└── seeders/
    ├── DatabaseSeeder.php
    ├── UserSeeder.php
    └── RoleSeeder.php
```

---

## Factory Implementation

### FakerBridge Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Factory;

use Faker\Generator;

/**
 * Bridge to Faker library with availability checking
 *
 * Faker is a require-dev dependency. This bridge ensures helpful
 * error messages if factories are used without Faker installed.
 */
class FakerBridge
{
    private static ?Generator $instance = null;

    /**
     * Get the Faker instance
     *
     * @throws \RuntimeException if Faker is not installed
     */
    public static function getInstance(): Generator
    {
        if (!class_exists(\Faker\Factory::class)) {
            throw new \RuntimeException(
                'Faker is required for model factories. ' .
                'Install it with: composer require --dev fakerphp/faker'
            );
        }

        return self::$instance ??= \Faker\Factory::create();
    }

    /**
     * Check if Faker is available
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Faker\Factory::class);
    }

    /**
     * Reset the Faker instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
```

### Base Factory Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Factory;

use Faker\Generator as Faker;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Collection;

/**
 * Base Factory class for generating test data
 *
 * Note: Requires fakerphp/faker as a dev dependency.
 * Install with: composer require --dev fakerphp/faker
 *
 * @template TModel of Model
 */
abstract class Factory
{
    /**
     * The Faker instance (lazy-loaded via FakerBridge)
     */
    protected Faker $faker;

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
     * Relationship callbacks
     *
     * @var array<string, callable>
     */
    protected array $afterCreating = [];

    /**
     * Models to recycle for relationships
     *
     * @var array<class-string, Collection>
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
     * @throws \RuntimeException if Faker is not installed
     */
    public function __construct(?Faker $faker = null)
    {
        $this->faker = $faker ?? FakerBridge::getInstance();
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
    public static function new(?string $model = null): static
    {
        $factory = new static();

        if ($model !== null) {
            $factory->model = $model;
        }

        return $factory;
    }

    /**
     * Set the number of models to create
     *
     * @return $this
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
     * @return $this
     */
    public function state(string|array $state): static
    {
        $clone = clone $this;

        if (is_array($state)) {
            $clone->attributes = array_merge($clone->attributes, $state);
        } else {
            $clone->states[] = $state;
        }

        return $clone;
    }

    /**
     * Create a single model without persisting
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function make(array $attributes = []): Model
    {
        return $this->makeMany($attributes)->first();
    }

    /**
     * Create multiple models without persisting
     *
     * @param array<string, mixed> $attributes
     * @return Collection<TModel>
     */
    public function makeMany(array $attributes = []): Collection
    {
        $models = new Collection();

        for ($i = 0; $i < $this->count; $i++) {
            $this->sequenceIndex = $i;
            $modelAttributes = $this->resolveAttributes($attributes);
            $models->push(new $this->model($modelAttributes));
        }

        return $models;
    }

    /**
     * Create a single model and persist to database
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes = []): Model
    {
        return $this->createMany($attributes)->first();
    }

    /**
     * Create multiple models and persist to database
     *
     * @param array<string, mixed> $attributes
     * @return Collection<TModel>
     */
    public function createMany(array $attributes = []): Collection
    {
        $models = new Collection();

        for ($i = 0; $i < $this->count; $i++) {
            $this->sequenceIndex = $i;
            $modelAttributes = $this->resolveAttributes($attributes);

            $model = $this->model::create($modelAttributes);

            // Run afterCreating callbacks
            foreach ($this->afterCreating as $callback) {
                $callback($model);
            }

            $models->push($model);
        }

        return $models;
    }

    /**
     * Resolve all attributes including states and overrides
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function resolveAttributes(array $attributes): array
    {
        // Start with definition
        $resolved = $this->definition();

        // Apply states
        foreach ($this->states as $state) {
            $method = $state . 'State';
            if (method_exists($this, $method)) {
                $resolved = array_merge($resolved, $this->$method());
            }
        }

        // Apply sequence
        if (!empty($this->sequence)) {
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
                $attributes[$key] = $value->create()->getKey();
            }
        }

        return $attributes;
    }

    /**
     * Set a sequence of attribute values
     *
     * @param array<array<string, mixed>> ...$sequence
     * @return $this
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
     * @param Collection|Model $models
     * @return $this
     */
    public function recycle(Collection|Model $models): static
    {
        $clone = clone $this;

        if ($models instanceof Model) {
            $models = new Collection([$models]);
        }

        $modelClass = get_class($models->first());
        $clone->recycle[$modelClass] = $models;

        return $clone;
    }

    /**
     * Get a recycled model or create a new one
     *
     * @param class-string<Model> $model
     * @return Model
     */
    protected function getRecycled(string $model): Model
    {
        if (isset($this->recycle[$model]) && $this->recycle[$model]->isNotEmpty()) {
            return $this->recycle[$model]->random();
        }

        // Create a new model using its factory
        return $model::factory()->create();
    }

    /**
     * Add a callback to run after creating
     *
     * @param callable $callback
     * @return $this
     */
    public function afterCreating(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;
        return $clone;
    }

    /**
     * Create models with a has-many relationship
     *
     * @param string $relationship
     * @param Factory|int $factory
     * @return $this
     */
    public function has(string $relationship, Factory|int $factory = 1): static
    {
        $count = is_int($factory) ? $factory : $factory->count;
        $relatedFactory = is_int($factory) ? null : $factory;

        return $this->afterCreating(function (Model $model) use ($relationship, $count, $relatedFactory) {
            $relationMethod = $model->$relationship();
            $relatedModel = $relationMethod->getRelated();
            $foreignKey = $relationMethod->getForeignKeyName();

            $factory = $relatedFactory ?? $relatedModel::factory();

            $factory->count($count)
                ->state([$foreignKey => $model->getKey()])
                ->create();
        });
    }

    /**
     * Create model with a belongs-to relationship
     *
     * @param string $relationship
     * @param Factory|Model|null $factory
     * @return $this
     */
    public function for(string $relationship, Factory|Model|null $factory = null): static
    {
        return $this->state(function () use ($relationship, $factory) {
            if ($factory instanceof Model) {
                $model = $factory;
            } elseif ($factory instanceof Factory) {
                $model = $factory->create();
            } else {
                // Will be resolved by the relationship
                return [];
            }

            // Get the foreign key from the relationship
            $tempModel = new $this->model();
            $relationMethod = $tempModel->$relationship();

            return [
                $relationMethod->getForeignKeyName() => $model->getKey(),
            ];
        });
    }
}
```

### Factory States (Trait)

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Factory\Concerns;

/**
 * HasStates trait for factory state management
 */
trait HasStates
{
    /**
     * Define a state transformation
     *
     * @param string $name State name
     * @param callable|array $attributes State attributes or callback
     * @return $this
     */
    protected function defineState(string $name, callable|array $attributes): static
    {
        // State methods are defined as {name}State() on the factory class
        return $this;
    }
}
```

### Example Factory

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Glueful\Database\Factory\Factory;

/**
 * Factory for User model
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model this factory creates
     */
    protected string $model = User::class;

    /**
     * Define the model's default state
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => $this->faker->dateTimeBetween('-1 year'),
            'created_at' => $this->faker->dateTimeBetween('-2 years'),
        ];
    }

    /**
     * Indicate that the user is an admin
     */
    public function admin(): static
    {
        return $this->state([
            'role' => 'admin',
        ]);
    }

    /**
     * Indicate that the user is unverified
     */
    public function unverified(): static
    {
        return $this->state([
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is suspended
     */
    public function suspended(): static
    {
        return $this->state([
            'status' => 'suspended',
            'suspended_at' => $this->faker->dateTimeBetween('-1 month'),
        ]);
    }

    /**
     * Configure the factory to create a user with posts
     */
    public function withPosts(int $count = 3): static
    {
        return $this->has('posts', $count);
    }
}
```

---

## Seeder Implementation

### Base Seeder Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Seeders;

use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;

/**
 * Base Seeder class for database seeding
 */
abstract class Seeder
{
    /**
     * The DI container
     */
    protected ContainerInterface $container;

    /**
     * The database connection
     */
    protected Connection $connection;

    /**
     * Seeders that should run before this one
     *
     * @var array<class-string<Seeder>>
     */
    protected array $dependencies = [];

    /**
     * Create a new seeder instance
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->connection = $container->get(Connection::class);
    }

    /**
     * Run the database seeds
     */
    abstract public function run(): void;

    /**
     * Get seeder dependencies
     *
     * @return array<class-string<Seeder>>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Call another seeder
     *
     * @param class-string<Seeder>|array<class-string<Seeder>> $class
     */
    protected function call(string|array $class): void
    {
        $classes = is_array($class) ? $class : [$class];

        foreach ($classes as $seederClass) {
            $seeder = $this->container->get($seederClass);
            $seeder->run();
        }
    }

    /**
     * Run a closure within a database transaction
     *
     * @param callable $callback
     */
    protected function withTransaction(callable $callback): void
    {
        $this->connection->beginTransaction();

        try {
            $callback();
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Truncate a table before seeding
     *
     * @param string $table
     */
    protected function truncate(string $table): void
    {
        // Disable foreign key checks temporarily
        $this->connection->statement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->table($table)->truncate();
        $this->connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
```

### Example Seeders

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Glueful\Database\Seeders\Seeder;

/**
 * Main database seeder
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            PostSeeder::class,
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Glueful\Database\Seeders\Seeder;

/**
 * User seeder
 */
class UserSeeder extends Seeder
{
    /**
     * Dependencies that must run first
     */
    protected array $dependencies = [
        RoleSeeder::class,
    ];

    /**
     * Run the database seeds
     */
    public function run(): void
    {
        // Create admin user
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin User',
        ]);

        // Create regular users
        User::factory()
            ->count(50)
            ->create();

        // Create users with posts
        User::factory()
            ->count(10)
            ->withPosts(5)
            ->create();
    }
}
```

---

## Console Commands

### db:seed Command

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Database;

use Glueful\Console\BaseCommand;
use Glueful\Database\Seeders\Seeder;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:seed',
    description: 'Seed the database with records'
)]
class SeedCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument(
            'class',
            InputArgument::OPTIONAL,
            'The seeder class to run',
            'Database\\Seeders\\DatabaseSeeder'
        )
        ->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Force the operation to run in production'
        )
        ->addOption(
            'class',
            'c',
            InputOption::VALUE_OPTIONAL,
            'The class name of the root seeder'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Production check
        if ($this->isProduction() && !$input->getOption('force')) {
            $output->writeln('<error>Cannot run seeder in production without --force</error>');
            return 1;
        }

        $seederClass = $input->getArgument('class');

        if (!class_exists($seederClass)) {
            $output->writeln("<error>Seeder class not found: {$seederClass}</error>");
            return 1;
        }

        $output->writeln("<info>Seeding database...</info>");

        $container = $this->getContainer();
        $seeder = $container->get($seederClass);
        $seeder->run();

        $output->writeln("<info>Database seeding completed.</info>");

        return 0;
    }

    private function isProduction(): bool
    {
        return env('APP_ENV', 'production') === 'production';
    }
}
```

### scaffold:factory Command

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Glueful\Database\Factory\FakerBridge;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:factory',
    description: 'Scaffold a model factory class'
)]
class FactoryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'The name of the factory (e.g., UserFactory)'
        )
        ->addOption(
            'model',
            'm',
            InputOption::VALUE_OPTIONAL,
            'The model class the factory creates'
        )
        ->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing files'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check if Faker is available (dev dependency)
        if (!FakerBridge::isAvailable()) {
            $output->writeln('<comment>Note: fakerphp/faker is not installed.</comment>');
            $output->writeln('<comment>Install it with: composer require --dev fakerphp/faker</comment>');
            $output->writeln('');
        }

        $name = $input->getArgument('name');
        $model = $input->getOption('model');
        $force = $input->getOption('force');

        // Ensure name ends with Factory
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        // Infer model from factory name if not provided
        if ($model === null) {
            $model = 'App\\Models\\' . str_replace('Factory', '', $name);
        }

        // Generate the factory file
        $content = $this->generateFactory($name, $model);
        $path = "database/factories/{$name}.php";

        if (file_exists($path) && !$force) {
            $output->writeln("<error>Factory already exists: {$path}</error>");
            return 1;
        }

        $this->writeFile($path, $content);

        $output->writeln("<info>Factory created: {$path}</info>");

        if (!FakerBridge::isAvailable()) {
            $output->writeln('');
            $output->writeln('<info>Next step:</info> composer require --dev fakerphp/faker');
        }

        return 0;
    }

    private function generateFactory(string $name, string $model): string
    {
        // Generate from stub template
        return <<<PHP
<?php

declare(strict_types=1);

namespace Database\Factories;

use {$model};
use Glueful\Database\Factory\Factory;

/**
 * Factory for {$model}
 *
 * Requires: composer require --dev fakerphp/faker
 *
 * @extends Factory<{$model}>
 */
class {$name} extends Factory
{
    /**
     * The model this factory creates
     */
    protected string \$model = {$model}::class;

    /**
     * Define the model's default state
     */
    public function definition(): array
    {
        return [
            // Define default attributes here
            // 'name' => \$this->faker->name(),
            // 'email' => \$this->faker->unique()->safeEmail(),
        ];
    }
}
PHP;
    }
}
```

---

## ORM Integration

### HasFactory Trait

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\Factory\Factory;

/**
 * HasFactory trait for models
 *
 * Provides the static factory() method for generating test data.
 */
trait HasFactory
{
    /**
     * Get a new factory instance for the model
     *
     * @param int $count Number of models to create
     * @return Factory<static>
     */
    public static function factory(int $count = 1): Factory
    {
        $factoryClass = static::resolveFactoryClass();

        return $factoryClass::new(static::class)->count($count);
    }

    /**
     * Resolve the factory class for this model
     *
     * @return class-string<Factory>
     */
    protected static function resolveFactoryClass(): string
    {
        // Convention: App\Models\User -> Database\Factories\UserFactory
        $modelClass = static::class;
        $modelName = class_basename($modelClass);

        $factoryClass = "Database\\Factories\\{$modelName}Factory";

        if (!class_exists($factoryClass)) {
            throw new \RuntimeException(
                "Factory [{$factoryClass}] not found for model [{$modelClass}]"
            );
        }

        return $factoryClass;
    }
}
```

### Usage in Models

```php
<?php

namespace App\Models;

use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Concerns\HasFactory;

class User extends Model
{
    use HasFactory;

    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
}
```

### Usage in Tests

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Post;
use Glueful\Testing\TestCase;

class UserTest extends TestCase
{
    public function testUserCanHavePosts(): void
    {
        // Create a single user
        $user = User::factory()->create();

        // Create user with specific attributes
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
        ]);

        // Create multiple users
        $users = User::factory()->count(10)->create();

        // Create user with related posts
        $user = User::factory()
            ->has('posts', 3)
            ->create();

        $this->assertCount(3, $user->posts);
    }

    public function testFactoryStates(): void
    {
        $unverified = User::factory()->unverified()->create();

        $this->assertNull($unverified->email_verified_at);
    }

    public function testFactorySequences(): void
    {
        $users = User::factory()
            ->count(3)
            ->sequence(
                ['status' => 'active'],
                ['status' => 'pending'],
                ['status' => 'suspended']
            )
            ->create();

        $this->assertEquals('active', $users[0]->status);
        $this->assertEquals('pending', $users[1]->status);
        $this->assertEquals('suspended', $users[2]->status);
    }

    public function testRecycleRelatedModels(): void
    {
        $users = User::factory()->count(3)->create();

        // Reuse existing users instead of creating new ones
        $posts = Post::factory()
            ->count(10)
            ->recycle($users)
            ->create();

        // Each post belongs to one of the 3 users
    }
}
```

---

## Implementation Phases

### Phase 1: Seeders (Core - Week 1)

**Deliverables:**
- [ ] `Seeder` base class (no external dependencies)
- [ ] `db:seed` command with environment protection
- [ ] `scaffold:seeder` command
- [ ] Dependency ordering support

**Acceptance Criteria:**
```bash
# Works in production (no Faker needed)
php glueful scaffold:seeder RoleSeeder
php glueful db:seed
php glueful db:seed --class=RoleSeeder
```

### Phase 2: Factories Core (Week 1-2)

**Deliverables:**
- [ ] `Factory` base class
- [ ] `FakerBridge` with availability checking
- [ ] `FactoryBuilder` for fluent interface
- [ ] Basic `create()` and `make()` methods
- [ ] `HasFactory` trait for models
- [ ] Add `fakerphp/faker` to `require-dev`

**Acceptance Criteria:**
```php
// After: composer require --dev fakerphp/faker
$user = User::factory()->create();
$users = User::factory()->count(10)->make();
```

### Phase 3: States & Relationships (Week 2)

**Deliverables:**
- [ ] Factory states
- [ ] Sequences
- [ ] `has()` for has-many relationships
- [ ] `for()` for belongs-to relationships
- [ ] `recycle()` for reusing models

**Acceptance Criteria:**
```php
$admin = User::factory()->admin()->create();
$user = User::factory()->has('posts', 5)->create();
```

### Phase 4: Commands & Polish (Week 3)

**Deliverables:**
- [ ] `scaffold:factory` command (with Faker check)
- [ ] Complete test coverage
- [ ] Documentation
- [ ] Helpful error messages when Faker missing

**Acceptance Criteria:**
```bash
php glueful scaffold:factory UserFactory
# Note: fakerphp/faker is not installed.
# Install it with: composer require --dev fakerphp/faker
#
# Factory created: database/factories/UserFactory.php
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Database\Factory;

use PHPUnit\Framework\TestCase;
use Glueful\Database\Factory\Factory;

class FactoryTest extends TestCase
{
    public function testMakeCreatesModelWithoutPersisting(): void
    {
        // Test that make() doesn't hit the database
    }

    public function testCreatePersistsToDatabase(): void
    {
        // Test that create() saves to database
    }

    public function testStateOverridesDefinition(): void
    {
        // Test state transformation
    }

    public function testSequenceRotatesValues(): void
    {
        // Test sequence functionality
    }
}
```

---

## API Reference

### Factory Methods

| Method | Description |
|--------|-------------|
| `::new()` | Create a new factory instance |
| `count(int $n)` | Set number of models to create |
| `state(array\|string)` | Apply a state transformation |
| `sequence(array...)` | Define attribute sequence |
| `make(array $attrs)` | Create model without persisting |
| `create(array $attrs)` | Create and persist model |
| `has(string $rel, int)` | Add has-many relationship |
| `for(string $rel, Factory)` | Add belongs-to relationship |
| `recycle(Collection)` | Reuse existing models |
| `afterCreating(callable)` | Add post-creation callback |

### Console Commands

| Command | Description |
|---------|-------------|
| `db:seed [class]` | Run database seeders |
| `scaffold:factory <name>` | Generate factory class |
| `scaffold:seeder <name>` | Generate seeder class |
