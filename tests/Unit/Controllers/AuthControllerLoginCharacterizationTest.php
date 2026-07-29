<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Auth\AuthBootstrap;
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

/**
 * Characterizes AuthController::login() as it behaves TODAY, before the orchestrator
 * extraction. Every assertion here is a description of current behavior, not a wish.
 *
 * The token and API-key branches matter most: those providers return an identity array with
 * NO tokens, so any refactor that routes them through a tokens-required type turns a working
 * login into a 500. The pre-existing suite does not cover these branches end to end.
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

    /**
     * Identity-only payload, as API-key and token providers actually return.
     *
     * @return array<string,mixed>
     */
    private function identityOnlyResult(): array
    {
        return ['uuid' => 'user00000001', 'provider' => 'api_key', 'scopes' => ['read']];
    }

    private function controller(
        AuthenticationService $auth,
        ?TwoFactorServiceInterface $twoFactor = null,
    ): AuthController {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        // The real shaper: it is final, and its CSRF/event steps are already exception-guarded,
        // so it runs standalone and returns the genuine login envelope.
        $shaper = new LoginResponseShaper($context);

        $definitions = [
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            AuthBootstrap::class => new ValueDefinition(AuthBootstrap::class, new AuthBootstrap($context)),
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

    /** @param array<string,mixed> $body */
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
