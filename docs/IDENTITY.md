# Identity & User Providers

Glueful's core is **provider-agnostic about who your users are.** The framework owns the security
spine — sessions, tokens, API keys, auth middleware, authorization — but the *concrete user store*
(the `users` table, password hashing, account lifecycle) lives in an **extension**, behind a small
contract. The first-party store is [`glueful/users`](https://github.com/glueful/users); you can
swap in your own (LDAP, an existing DB, a SaaS directory) by implementing one interface.

> **This replaced the old in-core `User`/`UserRepository`.** Core no longer ships a `User` model or
> repository; everything works through `UserIdentity` + `UserProviderInterface`. Apps must enable a
> user store (the api-skeleton enables `glueful/users` by default). Without one, core binds a
> fail-closed `NullUserProvider` and authentication is disabled by design.

## The pieces

| Type | Role |
|---|---|
| `Glueful\Auth\UserIdentity` | The one canonical runtime identity — identity facts + claims. Immutable, `final`. |
| `Glueful\Auth\Contracts\UserProviderInterface` | Looks users up and verifies credentials. Implemented by a user store. |
| `Glueful\Auth\NullUserProvider` | Fail-closed default binding — every lookup returns `null`. |
| `Glueful\Auth\IdentityResolver` | Post-auth: applies the account-status gate and folds in claims providers. |
| `Glueful\Auth\Contracts\IdentityClaimsProviderInterface` | Decorates an identity with claims (e.g. roles). Implemented by RBAC, e.g. `glueful/aegis`. |
| `Glueful\Auth\Contracts\TwoFactorServiceInterface` | Optional 2FA, provided by an extension. |

### `UserIdentity`

The authenticated identity **plus its runtime claims** — not a database row. It carries identity
facts (uuid, email, username, status), runtime context (session uuid, provider), and an open
**claims bag** (roles, scopes, permissions, …). It's immutable; `with*()` methods return copies.

```php
$id = new UserIdentity(
    uuid: 'u-abc123',
    roles: ['editor'],
    scopes: ['read:posts'],
    attributes: ['tenant_id' => 42],
    email: 'amy@example.test',
    username: 'amy',
    status: 'active',
);

$id->uuid();                 // 'u-abc123'
$id->email();                // 'amy@example.test' (nullable)
$id->status();               // 'active' (nullable)
$id->roles();                // ['editor']      — typed claim accessor
$id->scopes();               // ['read:posts']
$id->claim('permissions', []); // arbitrary claim with default
$id->attr('tenant_id');      // non-claim attribute
$id->withClaims(['roles' => ['editor', 'admin']]); // returns a NEW identity
$id->withSession($sessionUuid, 'jwt');             // attaches runtime context
$id->toArray();              // array shape for session/user_data
```

**Identity facts vs. claims:** a claims provider can change *what a user can do* (claims), never
*who they are* (identity facts) — the resolver re-pins the facts after enrichment.

### `UserProviderInterface`

Three methods — authentication only (no registration/profile writes; those belong to the store):

```php
interface UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity;
    public function findByLogin(string $identifier): ?UserIdentity; // email/username/etc.
    public function verifyCredentials(string $identifier, string $password): ?UserIdentity;
}
```

Core binds `NullUserProvider` by default; a user store rebinds it (see below).

### `IdentityResolver`

Runs **after** credentials verify. It (1) gates on account status and (2) folds registered claims
providers in additively, re-pinning identity facts each time.

```php
new IdentityResolver(
    $claimsProviders,            // collected from the 'identity.claims_provider' container tag
    $allowedStatuses,            // config('security.auth.allowed_login_statuses'), default ['active']
);
```

A user whose `status` isn't in `allowed_login_statuses` is rejected at login (returns `null`).

## The login flow

```
POST /auth/login
  → AuthenticationService::verifyCredentials()
      → UserProviderInterface::verifyCredentials($identifier, $password)   // the store checks the hash
      → IdentityResolver::resolve($identity)
            → status gate (allowed_login_statuses)
            → fold each IdentityClaimsProvider::enrich() (roles/permissions/…)
  → session/token layer attaches sessionUuid + provider, persists identity + claims
```

If no user store is installed, `verifyCredentials()` returns `null` (NullUserProvider) and login
fails closed.

## Accessing the current user

`RequestUserContext` exposes the authenticated identity as a `UserIdentity`:

```php
$user = $requestUserContext->getUser();   // ?UserIdentity
$user?->uuid();
$user?->roles();
```

Controllers extending `BaseController` expose the same identity via their user accessors. (The old
`AuthenticatedUser` type is gone — everything is `UserIdentity` now, with method accessors.)

## Enabling a user store

Install and enable a store — the first-party one:

```bash
composer require glueful/users
```

```php
// config/extensions.php
'enabled' => [
    'Glueful\\Extensions\\Users\\UsersServiceProvider',
],
```

Then run migrations (`users`/`profiles` ship with the extension; the auth spine ships with core —
see [Migrations & Core Capability Schema](MIGRATIONS_AND_CAPABILITIES.md)):

```bash
php glueful migrate:run
```

## Writing your own user store

Implement `UserProviderInterface` and bind it to the contract. In an extension's `services()`:

```php
use Glueful\Auth\Contracts\UserProviderInterface;

public static function services(): array
{
    return [
        MyUserProvider::class => [
            'class' => MyUserProvider::class,
            'shared' => true,
            'arguments' => ['@' . MyDirectoryClient::class],
            'alias' => [UserProviderInterface::class], // rebinds the contract away from NullUserProvider
        ],
    ];
}
```

Return `UserIdentity` instances from your lookups; for `verifyCredentials()`, return the identity on
a correct password and `null` otherwise. Treat the uuid as an opaque principal id.

## Adding claims (roles, permissions, …)

A claims provider enriches every authenticated identity post-login. Implement the interface and tag
it `identity.claims_provider` — core's `IdentityResolver` collects and invokes all of them:

```php
use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;
use Glueful\Auth\UserIdentity;

final class MyRoleClaims implements IdentityClaimsProviderInterface
{
    public function enrich(UserIdentity $identity): UserIdentity
    {
        $roles = $this->roleStore->rolesFor($identity->uuid()); // list<string>
        return $roles === []
            ? $identity                                          // never fabricate membership
            : $identity->withClaims(['roles' => array_values(array_unique([...$identity->roles(), ...$roles]))]);
    }
}
```

```php
// services()
MyRoleClaims::class => [
    'class' => MyRoleClaims::class,
    'arguments' => ['@' . MyRoleStore::class],
    'shared' => true,
    'tags' => ['identity.claims_provider'],
],
```

This is exactly how `glueful/aegis` adds RBAC role claims. Enrichment is **additive only** — a
claims provider can grant capabilities, never change who the user is.

## Optional: two-factor

If an extension registers a `TwoFactorServiceInterface` implementation, `AuthController` routes
login through it (`isEnabled()` / `beginLogin()`); with none registered, 2FA is skipped entirely.
`glueful/users` provides one.

## Configuration

```php
// config/security.php
'auth' => [
    // Account statuses permitted to authenticate. Others are rejected by IdentityResolver.
    'allowed_login_statuses' => ['active'],
],
```

## See also

- [Migrations & Core Capability Schema](MIGRATIONS_AND_CAPABILITIES.md) — where the auth/identity tables live.
- [API Keys](API_KEYS.md) — API-key auth resolves the principal via `UserProviderInterface`.
