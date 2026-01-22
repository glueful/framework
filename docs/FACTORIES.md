# Database Factories & Seeders

The Glueful Framework provides a factory and seeder system for generating test data and populating your database with initial data.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Factories](#factories)
  - [Creating Factories](#creating-factories)
  - [Factory Definition](#factory-definition)
  - [Using Factories](#using-factories)
  - [Factory States](#factory-states)
  - [Sequences](#sequences)
  - [Relationships](#relationships)
  - [Recycling Models](#recycling-models)
- [Seeders](#seeders)
  - [Creating Seeders](#creating-seeders)
  - [Running Seeders](#running-seeders)
  - [Seeder Dependencies](#seeder-dependencies)
- [Console Commands](#console-commands)
- [Best Practices](#best-practices)

## Overview

The factory and seeder system helps you:

- **Factories**: Generate fake data for testing using the Faker library
- **Seeders**: Populate your database with initial or test data

Factories are designed for development and testing, while seeders can be used in both development and production environments.

## Installation

Factories require the `fakerphp/faker` package as a dev dependency:

```bash
composer require --dev fakerphp/faker
```

Seeders work without any additional dependencies.

## Factories

### Creating Factories

Use the `scaffold:factory` command to generate a new factory:

```bash
# Basic factory (infers model from name)
php glueful scaffold:factory UserFactory

# Specify the model explicitly
php glueful scaffold:factory PostFactory --model=Post

# Force overwrite existing factory
php glueful scaffold:factory UserFactory --force
```

The factory will be created in `database/factories/`.

### Factory Definition

A factory defines the default attributes for your model:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Glueful\Database\Factory\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'status' => 'active',
            'created_at' => $this->faker->dateTimeBetween('-1 year'),
        ];
    }
}
```

### Using Factories

Add the `HasFactory` trait to your model:

```php
<?php

namespace App\Models;

use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Concerns\HasFactory;

class User extends Model
{
    use HasFactory;

    protected string $table = 'users';
}
```

Then use the factory to generate models:

```php
// Create a single model (persisted to database)
$user = User::factory()->create();

// Create multiple models
$users = User::factory()->count(10)->create();

// Create with specific attributes
$admin = User::factory()->create([
    'email' => 'admin@example.com',
    'role' => 'admin',
]);

// Make models without persisting (useful for testing)
$user = User::factory()->make();
$users = User::factory()->count(5)->make();
```

### Factory States

States allow you to define variations of your model:

```php
class UserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => $this->faker->dateTimeBetween('-1 year'),
            'role' => 'user',
            'status' => 'active',
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
}
```

Use states when creating models:

```php
// Create an admin user
$admin = User::factory()->admin()->create();

// Create an unverified admin
$admin = User::factory()->admin()->unverified()->create();

// Create 5 suspended users
$users = User::factory()->count(5)->suspended()->create();
```

### Sequences

Create models with rotating attribute values:

```php
// Create users with different statuses
$users = User::factory()
    ->count(6)
    ->sequence(
        ['status' => 'active'],
        ['status' => 'pending'],
        ['status' => 'suspended']
    )
    ->create();

// Result: active, pending, suspended, active, pending, suspended
```

### Relationships

#### Has Many Relationships

Create models with related children:

```php
// Create a user with 3 posts
$user = User::factory()
    ->has('posts', 3)
    ->create();

// Create a user with posts using a custom factory
$user = User::factory()
    ->has('posts', Post::factory()->count(5)->published())
    ->create();
```

#### Belongs To Relationships

Create models with a parent:

```php
// Create a post for an existing user
$user = User::factory()->create();
$post = Post::factory()
    ->for('author', $user)
    ->create();

// Create a post with a new user
$post = Post::factory()
    ->for('author', User::factory()->admin())
    ->create();
```

### Recycling Models

Reuse existing models instead of creating new ones:

```php
// Create 3 users
$users = User::factory()->count(3)->create();

// Create 10 posts, each belonging to one of the 3 users
$posts = Post::factory()
    ->count(10)
    ->recycle($users)
    ->create();
```

### Callbacks

Run code after creating models:

```php
$user = User::factory()
    ->afterCreating(function (User $user) {
        // Send welcome email, create profile, etc.
    })
    ->create();
```

## Seeders

Seeders are used to populate your database with initial or test data.

### Creating Seeders

Use the `scaffold:seeder` command:

```bash
# Create a basic seeder
php glueful scaffold:seeder UserSeeder

# Create a seeder for a specific model
php glueful scaffold:seeder RoleSeeder --model=Role

# Create the main database seeder
php glueful scaffold:seeder DatabaseSeeder
```

Seeders are created in `database/seeders/`.

### Seeder Structure

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Glueful\Database\Seeders\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seeders that must run before this one
     */
    protected array $dependencies = [
        RoleSeeder::class,
    ];

    public function run(): void
    {
        // Create an admin user
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin User',
        ]);

        // Create regular users
        User::factory()->count(50)->create();
    }
}
```

### Database Seeder

The `DatabaseSeeder` orchestrates all other seeders:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Glueful\Database\Seeders\Seeder;

class DatabaseSeeder extends Seeder
{
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

### Running Seeders

```bash
# Run the default DatabaseSeeder
php glueful db:seed

# Run a specific seeder
php glueful db:seed UserSeeder

# Run in production (requires --force)
php glueful db:seed --force
```

### Seeder Dependencies

Declare dependencies to ensure seeders run in the correct order:

```php
class PostSeeder extends Seeder
{
    protected array $dependencies = [
        UserSeeder::class,
        CategorySeeder::class,
    ];

    public function run(): void
    {
        // Users and categories are guaranteed to exist
    }
}
```

### Transactions

Wrap seeding operations in a transaction:

```php
public function run(): void
{
    $this->withTransaction(function () {
        User::factory()->count(100)->create();
        Post::factory()->count(500)->create();
    });
}
```

### Truncating Tables

Clear a table before seeding:

```php
public function run(): void
{
    $this->truncate('users');

    User::factory()->count(100)->create();
}
```

## Console Commands

| Command | Description |
|---------|-------------|
| `scaffold:factory <name>` | Generate a new factory class |
| `scaffold:seeder <name>` | Generate a new seeder class |
| `db:seed [class]` | Run database seeders |

### scaffold:factory

```bash
php glueful scaffold:factory UserFactory [options]

Options:
  -m, --model=MODEL   The model class the factory creates
  -f, --force         Overwrite existing file
  -p, --path=PATH     Custom path for the factory file
```

### scaffold:seeder

```bash
php glueful scaffold:seeder UserSeeder [options]

Options:
  -m, --model=MODEL   The model class to use for seeding
  -f, --force         Overwrite existing file
  -p, --path=PATH     Custom path for the seeder file
```

### db:seed

```bash
php glueful db:seed [class] [options]

Arguments:
  class               The seeder class to run (default: DatabaseSeeder)

Options:
  -f, --force         Force the operation to run in production
```

## Best Practices

### 1. Use States for Variations

Instead of overriding attributes inline, define states for common variations:

```php
// Good
$admin = User::factory()->admin()->create();

// Avoid
$admin = User::factory()->create(['role' => 'admin', 'permissions' => [...]]);
```

### 2. Keep Definition Simple

The `definition()` method should return sensible defaults. Use states for variations:

```php
public function definition(): array
{
    return [
        'name' => $this->faker->name(),
        'email' => $this->faker->unique()->safeEmail(),
        'status' => 'active', // Sensible default
    ];
}

public function suspended(): static
{
    return $this->state(['status' => 'suspended']);
}
```

### 3. Use Seeders for Initial Data

Seeders are ideal for data that should exist in every environment:

```php
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['admin', 'moderator', 'user'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}
```

### 4. Protect Production

Always require `--force` for production seeding:

```bash
# This will fail in production
php glueful db:seed

# This will work in production
php glueful db:seed --force
```

### 5. Use Transactions for Large Seeds

Wrap large seeding operations in transactions for better performance and data integrity:

```php
public function run(): void
{
    $this->withTransaction(function () {
        // All operations are atomic
        User::factory()->count(1000)->create();
    });
}
```

### 6. Declare Dependencies

Always declare seeder dependencies to ensure correct execution order:

```php
class CommentSeeder extends Seeder
{
    protected array $dependencies = [
        UserSeeder::class,
        PostSeeder::class,
    ];
}
```
