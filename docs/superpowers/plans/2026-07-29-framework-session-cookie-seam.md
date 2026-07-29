# Framework Session Cookie Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the framework an opt-in HttpOnly browser-session transport — cookie-based authentication, refresh, and logout — alongside the existing bearer path, without changing bearer behavior.

**Architecture:** A transport-neutral `LoginOrchestrator` returns a closed `LoginOutcome` (`authenticated(AuthenticatedSession)` or `twoFactorRequired(TwoFactorChallenge)`). `SessionCookieIssuer` accepts *only* an `AuthenticatedSession`, so issuing cookies for an unfinished 2FA login is impossible to express. `SessionCookieMiddleware` (alias `session_cookie`) adapts a cookie into the `Authorization` header that the existing `auth` middleware already understands, marking transport provenance so callers can apply CSRF rules to cookie requests only. Two new endpoints — `POST /auth/session/refresh` and `POST /auth/session/logout` — own rotation and revocation.

**Tech Stack:** PHP 8.3+, Symfony HttpFoundation, PHPUnit 10, PHPStan, PHP_CodeSniffer (PSR-12).

## Global Constraints

- **Framework-generic only.** No references to any consuming application, its packs, storefronts, commerce, or tenancy — in code, comments, tests, config, or docs. This ships to every Glueful app.
- **Bearer authentication stays byte-compatible.** Existing `Authorization: Bearer` requests, `POST /auth/login` JSON responses, and `POST /auth/refresh-token` must behave exactly as before. Any behavioral diff is a bug, not a trade-off.
- **Cookie attributes, fixed:** `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/` for access, no domain by default, host-configurable names.
- **The refresh cookie is path-scoped** to the session route prefix. The middleware therefore authenticates **access cookies only** and never refreshes transparently.
- **"Off by default" means absent, not inert.** With `SESSION_COOKIE_ENABLED=false` the session routes must not register and the controller must fail closed before reading any credential — default cookie names would otherwise leave a disabled install's endpoints fully operational.
- **Password login only in the orchestrator.** The token / API-key branch stays in `AuthController` untouched: those providers return an identity array with no tokens.
- **No tokens in cookie-path response bodies.** Access and refresh values reach `SessionCookieIssuer` and nothing else — never a response body, log line, template, or JS-readable store.
- **`SessionCreatedEvent` / `SessionCachedEvent`** fire exactly once for both transports (they live inside session issuance). **`LoginResponseBuildingEvent` / `LoginResponseBuiltEvent`** stay JSON-only — they shape a token response that cookie logins never return.
- **The 2FA gate is un-bypassable.** No code path may reach session issuance for a 2FA-enabled account without passing `TwoFactorServiceInterface::beginLogin()`.
- **Commit cadence:** commit at Tasks 3, 5, and 9 only — not after every task. No AI/assistant attribution in commit messages or any file.
- **Quality gates per commit:** `composer test`, `composer run phpcs`, `composer run analyse`.

---

## File Structure

**New — `src/Auth/Session/`** (the seam's own namespace; each file has one job):

| File | Responsibility |
|---|---|
| `AuthenticatedSession.php` | Value object for a completed session: access token, refresh token, TTL, token type, user. The only type `SessionCookieIssuer` accepts. |
| `TwoFactorChallenge.php` | Value object for an unfinished login: challenge token, TTL, delivery target. Carries no tokens. |
| `LoginOutcome.php` | Closed result: exactly one of the two above. `session()` throws when the outcome is a challenge. |
| `LoginOrchestrator.php` | **Password login only**: credential verification → 2FA gate → session issuance. Returns `LoginOutcome`. Shapes no HTTP response and dispatches no events. |
| `SessionCookieConfig.php` | Typed reader for `auth.session_cookie.*`. One place parses config. |
| `SessionCookieIssuer.php` | Writes and clears the cookie pair. The only place cookie attributes are set. |
| `SameOriginGuard.php` | Fetch-metadata / exact-origin check for cookie-only endpoints. |
| `SessionLogout.php` | Named composition point: revoke the server session **and** clear both cookies, in one call. Cookie-only. |
| `SessionLogoutResult.php` | Whether revocation actually succeeded, alongside the cookie-cleared response. |

**New elsewhere:**

- `src/Routing/Middleware/SessionCookieMiddleware.php` — the `session_cookie` transport adapter.
- `src/Controllers/SessionController.php` — `refresh()` and `logout()` for the cookie transport.
- `tests/Unit/Auth/Session/` — one test file per unit above.
- `tests/Unit/Routing/SessionCookieMiddlewareTest.php`.

**Modified:**

- `src/Controllers/AuthController.php` — `login()` delegates to `LoginOrchestrator`; response shaping unchanged.
- `src/Container/Providers/CoreProvider.php` — service definitions + `session_cookie` alias.
- `src/Routing/Middleware/CSRFMiddleware.php` — canonical session binding (Task 6).
- `src/Auth/LoginResponseShaper.php` — binds the login-issued CSRF token to the new session (Task 6).
- `routes/auth.php` — two new routes.
- `config/auth.php` — `session_cookie` block.
- `.env.example`, `CHANGELOG.md`, `docs/` — Task 9.

---

## Task 1: Typed login outcome value objects

**Files:**
- Create: `src/Auth/Session/AuthenticatedSession.php`, `src/Auth/Session/TwoFactorChallenge.php`, `src/Auth/Session/LoginOutcome.php`
- Test: `tests/Unit/Auth/Session/LoginOutcomeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AuthenticatedSession::fromSessionArray(array $session): self` with readonly `$accessToken`, `$refreshToken`, `$expiresIn`, `$tokenType`, `$user`, and `toSessionArray(): array`; `TwoFactorChallenge::fromArray(array $challenge): self` with readonly `$token`, `$expiresIn`, `$deliveredTo`; `LoginOutcome::authenticated(AuthenticatedSession): self`, `LoginOutcome::twoFactorRequired(TwoFactorChallenge): self`, `isAuthenticated(): bool`, `session(): AuthenticatedSession`, `challenge(): TwoFactorChallenge`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\LoginOutcome;
use Glueful\Auth\Session\TwoFactorChallenge;
use PHPUnit\Framework\TestCase;

final class LoginOutcomeTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sessionArray(): array
    {
        return [
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'user00000001', 'email' => 'user@example.test'],
        ];
    }

    public function testAuthenticatedOutcomeExposesItsSession(): void
    {
        $outcome = LoginOutcome::authenticated(AuthenticatedSession::fromSessionArray($this->sessionArray()));

        self::assertTrue($outcome->isAuthenticated());
        self::assertSame('access-abc', $outcome->session()->accessToken);
        self::assertSame('refresh-xyz', $outcome->session()->refreshToken);
        self::assertSame(3600, $outcome->session()->expiresIn);
    }

    public function testChallengeOutcomeCannotProduceASession(): void
    {
        $outcome = LoginOutcome::twoFactorRequired(TwoFactorChallenge::fromArray([
            'token' => 'challenge-1',
            'expires_in' => 300,
            'delivered_to' => 'u***@example.test',
        ]));

        self::assertFalse($outcome->isAuthenticated());
        self::assertSame('challenge-1', $outcome->challenge()->token);

        // The structural fail-closed guarantee: a login awaiting 2FA has no session to hand out,
        // so nothing downstream (cookie issuance included) can obtain one from this outcome.
        $this->expectException(\LogicException::class);
        $outcome->session();
    }

    public function testAuthenticatedOutcomeHasNoChallenge(): void
    {
        $outcome = LoginOutcome::authenticated(AuthenticatedSession::fromSessionArray($this->sessionArray()));

        $this->expectException(\LogicException::class);
        $outcome->challenge();
    }

    public function testSessionArrayRoundTripsUnchanged(): void
    {
        // JSON login re-shapes from this array; any key loss here is a response regression.
        $original = $this->sessionArray();

        self::assertSame($original, AuthenticatedSession::fromSessionArray($original)->toSessionArray());
    }

    public function testExtraSessionKeysSurviveTheRoundTrip(): void
    {
        // Providers and listeners add keys (e.g. 'provider'); the value object must not eat them.
        $original = $this->sessionArray() + ['provider' => 'jwt', 'remember_me' => true];

        self::assertSame($original, AuthenticatedSession::fromSessionArray($original)->toSessionArray());
    }

    public function testAMissingAccessTokenIsRejected(): void
    {
        $broken = $this->sessionArray();
        unset($broken['access_token']);

        $this->expectException(\InvalidArgumentException::class);
        AuthenticatedSession::fromSessionArray($broken);
    }

    public function testAnEmptyRefreshTokenIsRejected(): void
    {
        $broken = $this->sessionArray();
        $broken['refresh_token'] = '';

        $this->expectException(\InvalidArgumentException::class);
        AuthenticatedSession::fromSessionArray($broken);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/LoginOutcomeTest.php`
Expected: FAIL — `Class "Glueful\Auth\Session\AuthenticatedSession" not found`.

- [ ] **Step 3: Implement the three value objects**

`src/Auth/Session/AuthenticatedSession.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

/**
 * A completed authentication: the session a login produced.
 *
 * This type exists so that "we have a real session" is expressible in the type system.
 * Consumers that must never act on an unfinished login (cookie issuance above all) accept
 * THIS type rather than an array or a union, which makes the unsafe call unwritable instead
 * of merely discouraged.
 *
 * The original session array is retained verbatim so JSON responses can be shaped from an
 * unchanged payload — providers and login listeners add keys this object does not model.
 */
final class AuthenticatedSession
{
    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $raw
     */
    private function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $tokenType,
        public readonly array $user,
        private readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $session */
    public static function fromSessionArray(array $session): self
    {
        $accessToken = (string) ($session['access_token'] ?? '');
        $refreshToken = (string) ($session['refresh_token'] ?? '');
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Session is missing an access token.');
        }
        if ($refreshToken === '') {
            throw new \InvalidArgumentException('Session is missing a refresh token.');
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: (int) ($session['expires_in'] ?? 0),
            tokenType: (string) ($session['token_type'] ?? 'Bearer'),
            user: is_array($session['user'] ?? null) ? $session['user'] : [],
            raw: $session,
        );
    }

    /** @return array<string,mixed> The session exactly as issued — no keys added or dropped. */
    public function toSessionArray(): array
    {
        return $this->raw;
    }
}
```

`src/Auth/Session/TwoFactorChallenge.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

/**
 * An unfinished login awaiting second-factor verification.
 *
 * Deliberately carries no access or refresh token: at this point none exists.
 */
final class TwoFactorChallenge
{
    private function __construct(
        public readonly string $token,
        public readonly int $expiresIn,
        public readonly ?string $deliveredTo,
    ) {
    }

    /** @param array<string,mixed> $challenge */
    public static function fromArray(array $challenge): self
    {
        $token = (string) ($challenge['token'] ?? '');
        if ($token === '') {
            throw new \InvalidArgumentException('Two-factor challenge is missing its token.');
        }

        $deliveredTo = $challenge['delivered_to'] ?? null;

        return new self(
            token: $token,
            expiresIn: (int) ($challenge['expires_in'] ?? 0),
            deliveredTo: $deliveredTo === null ? null : (string) $deliveredTo,
        );
    }
}
```

`src/Auth/Session/LoginOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

/**
 * The closed result of a login attempt: EITHER an authenticated session OR a pending
 * two-factor challenge, never both and never neither.
 *
 * Transport-neutral by construction — it holds no HTTP concepts, so JSON and cookie callers
 * can share one login path without either inheriting the other's response semantics.
 */
final class LoginOutcome
{
    private function __construct(
        private readonly ?AuthenticatedSession $session,
        private readonly ?TwoFactorChallenge $challenge,
    ) {
    }

    public static function authenticated(AuthenticatedSession $session): self
    {
        return new self($session, null);
    }

    public static function twoFactorRequired(TwoFactorChallenge $challenge): self
    {
        return new self(null, $challenge);
    }

    public function isAuthenticated(): bool
    {
        return $this->session !== null;
    }

    public function session(): AuthenticatedSession
    {
        if ($this->session === null) {
            throw new \LogicException('This login is awaiting two-factor verification and has no session.');
        }

        return $this->session;
    }

    public function challenge(): TwoFactorChallenge
    {
        if ($this->challenge === null) {
            throw new \LogicException('This login is complete and has no pending challenge.');
        }

        return $this->challenge;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/LoginOutcomeTest.php`
Expected: PASS (7 tests).

---

## Task 2: Characterization tests for the four existing login branches

**Files:**
- Test: `tests/Unit/Controllers/AuthControllerLoginCharacterizationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the safety net Task 3 refactors against.

**Why first:** Task 3 moves login logic. The existing suite covers the shaper and the 2FA service but **not** the controller's four branches end to end, so "existing tests still pass" is not evidence that login still works. These tests characterize today's behavior — they are written against the CURRENT controller and must pass before any refactoring starts.

The token and API-key branches matter most: providers return an identity array with **no tokens** (`ApiKeyAuthenticationProvider::authenticate()` returns `$userData`), and where `access_token` is present at all it can be `''`. Any refactor that routes this branch through a type requiring tokens turns a working login into a 500.

- [ ] **Step 1: Write the characterization tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Auth\LoginResponseShaper;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Controllers\AuthController;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Characterizes AuthController::login() as it behaves TODAY, before the orchestrator
 * extraction. Every assertion here is a description of current behavior, not a wish.
 */
final class AuthControllerLoginCharacterizationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fullSession(): array
    {
        return [
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'user00000001'],
        ];
    }

    /** Identity-only payload, as API-key and token providers actually return. */
    private function identityOnlyResult(): array
    {
        return ['uuid' => 'user00000001', 'provider' => 'api_key', 'scopes' => ['read']];
    }

    private function controller(
        AuthenticationService $auth,
        ?TwoFactorServiceInterface $twoFactor = null,
    ): AuthController {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $shaper = $this->createMock(LoginResponseShaper::class);
        $shaper->method('shape')->willReturnCallback(
            static fn (Request $r, array $session): Response => new Response(json_encode($session))
        );

        $definitions = [
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            AuthenticationService::class => new ValueDefinition(AuthenticationService::class, $auth),
            LoginResponseShaper::class => new ValueDefinition(LoginResponseShaper::class, $shaper),
        ];
        if ($twoFactor !== null) {
            $definitions[TwoFactorServiceInterface::class] =
                new ValueDefinition(TwoFactorServiceInterface::class, $twoFactor);
        }

        $container = new Container();
        $container->load($definitions);
        $context->setContainer($container);

        return new AuthController($context);
    }

    private function post(array $body): Request
    {
        return Request::create('/auth/login', 'POST', $body);
    }

    public function testPasswordLoginShapesTheIssuedSession(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001']);
        $auth->method('issueSession')->willReturn($this->fullSession());

        $response = $this->controller($auth)->login($this->post(['username' => 'u', 'password' => 'p']));

        self::assertStringContainsString('access-abc', (string) $response->getContent());
    }

    public function testApiKeyLoginSucceedsWithAnIdentityOnlyPayload(): void
    {
        // The branch that a tokens-required type would break. It must keep working verbatim.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('authenticate')->willReturn($this->identityOnlyResult());
        $auth->expects(self::never())->method('verifyCredentials');

        $response = $this->controller($auth)->login($this->post(['api_key' => 'key-1']));

        self::assertStringContainsString('user00000001', (string) $response->getContent());
    }

    public function testTokenLoginSucceedsWithAnIdentityOnlyPayload(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('authenticate')->willReturn($this->identityOnlyResult());

        $response = $this->controller($auth)->login($this->post(['token' => 'tok-1']));

        self::assertStringContainsString('user00000001', (string) $response->getContent());
    }

    public function testTwoFactorEnabledLoginReturnsAChallengeAndIssuesNoSession(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001', 'email' => 'u@example.test']);
        $auth->expects(self::never())->method('issueSession');

        $twoFactor = $this->createMock(TwoFactorServiceInterface::class);
        $twoFactor->method('isEnabled')->willReturn(true);
        $twoFactor->method('beginLogin')->willReturn([
            'token' => 'challenge-1',
            'expires_in' => 300,
            'delivered_to' => 'u***@example.test',
        ]);

        $content = (string) $this->controller($auth, $twoFactor)
            ->login($this->post(['username' => 'u', 'password' => 'p']))
            ->getContent();

        self::assertStringContainsString('two_factor_required', $content);
        self::assertStringContainsString('challenge-1', $content);
        self::assertStringNotContainsString('access_token', $content);
    }

    public function testInvalidPasswordCredentialsThrow(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->controller($auth)->login($this->post(['username' => 'u', 'password' => 'bad']));
    }

    public function testInvalidApiKeyThrows(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('authenticate')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->controller($auth)->login($this->post(['api_key' => 'bad']));
    }
}
```

- [ ] **Step 2: Run against the UNCHANGED controller**

Run: `vendor/bin/phpunit tests/Unit/Controllers/AuthControllerLoginCharacterizationTest.php`
Expected: PASS (6 tests) with no production code touched. A failure here means the test misdescribes current behavior — fix the test, not the controller.

---

## Task 3: LoginOrchestrator for password login, with `AuthController::login()` refactored onto it

**Files:**
- Create: `src/Auth/Session/LoginOrchestrator.php`
- Modify: `src/Controllers/AuthController.php` (the `login()` method and constructor), `src/Container/Providers/CoreProvider.php`
- Test: `tests/Unit/Auth/Session/LoginOrchestratorTest.php`

**Interfaces:**
- Consumes: `LoginOutcome`, `AuthenticatedSession`, `TwoFactorChallenge` (Task 1).
- Produces: `LoginOrchestrator::login(array $credentials, ?string $providerName = null): LoginOutcome`, constructed as `new LoginOrchestrator(AuthenticationService $auth, ?TwoFactorServiceInterface $twoFactor)`.

**Scope: password login only.** The token / API-key branch stays in `AuthController` exactly as it is today and never enters the orchestrator. Those providers return an identity array with no tokens — `ApiKeyAuthenticationProvider::authenticate()` returns `$userData`, and where `access_token` appears at all it can be `''` — so passing that payload through `AuthenticatedSession` would throw. They are credential exchange, not interactive login: there is no "verified user, no session yet" state for a second factor to gate, and no browser ever presents an API key to a cookie login. Keeping them out of the orchestrator is what makes `AuthenticatedSession`'s tokens-required invariant safe to rely on.

**Behavior to preserve exactly** (currently inline in `AuthController::login()`, password branch):
1. `remember` in credentials becomes the `remember_me` flag.
2. `verifyCredentials($credentials, $providerName)`; `null` → `AuthenticationException('Invalid credentials')`.
3. `$preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt')`.
4. When a `TwoFactorServiceInterface` is registered **and** `isEnabled($uuid)` is true → `beginLogin([...], $preferredProvider)` and return a challenge outcome. **`issueSession()` must not be called.**
5. Otherwise `issueSession($userData, $preferredProvider)` → authenticated outcome.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Auth\Session\LoginOrchestrator;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use PHPUnit\Framework\TestCase;

final class LoginOrchestratorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sessionArray(): array
    {
        return [
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'user00000001'],
        ];
    }

    public function testTwoFactorEnabledAccountNeverReachesSessionIssuance(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001', 'email' => 'u@example.test']);
        // The gate's whole purpose: no session may exist while a challenge is outstanding.
        $auth->expects(self::never())->method('issueSession');

        $twoFactor = $this->createMock(TwoFactorServiceInterface::class);
        $twoFactor->method('isEnabled')->with('user00000001')->willReturn(true);
        $twoFactor->expects(self::once())->method('beginLogin')->willReturn([
            'token' => 'challenge-1',
            'expires_in' => 300,
            'delivered_to' => 'u***@example.test',
        ]);

        $outcome = (new LoginOrchestrator($auth, $twoFactor))->login(['username' => 'u', 'password' => 'p']);

        self::assertFalse($outcome->isAuthenticated());
        self::assertSame('challenge-1', $outcome->challenge()->token);
    }

    public function testPasswordLoginWithoutTwoFactorIssuesASession(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001']);
        $auth->expects(self::once())->method('issueSession')
            ->with(['uuid' => 'user00000001'], 'jwt')
            ->willReturn($this->sessionArray());

        $outcome = (new LoginOrchestrator($auth, null))->login(['username' => 'u', 'password' => 'p']);

        self::assertTrue($outcome->isAuthenticated());
        self::assertSame('access-abc', $outcome->session()->accessToken);
    }

    public function testTwoFactorIsSkippedWhenDisabledForTheUser(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001']);
        $auth->expects(self::once())->method('issueSession')->willReturn($this->sessionArray());

        $twoFactor = $this->createMock(TwoFactorServiceInterface::class);
        $twoFactor->method('isEnabled')->willReturn(false);
        $twoFactor->expects(self::never())->method('beginLogin');

        $outcome = (new LoginOrchestrator($auth, $twoFactor))->login(['username' => 'u', 'password' => 'p']);

        self::assertTrue($outcome->isAuthenticated());
    }

    public function testInvalidCredentialsThrow(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        (new LoginOrchestrator($auth, null))->login(['username' => 'u', 'password' => 'wrong']);
    }

    public function testTheOrchestratorNeverHandlesProviderCredentialExchange(): void
    {
        // Token and API-key credentials stay in the controller: those providers return an
        // identity array with no tokens, which AuthenticatedSession rejects by design.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('authenticate');
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001']);
        $auth->method('issueSession')->willReturn($this->sessionArray());

        (new LoginOrchestrator($auth, null))->login(['api_key' => 'key-1', 'username' => 'u', 'password' => 'p']);
    }

    public function testRememberFlagAndExplicitProviderAreForwarded(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('verifyCredentials')
            ->with(self::callback(static fn (array $c): bool => ($c['remember_me'] ?? null) === true), 'ldap')
            ->willReturn(['uuid' => 'user00000001']);
        $auth->expects(self::once())->method('issueSession')
            ->with(['uuid' => 'user00000001'], 'ldap')
            ->willReturn($this->sessionArray());

        (new LoginOrchestrator($auth, null))->login(
            ['username' => 'u', 'password' => 'p', 'remember' => true, 'provider' => 'ldap'],
            'ldap',
        );
    }

    public function testTheOrchestratorHasNoEventOrResponseDependencies(): void
    {
        // Response-shaping events (LoginResponseBuilding/Built) must stay JSON-only. The
        // orchestrator cannot dispatch them because it is constructed without any dispatcher —
        // asserted structurally so a future constructor argument cannot slip one in unnoticed.
        $parameters = (new \ReflectionClass(LoginOrchestrator::class))->getConstructor()?->getParameters() ?? [];
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $parameters,
        );

        self::assertSame(
            [AuthenticationService::class, '?' . TwoFactorServiceInterface::class],
            $types,
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/LoginOrchestratorTest.php`
Expected: FAIL — `Class "Glueful\Auth\Session\LoginOrchestrator" not found`.

- [ ] **Step 3: Implement the orchestrator**

`src/Auth/Session/LoginOrchestrator.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * The one login path: credential verification, the two-factor gate, then session issuance.
 *
 * Extracted from AuthController so that every transport — JSON, browser cookie, anything a
 * host app adds later — passes the SAME gate. A second login path that reached session
 * issuance directly could skip two-factor verification entirely; keeping this class the only
 * caller of issueSession() for interactive logins is what prevents that.
 *
 * Returns a transport-neutral {@see LoginOutcome} and shapes no HTTP response. It holds no
 * event dispatcher: session-lifecycle events fire inside session issuance (so both transports
 * get them exactly once), while response-shaping events belong to the JSON response shaper and
 * must not fire for a transport that returns no token body.
 */
final class LoginOrchestrator
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly ?TwoFactorServiceInterface $twoFactor = null,
    ) {
    }

    /**
     * Password login only. Token / API-key credential exchange stays in the controller: those
     * providers return an identity array with no tokens, so it cannot be modelled as a session,
     * and it has no intermediate state for a second factor to gate.
     *
     * @param array<string,mixed> $credentials
     * @throws AuthenticationException When the credentials are invalid.
     */
    public function login(array $credentials, ?string $providerName = null): LoginOutcome
    {
        $rememberMe = isset($credentials['remember']) && (bool) $credentials['remember'];
        $credentials['remember_me'] = $rememberMe;

        $providerValue = $credentials['provider'] ?? null;
        $preferredProvider = $providerName ?? (is_string($providerValue) ? $providerValue : 'jwt');

        $userData = $this->auth->verifyCredentials($credentials, $providerName);
        if ($userData === null) {
            throw new AuthenticationException('Invalid credentials');
        }

        $uuid = (string) ($userData['uuid'] ?? '');
        if ($this->twoFactor !== null && $this->twoFactor->isEnabled($uuid)) {
            $challenge = $this->twoFactor->beginLogin(
                [
                    'uuid'              => $uuid,
                    'email'             => (string) ($userData['email'] ?? ''),
                    'email_verified_at' => $userData['email_verified_at'] ?? null,
                    'username'          => $userData['username'] ?? null,
                    'profile'           => $userData['profile'] ?? null,
                    'remember_me'       => $rememberMe,
                    'status'            => $userData['status'] ?? null,
                ],
                $preferredProvider
            );

            return LoginOutcome::twoFactorRequired(TwoFactorChallenge::fromArray($challenge));
        }

        return LoginOutcome::authenticated(
            AuthenticatedSession::fromSessionArray($this->auth->issueSession($userData, $preferredProvider))
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/LoginOrchestratorTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Refactor `AuthController::login()` onto the orchestrator**

In `src/Controllers/AuthController.php`, add the property and resolve it in the constructor next to `$this->loginResponseShaper`:

```php
    private \Glueful\Auth\Session\LoginOrchestrator $loginOrchestrator;
```

```php
        $this->loginOrchestrator = container($this->context)->get(\Glueful\Auth\Session\LoginOrchestrator::class);
```

Replace the whole body of `login()` (everything after the attributes, from `$credentials = RequestHelper::getRequestData($request);` to the final `return`) with:

```php
    public function login(SymfonyRequest $request)
    {
        $credentials = RequestHelper::getRequestData($request);
        $providerValue = $credentials['provider'] ?? null;
        $providerName = is_string($providerValue) ? $providerValue : null;

        // Route 1 — token / API-key provider login, UNCHANGED. These providers return an
        // identity array with no tokens, so it is shaped directly and never modelled as a
        // session. Deliberately outside the orchestrator (see Task 3 scope note).
        if (isset($credentials['token']) || isset($credentials['api_key'])) {
            $result = $this->authService->authenticate($credentials, $providerName);
            if ($result === null) {
                throw new AuthenticationException('Invalid credentials');
            }
            return $this->loginResponseShaper->shape($request, $result);
        }

        // Route 2 — password login, through the shared orchestrator and its 2FA gate.
        $outcome = $this->loginOrchestrator->login($credentials, $providerName);

        if (!$outcome->isAuthenticated()) {
            $challenge = $outcome->challenge();

            // Challenge responses deliberately skip CSRF + login events — login is
            // not yet complete and there is no session to bind a CSRF token to.
            return Response::success([
                'two_factor_required' => true,
                'challenge_token'     => $challenge->token,
                'expires_in'          => $challenge->expiresIn,
                'delivered_to'        => $challenge->deliveredTo,
            ], 'Two-factor verification required');
        }

        return $this->loginResponseShaper->shape($request, $outcome->session()->toSessionArray());
    }
```

Leave the `$this->twoFactor` property in place — `TwoFactorController` and other methods may still resolve it — but `login()` no longer reads it.

- [ ] **Step 6: Register the orchestrator in the container**

In `src/Container/Providers/CoreProvider.php`, beside the other auth definitions, add:

```php
        $defs[\Glueful\Auth\Session\LoginOrchestrator::class] = new FactoryDefinition(
            \Glueful\Auth\Session\LoginOrchestrator::class,
            static function (\Psr\Container\ContainerInterface $c) {
                $twoFactorClass = \Glueful\Auth\Contracts\TwoFactorServiceInterface::class;

                return new \Glueful\Auth\Session\LoginOrchestrator(
                    $c->get(\Glueful\Auth\AuthenticationService::class),
                    $c->has($twoFactorClass) ? $c->get($twoFactorClass) : null,
                );
            }
        );
```

- [ ] **Step 7: Prove the JSON login response did not change**

Run the existing auth suites — they cover the shaper, the 2FA login branch and the controller:

Run: `vendor/bin/phpunit tests/Unit/Auth tests/Unit/Controllers tests/Integration/Auth tests/Integration/Controllers`
Expected: PASS with no new failures. The Task 2 characterization tests are the primary gate — all four branches must still behave identically. `LoginResponseShaperTest` and the `TwoFactor` suite must be green **without edits** — if either needs changing, the extraction altered behavior and must be corrected instead.

- [ ] **Step 8: Full gates, then commit**

```bash
composer test && composer run phpcs && composer run analyse
git add src/Auth/Session tests/Unit/Auth/Session tests/Unit/Controllers/AuthControllerLoginCharacterizationTest.php src/Controllers/AuthController.php src/Container/Providers/CoreProvider.php
git commit -m "feat(auth): extract a transport-neutral login orchestrator

Credential verification, the two-factor gate and session issuance move behind
LoginOrchestrator, which returns a closed LoginOutcome — an authenticated session
or a pending challenge, never both. JSON login shapes from the unchanged session
array, so responses are byte-identical.

The closed result is what makes a second transport safe to add: a login awaiting
two-factor verification has no session to hand out, so no caller can issue one."
```

---

## Task 4: Cookie configuration and issuer

**Files:**
- Create: `src/Auth/Session/SessionCookieConfig.php`, `src/Auth/Session/SessionCookieIssuer.php`
- Modify: `config/auth.php`
- Test: `tests/Unit/Auth/Session/SessionCookieIssuerTest.php`

**Interfaces:**
- Consumes: `AuthenticatedSession` (Task 1).
- Produces: `SessionCookieConfig::fromContext(ApplicationContext $context): self` with readonly `$enabled`, `$accessName`, `$refreshName`, `$refreshTtl`, `$path`, `$refreshPath`, `$domain`, `$secure`, `$sameSite`; `SessionCookieIssuer::issue(Response $response, AuthenticatedSession $session): Response` and `SessionCookieIssuer::clear(Response $response): Response`.

**Note on `rotate()`:** the spec lists issue/rotate/clear. Rotation is `issue()` called with the rotated session — overwriting a cookie is how a browser replaces it — so no separate `rotate()` method exists. A wrapper that only forwards would be dead API.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class SessionCookieIssuerTest extends TestCase
{
    private function config(): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: true,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function session(): AuthenticatedSession
    {
        return AuthenticatedSession::fromSessionArray([
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'user00000001'],
        ]);
    }

    /** @return array<string,Cookie> */
    private function cookies(Response $response): array
    {
        $byName = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }

    public function testIssueSetsBothCookiesWithTheMandatedAttributes(): void
    {
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $cookies = $this->cookies($response);

        self::assertArrayHasKey('gf_session', $cookies);
        self::assertArrayHasKey('gf_refresh', $cookies);

        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isHttpOnly(), 'session cookies must be HttpOnly');
            self::assertTrue($cookie->isSecure(), 'session cookies must be Secure');
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertNull($cookie->getDomain(), 'no domain by default — host-only cookies');
        }
    }

    public function testTheAccessCookieCarriesTheAccessTokenAtRootPath(): void
    {
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $access = $this->cookies($response)['gf_session'];

        self::assertSame('access-abc', $access->getValue());
        self::assertSame('/', $access->getPath());
    }

    public function testTheRefreshCookieIsPathScopedSoItIsNotSentOnOrdinaryRequests(): void
    {
        // The narrow path is the whole point: the refresh credential travels only to the
        // refresh route, so an XSS-free read of an ordinary response can never see it.
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $refresh = $this->cookies($response)['gf_refresh'];

        self::assertSame('refresh-xyz', $refresh->getValue());
        self::assertSame('/auth/session', $refresh->getPath());
    }

    public function testAccessCookieExpiryFollowsTheSessionTtl(): void
    {
        $before = time();
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $access = $this->cookies($response)['gf_session'];

        self::assertGreaterThanOrEqual($before + 3600, $access->getExpiresTime());
        self::assertLessThanOrEqual(time() + 3600, $access->getExpiresTime());
    }

    public function testClearExpiresBothCookiesOnTheirOwnPaths(): void
    {
        // A clear on the wrong path leaves the cookie alive in the browser — logout would
        // appear to succeed and the next request would still carry a credential.
        $response = (new SessionCookieIssuer($this->config()))->clear(new Response());
        $cookies = $this->cookies($response);

        self::assertSame('/', $cookies['gf_session']->getPath());
        self::assertSame('/auth/session', $cookies['gf_refresh']->getPath());
        foreach ($cookies as $cookie) {
            self::assertLessThan(time(), $cookie->getExpiresTime(), 'cleared cookies must be expired');
        }
    }

    public function testHostConfigurableNamesAreHonoured(): void
    {
        $config = new SessionCookieConfig(
            enabled: true,
            accessName: 'app_a_session',
            refreshName: 'app_a_refresh',
            refreshTtl: 600,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );

        $cookies = $this->cookies((new SessionCookieIssuer($config))->issue(new Response(), $this->session()));

        self::assertArrayHasKey('app_a_session', $cookies);
        self::assertArrayHasKey('app_a_refresh', $cookies);
    }

    public function testIssueAcceptsOnlyACompletedSession(): void
    {
        // Structural guarantee: there is no overload taking an array or a LoginOutcome, so
        // "issue cookies for a login still awaiting two-factor verification" is unwritable.
        $parameter = (new \ReflectionMethod(SessionCookieIssuer::class, 'issue'))->getParameters()[1];

        self::assertSame(AuthenticatedSession::class, (string) $parameter->getType());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SessionCookieIssuerTest.php`
Expected: FAIL — `Class "Glueful\Auth\Session\SessionCookieConfig" not found`.

- [ ] **Step 3: Implement the config reader**

`src/Auth/Session/SessionCookieConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Typed view of `auth.session_cookie.*`.
 *
 * One place parses this configuration, so the middleware, the issuer and the session
 * endpoints cannot drift on cookie names or paths — a mismatch there does not fail loudly,
 * it silently leaves a credential in the browser or fails to send one.
 */
final class SessionCookieConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $accessName,
        public readonly string $refreshName,
        public readonly int $refreshTtl,
        public readonly string $path,
        public readonly string $refreshPath,
        public readonly ?string $domain,
        public readonly bool $secure,
        public readonly string $sameSite,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        /** @var array<string,mixed> $raw */
        $raw = (array) config($context, 'auth.session_cookie', []);
        $domain = $raw['domain'] ?? null;
        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            accessName: (string) ($raw['access_name'] ?? 'gf_session'),
            refreshName: (string) ($raw['refresh_name'] ?? 'gf_refresh'),
            refreshTtl: (int) ($raw['refresh_ttl'] ?? 2592000),
            path: (string) ($raw['path'] ?? '/'),
            refreshPath: (string) ($raw['refresh_path'] ?? '/auth/session'),
            domain: $domain,
            secure: (bool) ($raw['secure'] ?? true),
            sameSite: (string) ($raw['same_site'] ?? Cookie::SAMESITE_LAX),
        );
    }
}
```

- [ ] **Step 4: Implement the issuer**

`src/Auth/Session/SessionCookieIssuer.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only place session cookie attributes are set.
 *
 * `issue()` accepts an {@see AuthenticatedSession} and nothing else: a login that is still
 * awaiting second-factor verification cannot produce that type, so issuing cookies for an
 * unfinished login is not an error to remember to avoid — it is unrepresentable.
 *
 * Rotation is `issue()` with the rotated session; a browser replaces a cookie by receiving a
 * new value for the same name and path.
 */
final class SessionCookieIssuer
{
    public function __construct(private readonly SessionCookieConfig $config)
    {
    }

    public function issue(Response $response, AuthenticatedSession $session): Response
    {
        $response->headers->setCookie($this->cookie(
            $this->config->accessName,
            $session->accessToken,
            time() + max(0, $session->expiresIn),
            $this->config->path,
        ));

        $response->headers->setCookie($this->cookie(
            $this->config->refreshName,
            $session->refreshToken,
            time() + $this->config->refreshTtl,
            $this->config->refreshPath,
        ));

        return $response;
    }

    public function clear(Response $response): Response
    {
        // Each cookie must be expired on the SAME path it was set on, or the browser keeps it.
        $response->headers->setCookie($this->cookie($this->config->accessName, '', 1, $this->config->path));
        $response->headers->setCookie($this->cookie($this->config->refreshName, '', 1, $this->config->refreshPath));

        return $response;
    }

    private function cookie(string $name, string $value, int $expiresAt, string $path): Cookie
    {
        return Cookie::create(
            name: $name,
            value: $value,
            expire: $expiresAt,
            path: $path,
            domain: $this->config->domain,
            secure: $this->config->secure,
            httpOnly: true,
            raw: false,
            sameSite: $this->config->sameSite,
        );
    }
}
```

- [ ] **Step 5: Add the config block**

Append to the returned array in `config/auth.php`, after `two_factor`:

```php
    /**
     * Opt-in browser-session transport. Off by default: a fresh install behaves exactly like
     * a pre-cookie framework until SESSION_COOKIE_ENABLED=true and routes opt in via the
     * `session_cookie` middleware. Bearer authentication is unaffected either way.
     */
    'session_cookie' => [
        'enabled' => env('SESSION_COOKIE_ENABLED', false),

        // Host-configurable so several Glueful apps under one parent domain do not collide.
        'access_name' => env('SESSION_COOKIE_ACCESS_NAME', 'gf_session'),
        'refresh_name' => env('SESSION_COOKIE_REFRESH_NAME', 'gf_refresh'),

        // How long the refresh cookie itself survives (seconds). The access cookie follows
        // the issued session's own expires_in.
        'refresh_ttl' => (int) env('SESSION_COOKIE_REFRESH_TTL', 2592000),

        'path' => env('SESSION_COOKIE_PATH', '/'),

        // The refresh credential is sent ONLY to the session routes, never on ordinary requests.
        'refresh_path' => env('SESSION_COOKIE_REFRESH_PATH', '/auth/session'),

        // Null means a host-only cookie, which is the safe default.
        'domain' => env('SESSION_COOKIE_DOMAIN', null),

        'secure' => env('SESSION_COOKIE_SECURE', true),
        'same_site' => env('SESSION_COOKIE_SAMESITE', 'lax'),
    ],
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SessionCookieIssuerTest.php`
Expected: PASS (7 tests).

---

## Task 5: `session_cookie` middleware

**Files:**
- Create: `src/Routing/Middleware/SessionCookieMiddleware.php`
- Modify: `src/Container/Providers/CoreProvider.php`
- Test: `tests/Unit/Routing/SessionCookieMiddlewareTest.php`

**Interfaces:**
- Consumes: `SessionCookieConfig` (Task 4), `AuthenticationService::validateAccessToken(string $token, ?ApplicationContext $context = null): ?array`.
- Produces: middleware alias `session_cookie`, supporting `session_cookie:optional`. Sets request attribute `auth_transport` to `'cookie'` or `'bearer'`.

**Rules:**
1. Disabled config → pass through untouched, no attribute set.
2. No cookie, no bearer → pass through; `auth` decides.
3. Bearer only → set `auth_transport=bearer`, change nothing else.
4. Cookie only, required mode → inject `Authorization: Bearer <cookie>`, set `auth_transport=cookie`. An invalid cookie is left for `auth` to reject normally.
5. Cookie only, `optional` mode → validate first; inject only when valid, so an expired cookie degrades to anonymous instead of 401-ing a public page.
6. Both present → resolve both. Same user uuid → bearer wins (`auth_transport=bearer`, cookie ignored, no CSRF obligation inherited). Different, or either invalid → `401`, non-revealing message.

**Identity attributes stay `auth`'s job.** This middleware sets `auth_transport` and nothing else. `AuthMiddleware` already populates `user` and `auth.user` immediately afterwards, and a second identity attribute written here would be a divergent source that could disagree with it — particularly in required mode, where this middleware deliberately does not validate and therefore has no identity to publish.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\SessionCookieMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionCookieMiddlewareTest extends TestCase
{
    private function config(bool $enabled = true): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: $enabled,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /** @param array<string,array<string,mixed>|null> $sessionsByToken */
    private function middleware(array $sessionsByToken = [], bool $enabled = true): SessionCookieMiddleware
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('validateAccessToken')->willReturnCallback(
            static fn (string $token): ?array => $sessionsByToken[$token] ?? null
        );

        return new SessionCookieMiddleware(
            $this->config($enabled),
            $auth,
            ApplicationContext::forTesting(dirname(__DIR__, 3)),
        );
    }

    private static function echoTransport(): callable
    {
        return static fn (Request $r): Response => new Response(
            (string) $r->attributes->get('auth_transport', 'none') . '|' . (string) $r->headers->get('Authorization', '')
        );
    }

    public function testDisabledConfigIsAPassThrough(): void
    {
        $request = Request::create('/anything');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']], false)
            ->handle($request, self::echoTransport());

        self::assertSame('none|', $response->getContent());
    }

    public function testNoCredentialsPassesThroughUntouched(): void
    {
        $response = $this->middleware()->handle(Request::create('/anything'), self::echoTransport());

        self::assertSame('none|', $response->getContent());
    }

    public function testCookieIsInjectedAsABearerHeaderAndMarkedAsCookieTransport(): void
    {
        $request = Request::create('/account');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport());

        self::assertSame('cookie|Bearer access-abc', $response->getContent());
    }

    public function testAnInvalidCookieIsStillPassedOnInRequiredModeSoAuthRejectsItNormally(): void
    {
        $request = Request::create('/account');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware([])->handle($request, self::echoTransport());

        self::assertSame('cookie|Bearer expired', $response->getContent());
    }

    public function testAnInvalidCookieDegradesToAnonymousInOptionalMode(): void
    {
        // A public, cacheable page must not 401 because a visitor's session lapsed.
        $request = Request::create('/public');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware([])->handle($request, self::echoTransport(), 'optional');

        self::assertSame('none|', $response->getContent());
    }

    public function testAValidCookieIsInjectedInOptionalMode(): void
    {
        $request = Request::create('/public');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport(), 'optional');

        self::assertSame('cookie|Bearer access-abc', $response->getContent());
    }

    public function testAnExplicitBearerIsLeftAloneAndMarkedAsBearerTransport(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');

        $response = $this->middleware(['api-token' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport());

        self::assertSame('bearer|Bearer api-token', $response->getContent());
    }

    public function testMatchingBearerAndCookieResolveToBearerTransport(): void
    {
        // Proving possession of an explicit bearer credential must not drag the request into
        // cookie CSRF obligations.
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware([
            'api-token' => ['uuid' => 'u1'],
            'access-abc' => ['uuid' => 'u1'],
        ])->handle($request, self::echoTransport());

        self::assertSame('bearer|Bearer api-token', $response->getContent());
    }

    public function testMismatchedBearerAndCookieAreRejected(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware([
            'api-token' => ['uuid' => 'u1'],
            'access-abc' => ['uuid' => 'u2'],
        ])->handle($request, static fn (): Response => new Response('next'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('u1', (string) $response->getContent());
        self::assertStringNotContainsString('u2', (string) $response->getContent());
    }

    public function testAnInvalidCookieAlongsideAValidBearerIsRejected(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware(['api-token' => ['uuid' => 'u1']])
            ->handle($request, static fn (): Response => new Response('next'));

        self::assertSame(401, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Routing/SessionCookieMiddlewareTest.php`
Expected: FAIL — `Class "Glueful\Routing\Middleware\SessionCookieMiddleware" not found`.

- [ ] **Step 3: Implement the middleware**

`src/Routing/Middleware/SessionCookieMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapts an HttpOnly session cookie into the `Authorization` header that {@see AuthMiddleware}
 * already understands, so browser clients authenticate through the SAME path as API clients
 * without the bearer extraction being modified.
 *
 * Runs BEFORE `auth`. It authenticates nothing itself; it decides which credential the request
 * is making, and records that decision in the `auth_transport` request attribute. Callers need
 * that provenance because cookie-authenticated unsafe requests are CSRF-exposed while bearer
 * requests are not — a distinction that is lost if a cookie is silently treated as a bearer.
 *
 * Deliberately does NOT refresh: the refresh cookie is path-scoped to the session routes and is
 * not sent on ordinary requests, so a transparent refresh here is impossible by construction.
 * Clients call the refresh endpoint explicitly.
 *
 * Params: `optional` — an invalid or expired cookie degrades to anonymous instead of being
 * passed on for rejection, so public pages survive a lapsed session.
 */
final class SessionCookieMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly SessionCookieConfig $config,
        private readonly AuthenticationService $auth,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (!$this->config->enabled) {
            return $next($request);
        }

        $cookie = (string) ($request->cookies->get($this->config->accessName) ?? '');
        $bearer = $this->bearerToken($request);

        if ($bearer !== null && $cookie !== '') {
            if (!$this->sameIdentity($bearer, $cookie)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Conflicting credentials.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Both credentials, one identity: the explicit bearer wins.
            $request->attributes->set('auth_transport', 'bearer');

            return $next($request);
        }

        if ($bearer !== null) {
            $request->attributes->set('auth_transport', 'bearer');

            return $next($request);
        }

        if ($cookie === '') {
            return $next($request);
        }

        if (in_array('optional', $params, true) && $this->identity($cookie) === null) {
            return $next($request);
        }

        $request->headers->set('Authorization', 'Bearer ' . $cookie);
        $request->attributes->set('auth_transport', 'cookie');

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) ($request->headers->get('Authorization') ?? '');
        if (preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    private function sameIdentity(string $bearer, string $cookie): bool
    {
        $bearerIdentity = $this->identity($bearer);
        $cookieIdentity = $this->identity($cookie);

        return $bearerIdentity !== null && $cookieIdentity !== null && $bearerIdentity === $cookieIdentity;
    }

    private function identity(string $token): ?string
    {
        $session = $this->auth->validateAccessToken($token, $this->context);
        if ($session === null) {
            return null;
        }

        $user = $session['user'] ?? null;
        $uuid = is_array($user) ? ($user['uuid'] ?? null) : ($session['uuid'] ?? null);

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Routing/SessionCookieMiddlewareTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Register the service and alias**

In `src/Container/Providers/CoreProvider.php`, add the definitions beside the other middleware entries:

```php
        $defs[\Glueful\Auth\Session\SessionCookieConfig::class] = new FactoryDefinition(
            \Glueful\Auth\Session\SessionCookieConfig::class,
            static fn(\Psr\Container\ContainerInterface $c) =>
                \Glueful\Auth\Session\SessionCookieConfig::fromContext(
                    $c->get(\Glueful\Bootstrap\ApplicationContext::class)
                )
        );
        $defs[\Glueful\Auth\Session\SessionCookieIssuer::class] = new FactoryDefinition(
            \Glueful\Auth\Session\SessionCookieIssuer::class,
            static fn(\Psr\Container\ContainerInterface $c) =>
                new \Glueful\Auth\Session\SessionCookieIssuer(
                    $c->get(\Glueful\Auth\Session\SessionCookieConfig::class)
                )
        );
        $defs[\Glueful\Routing\Middleware\SessionCookieMiddleware::class] = new FactoryDefinition(
            \Glueful\Routing\Middleware\SessionCookieMiddleware::class,
            static fn(\Psr\Container\ContainerInterface $c) =>
                new \Glueful\Routing\Middleware\SessionCookieMiddleware(
                    $c->get(\Glueful\Auth\Session\SessionCookieConfig::class),
                    $c->get(\Glueful\Auth\AuthenticationService::class),
                    $c->get(\Glueful\Bootstrap\ApplicationContext::class),
                )
        );
        $defs['session_cookie'] = new AliasDefinition(
            'session_cookie',
            \Glueful\Routing\Middleware\SessionCookieMiddleware::class
        );
```

- [ ] **Step 6: Full gates, then commit**

```bash
composer test && composer run phpcs && composer run analyse
git add src/Auth/Session src/Routing/Middleware/SessionCookieMiddleware.php src/Container/Providers/CoreProvider.php config/auth.php tests/Unit/Auth/Session tests/Unit/Routing/SessionCookieMiddlewareTest.php
git commit -m "feat(auth): add opt-in HttpOnly session cookie transport

SessionCookieIssuer owns cookie attributes (HttpOnly, Secure, SameSite=Lax,
host-configurable names, refresh path-scoped to the session routes) and accepts only
a completed session. SessionCookieMiddleware adapts an access cookie into the
Authorization header the existing auth middleware already reads, and records
auth_transport so cookie requests can carry CSRF obligations that bearer requests
do not.

Mixed credentials are never silently resolved: matching identities defer to the
explicit bearer, mismatches are rejected. Off by default; bearer behaviour unchanged."
```

---

## Task 6: Canonical CSRF session binding

**Files:**
- Modify: `src/Routing/Middleware/CSRFMiddleware.php` (`getSessionId()`), `src/Auth/LoginResponseShaper.php`
- Test: `tests/Unit/Routing/CsrfSessionBindingTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `CSRFMiddleware::generateTokenForSession(Request $request, string $sessionId, ?int $lifetime = null): string`; `getSessionId()` resolving the canonical session uuid.

**Why this task exists.** `getSessionId()` reads `$user['session_id']`, but no provider produces that key — `JwtAuthenticationProvider` returns `sid` and `session_uuid`. So today every CSRF token falls through to the anonymous fingerprint branch (IP + User-Agent + Accept-Language + Accept-Encoding). For bearer clients that is harmless, because they don't rely on CSRF. For a cookie transport it is not: two visitors behind the same NAT with the same browser share a fingerprint, so one visitor's token can validate another's request — which is the exact thing CSRF protection exists to prevent.

`LoginResponseShaper` compounds it: it generates the token during login, before any authenticated identity is attached to the request, so the login token is fingerprint-bound while later requests would be session-bound. Without this task, the CSRF continuity that Task 7 asserts is meaningless — it would be asserting the stability of a fingerprint.

**Upgrade note for the release:** CSRF tokens issued to AUTHENTICATED callers before the upgrade are fingerprint-bound and stop validating afterwards. There is no compatibility window and no automatic recovery: an affected client receives a `403` and must fetch a new CSRF token or reload the page. Old fingerprint-keyed cache entries are left to expire on their own. This makes the release's upgrade notes mandatory (Task 9); the release is a minor because of the new session transport, not because of this.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CsrfSessionBindingTest extends TestCase
{
    private function request(string $ip = '203.0.113.10'): Request
    {
        $request = Request::create('/account/profile', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (shared browser)');

        return $request;
    }

    public function testTokensBindToTheSessionUuidWhenAuthenticated(): void
    {
        $csrf = new CSRFMiddleware();

        $first = $this->request();
        $first->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);
        $token = $csrf->generateToken($first);

        // Same session, different request object: the token must still validate.
        $second = $this->request();
        $second->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);

        self::assertTrue($csrf->validateToken($second, $token));
    }

    public function testADifferentSessionCannotReuseTheToken(): void
    {
        // The failure this task exists to prevent: identical fingerprints (same IP, same
        // User-Agent), different sessions. Under fingerprint binding this passes — which is
        // precisely the hole.
        $csrf = new CSRFMiddleware();

        $issuing = $this->request();
        $issuing->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);
        $token = $csrf->generateToken($issuing);

        $other = $this->request();
        $other->attributes->set('user', ['uuid' => 'u2', 'sid' => 'session-B']);

        self::assertFalse($csrf->validateToken($other, $token));
    }

    public function testSessionUuidIsAcceptedAsWellAsSid(): void
    {
        $csrf = new CSRFMiddleware();

        $issuing = $this->request();
        $issuing->attributes->set('user', ['uuid' => 'u1', 'session_uuid' => 'session-C']);
        $token = $csrf->generateToken($issuing);

        $second = $this->request();
        $second->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-C']);

        self::assertTrue($csrf->validateToken($second, $token), 'sid and session_uuid name one thing');
    }

    public function testAnExplicitSessionIdCanBeBoundBeforeIdentityIsAttached(): void
    {
        // Login shaping runs before any authenticated identity is on the request, so it must
        // be able to bind the token to the session it just issued.
        $csrf = new CSRFMiddleware();

        $token = $csrf->generateTokenForSession($this->request(), 'session-D');

        $later = $this->request();
        $later->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-D']);

        self::assertTrue($csrf->validateToken($later, $token));
    }

    public function testAnonymousRequestsStillFallBackToFingerprinting(): void
    {
        // Unauthenticated forms must keep working exactly as before.
        $csrf = new CSRFMiddleware();
        $token = $csrf->generateToken($this->request());

        self::assertTrue($csrf->validateToken($this->request(), $token));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Routing/CsrfSessionBindingTest.php`
Expected: FAIL — `testADifferentSessionCannotReuseTheToken` passes validation (both requests share a fingerprint), and `generateTokenForSession()` does not exist.

- [ ] **Step 3: Make `getSessionId()` resolve the canonical session uuid**

In `src/Routing/Middleware/CSRFMiddleware.php`, add the override property and replace the first branch of `getSessionId()`:

```php
    /** Explicit session binding, set when a caller knows the session before auth attaches it. */
    private ?string $boundSessionId = null;
```

```php
    private function getSessionId(Request $request): string
    {
        if ($this->boundSessionId !== null) {
            return 'sid_' . $this->boundSessionId;
        }

        // Providers name the session uuid `sid` (JWT claim) or `session_uuid` (session row);
        // `session_id` is accepted for any provider that uses it. Reading only `session_id`
        // silently fell through to fingerprinting for every JWT-authenticated request.
        $user = $request->attributes->get('user');
        if (is_array($user)) {
            foreach (['sid', 'session_uuid', 'session_id'] as $key) {
                $value = $user[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return 'sid_' . $value;
                }
            }
        }

        // Try to get from JWT session
        $jwtSession = $request->attributes->get('jwt_session');
        if ($jwtSession !== null) {
            return 'jwt_' . substr(hash('sha256', $jwtSession), 0, 16);
        }

        // Fallback to fingerprinting for anonymous sessions
        // ... existing fingerprint code unchanged ...
    }
```

Add the explicit-binding entry point next to `generateToken()`:

```php
    /**
     * Generate a token bound to a KNOWN session, for callers that hold the session before an
     * authenticated identity is attached to the request — login response shaping above all.
     * Without this, a login-issued token binds to the anonymous fingerprint and every later
     * session-bound request rejects it.
     */
    public function generateTokenForSession(Request $request, string $sessionId, ?int $lifetime = null): string
    {
        $previous = $this->boundSessionId;
        $this->boundSessionId = $sessionId;
        try {
            return $this->generateToken($request, $lifetime);
        } finally {
            $this->boundSessionId = $previous;
        }
    }
```

- [ ] **Step 4: Bind the login-issued token to the new session**

In `src/Auth/LoginResponseShaper.php`, inside the CSRF block, replace `$token = $csrf->generateToken($request);` with:

```php
                $sessionId = '';
                foreach (['sid', 'session_uuid', 'session_id'] as $key) {
                    $candidate = $session[$key] ?? ($session['user'][$key] ?? null);
                    if (is_string($candidate) && $candidate !== '') {
                        $sessionId = $candidate;
                        break;
                    }
                }

                // Bind to the session just issued; falling back to the request-derived binding
                // only when the provider surfaced no session identifier at all.
                $token = $sessionId !== ''
                    ? $csrf->generateTokenForSession($request, $sessionId)
                    : $csrf->generateToken($request);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Routing/CsrfSessionBindingTest.php tests/Unit/Auth/LoginResponseShaperTest.php`
Expected: PASS. `LoginResponseShaperTest` must stay green **without edits** — the response shape is unchanged; only what the token binds to changed.

- [ ] **Step 6: Run the full CSRF and auth suites**

Run: `vendor/bin/phpunit tests/Unit/Routing tests/Unit/Auth`
Expected: PASS. A CSRF test that assumed fingerprint binding for an authenticated request is a genuine finding — report it rather than adjusting it silently.

---

## Task 7: `POST /auth/session/refresh`

**Files:**
- Create: `src/Auth/Session/SameOriginGuard.php`, `src/Controllers/SessionController.php`
- Modify: `routes/auth.php`, `src/Container/Providers/CoreProvider.php`
- Test: `tests/Unit/Auth/Session/SameOriginGuardTest.php`, `tests/Unit/Auth/Session/SessionRefreshTest.php`

**Interfaces:**
- Consumes: `SessionCookieConfig`, `SessionCookieIssuer`, `AuthenticatedSession` (Tasks 1 & 4), `AuthenticationService::refreshTokens(string $refreshToken): ?array`.
- Produces: `SameOriginGuard::isSameOrigin(Request $request): bool`; `SessionController::refresh(Request $request): Response`.

**Behavior:** reject unless same-origin; read the refresh cookie (never a body field); rotate via `refreshTokens()`; re-issue both cookies from the rotated session; return `{"success":true,"message":"Session refreshed"}` with **no tokens**; on failure clear both cookies and return 401.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Auth/Session/SameOriginGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\SameOriginGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SameOriginGuardTest extends TestCase
{
    public function testFetchMetadataSameOriginIsAccepted(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertTrue((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testFetchMetadataCrossSiteIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'cross-site');
        $request->headers->set('Origin', 'https://app.example.test');

        // Fetch metadata is authoritative when present — a matching Origin cannot rescue it.
        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedBecauseASiblingSubdomainIsNotThisOrigin(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testExactOriginMatchIsAcceptedWhenFetchMetadataIsAbsent(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'https://app.example.test');

        self::assertTrue((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testAForeignOriginIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'https://evil.example.test');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testASchemeMismatchIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'http://app.example.test');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testARequestWithNeitherHeaderIsRejected(): void
    {
        // Browsers send one or the other on a POST; anything else should use the bearer
        // refresh endpoint rather than the cookie one.
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }
}
```

`tests/Unit/Auth/Session/SessionRefreshTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Controllers\SessionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionRefreshTest extends TestCase
{
    private function config(): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: true,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function controller(AuthenticationService $auth): SessionController
    {
        $config = $this->config();

        return new SessionController($auth, new SessionCookieIssuer($config), $config, new SameOriginGuard());
    }

    private function request(): Request
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');
        $request->cookies->set('gf_refresh', 'refresh-old');

        return $request;
    }

    /** @return array<string,Cookie> */
    private function cookies(Response $response): array
    {
        $byName = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }

    public function testRefreshRotatesBothCookiesAndReturnsNoTokens(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('refreshTokens')->with('refresh-old')->willReturn([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'u1'],
        ]);

        $response = $this->controller($auth)->refresh($this->request());
        $cookies = $this->cookies($response);
        $body = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('access-new', $cookies['gf_session']->getValue());
        self::assertSame('refresh-new', $cookies['gf_refresh']->getValue());
        self::assertStringNotContainsString('access-new', $body, 'tokens must never reach the body');
        self::assertStringNotContainsString('refresh-new', $body, 'tokens must never reach the body');
    }

    public function testRefreshIsRejectedWhenNotSameOrigin(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = $this->request();
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        self::assertSame(403, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshWithoutTheCookieIs401AndDoesNotCallTheService(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = $this->request();
        $request->cookies->remove('gf_refresh');

        self::assertSame(401, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshIgnoresARefreshTokenSuppliedInTheBody(): void
    {
        // The cookie is the only accepted source; accepting a body value would hand an
        // attacker a way to drive rotation with a stolen token from a foreign context.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = Request::create(
            'https://app.example.test/auth/session/refresh',
            'POST',
            ['refresh_token' => 'refresh-from-body'],
        );
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertSame(401, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshPreservesTheSessionIdentityThatCsrfTokensBindTo(): void
    {
        // CSRFMiddleware binds tokens to $user['session_id'], and RefreshService rotates tokens
        // while preserving the session uuid (persistRotatedSession($sessionUuid, $tokens)). So a
        // CSRF token issued before a refresh stays valid after it. Forcing new CSRF state here
        // would invalidate tokens already embedded in rendered forms and buy nothing: the
        // binding is unchanged and the token never left the origin.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('refreshTokens')->willReturn([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'u1', 'session_id' => 'session-1'],
        ]);

        $body = (string) $this->controller($auth)->refresh($this->request())->getContent();

        self::assertStringNotContainsString('csrf', strtolower($body), 'refresh issues no new CSRF state');
    }

    public function testADisabledTransportRefusesBeforeReadingAnyCredential(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $config = new SessionCookieConfig(
            enabled: false,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
        $controller = new SessionController(
            $auth,
            new SessionCookieIssuer($config),
            $config,
            new SameOriginGuard(),
        );

        $response = $controller->refresh($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $response->headers->getCookies(), 'a disabled transport emits no cookies');
    }

    public function testAnExpiredRefreshTokenClearsBothCookies(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('refreshTokens')->willReturn(null);

        $response = $this->controller($auth)->refresh($this->request());
        $cookies = $this->cookies($response);

        self::assertSame(401, $response->getStatusCode());
        foreach (['gf_session', 'gf_refresh'] as $name) {
            self::assertLessThan(time(), $cookies[$name]->getExpiresTime(), $name . ' must be expired');
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SameOriginGuardTest.php tests/Unit/Auth/Session/SessionRefreshTest.php`
Expected: FAIL — `Class "Glueful\Auth\Session\SameOriginGuard" not found`.

- [ ] **Step 3: Implement the guard**

`src/Auth/Session/SameOriginGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Request;

/**
 * Same-origin check for endpoints whose only credential is a cookie.
 *
 * Such endpoints cannot rely on a CSRF token — the caller may hold no token yet, and the
 * cookie is attached by the browser automatically — so origin provenance is the protection.
 *
 * `Sec-Fetch-Site` is authoritative when present: it is set by the browser and cannot be
 * forged by page script. Only `same-origin` passes; `same-site` is rejected because a sibling
 * subdomain is a different origin. Without fetch metadata, the `Origin` header must match the
 * request's own scheme, host and port exactly. A request carrying neither header is rejected:
 * browsers send one on a POST, and non-browser clients should use the bearer refresh endpoint.
 */
final class SameOriginGuard
{
    public function isSameOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null && $fetchSite !== '') {
            return strtolower($fetchSite) === 'same-origin';
        }

        $origin = (string) ($request->headers->get('Origin') ?? '');
        if ($origin === '') {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost();
    }
}
```

- [ ] **Step 4: Implement the controller (refresh only; `logout()` lands in Task 8)**

`src/Controllers/SessionController.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse as ApiResponseAttribute;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie-transport session endpoints.
 *
 * Separate from AuthController because these speak only cookies: they read no credential from
 * the request body and return none in the response body. The bearer equivalents
 * (`/auth/refresh-token`, `/auth/logout`) are untouched and remain the API client's path.
 */
final class SessionController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly SessionCookieIssuer $issuer,
        private readonly SessionCookieConfig $config,
        private readonly SameOriginGuard $origin,
    ) {
    }

    #[ApiOperation(
        summary: 'Refresh the browser session',
        description: 'Rotates the session cookie pair using the path-scoped refresh cookie. '
            . 'Returns no tokens. Same-origin requests only.',
        tags: ['Authentication'],
    )]
    #[ApiResponseAttribute(200, description: 'Session refreshed')]
    #[ApiResponseAttribute(401, description: 'Missing or expired refresh cookie')]
    #[ApiResponseAttribute(403, description: 'Request is not same-origin')]
    public function refresh(Request $request): Response
    {
        if (!$this->config->enabled) {
            return $this->disabled();
        }

        if (!$this->origin->isSameOrigin($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        // The cookie is the ONLY accepted source — never a body field.
        $refreshToken = (string) ($request->cookies->get($this->config->refreshName) ?? '');
        if ($refreshToken === '') {
            return new JsonResponse(['success' => false, 'message' => 'Session expired.'], 401);
        }

        $rotated = $this->auth->refreshTokens($refreshToken);
        if ($rotated === null) {
            // A dead refresh credential leaves nothing worth keeping in the browser.
            return $this->issuer->clear(
                new JsonResponse(['success' => false, 'message' => 'Session expired.'], 401)
            );
        }

        return $this->issuer->issue(
            new JsonResponse(['success' => true, 'message' => 'Session refreshed'], 200),
            AuthenticatedSession::fromSessionArray($rotated),
        );
    }

    /**
     * The transport is off: behave as though these routes do not exist. Reached only when the
     * routes were registered while enabled and the flag was turned off afterwards — the route
     * file also refuses to register them while disabled. Fails closed BEFORE any credential is
     * read, so a disabled install performs no authentication work on this path.
     */
    private function disabled(): Response
    {
        return new JsonResponse(['success' => false, 'message' => 'Not found.'], 404);
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/auth.php`, inside the existing `/auth` group, after the `refresh-token` route:

```php
    // Cookie-transport session routes. The refresh cookie is path-scoped to `/auth/session`,
    // so it is sent here and nowhere else. Registered ONLY when the transport is enabled:
    // "off by default" has to mean the endpoints are absent, not merely that the middleware
    // declines — with default cookie names they would otherwise stay fully operational.
    if ((bool) config($router->getContext(), 'auth.session_cookie.enabled', false)) {
        $router->group(['prefix' => '/session'], function (Router $router) {
            $router->post('/refresh', [SessionController::class, 'refresh'])
                ->middleware('rate_limit:30,60');
        });
    }
```

Add the import at the top: `use Glueful\Controllers\SessionController;`

- [ ] **Step 6: Register the controller**

In `src/Container/Providers/CoreProvider.php`:

```php
        $defs[\Glueful\Controllers\SessionController::class] = new FactoryDefinition(
            \Glueful\Controllers\SessionController::class,
            static fn(\Psr\Container\ContainerInterface $c) =>
                new \Glueful\Controllers\SessionController(
                    $c->get(\Glueful\Auth\AuthenticationService::class),
                    $c->get(\Glueful\Auth\Session\SessionCookieIssuer::class),
                    $c->get(\Glueful\Auth\Session\SessionCookieConfig::class),
                    new \Glueful\Auth\Session\SameOriginGuard(),
                )
        );
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SameOriginGuardTest.php tests/Unit/Auth/Session/SessionRefreshTest.php`
Expected: PASS (14 tests).

---

## Task 8: Session logout composition point

**Files:**
- Create: `src/Auth/Session/SessionLogout.php`, `src/Auth/Session/SessionLogoutResult.php`
- Modify: `src/Controllers/SessionController.php` (add `logout()`), `routes/auth.php`, `src/Container/Providers/CoreProvider.php`
- Test: `tests/Unit/Auth/Session/SessionLogoutTest.php`

**Interfaces:**
- Consumes: `AuthenticationService::terminateSession(string $token): bool`, `SessionCookieIssuer::clear()`, `SessionCookieConfig`.
- Produces: `SessionLogoutResult` (readonly `bool $revoked`, `Response $response`) and `SessionLogout::logout(Request $request, Response $response): SessionLogoutResult`.

**Why a service and not two calls in a controller:** the invariant is "the server session is revoked **and** both cookies are cleared". Two independently-tested calls can both pass while a caller does only one of them; one composition point makes the pair the thing under test.

**Two rules the naive version gets wrong:**

- *Revocation failure must not be reported as success.* Cookies are cleared either way — a visitor who clicked sign out must not keep a working credential — but if `terminateSession()` returns false the server session may still be live and a copied token still usable. The result carries that fact and the endpoint returns `500` with it logged, rather than a green 200 over a half-completed logout.
- *This endpoint is cookie-only.* It reads the access cookie and nothing else. Bearer clients use the existing `POST /auth/logout`. Accepting either credential here would mean silently preferring one when both are present — the exact ambiguity the middleware refuses to resolve silently.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionLogoutTest extends TestCase
{
    private function config(): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: true,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function logout(AuthenticationService $auth): SessionLogout
    {
        $config = $this->config();

        return new SessionLogout($auth, new SessionCookieIssuer($config), $config);
    }

    /** @return array<string,Cookie> */
    private function cookies(Response $response): array
    {
        $byName = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }

    public function testLogoutRevokesTheSessionAndClearsBothCookiesTogether(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('terminateSession')->with('access-abc')->willReturn(true);

        $request = Request::create('/auth/session/logout', 'POST');
        $request->cookies->set('gf_session', 'access-abc');

        $result = $this->logout($auth)->logout($request, new Response());
        $cookies = $this->cookies($result->response);

        self::assertTrue($result->revoked);
        self::assertLessThan(time(), $cookies['gf_session']->getExpiresTime());
        self::assertLessThan(time(), $cookies['gf_refresh']->getExpiresTime());
    }

    public function testFailedRevocationClearsCookiesButIsReportedAsAFailure(): void
    {
        // Both halves matter. Leaving a credential in the browser would let the visitor keep
        // browsing as themselves after clicking sign out; reporting success would hide that
        // the server session is still live and a copied token still works.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('terminateSession')->willReturn(false);

        $request = Request::create('/auth/session/logout', 'POST');
        $request->cookies->set('gf_session', 'access-abc');

        $result = $this->logout($auth)->logout($request, new Response());
        $cookies = $this->cookies($result->response);

        self::assertFalse($result->revoked);
        self::assertLessThan(time(), $cookies['gf_session']->getExpiresTime());
        self::assertLessThan(time(), $cookies['gf_refresh']->getExpiresTime());
    }

    public function testLogoutWithoutACookieClearsAndCountsAsRevoked(): void
    {
        // Nothing to revoke is not a failure — there was no session to begin with.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('terminateSession');

        $result = $this->logout($auth)->logout(Request::create('/auth/session/logout', 'POST'), new Response());

        self::assertTrue($result->revoked);
        self::assertCount(2, $this->cookies($result->response));
    }

    public function testABearerCredentialIsIgnoredBecauseThisEndpointIsCookieOnly(): void
    {
        // Bearer clients use POST /auth/logout. Honouring both here would mean silently
        // choosing one when both are present.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('terminateSession');

        $request = Request::create('/auth/session/logout', 'POST');
        $request->headers->set('Authorization', 'Bearer bearer-token');

        $result = $this->logout($auth)->logout($request, new Response());

        self::assertCount(2, $this->cookies($result->response), 'cookies are still cleared');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SessionLogoutTest.php`
Expected: FAIL — `Class "Glueful\Auth\Session\SessionLogout" not found`.

- [ ] **Step 3: Implement `SessionLogout`**

`src/Auth/Session/SessionLogout.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a browser session: revokes it server-side AND clears both cookies.
 *
 * Both halves live here rather than in a controller because the guarantee is the PAIR.
 * Revocation without clearing leaves a dead cookie that still looks like a credential;
 * clearing without revocation leaves a live session that a copied token can still use.
 * Testing the two operations separately cannot catch a caller that performs only one.
 *
 * Cookies are cleared even when revocation fails — a visitor who clicked "sign out" must not
 * be left holding a working credential — but the failure is REPORTED rather than swallowed,
 * because a live server session after a logout is a security-relevant outcome, not a detail.
 *
 * Cookie-only by design: bearer clients use the existing POST /auth/logout. Reading either
 * credential here would mean silently choosing one when both are present.
 */
final class SessionLogout
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly SessionCookieIssuer $issuer,
        private readonly SessionCookieConfig $config,
    ) {
    }

    public function logout(Request $request, Response $response): SessionLogoutResult
    {
        $token = (string) ($request->cookies->get($this->config->accessName) ?? '');

        // No cookie means there was no session of ours to revoke — not a failure.
        $revoked = $token === '' ? true : $this->auth->terminateSession($token);

        return new SessionLogoutResult($revoked, $this->issuer->clear($response));
    }
}
```

`src/Auth/Session/SessionLogoutResult.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Response;

/**
 * Outcome of a logout: the response with both cookies cleared, and whether the server-side
 * session was actually revoked. Callers must not report success on `revoked === false`.
 */
final class SessionLogoutResult
{
    public function __construct(
        public readonly bool $revoked,
        public readonly Response $response,
    ) {
    }
}
```

- [ ] **Step 4: Add the logout dependency and method to `SessionController`**

Add the fifth constructor argument and its import (`use Glueful\Auth\Session\SessionLogout;`):

```php
        private readonly SameOriginGuard $origin,
        private readonly SessionLogout $sessionLogout,
    ) {
    }
```

Update the container definition added in Task 7 to pass it:

```php
                    new \Glueful\Auth\Session\SameOriginGuard(),
                    $c->get(\Glueful\Auth\Session\SessionLogout::class),
                )
```

Update `SessionRefreshTest::controller()` to match the new signature:

```php
        return new SessionController(
            $auth,
            new SessionCookieIssuer($config),
            $config,
            new SameOriginGuard(),
            new SessionLogout($auth, new SessionCookieIssuer($config), $config),
        );
```

Then add the method:

```php
    #[ApiOperation(
        summary: 'End the browser session',
        description: 'Revokes the server-side session and expires both session cookies. '
            . 'Same-origin requests only.',
        tags: ['Authentication'],
    )]
    #[ApiResponseAttribute(200, description: 'Logged out')]
    #[ApiResponseAttribute(403, description: 'Request is not same-origin')]
    public function logout(Request $request): Response
    {
        if (!$this->config->enabled) {
            return $this->disabled();
        }

        if (!$this->origin->isSameOrigin($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        $result = $this->sessionLogout->logout(
            $request,
            new JsonResponse(['success' => true, 'message' => 'Logged out successfully'], 200)
        );

        if (!$result->revoked) {
            // Cookies are cleared, but the server session may still be live and a copied token
            // still usable — that is not a successful logout and must not be reported as one.
            error_log('Session logout: server-side revocation failed; cookies cleared.');

            return $this->issuer->clear(
                new JsonResponse(['success' => false, 'message' => 'Logout incomplete.'], 500)
            );
        }

        return $result->response;
    }
```

- [ ] **Step 5: Add the route and register the service**

In `routes/auth.php`, inside the `/session` group added in Task 7:

```php
        $router->post('/logout', [SessionController::class, 'logout']);
```

Both routes live inside the `enabled` check added in Task 7, so a disabled install registers neither.

```php
```

In `src/Container/Providers/CoreProvider.php`:

```php
        $defs[\Glueful\Auth\Session\SessionLogout::class] = new FactoryDefinition(
            \Glueful\Auth\Session\SessionLogout::class,
            static fn(\Psr\Container\ContainerInterface $c) =>
                new \Glueful\Auth\Session\SessionLogout(
                    $c->get(\Glueful\Auth\AuthenticationService::class),
                    $c->get(\Glueful\Auth\Session\SessionCookieIssuer::class),
                    $c->get(\Glueful\Auth\Session\SessionCookieConfig::class),
                )
        );
```

- [ ] **Step 6: Run the session suites to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session tests/Unit/Routing/SessionCookieMiddlewareTest.php`
Expected: PASS (all session tests, including `SessionRefreshTest` which needed this class).

---

## Task 9: Documentation, environment sample, and changelog

**Files:**
- Create: `docs/BROWSER_SESSIONS.md`
- Modify: `.env.example`, `CHANGELOG.md`
- Test: `tests/Unit/Auth/Session/SessionCookieConfigDefaultsTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: no new code interfaces.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class SessionCookieConfigDefaultsTest extends TestCase
{
    public function testTheTransportIsOffByDefault(): void
    {
        // A framework upgrade must not switch on a new authentication transport by itself.
        $config = SessionCookieConfig::fromContext(ApplicationContext::forTesting(dirname(__DIR__, 4)));

        self::assertFalse($config->enabled);
    }

    public function testSecureDefaultsAreNotOptIn(): void
    {
        $config = SessionCookieConfig::fromContext(ApplicationContext::forTesting(dirname(__DIR__, 4)));

        self::assertTrue($config->secure);
        self::assertSame(Cookie::SAMESITE_LAX, $config->sameSite);
        self::assertNull($config->domain, 'host-only by default');
        self::assertSame('/auth/session', $config->refreshPath, 'refresh must stay path-scoped');
    }

    public function testEveryDocumentedEnvVarAppearsInTheEnvExample(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 4) . '/.env.example');

        foreach ([
            'SESSION_COOKIE_ENABLED',
            'SESSION_COOKIE_ACCESS_NAME',
            'SESSION_COOKIE_REFRESH_NAME',
            'SESSION_COOKIE_REFRESH_TTL',
            'SESSION_COOKIE_PATH',
            'SESSION_COOKIE_REFRESH_PATH',
            'SESSION_COOKIE_DOMAIN',
            'SESSION_COOKIE_SECURE',
            'SESSION_COOKIE_SAMESITE',
        ] as $key) {
            self::assertStringContainsString($key, $example, $key . ' is missing from .env.example');
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/Session/SessionCookieConfigDefaultsTest.php`
Expected: FAIL on `testEveryDocumentedEnvVarAppearsInTheEnvExample` — the keys are not in `.env.example` yet.

- [ ] **Step 3: Add the environment sample block**

Append to `.env.example`, under the authentication section:

```env
# Browser session transport (opt-in). Off by default; bearer API auth is unaffected.
SESSION_COOKIE_ENABLED=false
SESSION_COOKIE_ACCESS_NAME=gf_session
SESSION_COOKIE_REFRESH_NAME=gf_refresh
SESSION_COOKIE_REFRESH_TTL=2592000
SESSION_COOKIE_PATH=/
SESSION_COOKIE_REFRESH_PATH=/auth/session
SESSION_COOKIE_DOMAIN=
SESSION_COOKIE_SECURE=true
SESSION_COOKIE_SAMESITE=lax
```

- [ ] **Step 4: Write the documentation page**

Create `docs/BROWSER_SESSIONS.md` covering, in this order:

1. **What this is** — an opt-in cookie transport for browser clients, sitting alongside bearer auth. One paragraph.
2. **Enabling it** — set `SESSION_COOKIE_ENABLED=true`, add `session_cookie` before `auth` on the routes that should accept cookies: `->middleware(['session_cookie', 'auth'])`, or `['session_cookie:optional', 'auth:optional']` for pages that must survive a lapsed session.
3. **Issuing a session** — a host app's login controller calls `LoginOrchestrator::login()`, checks `isAuthenticated()`, renders its own two-factor step for a challenge outcome, and otherwise passes `$outcome->session()` to `SessionCookieIssuer::issue()`. Include this snippet:

```php
$outcome = $orchestrator->login($credentials);

if (!$outcome->isAuthenticated()) {
    // Render your second-factor step. There is no session yet, and no cookie to set.
    return $this->twoFactorPrompt($outcome->challenge());
}

return $issuer->issue(new RedirectResponse('/'), $outcome->session());
```

4. **Refresh and logout** — `POST /auth/session/refresh` and `POST /auth/session/logout`, same-origin only, no tokens in bodies. Note that JS may retry safe reads after a refresh but unsafe requests must never be replayed automatically.
5. **CSRF** — cookie-authenticated unsafe requests are CSRF-exposed and bearer ones are not; `auth_transport` on the request tells them apart. `SameSite=Lax` is defence in depth, not the primary control.
6. **What is unchanged** — bearer extraction, `/auth/login`, `/auth/refresh-token`, `/auth/logout`.

- [ ] **Step 5: Update the changelog**

Add under `## [Unreleased]` → `### Added` in `CHANGELOG.md`:

```markdown
- **Opt-in browser session transport.** `session_cookie` middleware adapts an HttpOnly access
  cookie into the `Authorization` header the existing `auth` middleware reads, marking
  `auth_transport` so cookie requests can carry CSRF obligations bearer requests do not.
  `SessionCookieIssuer` owns cookie attributes (HttpOnly, Secure, SameSite=Lax, host-configurable
  names, refresh cookie path-scoped to `/auth/session`) and accepts only a completed session, so
  cookies cannot be issued for a login still awaiting two-factor verification. New endpoints
  `POST /auth/session/refresh` and `POST /auth/session/logout` rotate and revoke without ever
  putting tokens in a response body. Login moves behind a transport-neutral `LoginOrchestrator`
  so every transport passes the same two-factor gate. Off by default; bearer authentication and
  JSON login responses are unchanged. See `docs/BROWSER_SESSIONS.md`.
```

- [ ] **Step 6: Run the full gates**

```bash
composer test && composer run phpcs && composer run analyse
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Auth/Session src/Controllers/SessionController.php routes/auth.php src/Container/Providers/CoreProvider.php tests/Unit/Auth/Session .env.example docs/BROWSER_SESSIONS.md CHANGELOG.md
git commit -m "feat(auth): add session refresh and logout endpoints with docs

POST /auth/session/refresh rotates the cookie pair from the path-scoped refresh
cookie and returns no tokens; POST /auth/session/logout revokes the session and
clears both cookies through one composition point, so the pair is what is tested
rather than two operations a caller might do only half of. Both are same-origin
only, enforced by fetch metadata with an exact-Origin fallback."
```

---

## Release

After Task 9, cut the release with the repository's own release workflow (the `release` skill): this is a **minor** version — new features, new env vars, no behavioral change to existing paths. The consuming application pins the published version afterwards; do not bump any dependent repository before the release is published.
