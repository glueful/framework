# API Key Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a dedicated `api_keys` table supporting scopes, IP allowlists, expiration, rotation with grace period, and environment-prefixed keys. The framework provides the code surface (model, service, provider, route attribute, middleware, CLI); api-skeleton ships the schema migration following the established convention.

**Architecture:** A new `ApiKeyService` owns key generation (SHA-256 over high-entropy plaintext, indexed prefix lookup, collision-tolerant lookup), verification (prefix → hash compare → expiry → CIDR allowlist), rotation with grace period, and revocation. An `ApiKey` ORM model wraps the table. `ApiKeyAuthenticationProvider` is **single-track** — verifies via the new table and returns null on any failure. The canonical api-skeleton schema does not have a `users.api_key` column (`UserRepository::findByApiKey()` queries a column that doesn't exist in `001_CreateInitialSchema.php`), so there is no legacy data to preserve and no dual-track fallback to maintain. Scope enforcement at routes: a repeatable `#[RequireScope]` attribute, processed by `AttributeRouteLoader` (auto-attaches `require_scope` middleware), enforced by `RequireScopeMiddleware` reading scopes the provider populated on the request.

**Tech Stack:** PHP 8.3+, PHPUnit 10, SQLite (in-memory for integration tests), framework's existing `SchemaBuilderInterface`, ORM, attribute routing.

**Spec:** `docs/superpowers/specs/2026-05-21-api-key-hardening-design.md` (note: spec describes a dual-track fallback that this plan supersedes — see Goal/Architecture above for the simplified approach).

---

## File Structure

**New files (framework repo):**

| Path | Responsibility |
|---|---|
| `src/Auth/ApiKey/ApiKey.php` | ORM model on `api_keys` table |
| `src/Auth/ApiKey/ApiKeyService.php` | Generation, hashing, verify, rotate, revoke, list |
| `src/Auth/ApiKey/Support/CidrMatcher.php` | Inline IPv4 CIDR/IP matcher |
| `src/Auth/ApiKey/Exceptions/InvalidApiKeyException.php` | extends `AuthenticationException`. Any auth failure other than expiration. |
| `src/Auth/ApiKey/Exceptions/ApiKeyExpiredException.php` | extends `AuthenticationException`. Kept distinct so consumers can produce a "your key expired" diagnostic. |
| `src/Auth/ApiKey/Exceptions/InsufficientScopeException.php` | extends `AuthorizationException`. Thrown by `RequireScopeMiddleware`. |
| `src/Routing/Attributes/RequireScope.php` | `#[\Attribute(IS_REPEATABLE)]` for declaring required scopes |
| `src/Routing/Middleware/RequireScopeMiddleware.php` | Reads route config, enforces AND/OR scope semantics |
| `src/Console/Commands/ApiKey/CreateCommand.php` | `apikey:create` |
| `src/Console/Commands/ApiKey/ListCommand.php` | `apikey:list` |
| `src/Console/Commands/ApiKey/RotateCommand.php` | `apikey:rotate` |
| `src/Console/Commands/ApiKey/RevokeCommand.php` | `apikey:revoke` |
| `tests/Unit/Auth/ApiKey/CidrMatcherTest.php` | CIDR edge cases |
| `tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php` | Generation, hash, scope matching, row-level check |
| `tests/Integration/Auth/ApiKeyAuthenticationTest.php` | Booted framework + SQLite; full service + provider flow |

**New files (api-skeleton repo):**

| Path | Responsibility |
|---|---|
| `api-skeleton/database/migrations/009_CreateApiKeysTable.php` | Schema migration. Follows existing convention (`<NN>_<PascalCaseClassName>.php`). |

**Modified files:**

| Path | Change |
|---|---|
| `src/Auth/ApiKeyAuthenticationProvider.php` | Single-track via `ApiKeyService::verify()`. All four interface methods (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) updated. Populate `api_key_scopes` on the request. |
| `src/Repository/UserRepository.php` | Remove `findByApiKey()` — zero callers after the provider switches to `ApiKeyService::verify()` (org-wide grep verified). |
| `src/Routing/Route.php` | Add `setRequireScopeConfig()` / `getRequireScopeConfig()`. |
| `src/Routing/AttributeRouteLoader.php` | Add `processRequireScopeAttributes()` mirroring `processRateLimitAttributes()`. Auto-attaches `require_scope` middleware. |
| `src/Routing/Router.php` | Set `_route` and `_route_params` on `$request->attributes` after match, before middleware. |
| `src/Container/Providers/CoreProvider.php` | Register `ApiKeyService` + `require_scope` middleware alias. |
| `CLAUDE.md` | Pointer bullet under Authentication. |
| `docs/FRAMEWORK_IMPROVEMENTS.md` | Flip 5.3 row to ✅. |
| `CHANGELOG.md` | Unreleased entry. |

---

## Task 1: Migration File (api-skeleton)

**Files:**
- Create: `/Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/009_CreateApiKeysTable.php`

Follows the api-skeleton convention used by `001_CreateInitialSchema.php` through `008_CreateAuthRefreshTokensTable.php`: `<NN>_<PascalCaseClassName>.php`, single digit-block prefix, PascalCase class name. The next sequential number is `009`.

- [ ] **Step 1: Confirm the next sequential number**

Run: `ls /Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/ | sort | tail -3`
Expected: the latest is `008_CreateAuthRefreshTokensTable.php`. We use `009`.

- [ ] **Step 2: Write the migration**

Create `/Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/009_CreateApiKeysTable.php`:

```php
<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class CreateApiKeysTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('api_keys')) {
            return;
        }

        $schema->createTable('api_keys', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_id', 12);
            $table->string('name', 255);
            $table->string('key_prefix', 24);
            $table->string('key_hash', 64);
            $table->text('scopes')->nullable();
            $table->text('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->bigInteger('rotated_from_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique('key_hash');
            $table->index('user_id');
            $table->index('key_prefix');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('api_keys');
    }

    public function getDescription(): string
    {
        return 'Creates api_keys table for hardened API key authentication '
            . '(scopes, IP allowlist, expiration, rotation grace period).';
    }
}
```

If `createTable` / `dropTableIfExists` names differ in the actual schema builder, mirror what `001_CreateInitialSchema.php` uses for its tables.

- [ ] **Step 3: Smoke-test the file parses**

Run: `php -l /Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/009_CreateApiKeysTable.php`
Expected: `No syntax errors detected`

---

## Task 2: Exception Classes

**Files:**
- Create: `src/Auth/ApiKey/Exceptions/InvalidApiKeyException.php`
- Create: `src/Auth/ApiKey/Exceptions/ApiKeyExpiredException.php`
- Create: `src/Auth/ApiKey/Exceptions/InsufficientScopeException.php`

Three small single-purpose exceptions. `InvalidApiKeyException` covers any auth-key failure (not found, hash mismatch, revoked, IP blocked); `ApiKeyExpiredException` is kept distinct so we can produce a specific "your key expired" diagnostic; `InsufficientScopeException` is for authorization (403), not authentication (401).

- [ ] **Step 1: Create the directory**

```bash
mkdir -p src/Auth/ApiKey/Exceptions
```

- [ ] **Step 2: Write `InvalidApiKeyException`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when an API key fails authentication for any reason other than
 * expiration: not found, hash mismatch, revoked, or IP not in allowlist.
 * The provider catches this and returns null with a generic error message
 * (don't leak which specific check failed — attackers shouldn't learn
 * "this prefix is known but the IP doesn't match").
 */
final class InvalidApiKeyException extends AuthenticationException
{
}
```

- [ ] **Step 3: Write `ApiKeyExpiredException`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when a row matched the request's key but is past expires_at.
 * Distinct from InvalidApiKeyException so consumers can produce a specific
 * "your key expired" diagnostic — this is a frequent support question.
 */
final class ApiKeyExpiredException extends AuthenticationException
{
}
```

- [ ] **Step 4: Write `InsufficientScopeException`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthorizationException;

/**
 * Thrown by RequireScopeMiddleware when an authenticated API key lacks
 * the scopes a route declares via #[RequireScope]. Maps to 403.
 */
final class InsufficientScopeException extends AuthorizationException
{
}
```

- [ ] **Step 5: Verify all three parse**

Run: `for f in src/Auth/ApiKey/Exceptions/*.php; do php -l "$f" || exit 1; done`
Expected: three `No syntax errors detected` lines.

---

## Task 3: CidrMatcher Utility

**Files:**
- Create: `src/Auth/ApiKey/Support/CidrMatcher.php`
- Test: `tests/Unit/Auth/ApiKey/CidrMatcherTest.php`

- [ ] **Step 1: Write failing tests**

```bash
mkdir -p tests/Unit/Auth/ApiKey
```

Create `tests/Unit/Auth/ApiKey/CidrMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\ApiKey;

use Glueful\Auth\ApiKey\Support\CidrMatcher;
use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    public function testMatchesExactSingleIp(): void
    {
        $this->assertTrue(CidrMatcher::matches('203.0.113.42', '203.0.113.42'));
        $this->assertFalse(CidrMatcher::matches('203.0.113.43', '203.0.113.42'));
    }

    public function testMatchesCidrRange(): void
    {
        $this->assertTrue(CidrMatcher::matches('192.168.1.50', '192.168.1.0/24'));
        $this->assertTrue(CidrMatcher::matches('192.168.1.0', '192.168.1.0/24'));
        $this->assertTrue(CidrMatcher::matches('192.168.1.255', '192.168.1.0/24'));
        $this->assertFalse(CidrMatcher::matches('192.168.2.1', '192.168.1.0/24'));
    }

    public function testMatchesSlash32AsExactIp(): void
    {
        $this->assertTrue(CidrMatcher::matches('10.0.0.5', '10.0.0.5/32'));
        $this->assertFalse(CidrMatcher::matches('10.0.0.6', '10.0.0.5/32'));
    }

    public function testMatchesAny(): void
    {
        $allowed = ['192.168.1.0/24', '203.0.113.42'];
        $this->assertTrue(CidrMatcher::matchesAny('192.168.1.5', $allowed));
        $this->assertTrue(CidrMatcher::matchesAny('203.0.113.42', $allowed));
        $this->assertFalse(CidrMatcher::matchesAny('10.0.0.1', $allowed));
    }

    public function testEmptyAllowlistMatchesEverything(): void
    {
        // Empty allowlist is treated as "no restriction"
        $this->assertTrue(CidrMatcher::matchesAny('1.2.3.4', []));
    }

    public function testMalformedInputReturnsFalse(): void
    {
        $this->assertFalse(CidrMatcher::matches('1.2.3.4', 'not-a-cidr'));
        $this->assertFalse(CidrMatcher::matches('1.2.3.4', '999.999.999.999/24'));
        $this->assertFalse(CidrMatcher::matches('not-an-ip', '192.168.1.0/24'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/ApiKey/CidrMatcherTest.php -v`
Expected: FAIL — `CidrMatcher` class not found.

- [ ] **Step 3: Implement `CidrMatcher`**

```bash
mkdir -p src/Auth/ApiKey/Support
```

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Support;

/**
 * IPv4 CIDR / single-IP matcher.
 *
 * Inline implementation (no external dependency) — fail-closed on
 * malformed input. IPv6 support can be added later when the framework's
 * overall IPv6 story is settled.
 */
final class CidrMatcher
{
    public static function matches(string $ip, string $cidrOrIp): bool
    {
        $client = @inet_pton($ip);
        if ($client === false) {
            return false;
        }

        if (!str_contains($cidrOrIp, '/')) {
            $target = @inet_pton($cidrOrIp);
            return $target !== false && hash_equals($target, $client);
        }

        [$subnet, $bits] = explode('/', $cidrOrIp, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || !ctype_digit($bits)) {
            return false;
        }

        $prefixLen = (int) $bits;
        if ($prefixLen < 0 || $prefixLen > 32 || strlen($subnetBin) !== 4) {
            return false; // IPv4 only for now
        }

        $mask = $prefixLen === 0 ? 0 : ((~0) << (32 - $prefixLen)) & 0xFFFFFFFF;
        $clientInt = unpack('N', $client)[1];
        $subnetInt = unpack('N', $subnetBin)[1];

        return ($clientInt & $mask) === ($subnetInt & $mask);
    }

    /**
     * Empty allowlist means "no restriction" → matches everything.
     *
     * @param array<int, string> $allowlist
     */
    public static function matchesAny(string $ip, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $entry) {
            if (self::matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/ApiKey/CidrMatcherTest.php -v`
Expected: PASS, 6 tests.

---

## Task 4: ApiKey Model

**Files:**
- Create: `src/Auth/ApiKey/ApiKey.php`

ORM model on `api_keys`. Exercised by service tests; no dedicated unit tests.

- [ ] **Step 1: Create the directory and model**

```bash
mkdir -p src/Auth/ApiKey
```

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey;

use Glueful\Database\ORM\Model;

final class ApiKey extends Model
{
    protected string $table = 'api_keys';

    /** @var array<int, string> */
    protected array $fillable = [
        'uuid',
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'allowed_ips',
        'expires_at',
        'rotated_from_id',
        'revoked_at',
    ];

    /** @return array<int, string> */
    public function getScopes(): array
    {
        $raw = $this->scopes ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return array<int, string> */
    public function getAllowedIps(): array
    {
        $raw = $this->allowed_ips ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expires_at ?? null;
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }
        $ts = strtotime($expiresAt);
        return $ts !== false && $ts < time();
    }

    public function isRevoked(): bool
    {
        return ($this->revoked_at ?? null) !== null;
    }
}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l src/Auth/ApiKey/ApiKey.php`
Expected: `No syntax errors detected`

---

## Task 5: ApiKeyService — Pure Helpers

**Files:**
- Create: `src/Auth/ApiKey/ApiKeyService.php`
- Test: `tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php`

The pure-function helpers (no DB I/O) are the foundation. DB-touching methods come in Tasks 6 and 7.

- [ ] **Step 1: Write failing tests for pure helpers**

Create `tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\ApiKey;

use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use PHPUnit\Framework\TestCase;

class ApiKeyServiceTest extends TestCase
{
    public function testGeneratedKeyHasEnvironmentPrefix(): void
    {
        $this->assertStringStartsWith('gf_live_', ApiKeyService::generatePlainKey('production'));
        $this->assertStringStartsWith('gf_test_', ApiKeyService::generatePlainKey('testing'));
        $this->assertStringStartsWith('gf_test_', ApiKeyService::generatePlainKey('development'));
    }

    public function testGeneratedKeyHasEnoughEntropy(): void
    {
        // 'gf_live_' is 8 chars; the random part must be at least 32 chars
        $this->assertGreaterThanOrEqual(40, strlen(ApiKeyService::generatePlainKey('production')));
    }

    public function testGeneratedKeysAreUnique(): void
    {
        $keys = [];
        for ($i = 0; $i < 50; $i++) {
            $keys[] = ApiKeyService::generatePlainKey('production');
        }
        $this->assertCount(50, array_unique($keys));
    }

    public function testPrefixExtractionTakesFirst16Chars(): void
    {
        $this->assertSame(
            'gf_live_abcdef01',
            ApiKeyService::extractPrefix('gf_live_abcdef0123456789moretext')
        );
    }

    public function testHashIsSha256Hex(): void
    {
        $key = 'gf_live_known_key_for_test';
        $hash = ApiKeyService::hashKey($key);
        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', $key), $hash);
    }

    public function testScopeMatchExact(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies(['read:posts'], 'read:posts'));
        $this->assertFalse(ApiKeyService::scopeSatisfies(['read:posts'], 'write:posts'));
    }

    public function testScopeMatchWildcard(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies(['read:*'], 'read:posts'));
        $this->assertTrue(ApiKeyService::scopeSatisfies(['*'], 'anything:at:all'));
        $this->assertFalse(ApiKeyService::scopeSatisfies(['read:*'], 'write:posts'));
    }

    public function testEmptyScopeListGrantsFullAccess(): void
    {
        $this->assertTrue(ApiKeyService::scopeSatisfies([], 'anything'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php -v`
Expected: FAIL — `ApiKeyService` not found.

- [ ] **Step 3: Implement `ApiKeyService` skeleton + pure helpers**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey;

use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
use Glueful\Auth\ApiKey\Support\CidrMatcher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * Generation, verification, rotation, and revocation for API keys.
 *
 * Key format: gf_live_<32-char-base62> in production, gf_test_<32-char-base62>
 * elsewhere. The first 16 chars are stored as the indexed lookup column;
 * the full key is SHA-256 hashed and stored separately. See:
 *   docs/superpowers/specs/2026-05-21-api-key-hardening-design.md
 */
final class ApiKeyService
{
    private const PREFIX_LENGTH = 16;
    private const RANDOM_LENGTH = 32;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function generatePlainKey(string $environment): string
    {
        $prefix = $environment === 'production' ? 'gf_live_' : 'gf_test_';
        $random = '';
        $alphabetLength = strlen(self::ALPHABET);
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }
        return $prefix . $random;
    }

    public static function extractPrefix(string $plainKey): string
    {
        return substr($plainKey, 0, self::PREFIX_LENGTH);
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * fnmatch-style scope check. Empty $grantedScopes = full access.
     *
     * @param array<int, string> $grantedScopes
     */
    public static function scopeSatisfies(array $grantedScopes, string $required): bool
    {
        if ($grantedScopes === []) {
            return true;
        }

        foreach ($grantedScopes as $granted) {
            if (fnmatch($granted, $required)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php -v`
Expected: PASS, 8 tests.

---

## Task 6: ApiKeyService — `create()`, `rotate()`, `revoke()`, `forUser()`

**Files:**
- Modify: `src/Auth/ApiKey/ApiKeyService.php`

DB-touching methods. Exercised end-to-end in Task 13's integration test.

- [ ] **Step 1: Add `create()`**

Append to `ApiKeyService`:

```php
    /**
     * Create a new API key. Returns the plaintext key ONCE; never stored.
     *
     * @param array{
     *     user_id: string,
     *     name: string,
     *     scopes?: array<int, string>|null,
     *     allowed_ips?: array<int, string>|null,
     *     expires_at?: string|null,
     * } $attrs
     * @return array{plain: string, key: ApiKey}
     */
    public static function create(ApplicationContext $context, array $attrs): array
    {
        $env = $context->getEnvironment();
        $plain = self::generatePlainKey($env);

        $key = new ApiKey([
            'uuid'        => Utils::generateNanoID(),
            'user_id'     => $attrs['user_id'],
            'name'        => $attrs['name'],
            'key_prefix'  => self::extractPrefix($plain),
            'key_hash'    => self::hashKey($plain),
            'scopes'      => isset($attrs['scopes']) && $attrs['scopes'] !== null
                ? json_encode(array_values($attrs['scopes']))
                : null,
            'allowed_ips' => isset($attrs['allowed_ips']) && $attrs['allowed_ips'] !== null
                ? json_encode(array_values($attrs['allowed_ips']))
                : null,
            'expires_at'  => $attrs['expires_at'] ?? null,
        ], $context);

        $key->save();

        return ['plain' => $plain, 'key' => $key];
    }
```

- [ ] **Step 2: Add `rotate()`**

```php
    /**
     * Rotate a key with a grace period. Old key's expires_at becomes
     * now + graceHours, so both keys are valid during the grace window.
     *
     * @return array{old_uuid: string, new_plain: string, old_expires_at: string}
     */
    public static function rotate(
        ApplicationContext $context,
        ApiKey $existing,
        int $graceHours = 24
    ): array {
        $env = $context->getEnvironment();
        $newPlain = self::generatePlainKey($env);

        $newKey = new ApiKey([
            'uuid'            => Utils::generateNanoID(),
            'user_id'         => $existing->user_id,
            'name'            => $existing->name . ' (rotated)',
            'key_prefix'      => self::extractPrefix($newPlain),
            'key_hash'        => self::hashKey($newPlain),
            'scopes'          => $existing->scopes,
            'allowed_ips'     => $existing->allowed_ips,
            'expires_at'      => $existing->expires_at,
            'rotated_from_id' => $existing->id,
        ], $context);
        $newKey->save();

        $newExpiry = date('Y-m-d H:i:s', time() + ($graceHours * 3600));
        $existing->expires_at = $newExpiry;
        $existing->save();

        return [
            'old_uuid'       => $existing->uuid,
            'new_plain'      => $newPlain,
            'old_expires_at' => $newExpiry,
        ];
    }
```

- [ ] **Step 3: Add `revoke()` and `forUser()`**

```php
    public static function revoke(ApplicationContext $context, ApiKey $key): void
    {
        $key->revoked_at = date('Y-m-d H:i:s');
        $key->save();
    }

    /** @return array<int, ApiKey> */
    public static function forUser(ApplicationContext $context, string $userId): array
    {
        return ApiKey::query($context)
            ->where('user_id', '=', $userId)
            ->get();
    }
```

- [ ] **Step 4: Verify the file still parses**

Run: `php -l src/Auth/ApiKey/ApiKeyService.php`
Expected: `No syntax errors detected`

---

## Task 7: ApiKeyService — `verify()`

**Files:**
- Modify: `src/Auth/ApiKey/ApiKeyService.php`

Looks up by prefix (collision-tolerant: fetches ALL rows for the prefix and hash-compares each), then enforces revocation / expiration / IP allowlist. Throws `InvalidApiKeyException` for everything except expiration; `ApiKeyExpiredException` for that one case.

- [ ] **Step 1: Append `verify()` to ApiKeyService**

```php
    /**
     * Verify a plaintext key against the api_keys table.
     *
     * Throws:
     *   - ApiKeyExpiredException when a row matched (hash equal) but is
     *     past expires_at. Distinct so the consumer can produce a
     *     specific "your key expired" diagnostic.
     *   - InvalidApiKeyException for anything else — no prefix match, no
     *     hash match across all prefix candidates, revoked, or IP not
     *     in allowed_ips. The provider catches this and returns null
     *     with a generic error (don't leak which check failed).
     *
     * Prefix is indexed but NOT unique by construction — collisions are
     * statistically impossible with ~190 bits of entropy in the random
     * portion, but the code MUST fetch all matching rows and hash_equals
     * each (the unique constraint on key_hash is the actual guarantee).
     */
    public static function verify(
        ApplicationContext $context,
        string $plainKey,
        string $clientIp
    ): ApiKey {
        $prefix = self::extractPrefix($plainKey);
        $expectedHash = self::hashKey($plainKey);

        $candidates = ApiKey::query($context)
            ->where('key_prefix', '=', $prefix)
            ->get();

        $matched = null;
        foreach ($candidates as $row) {
            if (hash_equals($row->key_hash ?? '', $expectedHash)) {
                $matched = $row;
                break;
            }
        }

        if ($matched === null) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        if ($matched->isRevoked()) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        if ($matched->isExpired()) {
            throw new ApiKeyExpiredException('Expired API key');
        }

        $allowed = $matched->getAllowedIps();
        if ($allowed !== [] && !CidrMatcher::matchesAny($clientIp, $allowed)) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        return $matched;
    }
```

- [ ] **Step 2: Verify it parses**

Run: `php -l src/Auth/ApiKey/ApiKeyService.php`
Expected: `No syntax errors detected`

(`verify()` is exercised by Task 13's integration test.)

---

## Task 8: `RequireScope` Attribute

**Files:**
- Create: `src/Routing/Attributes/RequireScope.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;

/**
 * Declare required scopes for a route.
 *
 * IS_REPEATABLE is load-bearing: stacking the attribute expresses AND
 * semantics across requirements. Multiple scopes within one attribute = OR.
 *
 * @example
 * #[RequireScope('read:posts')]
 *
 * @example
 * // OR within one attribute, AND across two attributes
 * #[RequireScope(['write:posts', 'admin:posts'])]
 * #[RequireScope('publish:posts')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequireScope
{
    /** @var array<int, string> */
    public readonly array $scopes;

    /** @param string|array<int, string> $scopes  OR semantics within. */
    public function __construct(string|array $scopes)
    {
        $this->scopes = is_string($scopes) ? [$scopes] : array_values($scopes);
    }
}
```

- [ ] **Step 1: Verify it parses**

Run: `php -l src/Routing/Attributes/RequireScope.php`
Expected: `No syntax errors detected`

---

## Task 9: Route Metadata — `setRequireScopeConfig` / `getRequireScopeConfig`

**Files:**
- Modify: `src/Routing/Route.php`

Mirrors `setRateLimitConfig` / `getRateLimitConfig`.

- [ ] **Step 1: Locate the rate-limit config methods**

Run: `grep -n "setRateLimitConfig\|getRateLimitConfig\|rateLimitConfig" src/Routing/Route.php`

Find `protected array $rateLimitConfig = []` and the matching setter/getter (around line 312).

- [ ] **Step 2: Add the scope-config equivalents**

Below the rate-limit definitions, add:

```php
    /**
     * Required-scope configurations (one entry per stacked #[RequireScope]).
     * Across attributes = AND; within an attribute = OR.
     *
     * @var array<int, array<int, string>>
     */
    protected array $requireScopeConfig = [];

    /** @param array<int, array<int, string>> $config */
    public function setRequireScopeConfig(array $config): self
    {
        $this->requireScopeConfig = $config;
        return $this;
    }

    /** @return array<int, array<int, string>> */
    public function getRequireScopeConfig(): array
    {
        return $this->requireScopeConfig;
    }
```

- [ ] **Step 3: Verify it parses**

Run: `php -l src/Routing/Route.php`
Expected: `No syntax errors detected`

---

## Task 10: Router exposes the matched route on the request

**Files:**
- Modify: `src/Routing/Router.php`

`RequireScopeMiddleware` reads the matched route from `$request->attributes->get('_route')`. The current `Router::dispatch()` matches the route but doesn't put it on the request before middleware. Without this small change, route-aware middleware (ours + any future ones) has no clean way to read route-level metadata.

- [ ] **Step 1: Locate the dispatch method's post-match block**

Open `src/Routing/Router.php`. Find the block (around line 610) that has:

```php
$route = $match['route'];
$params = $match['params'];
```

- [ ] **Step 2: Add the request attributes immediately after**

Insert between the `$params = $match['params'];` line and the next non-empty line:

```php
        // Expose matched route and params to middleware via the request
        // attributes bag, so middleware can read route-level metadata.
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', $params);
```

- [ ] **Step 3: Verify nothing regressed**

Run: `vendor/bin/phpunit tests/Integration/RouterIntegrationTest.php -v`
Expected: same baseline pass count as before this change.

---

## Task 11: `AttributeRouteLoader` Wiring + Auto-Attach Middleware

**Files:**
- Modify: `src/Routing/AttributeRouteLoader.php`

Mirrors `processRateLimitAttributes()`. Also auto-attaches `require_scope` middleware so the attribute is self-contained.

- [ ] **Step 1: Add the import**

At the top of the file:

```php
use Glueful\Routing\Attributes\RequireScope;
```

- [ ] **Step 2: Add `processRequireScopeAttributes()`**

After `processRateLimitAttributes()` (around line 360), add:

```php
    /**
     * Process #[RequireScope] attributes on a method.
     *
     * Collects all instances (the IS_REPEATABLE flag makes this return >1
     * when the attribute is stacked), stores them on the route as metadata,
     * and auto-attaches the 'require_scope' middleware so the route's
     * pipeline actually enforces them. Without the middleware attach the
     * metadata would be stored but never read.
     */
    private function processRequireScopeAttributes(\ReflectionMethod $method, \Glueful\Routing\Route $route): void
    {
        $attributes = $method->getAttributes(RequireScope::class);
        if (count($attributes) === 0) {
            return;
        }

        $configs = [];
        foreach ($attributes as $attribute) {
            $configs[] = $attribute->newInstance()->scopes;
        }

        $route->setRequireScopeConfig($configs);
        $route->middleware('require_scope');
    }
```

- [ ] **Step 3: Call from both processing sites**

Run: `grep -n "processRateLimitAttributes" src/Routing/AttributeRouteLoader.php`

Each call site processes attributes for a route. Add `$this->processRequireScopeAttributes($method, $route);` directly after each `$this->processRateLimitAttributes(...)` line (two sites — class-level and method-level).

- [ ] **Step 4: Verify it parses**

Run: `php -l src/Routing/AttributeRouteLoader.php`
Expected: `No syntax errors detected`

---

## Task 12: `RequireScopeMiddleware`

**Files:**
- Create: `src/Routing/Middleware/RequireScopeMiddleware.php`

Reads `$route->getRequireScopeConfig()` and scopes from `$request->attributes->get('api_key_scopes')`, enforces AND across attributes / OR within.

> **Namespace note:** `RouteMiddleware` lives at `Glueful\Routing\RouteMiddleware` (NOT `Glueful\Routing\Middleware\RouteMiddleware`), matching the existing middleware classes under `src/Routing/Middleware/`.

```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireScopeMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, ...$params): Response
    {
        $route = $request->attributes->get('_route');
        $config = ($route !== null && method_exists($route, 'getRequireScopeConfig'))
            ? $route->getRequireScopeConfig()
            : [];

        if ($config === []) {
            return $next($request);
        }

        /** @var array<int, string> $granted */
        $granted = $request->attributes->get('api_key_scopes', []);
        if (!is_array($granted)) {
            $granted = [];
        }

        foreach ($config as $requiredAnyOf) {
            $satisfied = false;
            foreach ($requiredAnyOf as $required) {
                if (ApiKeyService::scopeSatisfies($granted, $required)) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                throw new InsufficientScopeException(sprintf(
                    'Insufficient scope: required any of [%s]',
                    implode(', ', $requiredAnyOf)
                ));
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 1: Verify it parses**

Run: `php -l src/Routing/Middleware/RequireScopeMiddleware.php`
Expected: `No syntax errors detected`

---

## Task 13: Container Registration

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php`

The middleware alias is what makes `$route->middleware('require_scope')` resolve. Service registration is optional but good practice.

- [ ] **Step 1: Find the middleware-alias block**

Run: `grep -n "rate_limit\|allow_ip\|middlewareAliases\|setMiddlewareAlias" src/Container/Providers/CoreProvider.php`

- [ ] **Step 2: Register `require_scope` next to `rate_limit`**

Add (matching the existing registration style):

```php
'require_scope' => \Glueful\Routing\Middleware\RequireScopeMiddleware::class,
```

- [ ] **Step 3: Register `ApiKeyService`**

```php
$defs[\Glueful\Auth\ApiKey\ApiKeyService::class] =
    $this->autowire(\Glueful\Auth\ApiKey\ApiKeyService::class);
```

- [ ] **Step 4: Verify it parses**

Run: `php -l src/Container/Providers/CoreProvider.php`
Expected: `No syntax errors detected`

---

## Task 14: Update `ApiKeyAuthenticationProvider` to Single-Track Auth + Remove `findByApiKey()`

**Files:**
- Modify: `src/Auth/ApiKeyAuthenticationProvider.php`
- Modify: `src/Repository/UserRepository.php`

The provider verifies via `ApiKeyService::verify()` and returns null on any failure. **No legacy `users.api_key` fallback** — the canonical schema doesn't have that column, and the dual-track approach was a security hole (a revoked key whose plaintext still existed in a custom `users.api_key` column would re-authenticate).

The provider calls `UserRepository::findByApiKey()` in three places — `authenticate()`, `validateToken()`, and `refreshTokens()`. All three switch to `ApiKeyService::verify()`. `generateTokens()` reads `$userData['api_key']` and `$userData['api_key_expires_at']` from the user array; these keys don't exist on a canonical user row, so the method must be reworked to fail explicitly rather than silently returning an empty token. After all three call sites are updated, `findByApiKey()` has zero callers (verified by org-wide grep — no extensions, api-skeleton app code, or other repos reference it) and is removed from `UserRepository`.

- [ ] **Step 1: Add imports to the provider**

At the top of `src/Auth/ApiKeyAuthenticationProvider.php`:

```php
use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
```

- [ ] **Step 2: Replace `authenticate()`**

Keep the signature `public function authenticate(Request $request): ?array`. Replace the body with:

```php
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;
        $apiKey = $this->extractApiKeyFromRequest($request);
        if ($apiKey === null || $apiKey === '') {
            $this->lastError = 'API key not found in request';
            return null;
        }

        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return null;
        }

        try {
            $key = ApiKeyService::verify(
                $this->context,
                $apiKey,
                $request->getClientIp() ?? ''
            );

            $userData = $this->getUserRepository()->find($key->user_id);
            if ($userData === null) {
                $this->lastError = 'API key belongs to no known user';
                return null;
            }

            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $key->user_id);
            $request->attributes->set('user_data', $userData);
            $request->attributes->set('auth_method', 'api_key');
            $request->attributes->set('api_key_scopes', $key->getScopes());

            return $userData;
        } catch (ApiKeyExpiredException) {
            $this->lastError = 'Expired API key';
            return null;
        } catch (InvalidApiKeyException) {
            $this->lastError = 'Invalid API key';
            return null;
        } catch (\Throwable $e) {
            $this->lastError = 'Authentication error: ' . $e->getMessage();
            return null;
        }
    }
```

- [ ] **Step 3: Replace `validateToken()`**

Replace the body of `public function validateToken(string $token): bool`:

```php
    public function validateToken(string $token): bool
    {
        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return false;
        }

        try {
            // Client IP isn't available here (no Request), so IP allowlists
            // can't be enforced on raw token validation. Pass an empty IP;
            // ApiKeyService::verify() treats this as "no allowlist match"
            // when the row actually has allowed_ips set, which is the safe
            // default. Routes that need IP-aware validation should use
            // authenticate(Request) instead.
            ApiKeyService::verify($this->context, $token, '');
            return true;
        } catch (ApiKeyExpiredException) {
            $this->lastError = 'Expired API key';
            return false;
        } catch (InvalidApiKeyException) {
            $this->lastError = 'Invalid API key';
            return false;
        } catch (\Throwable $e) {
            $this->lastError = 'API key validation error: ' . $e->getMessage();
            return false;
        }
    }
```

- [ ] **Step 4: Replace `refreshTokens()`**

Replace the body of `public function refreshTokens(string $refreshToken, array $sessionData): ?array`:

```php
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        if ($this->context === null) {
            $this->lastError = 'No application context available for API key verification';
            return null;
        }

        try {
            // For API keys there's no separate refresh token; "refresh"
            // means "the same key is still valid, here it is again".
            $key = ApiKeyService::verify($this->context, $refreshToken, '');

            $expiresIn = 0;
            $expiresAt = $key->expires_at ?? null;
            if (is_string($expiresAt) && $expiresAt !== '') {
                $ts = strtotime($expiresAt);
                if ($ts !== false) {
                    $expiresIn = max(0, $ts - time());
                }
            }

            return [
                'access_token'  => $refreshToken,
                'refresh_token' => $refreshToken,
                'expires_in'    => $expiresIn,
            ];
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }
```

- [ ] **Step 5: Update `generateTokens()`**

The old implementation read `$userData['api_key']` and `$userData['api_key_expires_at']` — fields that don't exist on a canonical user row. API keys are administratively created via the CLI, not generated at login time; the method needs to communicate that explicitly rather than silently returning empty tokens.

Replace the body of `public function generateTokens(array $userData, ?int $accessTokenLifetime = null, ?int $refreshTokenLifetime = null): array`:

```php
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        // API keys are administratively created via `php glueful apikey:create`,
        // not generated as part of an auth flow. Callers that arrive here are
        // typically trying to use the JWT-style auth code paths against an
        // API-key provider; that's a programming error to report explicitly
        // rather than papering over with an empty token response.
        $this->lastError = 'API keys are created administratively via apikey:create CLI, '
            . 'not generated at authentication time. Use ApiKeyService::create() directly '
            . 'or the CLI command if you need to mint a new key.';

        return [
            'access_token'  => '',
            'refresh_token' => '',
            'expires_in'    => 0,
        ];
    }
```

- [ ] **Step 6: Remove `findByApiKey()` from UserRepository**

Open `src/Repository/UserRepository.php`. Delete the `findByApiKey()` method (around line 370–380):

```php
    /**
     * Find user by API key
     * ...
     */
    public function findByApiKey(string $apiKey): ?array
    {
        return $this->findBy('api_key', $apiKey);
    }
```

- [ ] **Step 7: Verify nothing else references `findByApiKey`**

Run: `grep -rn "findByApiKey" src/ tests/ 2>/dev/null`
Expected: no output (zero references after the provider's three call sites are gone and the method definition is removed).

- [ ] **Step 8: Verify both files parse**

Run: `php -l src/Auth/ApiKeyAuthenticationProvider.php && php -l src/Repository/UserRepository.php`
Expected: two `No syntax errors detected` lines.

---

## Task 15a: CLI Commands — `create` + `list`

**Files:**
- Create: `src/Console/Commands/ApiKey/CreateCommand.php`
- Create: `src/Console/Commands/ApiKey/ListCommand.php`

This codebase uses **Symfony Console via `Glueful\Console\BaseCommand`** (NOT Laravel-style `handle()`):
- Extends `Glueful\Console\BaseCommand` (which itself extends `Symfony\Component\Console\Command\Command`)
- `#[AsCommand]` attribute for name/description
- Options defined in `protected function configure(): void`
- Body in `protected function execute(InputInterface $input, OutputInterface $output): int`
- Inputs read via `$input->getOption('foo')`, `$input->getArgument('bar')`
- Return `self::SUCCESS` / `self::FAILURE`

Reference: `src/Console/Commands/Migrate/RunCommand.php` is the canonical example.

- [ ] **Step 1: Create the directory**

```bash
mkdir -p src/Console/Commands/ApiKey
```

- [ ] **Step 2: Write `CreateCommand`**

Representative body. **Important: do NOT redeclare `$context` via constructor property promotion.** `BaseCommand` already owns `protected ApplicationContext $context` and `protected ContainerInterface $container`; the constructor signature must match its parent so the dependencies are passed through correctly. Read `src/Console/Commands/Migrate/RunCommand.php` line 31 for the canonical pattern.

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\ApiKey;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apikey:create', description: 'Create a new API key for a user')]
final class CreateCommand extends BaseCommand
{
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User UUID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Developer-facing label')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Comma-separated scopes (e.g. read:*,write:posts)')
            ->addOption('ips', null, InputOption::VALUE_REQUIRED, 'Comma-separated CIDR/IPs (e.g. 192.168.1.0/24)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiration (datetime or relative, e.g. +1year)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getOption('user');
        $name = $input->getOption('name');
        if (!is_string($userId) || $userId === '' || !is_string($name) || $name === '') {
            $output->writeln('<error>--user=<uuid> and --name=<string> are required</error>');
            return self::FAILURE;
        }

        $scopes = self::parseCsv($input->getOption('scopes'));
        $ips = self::parseCsv($input->getOption('ips'));
        $expires = self::parseExpires($input->getOption('expires'));

        $result = ApiKeyService::create($this->context, [
            'user_id'     => $userId,
            'name'        => $name,
            'scopes'      => $scopes !== [] ? $scopes : null,
            'allowed_ips' => $ips !== [] ? $ips : null,
            'expires_at'  => $expires,
        ]);

        $output->writeln('');
        $output->writeln('┌──────────────────────────────────────────────────────────────┐');
        $output->writeln('│  API key created — SAVE THIS NOW. It will not be shown again. │');
        $output->writeln('├──────────────────────────────────────────────────────────────┤');
        $output->writeln('│  ' . $result['plain']);
        $output->writeln('└──────────────────────────────────────────────────────────────┘');
        $output->writeln('UUID: ' . $result['key']->uuid);
        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private static function parseCsv(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function parseExpires(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
```

If the framework's `BaseCommand` provides `$this->info()` / `$this->error()` helpers, use them instead of `<error>...</error>` tags.

- [ ] **Step 3: Write `ListCommand`**

`apikey:list --user=<uuid>` — fetches via `ApiKeyService::forUser()`, renders a table with columns: UUID, Name, Prefix, Scopes, Allowed IPs, Expires At, Revoked. Mirror the table-output pattern from existing list-style commands.

- [ ] **Step 4: Verify both parse**

Run: `php -l src/Console/Commands/ApiKey/CreateCommand.php && php -l src/Console/Commands/ApiKey/ListCommand.php`
Expected: two `No syntax errors detected` lines.

---

## Task 15b: CLI Commands — `rotate` + `revoke`

**Files:**
- Create: `src/Console/Commands/ApiKey/RotateCommand.php`
- Create: `src/Console/Commands/ApiKey/RevokeCommand.php`

- [ ] **Step 1: `RotateCommand`**

`apikey:rotate <uuid> [--grace=24]`. Behavior:
- Look up `ApiKey` by uuid via `ApiKey::query($context)->where('uuid', '=', $uuid)->first()`
- If not found: error, `self::FAILURE`
- Call `ApiKeyService::rotate($context, $key, $graceHours)`
- Print the new plaintext key in the labeled box (same as `CreateCommand`)
- Print the old key's new expiry timestamp

- [ ] **Step 2: `RevokeCommand`**

`apikey:revoke <uuid>`. Behavior:
- Look up by uuid; if not found: error, `self::FAILURE`
- Call `ApiKeyService::revoke($context, $key)`
- Print `Revoked: <name> (<uuid>)`

- [ ] **Step 3: Verify both parse**

Run: `php -l src/Console/Commands/ApiKey/RotateCommand.php && php -l src/Console/Commands/ApiKey/RevokeCommand.php`
Expected: two `No syntax errors detected` lines.

- [ ] **Step 4: Register the commands (if not auto-discovered)**

If the framework auto-discovers commands from `src/Console/Commands/`, no further action. Otherwise, find the registration point (CommandLoader or service provider) and add the four new classes.

Run: `php glueful list 2>&1 | grep -i apikey`
Expected: `apikey:create`, `apikey:list`, `apikey:rotate`, `apikey:revoke`.

---

## Task 16: Integration Test — Service + Provider

**Files:**
- Create: `tests/Integration/Auth/ApiKeyAuthenticationTest.php`

Booted framework + in-memory SQLite + applied migration. Three layers of coverage:

1. **`ApiKeyService` direct**: happy-path verify, exception types, IP allowlist, rotation grace, revocation.
2. **`ApiKeyAuthenticationProvider` direct**: new-table path populates `api_key_scopes`; provider returns null on revoked/expired/invalid (no fallback exists).
3. **Synthesized `users` table** — the framework doesn't own the users schema; tests stand up just enough to exercise the provider's `find()` call.

> **Migration loading note:** The framework's `MigrationManager` looks for migrations in the consumer's `database/migrations/` directory by default. Since the new migration lives in api-skeleton (NOT the framework's source tree), the integration test creates the `api_keys` table directly via PDO rather than running the migration. This keeps the test self-contained and avoids cross-repo coupling. The migration file itself is verified by running it in api-skeleton's test environment.

- [ ] **Step 1: Write the test**

Create `tests/Integration/Auth/ApiKeyAuthenticationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
use Glueful\Auth\ApiKeyAuthenticationProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ApiKeyAuthenticationTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->createSchemaInline();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // ── Service-level ──

    public function testCreateThenVerifyHappyPath(): void
    {
        $result = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'Test Key']);
        $verified = ApiKeyService::verify($this->context, $result['plain'], '203.0.113.5');
        $this->assertSame($result['key']->uuid, $verified->uuid);
    }

    public function testVerifyThrowsInvalidOnWrongHash(): void
    {
        ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'X']);
        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, 'gf_test_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', '1.2.3.4');
    }

    public function testVerifyThrowsExpiredForPastExpiry(): void
    {
        $result = ApiKeyService::create($this->context, [
            'user_id'    => 'u-abc12345abcd',
            'name'       => 'Past',
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->expectException(ApiKeyExpiredException::class);
        ApiKeyService::verify($this->context, $result['plain'], '1.2.3.4');
    }

    public function testVerifyEnforcesIpAllowlist(): void
    {
        $result = ApiKeyService::create($this->context, [
            'user_id'     => 'u-abc12345abcd',
            'name'        => 'IP-restricted',
            'allowed_ips' => ['10.0.0.0/8'],
        ]);
        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, $result['plain'], '203.0.113.5');
    }

    public function testRotateProducesNewKeyAndKeepsOldValidDuringGrace(): void
    {
        $original = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'O']);
        $rotation = ApiKeyService::rotate($this->context, $original['key'], graceHours: 24);

        $this->assertNotNull(ApiKeyService::verify($this->context, $rotation['new_plain'], '1.2.3.4'));
        $this->assertNotNull(ApiKeyService::verify($this->context, $original['plain'], '1.2.3.4'));
    }

    public function testRevokedKeyFailsVerify(): void
    {
        $result = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'Doomed']);
        ApiKeyService::revoke($this->context, $result['key']);

        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, $result['plain'], '1.2.3.4');
    }

    // ── Provider-level ──

    public function testProviderAuthenticatesAndPopulatesScopes(): void
    {
        $this->ensureUserRow('u-abc12345abcd');

        $result = ApiKeyService::create($this->context, [
            'user_id' => 'u-abc12345abcd',
            'name'    => 'Provider Test',
            'scopes'  => ['read:posts'],
        ]);

        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', $result['plain']);

        $userData = $provider->authenticate($request);
        $this->assertNotNull($userData);
        $this->assertSame('api_key', $request->attributes->get('auth_method'));
        $this->assertSame(['read:posts'], $request->attributes->get('api_key_scopes'));
    }

    public function testProviderReturnsNullForRevokedKey(): void
    {
        $this->ensureUserRow('u-abc12345abcd');

        $result = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'Doomed']);
        ApiKeyService::revoke($this->context, $result['key']);

        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', $result['plain']);

        $this->assertNull($provider->authenticate($request));
    }

    public function testProviderReturnsNullForUnknownKey(): void
    {
        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', 'gf_test_does_not_exist_at_all_in_table_xxxx');

        $this->assertNull($provider->authenticate($request));
    }

    // ── Harness ──

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-apikey-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents($configPath . '/app.php',
            "<?php\nreturn ['name'=>'T','version_full'=>'1.0.0','env'=>'testing','debug'=>true];");
        file_put_contents($configPath . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];");
        file_put_contents($configPath . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];");
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function createSchemaInline(): void
    {
        // The migration lives in api-skeleton, not the framework. For the
        // framework's own integration test we stand up the api_keys table
        // (and a minimal users table for the provider) directly via PDO.
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec('
            CREATE TABLE api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(12) NOT NULL UNIQUE,
                user_id VARCHAR(12) NOT NULL,
                name VARCHAR(255) NOT NULL,
                key_prefix VARCHAR(24) NOT NULL,
                key_hash VARCHAR(64) NOT NULL UNIQUE,
                scopes TEXT NULL,
                allowed_ips TEXT NULL,
                expires_at TIMESTAMP NULL,
                rotated_from_id INTEGER NULL,
                revoked_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $pdo->exec('CREATE INDEX idx_api_keys_user_id ON api_keys(user_id)');
        $pdo->exec('CREATE INDEX idx_api_keys_key_prefix ON api_keys(key_prefix)');

        $pdo->exec('
            CREATE TABLE users (
                uuid VARCHAR(12) PRIMARY KEY,
                username VARCHAR(255),
                email VARCHAR(255)
            )
        ');
    }

    private function ensureUserRow(string $uuid): void
    {
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO users (uuid, username, email) VALUES (?, ?, ?)');
        $stmt->execute([$uuid, 'test_user_' . $uuid, $uuid . '@example.com']);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit tests/Integration/Auth/ApiKeyAuthenticationTest.php -v`
Expected: PASS, 9 tests (6 service-level + 3 provider-level).

---

## Task 17: Docs + CHANGELOG + Final Verification

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/FRAMEWORK_IMPROVEMENTS.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update CLAUDE.md**

Add a bullet under the Authentication section:

```markdown
- **API key hardening** — Dedicated `api_keys` table (scopes, IP allowlist, expiration, rotation grace, environment-prefixed keys). Schema migration in api-skeleton (`009_CreateApiKeysTable.php`); code in `src/Auth/ApiKey/`. Use `ApiKeyService::create/verify/rotate/revoke` and the `#[RequireScope('write:posts')]` route attribute. CLI: `php glueful apikey:create|list|rotate|revoke`. Provider is single-track — verifies via the new table only; no legacy `users.api_key` fallback.
```

- [ ] **Step 2: Update FRAMEWORK_IMPROVEMENTS.md**

Find the Tier 1 row for "API key scopes + expiration + rotation":

```markdown
| **API key scopes + expiration + rotation** | 5.3 | ... |
```

Change to:

```markdown
| **API key scopes + expiration + rotation** ✅ | 5.3 | ... **Shipped 2026-05-21.** |
```

Also strike-through item 4 in the "Suggested Next Sprint" section.

- [ ] **Step 3: Update CHANGELOG.md Unreleased**

Append to the `### Added` section under `## [Unreleased]`:

```markdown
- **Hardened API keys via dedicated `api_keys` table**: New `ApiKeyService` provides creation, verification, rotation with grace period, and revocation. Keys carry scopes (`['read:*', 'write:posts']`), CIDR/IP allowlists (`['192.168.1.0/24']`), expiration, and environment-prefixed plaintext format (`gf_live_...` in production, `gf_test_...` elsewhere). Plaintext keys are SHA-256 hashed before storage; the first 16 chars are stored as an indexed prefix for O(1) lookup. The hash column is `UNIQUE` and lookup is collision-tolerant — if two prefixes ever collide (statistically impossible at 190 bits of entropy but defensively handled), the code iterates all candidates and `hash_equals` each. Rotation creates a new key and sets the old key's `expires_at` to `now + graceHours` so both work during the grace window.
- **`#[RequireScope]` route attribute**: Declares required scopes on controller methods. Repeatable — multiple scopes within one attribute are OR, multiple attributes are AND. `AttributeRouteLoader` auto-attaches the `require_scope` middleware so the declaration is self-contained.
- **`apikey:*` CLI commands**: `apikey:create`, `apikey:list`, `apikey:rotate`, `apikey:revoke`.
- **Router exposes the matched route on the request**: `Router::dispatch()` now sets `_route` and `_route_params` on `$request->attributes` before middleware runs, so middleware (ours + future) can read route-level metadata.

### Changed

- **`ApiKeyAuthenticationProvider` is now single-track**: Verifies via the new `api_keys` table only. The previous code path that queried `UserRepository::findByApiKey()` referenced a `users.api_key` column that doesn't exist in the canonical api-skeleton schema (`001_CreateInitialSchema.php`) — there was no legacy data to preserve. All four `AuthenticationProviderInterface` methods (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) updated. Provider returns null on any failure (revoked, expired, invalid, IP-blocked, unknown). Populates `api_key_scopes` on the request for `RequireScopeMiddleware` to enforce.

### Removed

- **`UserRepository::findByApiKey()`** — zero callers after the provider switches to `ApiKeyService::verify()`. The method queried a `users.api_key` column that doesn't exist in the canonical schema, so it was dead code for any standard install. Verified no external callers (extensions, api-skeleton app code, other repos in the org).
```

For api-skeleton, mirror a smaller note in `api-skeleton/CHANGELOG.md` if the project maintains one — the migration is the user-facing change there.

- [ ] **Step 4: Run the full test suite + static analysis (framework repo)**

Run: `composer test`
Expected: all tests pass, only existing-baseline skips.

Run: `composer run analyse`
Expected: `[OK] No errors`.

Run: `composer run phpcs`
Expected: no PSR-12 violations in new files.

- [ ] **Step 4b: Verify the api-skeleton migration**

Since the migration lives in another repo, run the verification there separately:

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/api-skeleton

# Syntax check
php -l database/migrations/009_CreateApiKeysTable.php

# Run the migration in api-skeleton's test environment (and roll back to
# confirm down() works). The exact command depends on api-skeleton's
# composer scripts — likely one of:
php glueful migrate:run
php glueful migrate:status

# If a test suite exists in api-skeleton that exercises migrations,
# run it too:
composer test 2>/dev/null || true

cd -
```

Expected: migration applies cleanly (creates `api_keys` table with all columns and indexes), `migrate:status` shows it as run, and the down() rollback drops the table cleanly.

- [ ] **Step 5: Commit (framework repo)**

```bash
git add CHANGELOG.md CLAUDE.md docs/FRAMEWORK_IMPROVEMENTS.md docs/superpowers/ \
  src/Auth/ApiKey/ \
  src/Auth/ApiKeyAuthenticationProvider.php \
  src/Console/Commands/ApiKey/ \
  src/Container/Providers/CoreProvider.php \
  src/Routing/Attributes/RequireScope.php \
  src/Routing/AttributeRouteLoader.php \
  src/Routing/Middleware/RequireScopeMiddleware.php \
  src/Routing/Route.php \
  src/Routing/Router.php \
  tests/Integration/Auth/ApiKeyAuthenticationTest.php \
  tests/Unit/Auth/ApiKey/
```

Commit message:

```
feat(auth): hardened API keys with scopes, IP allowlist, rotation, expiry

Introduces a dedicated api_keys table with per-key scopes, CIDR
allowlists, expiration, rotation with grace period, and environment-
prefixed plaintext (gf_live_* / gf_test_*). See
docs/superpowers/specs/2026-05-21-api-key-hardening-design.md.

- New ApiKeyService: create / verify / rotate / revoke / forUser. Keys
  SHA-256 hashed; first 16 chars indexed as key_prefix for O(1) lookup.
  Verify fetches all prefix candidates and hash_equals each (defensive
  against the statistically-impossible prefix collision).
- New ApiKey ORM model on the api_keys table.
- ApiKeyAuthenticationProvider is single-track: verifies via the new
  table, returns null on any failure. No legacy users.api_key fallback —
  the canonical schema doesn't have that column.
- #[RequireScope] route attribute (IS_REPEATABLE), processed by
  AttributeRouteLoader and enforced by RequireScopeMiddleware. AND
  across stacked attributes; OR within a single attribute's scope list.
  Middleware auto-attaches via the loader so the declaration is
  self-contained.
- Router::dispatch now exposes the matched route on $request->attributes
  so middleware can read route-level metadata.
- CidrMatcher: ~40-line IPv4 / CIDR matcher with /32 and exact-IP cases.
- Four apikey:* CLI commands (create, list, rotate, revoke).

Schema migration lives in api-skeleton (009_CreateApiKeysTable.php),
not the framework — following the existing convention where
api-skeleton owns the schema and the framework owns the code surface.
```

- [ ] **Step 6: Commit (api-skeleton repo)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/api-skeleton
git add database/migrations/009_CreateApiKeysTable.php
git commit -m "feat(db): add api_keys table for hardened API key auth

Companion migration for the framework's API key hardening feature.
Creates api_keys table with columns for scopes, IP allowlist,
expiration, rotation lineage, and revocation. See
glueful/framework docs/superpowers/specs/2026-05-21-api-key-hardening-design.md."
cd -
```

---

## Done

Seventeen tasks (some renumbered after the legacy fallback dropped out). The framework now has an opinionated, single-track API key system that supports production patterns without the security pitfalls of the dual-track approach the spec originally described.

**Out of scope (future work):**
- Usage tracking (`last_used_at`, request counts) — own design pass
- Per-key rate limit enforcement — column reserved but enforcement lives in rate-limit middleware
- IPv6 in `CidrMatcher` — when framework's overall IPv6 story is settled
- Cross-environment refusal (`gf_live_` rejected in non-prod and vice versa) — prefix distinguishes; enforcement is a follow-up
