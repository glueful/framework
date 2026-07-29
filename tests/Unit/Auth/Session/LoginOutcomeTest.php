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
