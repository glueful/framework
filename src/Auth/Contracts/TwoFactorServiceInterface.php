<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

/**
 * Two-factor login challenge seam. Core depends on this contract only — the concrete
 * implementation (user-state-bound: reads `users.two_factor_enabled`, delivers codes) lives in
 * an extension such as glueful/users. When no implementation is registered, core skips 2FA
 * entirely and logs the user straight in.
 *
 * Only the two methods the login flow needs are part of the contract; enrollment/management
 * (beginEnable, disable, …) are the implementation's own concern and not exposed here.
 */
interface TwoFactorServiceInterface
{
    /**
     * Whether 2FA is active for the given user. MUST fail closed (return false) — never throw —
     * when the master switch is off or the backing column/migration is absent.
     */
    public function isEnabled(string $userUuid): bool;

    /**
     * Begin a 2FA-login challenge for an already-credential-verified user.
     *
     * @param array<string, mixed> $user Must include uuid + email.
     * @param string|null $preferredProvider Token provider from /auth/login (jwt, ldap, saml, …),
     *                                        carried through so /2fa/verify issues the final
     *                                        session via the same provider.
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    public function beginLogin(array $user, ?string $preferredProvider = null): array;
}
