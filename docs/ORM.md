# Glueful ORM Documentation

The Glueful ORM provides an elegant Active Record implementation for working with your database. Each database table has a corresponding "Model" class that is used to interact with that table.

## Table of Contents

- [Defining Models](#defining-models)
- [Retrieving Models](#retrieving-models)
- [Inserting & Updating](#inserting--updating)
- [Deleting Models](#deleting-models)
- [Soft Deletes](#soft-deletes)
- [Query Scopes](#query-scopes)
- [Relationships](#relationships)
- [Eager Loading](#eager-loading)
- [Collections](#collections)
- [Attribute Casting](#attribute-casting)
- [Accessors & Mutators](#accessors--mutators)
- [Mass Assignment](#mass-assignment)

## Defining Models

### Creating a Model

Use the `scaffold:model` command to generate a new model:

```bash
# Basic model
php glueful scaffold:model User

# Model with migration
php glueful scaffold:model Post -m

# Model with soft deletes
php glueful scaffold:model Comment -s

# Model with fillable attributes
php glueful scaffold:model Article --fillable="title,body,author_id"
```

### Model Conventions

```php
<?php

namespace App\Models;

use Glueful\Database\ORM\Model;

class User extends Model
{
    // Table name (defaults to snake_case plural: 'users')
    protected string $table = 'users';

    // Primary key (defaults to 'id')
    protected string $primaryKey = 'id';

    // Enable timestamps (defaults to true)
    public bool $timestamps = true;

    // Timestamp column names
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
}
```

## Retrieving Models

### Finding Models

```php
// Find by primary key
$user = User::find(1);

// Find or fail (throws ModelNotFoundException)
$user = User::findOrFail(1);

// Find multiple
$users = User::find([1, 2, 3]);

// Get all models
$users = User::all();
```

### Query Building

```php
// Where clauses
$users = User::where('status', 'active')
    ->where('age', '>', 18)
    ->get();

// Or where
$users = User::where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->get();

// Where in
$users = User::whereIn('id', [1, 2, 3])->get();

// Where null
$users = User::whereNull('deleted_at')->get();

// Ordering
$users = User::orderBy('name', 'asc')
    ->latest('created_at')
    ->get();

// Limiting
$users = User::take(10)->skip(5)->get();

// First result
$user = User::where('email', 'john@example.com')->first();
$user = User::where('email', 'john@example.com')->firstOrFail();
```

### Chunking Results

For processing large amounts of data:

```php
User::chunk(200, function ($users) {
    foreach ($users as $user) {
        // Process each user
    }
});

// Each method
User::where('active', true)->each(function ($user) {
    echo $user->name;
}, 100);
```

## Inserting & Updating

### Creating Records

```php
// Create and save
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->save();

// Create with attributes
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// First or create
$user = User::firstOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe']
);

// First or new (doesn't save)
$user = User::firstOrNew(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe']
);
```

### Updating Records

```php
// Update single model
$user = User::find(1);
$user->name = 'Jane Doe';
$user->save();

// Update via query
User::where('status', 'inactive')
    ->update(['status' => 'active']);

// Update or create
$user = User::updateOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Smith', 'role' => 'admin']
);
```

### Dirty Tracking

```php
$user = User::find(1);
$user->name = 'New Name';

$user->isDirty();        // true
$user->isDirty('name');  // true
$user->isDirty('email'); // false

$user->isClean();        // false
$user->getDirty();       // ['name' => 'New Name']
```

## Deleting Models

```php
// Delete single model
$user = User::find(1);
$user->delete();

// Delete via query
User::where('status', 'inactive')->delete();
```

## Soft Deletes

Soft deletes mark records as deleted without actually removing them from the database.

### Enabling Soft Deletes

```php
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Concerns\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    // Optional: customize the column name
    public const DELETED_AT = 'deleted_at';
}
```

### Using Soft Deletes

```php
// Soft delete (sets deleted_at timestamp)
$post->delete();

// Check if trashed
$post->trashed(); // true

// Restore soft deleted model
$post->restore();

// Force delete (permanent)
$post->forceDelete();
```

### Querying Soft Deleted Models

```php
// Include trashed (soft deleted) models
$posts = Post::withTrashed()->get();

// Only trashed models
$posts = Post::onlyTrashed()->get();

// Exclude trashed (default behavior)
$posts = Post::withoutTrashed()->get();
```

## Query Scopes

### Local Scopes

Define reusable query constraints:

```php
class User extends Model
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}

// Usage
$users = User::active()->get();
$admins = User::active()->role('admin')->get();
```

### Global Scopes

Applied to all queries for a model:

```php
use Glueful\Database\ORM\Contracts\Scope;

class ActiveScope implements Scope
{
    public function apply(Builder $builder, object $model): void
    {
        $builder->where('is_active', true);
    }
}

// In the model
class User extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('active', new ActiveScope());
    }
}

// Remove global scope for specific query
User::withoutGlobalScope('active')->get();
```

## Relationships

### Defining Relationships

```php
class User extends Model
{
    // One to One
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    // One to Many
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // Belongs To
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // Many to Many
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

class Post extends Model
{
    // Inverse of HasMany
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Has Many Through
    public function categories(): HasManyThrough
    {
        return $this->hasManyThrough(
            Category::class,
            PostCategory::class
        );
    }
}
```

### Accessing Relationships

```php
// Access as property (lazy loads)
$posts = $user->posts;
$profile = $user->profile;

// Access as method for chaining
$publishedPosts = $user->posts()->where('published', true)->get();
```

### Pivot Tables (Many to Many)

```php
class User extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('assigned_at', 'assigned_by')
            ->withTimestamps();
    }
}

// Access pivot data
foreach ($user->roles as $role) {
    echo $role->pivot->assigned_at;
}

// Attach / Detach
$user->roles()->attach($roleId);
$user->roles()->attach($roleId, ['assigned_by' => $adminId]);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
```

## Eager Loading

Prevent N+1 query problems by eager loading relationships:

```php
// Eager load single relationship
$users = User::with('posts')->get();

// Multiple relationships
$users = User::with(['posts', 'profile'])->get();

// Nested eager loading
$users = User::with('posts.comments')->get();

// Eager load with constraints
$users = User::with(['posts' => function ($query) {
    $query->where('published', true)
          ->orderBy('created_at', 'desc');
}])->get();
```

### Lazy Eager Loading

```php
$users = User::all();

// Load after initial query
$users->load('posts');
$users->load(['posts', 'comments']);

// Load only if not already loaded
$users->loadMissing('posts');
```

### Relationship Existence Queries

```php
// Has relationship
$users = User::has('posts')->get();
$users = User::has('posts', '>=', 5)->get();

// Where has (with constraints)
$users = User::whereHas('posts', function ($query) {
    $query->where('published', true);
})->get();

// Doesn't have
$users = User::doesntHave('posts')->get();

// With count
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    echo $user->posts_count;
}
```

## Collections

Query results are returned as Collection instances:

```php
$users = User::all();

// Collection methods
$users->count();
$users->first();
$users->last();
$users->isEmpty();
$users->isNotEmpty();

// Filtering
$active = $users->filter(fn($u) => $u->status === 'active');
$names = $users->pluck('name');
$byId = $users->keyBy('id');

// Transforming
$mapped = $users->map(fn($u) => $u->toArray());
$emails = $users->map(fn($u) => $u->email)->toArray();

// Aggregation
$users->sum('credits');
$users->avg('age');
$users->max('salary');
$users->min('salary');

// Grouping
$byRole = $users->groupBy('role');

// Converting
$users->toArray();
$users->toJson();
```

## Attribute Casting

### Built-in Casts

```php
class User extends Model
{
    protected array $casts = [
        'id' => 'integer',
        'is_admin' => 'boolean',
        'balance' => 'float',
        'preferences' => 'array',
        'settings' => 'json',
        'birthday' => 'datetime',
        'created_at' => 'timestamp',
    ];
}
```

### Custom Cast Classes

```php
use Glueful\Database\ORM\Casts\AsJson;
use Glueful\Database\ORM\Casts\AsCollection;
use Glueful\Database\ORM\Casts\AsArrayObject;
use Glueful\Database\ORM\Casts\AsDateTime;
use Glueful\Database\ORM\Casts\AsEncryptedString;
use Glueful\Database\ORM\Casts\AsEnum;

class User extends Model
{
    protected array $casts = [
        'metadata' => AsJson::class,
        'tags' => AsCollection::class,
        'config' => AsArrayObject::class,
        'hired_at' => AsDateTime::class,
        'ssn' => AsEncryptedString::class,
        'status' => AsEnum::class . ':' . UserStatus::class,
    ];
}
```

### Creating Custom Casts

```php
use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;

class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return new Money(
            (int) $value,
            $attributes['currency'] ?? 'USD'
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Money ? $value->cents : (int) $value;
    }
}
```

## Accessors & Mutators

### Traditional Style

```php
class User extends Model
{
    // Accessor: getXxxAttribute
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    // Mutator: setXxxAttribute
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}

// Usage
echo $user->full_name;
$user->password = 'secret'; // Automatically hashed
```

### Modern Attribute Style

```php
use Glueful\Database\ORM\Casts\Attribute;

class User extends Model
{
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->first_name . ' ' . $this->last_name,
        );
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => password_hash($value, PASSWORD_DEFAULT),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => strtolower($value),
            set: fn ($value) => strtolower($value),
        );
    }
}
```

### Appending Accessors

Include accessors in array/JSON output:

```php
class User extends Model
{
    protected array $appends = ['full_name', 'is_admin'];
}
```

## Mass Assignment

### Fillable vs Guarded

```php
class User extends Model
{
    // Only these can be mass assigned
    protected array $fillable = ['name', 'email', 'password'];

    // Or guard specific attributes
    protected array $guarded = ['id', 'is_admin'];

    // Allow all (not recommended)
    protected array $guarded = [];
}
```

### Using Fill

```php
$user = new User();
$user->fill([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Force fill (bypass mass assignment)
$user->forceFill([
    'name' => 'John',
    'is_admin' => true,
]);
```

## Hiding Attributes

Control what appears in toArray() and toJson():

```php
class User extends Model
{
    // Always hide
    protected array $hidden = ['password', 'remember_token'];

    // Only show these
    protected array $visible = ['id', 'name', 'email'];
}

// Runtime modification
$user->makeVisible('password');
$user->makeHidden('email');
```

## Model Events

Hook into the model lifecycle:

```php
class User extends Model
{
    protected static function booted(): void
    {
        static::creating(function ($user) {
            $user->uuid = Uuid::generate();
        });

        static::created(function ($user) {
            Mail::send(new WelcomeEmail($user));
        });

        static::updating(function ($user) {
            $user->updated_by = Auth::id();
        });

        static::deleting(function ($user) {
            $user->tokens()->delete();
        });
    }
}
```

Available events: `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `restoring`, `restored`, `trashed`.

## Serialization

```php
// To array
$array = $user->toArray();

// To JSON
$json = $user->toJson();
$json = $user->toJson(JSON_PRETTY_PRINT);

// Automatic via __toString
echo $user; // Outputs JSON
```
