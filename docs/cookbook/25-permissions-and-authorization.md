# Permissions and Authorization

This guide covers the Glueful Framework's comprehensive permission system, including the Gate, voters, policies, attributes, and integration with the authentication system.

## Table of Contents
- [Overview](#overview)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Using Attributes](#using-attributes)
- [Gate System](#gate-system)
- [Voters](#voters)
- [Policies](#policies)
- [UserIdentity](#useridentity)
- [Integration with PermissionManager](#integration-with-permissionmanager)
- [Advanced Topics](#advanced-topics)
- [Examples](#examples)
- [Extension Development](#extension-development)

## Overview

The Glueful permissions system provides multiple layers of authorization:

1. **Gate**: Central authorization point using a voter-based system
2. **Voters**: Pluggable authorization logic (roles, scopes, ownership, policies)
3. **Attributes**: Declarative permission requirements on controllers
4. **Policies**: Class-based authorization for specific resources
5. **PermissionManager**: Facade that integrates providers and the Gate

## Quick Start

### 1. Define Permissions in Config

Edit `config/permissions.php`:

```php
return [
    'strategy' => 'affirmative',  // One GRANT is enough
    'provider_mode' => 'replace',  // or 'combine' for provider + gate
    'allow_deny_override' => false,

    // Define roles and their permissions
    'roles' => [
        'admin' => ['*'],  // Admin can do everything
        'editor' => ['posts.create', 'posts.edit', 'posts.publish'],
        'user' => ['posts.create', 'posts.edit.own'],
    ],

    // Register policies for resources
    'policies' => [
        'posts' => App\Policies\PostPolicy::class,
    ],

    // Optional super admin roles
    'super_roles' => ['super_admin'],
];
```

### 2. Use Attributes on Controllers

```php
use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Auth\Attributes\RequiresRole;

#[RequiresRole('admin')]
class AdminController
{
    // All methods require admin role
}

class PostController
{
    #[RequiresPermission('posts.create')]
    public function create(Request $request)
    {
        // Only users with posts.create permission
    }

    #[RequiresPermission('posts.edit')]
    #[RequiresPermission('posts.publish')]
    public function publish(int $id)
    {
        // Requires BOTH permissions
    }

    #[RequiresRole('editor')]
    #[RequiresRole('admin')]
    public function review()
    {
        // Requires editor OR admin role
    }
}
```

### 3. Add Middleware to Routes

```php
// In your route definitions
$router->group(['middleware' => ['auth', 'gate_permissions']], function ($router) {
    $router->resource('/posts', PostController::class);
});

// Or individually
$router->post('/admin/users', [AdminController::class, 'create'])
    ->middleware(['auth', 'gate_permissions']);
```

## Configuration

### Strategy Options

The `strategy` setting determines how multiple voters are evaluated:

- **`affirmative`** (default): One GRANT is enough to allow access
- **`consensus`**: Majority of voters must GRANT
- **`unanimous`**: All voters must GRANT (most restrictive)

### Provider Modes

The `provider_mode` setting controls how external providers interact with the Gate:

- **`replace`** (default): Use provider only, Gate is bypassed
- **`combine`**: Provider is consulted first, then Gate voters

### Allow Deny Override

When `allow_deny_override` is `true`, a later GRANT can override an earlier DENY. Keep this `false` for security.

## Using Attributes

### RequiresPermission

Declare specific permission requirements:

```php
use Glueful\Auth\Attributes\RequiresPermission;

class DocumentController
{
    #[RequiresPermission('documents.view')]
    public function index() { }

    #[RequiresPermission('documents.edit', resource: 'documents')]
    public function edit(int $id) { }
}
```

### RequiresRole

Declare role requirements:

```php
use Glueful\Auth\Attributes\RequiresRole;

#[RequiresRole('admin')]
class AdminDashboardController
{
    // All methods require admin role
}
```

### Stacking Attributes

Multiple attributes can be combined:

```php
#[RequiresRole('admin')]
#[RequiresPermission('system.configure')]
public function systemSettings()
{
    // Requires admin role AND system.configure permission
}
```

## Gate System

### Direct Gate Usage

```php
use Glueful\Permissions\Gate;
use Glueful\Permissions\Context;
use Glueful\Auth\UserIdentity;

// Get the Gate from container
$gate = app(Gate::class);

// Create user identity
$user = new UserIdentity(
    uuid: 'user-123',
    roles: ['editor'],
    scopes: ['read', 'write'],
    attributes: ['department' => 'marketing']
);

// Create context
$context = new Context(
    tenantId: 'tenant-456',
    routeParams: ['id' => 123],
    jwtClaims: ['sub' => 'user-123'],
    extra: ['ip' => '192.168.1.1']
);

// Check permission
$decision = $gate->decide($user, 'posts.edit', $post, $context);

if ($decision === \Glueful\Permissions\Vote::GRANT) {
    // Permission granted
}
```

### Registering Custom Voters

```php
use Glueful\Permissions\VoterInterface;
use Glueful\Permissions\Vote;

class DepartmentVoter implements VoterInterface
{
    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        // Check if user's department matches resource department
        if ($resource && $user->attr('department') === $resource->department) {
            return new Vote(Vote::GRANT);
        }

        return new Vote(Vote::ABSTAIN);
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return str_starts_with($permission, 'department.');
    }

    public function priority(): int
    {
        return 10; // Lower numbers = higher priority
    }
}

// Register the voter
$gate->registerVoter(new DepartmentVoter());
```

## Voters

### Built-in Voters

The framework includes several voters registered in this order:

1. **SuperRoleVoter** (priority: 0): Grants all permissions to super roles
2. **PolicyVoter** (priority: 5): Delegates to policy classes
3. **RoleVoter** (priority: 10): Checks role-based permissions from config
4. **ScopeVoter** (priority: 15): Checks JWT/OAuth scopes
5. **OwnershipVoter** (priority: 20): Checks resource ownership

### RoleVoter

Configured in `config/permissions.php`:

```php
'roles' => [
    'admin' => ['*'],  // Wildcard for all permissions
    'editor' => [
        'posts.*',      // All post permissions
        'comments.moderate'
    ],
    'user' => ['posts.create', 'posts.edit.own']
]
```

### OwnershipVoter

Automatically handles `.own` permissions:

```php
// In your policy or context
$context = new Context(extra: ['ownerId' => $post->author_id]);

// Permission check
$gate->decide($user, 'posts.edit.own', $post, $context);
// Returns GRANT if user->id() matches context->extra['ownerId']
```

## Policies

### Creating a Policy

```php
namespace App\Policies;

use Glueful\Permissions\PolicyInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Context;

class PostPolicy implements PolicyInterface
{
    public function view(UserIdentity $user, Post $post, Context $ctx): bool
    {
        // Everyone can view published posts
        return $post->isPublished();
    }

    public function edit(UserIdentity $user, Post $post, Context $ctx): bool
    {
        // Authors can edit their own posts
        if ($post->author_id === $user->id()) {
            return true;
        }

        // Editors can edit any post
        return in_array('editor', $user->roles());
    }

    public function delete(UserIdentity $user, Post $post, Context $ctx): bool
    {
        // Only admins can delete
        return in_array('admin', $user->roles());
    }
}
```

### Registering Policies

In `config/permissions.php`:

```php
'policies' => [
    'posts' => App\Policies\PostPolicy::class,
    Post::class => App\Policies\PostPolicy::class,  // Can use class name
]
```

### Using Resource Slugs

When checking permissions with string resources:

```php
$context = new Context(extra: ['resource_slug' => 'posts']);
$gate->decide($user, 'edit', 'my-post-id', $context);
// PolicyVoter will use 'posts' to find the policy
```

## UserIdentity

The `UserIdentity` class is a lightweight representation of the authenticated user:

```php
use Glueful\Auth\UserIdentity;

$user = new UserIdentity(
    uuid: 'user-123',
    roles: ['admin', 'editor'],
    scopes: ['read', 'write', 'delete'],
    attributes: [
        'department' => 'engineering',
        'permissions' => ['custom.permission'],
        'email' => 'user@example.com'
    ]
);

// Access methods
$userId = $user->id();                    // 'user-123'
$roles = $user->roles();                  // ['admin', 'editor']
$scopes = $user->scopes();                // ['read', 'write', 'delete']
$dept = $user->attr('department');        // 'engineering'
$unknown = $user->attr('unknown', 'default'); // 'default'
```

## Integration with PermissionManager

The `PermissionManager` integrates both provider-based and Gate-based authorization:

```php
use Glueful\Permissions\PermissionManager;

$manager = app('permission.manager');

// Check permission (uses provider or Gate based on config)
$canEdit = $manager->can(
    userUuid: 'user-123',
    permission: 'posts.edit',
    resource: 'posts',
    context: ['resource_obj' => $post]
);

// The manager automatically:
// 1. Builds UserIdentity from context
// 2. Checks provider_mode setting
// 3. Calls provider and/or Gate
// 4. Returns boolean result
```

## Advanced Topics

### Custom Decision Strategies

Create a custom Gate with different strategy:

```php
$gate = new Gate(
    strategy: 'unanimous',        // All voters must agree
    allowDenyOverride: false
);
```

### Combining Provider and Gate

In `config/permissions.php`:

```php
'provider_mode' => 'combine',  // Use both provider and Gate
```

How it works:
1. Provider is called first
2. If provider returns `true` → treated as GRANT
3. If provider returns `false` → treated as ABSTAIN
4. Gate voters are then evaluated
5. Final decision based on strategy

### Conditional Voter Registration

In a service provider:

```php
public function boot()
{
    $gate = $this->app->get(Gate::class);

    if (config('app.multi_tenant')) {
        $gate->registerVoter(new TenantVoter());
    }

    if (config('features.departments')) {
        $gate->registerVoter(new DepartmentVoter());
    }
}
```

### Permission Caching

The system integrates with `PermissionCache`:

```php
use Glueful\Permissions\PermissionCache;

$cache = app(PermissionCache::class);

// Cache user permissions
$cache->setUserPermissions('user-123', [
    'posts.create',
    'posts.edit',
    'comments.moderate'
]);

// Retrieve cached permissions
$permissions = $cache->getUserPermissions('user-123');
```

## Examples

### Example 1: Blog System

```php
// config/permissions.php
return [
    'roles' => [
        'admin' => ['*'],
        'editor' => ['posts.*', 'comments.*'],
        'author' => ['posts.create', 'posts.edit.own', 'comments.reply'],
        'subscriber' => ['posts.view', 'comments.view']
    ],
    'policies' => [
        'posts' => App\Policies\PostPolicy::class,
        'comments' => App\Policies\CommentPolicy::class,
    ]
];

// Controller
class BlogController
{
    #[RequiresPermission('posts.view')]
    public function index() { }

    #[RequiresPermission('posts.create')]
    public function store(Request $request) { }

    #[RequiresPermission('posts.edit')]
    public function update(int $id, Request $request)
    {
        $post = Post::find($id);

        // Additional ownership check
        $gate = app(Gate::class);
        $user = $this->getCurrentUserIdentity();
        $context = new Context(extra: ['ownerId' => $post->author_id]);

        if ($gate->decide($user, 'posts.edit.own', $post, $context) !== Vote::GRANT) {
            abort(403);
        }

        // Update post...
    }
}
```

### Example 2: Multi-Tenant System

```php
// Custom TenantVoter
class TenantVoter implements VoterInterface
{
    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        // Check if user belongs to the tenant
        $userTenant = $user->attr('tenant_id');
        $contextTenant = $ctx->tenantId;

        if ($userTenant && $contextTenant && $userTenant !== $contextTenant) {
            return new Vote(Vote::DENY);
        }

        return new Vote(Vote::ABSTAIN);
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return $ctx->tenantId !== null;
    }

    public function priority(): int
    {
        return 1; // Check tenant before other voters
    }
}
```

### Example 3: API with Scopes

```php
// For OAuth/JWT with scopes
class ApiController
{
    public function getData(Request $request)
    {
        $gate = app(Gate::class);

        // Build identity from JWT
        $user = new UserIdentity(
            uuid: $request->getAttribute('jwt.sub'),
            roles: [],
            scopes: $request->getAttribute('jwt.scopes', []),
            attributes: []
        );

        // ScopeVoter will check if 'read:data' is in scopes
        if ($gate->decide($user, 'read:data', null, new Context()) !== Vote::GRANT) {
            return new JsonResponse(['error' => 'Insufficient scope'], 403);
        }

        return new JsonResponse(['data' => $this->fetchData()]);
    }
}
```

## Extension Development

Extensions can integrate with the permissions system in three ways:

### 1. Permission Providers

Create database-backed RBAC systems:

```php
use Glueful\Interfaces\Permission\PermissionProviderInterface;

final class MyRbacProvider implements PermissionProviderInterface
{
    public function getProviderInfo(): array
    {
        return ['name' => 'myvendor/rbac'];
    }

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        // Your database lookup logic
        return $this->repo->allows($userUuid, $permission, $context['tenant_id'] ?? null);
    }

    public function getUserPermissions(string $userUuid): array
    {
        return $this->repo->getUserPermissions($userUuid);
    }

    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        return $this->repo->assignPermission($userUuid, $permission, $resource);
    }

    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        return $this->repo->revokePermission($userUuid, $permission, $resource);
    }

    // Implement other required methods...
}

// Register the provider
$pm = app('permission.manager');
$pm->setProvider(new MyRbacProvider());
```

### 2. Custom Voters

Add custom authorization logic to the Gate:

```php
use Glueful\Permissions\{VoterInterface, Vote, Context};
use Glueful\Auth\UserIdentity;

final class FeatureFlagVoter implements VoterInterface
{
    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return str_starts_with($permission, 'feature.');
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $feature = substr($permission, 8); // Remove 'feature.' prefix
        $tenantId = $ctx->tenantId ?? 'default';

        $enabled = $this->featureFlags->isEnabled($tenantId, $feature);

        return new Vote($enabled ? Vote::ABSTAIN : Vote::DENY);
    }

    public function priority(): int
    {
        return 40; // After core voters
    }
}

// Register in a service provider
public function boot()
{
    $gate = $this->app->get(\Glueful\Permissions\Gate::class);
    $gate->registerVoter(new FeatureFlagVoter($this->app->get('feature.flags')));
}
```

### 3. Policy Registration

Register policies for extension resources:

```php
use Glueful\Permissions\{PolicyInterface, Context};
use Glueful\Auth\UserIdentity;

final class InvoicePolicy implements PolicyInterface
{
    public function view(UserIdentity $user, mixed $invoice, Context $ctx): ?bool
    {
        // Accountants can view all invoices
        if (in_array('accountant', $user->roles(), true)) {
            return true;
        }

        // Users can view their own invoices
        if ($invoice && $invoice->user_id === $user->id()) {
            return true;
        }

        return null; // Let other voters decide
    }

    public function create(UserIdentity $user, mixed $invoice, Context $ctx): ?bool
    {
        return in_array('accountant', $user->roles(), true) ? true : null;
    }

    public function update(UserIdentity $user, mixed $invoice, Context $ctx): ?bool
    {
        return in_array('accountant', $user->roles(), true) ? true : null;
    }

    public function delete(UserIdentity $user, mixed $invoice, Context $ctx): ?bool
    {
        return in_array('admin', $user->roles(), true) ? true : null;
    }
}

// Register in extension boot method
public function boot()
{
    $registry = $this->app->get(\Glueful\Permissions\PolicyRegistry::class);
    $registry->register('invoices', InvoicePolicy::class);
}
```

### Extension Configuration

Extensions should provide their own config but can extend the main permissions config:

```php
// extensions/my-extension/config/permissions.php
return [
    'roles' => [
        'accountant' => ['invoices.*', 'reports.financial'],
        'billing_admin' => ['invoices.*', 'payments.*'],
    ],
    'policies' => [
        'invoices' => MyExtension\Policies\InvoicePolicy::class,
        'payments' => MyExtension\Policies\PaymentPolicy::class,
    ],
];

// In extension boot method
public function boot()
{
    $this->mergeConfigFrom(
        __DIR__ . '/../config/permissions.php',
        'permissions'
    );
}
```

### Multi-Tenant Extensions

Use `Context->tenantId` for tenant-scoped permissions:

```php
final class TenantResourceVoter implements VoterInterface
{
    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        // Ensure user belongs to the tenant
        $userTenant = $user->attr('tenant_id');
        $contextTenant = $ctx->tenantId;

        if ($userTenant && $contextTenant && $userTenant !== $contextTenant) {
            return new Vote(Vote::DENY);
        }

        return new Vote(Vote::ABSTAIN);
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return $ctx->tenantId !== null;
    }

    public function priority(): int
    {
        return 5; // High priority for security
    }
}
```

### Security Guidelines for Extensions

1. **Deny by default** - Return `null` or `ABSTAIN` when uncertain
2. **Validate tenant context** - Always check tenant isolation
3. **Use proper priorities** - Security voters should have lower numbers (higher priority)
4. **Test thoroughly** - Authorization bugs are security vulnerabilities
5. **Document permissions** - Clear docs for extension users

## Best Practices

1. **Use attributes for declarative permissions** - Cleaner than inline checks
2. **Implement policies for complex logic** - Better than voter spaghetti
3. **Keep roles simple** - Use permissions, not role checks in code
4. **Cache permissions** - Especially for database-backed providers
5. **Log authorization failures** - For security auditing
6. **Test your voters and policies** - Unit test authorization logic
7. **Use proper priority** - Order voters from most to least specific

## Troubleshooting

### Permission Always Denied

1. Check if middleware is applied: `['auth', 'gate_permissions']`
2. Verify user has `auth.user` attribute set
3. Check voter registration order in providers
4. Enable debug mode to trace voter decisions

### Attributes Not Working

1. Ensure `GateAttributeMiddleware` is registered
2. Check `handler_meta` is set with class/method info
3. Verify attribute namespace: `Glueful\Auth\Attributes\`

### Policy Not Found

1. Check policy is registered in `config/permissions.php`
2. Verify resource slug matches registration key
3. Use `Context->extra['resource_slug']` for custom mapping

## Related Documentation

- [Authentication](authentication.md) - User authentication and sessions
- [Middleware Development](middleware-development.md) - Creating custom middleware
- [Dependency Injection](dependency-injection.md) - Service container and providers
- [Configuration](configuration.md) - Framework configuration

## Summary

The Glueful permissions system provides a flexible, extensible authorization framework that can handle everything from simple role checks to complex business rules. By combining the Gate system with voters, policies, and attributes, you can implement any authorization strategy your application requires.