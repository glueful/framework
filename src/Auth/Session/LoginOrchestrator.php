<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * The one password-login path: credential verification, the two-factor gate, then session
 * issuance.
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
