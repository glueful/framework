# API Keys

Glueful ships a hardened API key system with per-key scopes, IP allowlists, expiration, rotation with grace period, and environment-prefixed plaintext (`gf_live_*` / `gf_test_*`). Keys are stored as SHA-256 hashes with an indexed prefix for O(1) lookup; rotation lets you roll keys without downtime.

## Quick start

### 1. Run the migration

The `api_keys` table is a **core foundation migration** shipped by the framework itself
(`framework/migrations/003_CreateApiKeysTable.php`, source `glueful/framework`, priority
`FOUNDATION`). It is auto-registered, so no copying or renumbering is needed in your app — just
apply pending migrations:

```bash
php glueful migrate:run
```

### 2. Create a key

```bash
php glueful apikey:create \
  --user=<user-uuid> \
  --name="Production Key" \
  --scopes="read:*,write:posts" \
  --ips="192.168.1.0/24,203.0.113.42" \
  --expires=+1year
```

Output:

```
┌──────────────────────────────────────────────────────────────┐
│  API key created — SAVE THIS NOW. It will not be shown again. │
├──────────────────────────────────────────────────────────────┤
│  gf_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0
└──────────────────────────────────────────────────────────────┘
UUID: kT9aZ2vQ8wXm
Name: Production Key
Scopes: read:*, write:posts
Allowed IPs: 192.168.1.0/24, 203.0.113.42
Expires at: 2027-05-21 00:00:00
```

The plaintext key is shown **exactly once** — store it somewhere safe immediately. Subsequent operations identify the key by its UUID, not the plaintext.

### 3. Use the key in requests

Three transport options are supported (in order of preference):

```bash
# Preferred: dedicated header
curl -H "X-API-Key: gf_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0" \
     https://api.example.com/posts

# Authorization header with ApiKey scheme
curl -H "Authorization: ApiKey gf_live_a1b2c3d4..." \
     https://api.example.com/posts

# Query string (legacy; avoid for sensitive endpoints — leaks in logs)
curl "https://api.example.com/posts?api_key=gf_live_a1b2c3d4..."
```

## Key format

```
gf_live_<32-char-base62>     # production
gf_test_<32-char-base62>     # non-production
```

- **Environment prefix** is resolved from `APP_ENV` at creation time. Anything other than `production` produces `gf_test_*`.
- **~190 bits of entropy** in the random portion. Brute force is computationally infeasible.
- **First 16 chars** are stored as `key_prefix` (indexed, not unique by design) for fast lookup.
- **Full key** is SHA-256 hashed and stored as `key_hash` (UNIQUE).

### Why SHA-256 and not bcrypt?

bcrypt's slow hashing is designed to make low-entropy passwords brute-resistant. API keys have ~190 bits of entropy from generation — adding 50-200ms of bcrypt work per auth request gives zero additional security. Industry practice (Stripe, GitHub, GitLab) uses SHA-256 for the same reason.

## Scopes

Scopes are arbitrary strings, colon-separated by convention with `fnmatch`-style wildcard support.

### Declaring scopes when creating a key

```php
use Glueful\Auth\ApiKey\ApiKeyService;

ApiKeyService::create($context, [
    'user_uuid' => $user->uuid,
    'name'    => 'Editor key',
    'scopes'  => ['read:posts', 'write:posts', 'admin:*'],
]);
```

CLI: `--scopes="read:posts,write:posts,admin:*"`

### Enforcing scopes on routes

Use the `#[RequireScope]` attribute on a controller method. The framework auto-attaches the `require_scope` middleware — no need to add it to the route group manually.

```php
use Glueful\Routing\Attributes\Get;
use Glueful\Routing\Attributes\Post;
use Glueful\Routing\Attributes\RequireScope;

class PostController
{
    #[Get('/posts')]
    #[RequireScope('read:posts')]
    public function index(): Response { /* ... */ }

    #[Post('/posts')]
    #[RequireScope(['write:posts', 'admin:posts'])]  // OR
    public function store(): Response { /* ... */ }

    #[Post('/posts/{id}/publish')]
    #[RequireScope(['write:posts', 'admin:posts'])]  // OR within attribute
    #[RequireScope('publish:posts')]                  // AND across attributes
    public function publish(int $id): Response { /* ... */ }
}
```

### Scope grammar

| Pattern | Matches |
|---|---|
| `read:posts` | exact: `read:posts` only |
| `read:*` | `read:posts`, `read:users`, `read:anything` |
| `*` | anything (admin) |

Matching uses PHP's `fnmatch()` — standard shell-glob style. No regex.

### Combining semantics

- **Within a single `#[RequireScope]` attribute** with multiple scopes: **OR**. The key needs *at least one* of the listed scopes.
- **Across multiple stacked `#[RequireScope]` attributes**: **AND**. Each attribute must independently pass.

```php
#[RequireScope(['write:posts', 'admin:posts'])]   // OR: needs write:posts OR admin:posts
#[RequireScope('publish:posts')]                   // AND: also needs publish:posts
```

A key with `['write:posts', 'publish:posts']` passes both. A key with just `['write:posts']` is rejected (missing `publish:posts`).

### A key with no scopes has full access

Setting `scopes` to `null` (or omitting it) means "no scope restriction" — the key passes any `#[RequireScope]` check. Use this for trusted admin keys.

```php
ApiKeyService::create($context, [
    'user_uuid' => $admin->uuid,
    'name'    => 'Admin key',
    // no 'scopes' → full access
]);
```

## IP allowlists

Restrict a key to specific CIDR ranges or single IPs.

```php
ApiKeyService::create($context, [
    'user_uuid'     => $user->uuid,
    'name'        => 'Office key',
    'allowed_ips' => ['192.168.1.0/24', '203.0.113.42', '10.0.0.5/32'],
]);
```

CLI: `--ips="192.168.1.0/24,203.0.113.42"`

**Behavior:**
- Empty / null `allowed_ips` → no IP restriction (key works from anywhere).
- A non-empty list → the client IP must match at least one entry (CIDR or exact).
- Verification uses `Request::getClientIp()` server-side; configure your reverse proxy / load balancer to set proper `X-Forwarded-For` headers if you're behind one.
- IPv4 only in this round; IPv6 is on the roadmap.

## Expiration

```php
ApiKeyService::create($context, [
    'user_uuid'    => $user->uuid,
    'name'       => 'One-year key',
    'expires_at' => '2027-05-21 00:00:00',  // or any strtotime() input
]);
```

CLI: `--expires=+1year` (relative) or `--expires="2027-05-21 00:00:00"` (absolute).

Expired keys fail with `ApiKeyExpiredException` (distinct from generic `InvalidApiKeyException` so you can produce a specific "your key expired" diagnostic to operators).

Omitting `expires_at` (or setting it to `null`) means **never expires** — explicit opt-in to expiration.

## Rotation with grace period

Rotating a key creates a **new** key and sets the **old** key's `expires_at` to `now + graceHours`. Both keys work during the grace window. After the window closes, the old key naturally expires.

```bash
php glueful apikey:rotate kT9aZ2vQ8wXm --grace=24
```

Output:

```
┌──────────────────────────────────────────────────────────────┐
│  New API key — SAVE THIS NOW. It will not be shown again.     │
├──────────────────────────────────────────────────────────────┤
│  gf_live_newKeyPlaintext...
└──────────────────────────────────────────────────────────────┘
Old key UUID:    kT9aZ2vQ8wXm
Old key expires: 2026-05-22 14:30:00
Both keys are valid during the grace window.
```

Programmatic:

```php
$rotation = ApiKeyService::rotate($context, $existingKey, graceHours: 24);
// $rotation['new_plain']       — the new plaintext key (show ONCE)
// $rotation['old_uuid']        — the old key's UUID
// $rotation['old_expires_at']  — when the old key stops working
```

The new key inherits the old key's scopes, `allowed_ips`, and `expires_at`. It gets a fresh UUID, fresh hash, and a name suffixed with `(rotated)`. The new key's `rotated_from_id` points to the old key — useful for audit trails.

## Revocation

Immediate, no grace period. The key fails the next verification call.

```bash
php glueful apikey:revoke kT9aZ2vQ8wXm
```

Programmatic:

```php
ApiKeyService::revoke($context, $key);
```

Revoked keys return `InvalidApiKeyException` (not a distinct "revoked" exception) — security best practice is not to reveal *why* authentication failed.

## Listing keys

```bash
php glueful apikey:list --user=<user-uuid>
```

Renders a table with UUID, Name, Prefix, Scopes, Allowed IPs, Expires At, Revoked.

Programmatic:

```php
$keys = ApiKeyService::forUser($context, $userId);
foreach ($keys as $key) {
    echo $key->name . ' (' . $key->uuid . ')' . PHP_EOL;
}
```

## Reading scopes in controllers

After `ApiKeyAuthenticationProvider` authenticates a request, the key's scopes are on `$request->attributes`:

```php
public function show(Request $request, string $id): Response
{
    $scopes = $request->attributes->get('api_key_scopes', []);
    // ['read:posts', 'write:posts', ...]

    if (in_array('admin:*', $scopes, true)) {
        // include sensitive admin-only fields in the response
    }

    return Response::ok($post);
}
```

The provider also sets:

| Attribute | Value |
|---|---|
| `authenticated` | `true` |
| `user_id` | the key's `user_uuid` (the principal uuid; request-attribute name kept as `user_id`) |
| `user_data` | the resolved identity as an array (`UserIdentity::toArray()`, via `UserProviderInterface`) |
| `auth_method` | `'api_key'` |
| `api_key_scopes` | `array<int, string>` — the key's scopes, or `[]` for unrestricted keys |

## Custom violation handlers

To override the default behavior when a route's required scope isn't satisfied — for example, to log to Sentry or emit a metric:

```php
use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;

// In a service provider's boot() method
set_exception_handler(function (\Throwable $e) use ($context) {
    if ($e instanceof InsufficientScopeException) {
        app($context, LoggerInterface::class)->warning($e->getMessage());
    }
    // re-throw / re-render as normal
});
```

For authentication failures (invalid / expired keys), check `$provider->getError()` on the authentication provider — it carries the last error message.

## Programmatic key creation

```php
use Glueful\Auth\ApiKey\ApiKeyService;

$result = ApiKeyService::create($context, [
    'user_uuid'     => $user->uuid,
    'name'        => 'Service-to-service',
    'scopes'      => ['read:posts'],
    'allowed_ips' => ['10.0.0.0/8'],
    'expires_at'  => null,  // never expires
]);

// IMPORTANT: $result['plain'] is the only time the plaintext key is available.
// Hash it and store somewhere safe, or display it to the user immediately.
$plaintext = $result['plain'];
$apiKey    = $result['key'];  // ApiKey ORM model — safe to store the UUID
```

## Programmatic verification

`ApiKeyAuthenticationProvider` is the production path. If you need to verify a key outside of an HTTP request flow:

```php
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;

try {
    $key = ApiKeyService::verify($context, $plaintext, $clientIp);
    // $key is the matched ApiKey model
} catch (ApiKeyExpiredException) {
    // past expires_at — distinct so you can produce a specific diagnostic
} catch (InvalidApiKeyException) {
    // anything else — not found, hash mismatch, revoked, IP-blocked
}
```

## Testing routes that require API keys

In integration tests, set the `X-API-Key` header on a created key, then dispatch the request through the router. The test harness in `tests/Integration/Auth/ApiKeyAuthenticationTest.php` is the canonical example.

```php
public function testProtectedEndpoint(): void
{
    // Create a key with the scope your route requires
    $result = ApiKeyService::create($this->context, [
        'user_uuid' => $this->user->uuid,
        'name'    => 'Test key',
        'scopes'  => ['read:posts'],
    ]);

    $request = Request::create('/posts', 'GET');
    $request->headers->set('X-API-Key', $result['plain']);

    $response = $this->router->dispatch($request);
    $this->assertSame(200, $response->getStatusCode());
}
```

A few harness notes from the integration test:

- **`BaseRepository::$sharedConnection` is a static.** In tests that synthesize a `users` table inline (because the canonical schema isn't loaded), pre-seed the shared connection so `UserRepository::find()` reads the same in-memory database:
  ```php
  new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
  ```

- **`RouteManifest::reset()` between framework boots** — the route manifest tracks load state in a static, so router-dispatching tests must reset before each fresh boot.

## Database schema

The `api_keys` table:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key, auto-increment | Internal row identifier |
| `uuid` | varchar(12), unique | Public identifier (12-char nanoid, matches framework convention) |
| `user_uuid` | varchar(12), indexed | External principal id (reference to a user's uuid); no DB FK (§2) |
| `name` | varchar(255) | Developer-facing label |
| `key_prefix` | varchar(24), indexed | First 16 chars of the plaintext key |
| `key_hash` | varchar(64), unique | SHA-256 hex of the full plaintext key |
| `scopes` | text, nullable | JSON array, e.g. `["read:*","write:posts"]` |
| `allowed_ips` | text, nullable | JSON array of CIDR/IPs |
| `expires_at` | timestamp, nullable | `null` = never expires |
| `rotated_from_id` | bigint, nullable | Self-reference for rotation lineage |
| `revoked_at` | timestamp, nullable | Soft revocation |
| `created_at`, `updated_at` | timestamp | Database-managed via `DEFAULT CURRENT_TIMESTAMP` |

## Security notes

- **Plaintext keys are shown exactly once.** There's no way to recover a key after creation. Store immediately.
- **No legacy `users.api_key` fallback.** The provider is single-track via the new table. The previous dual-track design referenced a column the canonical schema didn't have (it was dead code) and would have re-authenticated revoked keys whose plaintext still existed in a custom column.
- **All four `AuthenticationProviderInterface` methods** (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) use the new path. `generateTokens()` explicitly returns an error message — API keys are administratively created via the CLI, not minted at authentication time.
- **`canHandleToken()`** matches the existing `^[a-zA-Z0-9_\-]{16,64}$` pattern for backward compatibility with consumers that experimented with custom token shapes. New keys always have the `gf_live_` / `gf_test_` prefix.
- **`generateTokens()` is intentionally a no-op for API keys.** If your code calls it and gets an empty response with an error message in `lastError`, you're using the wrong auth provider for that flow — use the CLI / `ApiKeyService::create()` to mint keys, not the JWT-style auth flow.

## Reference

| File | Purpose |
|---|---|
| `src/Auth/ApiKey/ApiKey.php` | ORM model |
| `src/Auth/ApiKey/ApiKeyService.php` | Generation, verification, rotation, revocation |
| `src/Auth/ApiKey/Support/CidrMatcher.php` | IPv4 CIDR matcher |
| `src/Auth/ApiKey/Exceptions/` | Three exception classes |
| `src/Auth/ApiKeyAuthenticationProvider.php` | Single-track auth provider |
| `src/Routing/Attributes/RequireScope.php` | Route-level scope declaration |
| `src/Routing/Middleware/RequireScopeMiddleware.php` | Enforces declared scopes |
| `src/Console/Commands/ApiKey/` | Four CLI commands |
| `migrations/003_CreateApiKeysTable.php` | Schema (core foundation migration, source `glueful/framework`) |

For design rationale, see `docs/superpowers/specs/2026-05-21-api-key-hardening-design.md`.
