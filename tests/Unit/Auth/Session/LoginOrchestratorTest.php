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
