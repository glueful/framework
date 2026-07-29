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
