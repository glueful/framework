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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With the browser-session transport enabled, `POST /auth/login` issues the session cookies
 * when the request opts in with `auth_transport: "cookie"` — through the same orchestrator and
 * 2FA gate — and keeps tokens out of the body. Bearer clients are unchanged.
 */
final class AuthControllerCookieLoginTest extends TestCase
{
    /** @return array<string,mixed> */
    private function session(): array
    {
        return [
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 900,
            'token_type' => 'Bearer',
            'session_uuid' => 'session-1',
            'user' => ['id' => 'user00000001'],
        ];
    }

    private function controller(
        AuthenticationService $auth,
        bool $transportEnabled,
        ?TwoFactorServiceInterface $twoFactor = null,
    ): AuthController {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->mergeConfigDefaults('auth', ['session_cookie' => [
            'enabled' => $transportEnabled,
            'secure' => true,
        ]]);

        $definitions = [
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            AuthBootstrap::class => new ValueDefinition(AuthBootstrap::class, new AuthBootstrap($context)),
            AuthenticationService::class => new ValueDefinition(AuthenticationService::class, $auth),
            LoginResponseShaper::class => new ValueDefinition(
                LoginResponseShaper::class,
                new LoginResponseShaper($context)
            ),
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

    private function auth(): AuthenticationService
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001']);
        $auth->method('issueSession')->willReturn($this->session());

        return $auth;
    }

    /** @param array<string,mixed> $body */
    private function post(array $body): Request
    {
        return Request::create('/api/v1/auth/login', 'POST', $body);
    }

    /** @return array<string, Cookie> */
    private function cookies(Response $response): array
    {
        $byName = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }

    public function testCookieOptInIssuesSessionCookiesAndKeepsTokensOutOfTheBody(): void
    {
        $response = $this->controller($this->auth(), true)
            ->login($this->post(['username' => 'u', 'password' => 'p', 'auth_transport' => 'cookie']));

        $cookies = $this->cookies($response);
        self::assertSame('access-abc', $cookies['gf_session']->getValue());
        self::assertSame('refresh-xyz', $cookies['gf_refresh']->getValue());
        self::assertTrue($cookies['gf_session']->isHttpOnly());
        self::assertSame('/api/v1/auth/session', $cookies['gf_refresh']->getPath());

        $content = (string) $response->getContent();
        self::assertStringNotContainsString('access-abc', $content);
        self::assertStringNotContainsString('refresh-xyz', $content);
        self::assertStringContainsString('user00000001', $content);
        self::assertStringContainsString('csrf_token', $content);
    }

    public function testBearerLoginIsUnchangedWhenTheTransportIsEnabled(): void
    {
        $response = $this->controller($this->auth(), true)
            ->login($this->post(['username' => 'u', 'password' => 'p']));

        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('access-abc', (string) $response->getContent());
    }

    public function testTheOptInIsIgnoredWhileTheTransportIsDisabled(): void
    {
        $response = $this->controller($this->auth(), false)
            ->login($this->post(['username' => 'u', 'password' => 'p', 'auth_transport' => 'cookie']));

        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('access-abc', (string) $response->getContent());
    }

    public function testAPendingSecondFactorIssuesNoCookies(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('verifyCredentials')->willReturn(['uuid' => 'user00000001', 'email' => 'u@example.test']);
        $auth->expects(self::never())->method('issueSession');

        $twoFactor = $this->createMock(TwoFactorServiceInterface::class);
        $twoFactor->method('isEnabled')->willReturn(true);
        $twoFactor->method('beginLogin')->willReturn(['token' => 'challenge-1', 'expires_in' => 300]);

        $response = $this->controller($auth, true, $twoFactor)
            ->login($this->post(['username' => 'u', 'password' => 'p', 'auth_transport' => 'cookie']));

        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('two_factor_required', (string) $response->getContent());
    }
}
